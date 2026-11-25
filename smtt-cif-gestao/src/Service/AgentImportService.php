<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Agent\Cpf;
use SMTT\CIF\Gestao\Import\AgentFileParser;
use SMTT\CIF\Gestao\Repository\AgentImportRepository;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\DataProtection;
use SMTT\CIF\Gestao\Support\Uuid;

final class AgentImportService
{
    public function __construct(
        private readonly AgentFileParser $parser,
        private readonly AgentImportRepository $imports,
        private readonly AgentRepository $agents,
        private readonly AgentService $agentService,
        private readonly DataProtection $protection,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit
    ) {
    }

    /** @return array<string,mixed> */
    public function prepare(string $path, string $originalName, CifUser $actor): array
    {
        $parsed = $this->parser->parse($path, $originalName);
        if ($parsed['rows'] === []) {
            throw new RuntimeException('O arquivo não contém agentes para importar.');
        }

        $fileHash = hash_file('sha256', $path, true);
        if (!is_string($fileHash)) {
            throw new RuntimeException('Não foi possível calcular a integridade do arquivo de importação.');
        }
        $safeName = $this->safeFilename($originalName);
        $uuid = Uuid::v4();

        return $this->transactions->run(function () use ($parsed, $fileHash, $safeName, $uuid, $actor): array {
            $batchId = $this->imports->createBatch($uuid, $safeName, $parsed['format'], $fileHash, $actor->id);
            $seenMatriculas = [];
            $seenCpfs = [];
            $total = 0;
            $ready = 0;
            $duplicates = 0;
            $errors = 0;

            foreach ($parsed['rows'] as $row) {
                ++$total;
                $line = (int) $row['line'];
                try {
                    $input = $this->normalizeSpreadsheetCells($row['data']);
                    $normalized = $this->agentService->normalizeInput($input);
                    /** @var Cpf $cpf */
                    $cpf = $normalized['cpf'];
                    $protected = $this->protection->protectCpf($cpf->digits());
                    $matriculaKey = strtoupper((string) $normalized['matricula']);
                    $cpfKey = bin2hex($protected['lookup']);

                    if (isset($seenMatriculas[$matriculaKey]) || isset($seenCpfs[$cpfKey])) {
                        ++$duplicates;
                        $this->imports->addItem(
                            $batchId,
                            $line,
                            'DUPLICATE_FILE',
                            (string) $normalized['matricula'],
                            $protected['lookup'],
                            null,
                            null,
                            $this->serializableData($normalized),
                            'DUPLICATE_FILE',
                            'Matrícula ou CPF repetido dentro do próprio arquivo.'
                        );
                        continue;
                    }
                    $seenMatriculas[$matriculaKey] = true;
                    $seenCpfs[$cpfKey] = true;

                    if ($this->agents->existsMatricula((string) $normalized['matricula'])) {
                        ++$duplicates;
                        $this->imports->addItem(
                            $batchId,
                            $line,
                            'DUPLICATE_DB',
                            (string) $normalized['matricula'],
                            $protected['lookup'],
                            null,
                            null,
                            $this->serializableData($normalized),
                            'MATRICULA_EXISTENTE',
                            'Já existe agente cadastrado com esta matrícula.'
                        );
                        continue;
                    }
                    if ($this->agents->existsCpfLookup($protected['lookup'])) {
                        ++$duplicates;
                        $this->imports->addItem(
                            $batchId,
                            $line,
                            'DUPLICATE_DB',
                            (string) $normalized['matricula'],
                            $protected['lookup'],
                            null,
                            null,
                            $this->serializableData($normalized),
                            'CPF_EXISTENTE',
                            'Já existe agente cadastrado com este CPF.'
                        );
                        continue;
                    }

                    ++$ready;
                    $this->imports->addItem(
                        $batchId,
                        $line,
                        'READY',
                        (string) $normalized['matricula'],
                        $protected['lookup'],
                        $protected['ciphertext'],
                        $protected['key_id'],
                        $this->serializableData($normalized),
                        null,
                        null
                    );
                } catch (InvalidArgumentException|RuntimeException $e) {
                    ++$errors;
                    $rawMatricula = trim((string) ($row['data']['matricula'] ?? ''));
                    $this->imports->addItem(
                        $batchId,
                        $line,
                        'ERROR',
                        $rawMatricula !== '' ? substr($rawMatricula, 0, 30) : null,
                        null,
                        null,
                        null,
                        $this->safePreviewData($row['data']),
                        'VALIDATION_ERROR',
                        substr($e->getMessage(), 0, 500)
                    );
                }
            }

            $this->imports->updatePreviewCounts($batchId, $total, $ready, $duplicates, $errors);
            $this->audit->log(
                'AGENTES_IMPORTACAO_PREPARADA',
                'importacao_agentes',
                $batchId,
                $uuid,
                $actor,
                'IMPORT',
                null,
                [
                    'arquivo' => $safeName,
                    'formato' => $parsed['format'],
                    'total' => $total,
                    'prontas' => $ready,
                    'duplicadas' => $duplicates,
                    'erros' => $errors,
                ]
            );

            return $this->requireBatch($uuid);
        });
    }

    /** @return array<string,mixed> */
    public function details(string $uuid): array
    {
        $batch = $this->requireBatch($uuid);
        $batch['items'] = $this->imports->listItems((int) $batch['id']);
        return $batch;
    }

    /** @return list<array<string,mixed>> */
    public function recent(): array
    {
        return $this->imports->recentBatches();
    }

    /** @return array<string,mixed> */
    public function confirm(string $uuid, CifUser $actor): array
    {
        $batch = $this->transactions->run(function () use ($uuid): array {
            $locked = $this->imports->lockBatchByUuid($uuid);
            if ($locked === null) {
                throw new RuntimeException('Lote de importação não localizado.');
            }
            $status = (string) $locked['status'];
            if (in_array($status, ['COMPLETED', 'COMPLETED_WITH_ISSUES'], true)) {
                return $locked;
            }
            if (!in_array($status, ['PREVIEW', 'PROCESSING'], true)) {
                throw new RuntimeException('Este lote não pode ser confirmado no estado atual.');
            }
            if ($status === 'PREVIEW') {
                $this->imports->markConfirmed((int) $locked['id']);
                $locked['status'] = 'PROCESSING';
            }
            return $locked;
        });

        if (in_array((string) $batch['status'], ['COMPLETED', 'COMPLETED_WITH_ISSUES'], true)) {
            return $this->details($uuid);
        }

        $items = $this->imports->readyItems((int) $batch['id']);
        foreach ($items as $item) {
            if (!$this->imports->claimItem((int) $item['id'])) {
                continue;
            }
            try {
                $this->transactions->run(function () use ($item, $actor, $uuid): void {
                    $cpfDigits = $this->protection->decryptCpf(
                        (string) $item['cpf_ciphertext'],
                        (string) $item['cpf_key_id']
                    );
                    $data = $item['dados'];
                    $data['cpf'] = $cpfDigits;
                    $agent = $this->agentService->createImported(
                        $data,
                        $actor,
                        $uuid,
                        (int) $item['numero_linha']
                    );
                    $this->imports->markItemImported((int) $item['id'], (int) $agent['id']);
                });
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                $code = str_contains($message, 'existe') || str_contains($message, 'utilizado')
                    ? 'CONFLICT_AFTER_PREVIEW'
                    : 'IMPORT_ERROR';
                if ($code === 'CONFLICT_AFTER_PREVIEW') {
                    $this->imports->markItemConflict((int) $item['id'], $code, substr($message, 0, 500));
                } else {
                    $this->imports->markItemError((int) $item['id'], $code, substr($message, 0, 500));
                }
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha em linha de importação de agente: ' . $e->getMessage());
                $this->imports->markItemError(
                    (int) $item['id'],
                    'UNEXPECTED_IMPORT_ERROR',
                    'Não foi possível importar esta linha. Consulte o log técnico do sistema.'
                );
            }
        }

        $this->imports->completeBatch((int) $batch['id']);
        $completed = $this->requireBatch($uuid);
        $this->audit->log(
            'AGENTES_IMPORTACAO_CONFIRMADA',
            'importacao_agentes',
            (int) $completed['id'],
            $uuid,
            $actor,
            'IMPORT',
            null,
            [
                'status' => (string) $completed['status'],
                'total' => (int) $completed['total_linhas'],
                'importadas' => (int) $completed['linhas_importadas'],
                'duplicadas' => (int) $completed['linhas_duplicadas'],
                'erros' => (int) $completed['linhas_com_erro'],
            ]
        );
        return $this->details($uuid);
    }

    /** @return array<string,mixed> */
    private function requireBatch(string $uuid): array
    {
        $batch = $this->imports->findBatchByUuid(trim($uuid));
        if ($batch === null) {
            throw new RuntimeException('Lote de importação não localizado.');
        }
        return $batch;
    }

    /** @param array<string,string> $row @return array<string,string> */
    private function normalizeSpreadsheetCells(array $row): array
    {
        foreach (['data_nascimento', 'data_admissao'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && is_numeric($value)) {
                $serial = (float) $value;
                if ($serial >= 1 && $serial <= 100000) {
                    $days = (int) floor($serial);
                    $date = new DateTimeImmutable('1899-12-30');
                    $row[$field] = $date->modify('+' . $days . ' days')->format('Y-m-d');
                }
            }
        }

        $cpf = preg_replace('/\D+/', '', (string) ($row['cpf'] ?? '')) ?? '';
        if ($cpf !== '' && strlen($cpf) < 11 && ctype_digit($cpf)) {
            $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
        }
        $row['cpf'] = $cpf;
        return $row;
    }

    /** @param array<string,mixed> $normalized @return array<string,string|null> */
    private function serializableData(array $normalized): array
    {
        return [
            'matricula' => (string) $normalized['matricula'],
            'nome_completo' => (string) $normalized['nome_completo'],
            'naturalidade' => (string) $normalized['naturalidade'],
            'data_nascimento' => (string) $normalized['data_nascimento'],
            'tipo_sanguineo' => (string) $normalized['tipo_sanguineo'],
            'data_admissao' => (string) $normalized['data_admissao'],
            'situacao_funcional' => (string) $normalized['situacao_funcional'],
            'fonte_referencia' => $normalized['fonte_referencia'] !== null ? (string) $normalized['fonte_referencia'] : null,
        ];
    }

    /** @param array<string,string> $raw @return array<string,string> */
    private function safePreviewData(array $raw): array
    {
        $safe = [];
        foreach (['matricula', 'nome_completo', 'naturalidade', 'data_nascimento', 'tipo_sanguineo', 'data_admissao', 'situacao_funcional', 'fonte_referencia'] as $field) {
            $safe[$field] = substr(trim((string) ($raw[$field] ?? '')), 0, 200);
        }
        return $safe;
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'importacao';
        $name = trim($name);
        return $name !== '' ? substr($name, 0, 255) : 'importacao';
    }
}
