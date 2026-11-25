<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Database;

use mysqli;
use Throwable;

final class TransactionManager
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function run(callable $callback): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $callback();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
