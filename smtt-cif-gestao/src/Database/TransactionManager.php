<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Database;

use mysqli;
use mysqli_sql_exception;
use Throwable;

final class TransactionManager
{
    private int $depth = 0;

    public function __construct(private readonly mysqli $db)
    {
    }

    public function run(callable $operation, int $maxRetries = 3): mixed
    {
        if ($this->depth > 0) {
            return $this->runNested($operation);
        }

        $attempt = 0;

        beginning:
        ++$attempt;
        $this->db->begin_transaction();
        $this->depth = 1;

        try {
            $result = $operation($this->db);
            $this->db->commit();
            $this->depth = 0;
            return $result;
        } catch (Throwable $e) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Mantém a exceção original.
            }
            $this->depth = 0;

            if ($e instanceof mysqli_sql_exception && $attempt < $maxRetries && $this->isRetryable($e)) {
                usleep(random_int(20_000, 80_000) * $attempt);
                goto beginning;
            }

            throw $e;
        }
    }

    private function runNested(callable $operation): mixed
    {
        $savepoint = 'smtt_cif_sp_' . $this->depth;
        $this->db->query('SAVEPOINT ' . $savepoint);
        ++$this->depth;

        try {
            $result = $operation($this->db);
            --$this->depth;
            $this->db->query('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $e) {
            --$this->depth;
            $this->db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
            throw $e;
        }
    }

    private function isRetryable(mysqli_sql_exception $e): bool
    {
        $driverCode = (int) $e->getCode();
        $sqlState = method_exists($e, 'getSqlState') ? (string) $e->getSqlState() : '';
        return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}
