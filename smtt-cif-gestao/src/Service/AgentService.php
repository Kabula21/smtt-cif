<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use mysqli_sql_exception;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Agent\Cpf;
use SMTT\CIF\Gestao\Domain\Agent\FunctionalStatus;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\DataProtection;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class AgentService
{
    private const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function __construct(
        private readonly AgentRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly DataProtection $protection,
        private readonly PrivateStorage $storage
    ) {
    }

    /** @return array<string,mixed> */
    public function create(array $input, CifUser $actor): array
    {
        return $this->createWithContext(
            $input,
            $actor,
            'CADASTRO_MANUAL_CIF',
            'WEB',
            'AGENTE_CADASTRADO',
            null
        );
    }

    /** @return array<string,mixed> */
    public function createImported(
        array $input,
        CifUser $actor,
        string $batchUuid,
        int $line
    ): array {
        $reference = 'Importação ' . substr($batchUuid, 0, 8) . ', linha ' . $line;
        $rowReference = trim((string) ($input['fonte_referencia'] ?? ''));
        if ($rowReference !== '') {
            $reference .= ' — ' . $rowReference;
        }
        if ($this->length($reference) > 150) {
            $reference = function_exists('mb_substr')
                ? mb_substr($reference, 0, 150, 'UTF-8')
                : substr($reference, 0, 150);
        }
        $input['fonte_referencia'] = $reference;

        return $this->createWithContext(
            $input,
            $actor,
            'IMPORTACAO_LOTE_CIF',
            'IMPORT',
            'AGENTE_IMPORTADO',
            $reference
        );
    }

    /** @return array<string,mixed> */
    public function normalizeInput(array $input): array
    {
        return $this->validate($input);
    }

    public function cpfLookupForSearch(string $input): string
    {
        $cpf = Cpf::fromInput($input);
        return $this->protection->lookupCpf($cpf->digits());
    }

    /** @return array<string,string> */
    public function sourceOptions(): array
    {
        return [
            'CADASTRO_MANUAL_CIF' => 'Cadastro manual',
            'ALTERACAO_MANUAL_CIF' => 'Alteração manual',
            'IMPORTACAO_LOTE_CIF' => 'Importação em lote',
        ];
    }

    /** @return array<string,mixed> */
    private function createWithContext(
        array $input,
        CifUser $actor,
        string $source,
        string $origin,
        string $auditOperation,
        ?string $sourceReferenceOverride
    ): array {
        $data = $this->validate($input);
        if ($sourceReferenceOverride !== null) {
            $data['fonte_referencia'] = $sourceReferenceOverride;
        }
        $protected = $this->protection->protectCpf($data['cpf']->digits());

        try {
            return $this->transactions->run(function () use ($data, $protected, $actor, $source, $origin, $auditOperation): array {
                if ($this->repository->existsMatricula($data['matricula'])) {
                    throw new RuntimeException('Já existe um agente cadastrado com essa matrícula.');
                }
                if ($this->repository->existsCpfLookup($protected['lookup'])) {
                    throw new RuntimeException('Já existe um agente cadastrado com esse CPF.');
                }

                $uuid = Uuid::v4();
                $agentId = $this->repository->insertAgent(
                    $uuid,
                    $data['matricula'],
                    $protected['lookup'],
                    $data['situacao_funcional']
                );

                $this->repository->insertBiographical($agentId, 1, [
                    'nome_completo' => $data['nome_completo'],
                    'matricula' => $data['matricula'],
                    'naturalidade' => $data['naturalidade'],
                    'data_nascimento' => $data['data_nascimento'],
                    'cpf_lookup_hmac' => $protected['lookup'],
                    'cpf_ciphertext' => $protected['ciphertext'],
                    'cpf_key_id' => $protected['key_id'],
                    'tipo_sanguineo' => $data['tipo_sanguineo'],
                    'data_admissao' => $data['data_admissao'],
                    'fonte' => $source,
                    'fonte_referencia' => $data['fonte_referencia'],
                ], $actor->id);

                $this->repository->insertFunctionalStatus(
                    $agentId,
                    $data['situacao_funcional'],
                    $actor->id,
                    $source
                );

                $this->audit->log(
                    $auditOperation,
                    'agente',
                    $agentId,
                    $uuid,
                    $actor,
                    $origin,
                    null,
                    $this->auditSnapshot($data, 1)
                );

                return $this->requireCurrent($uuid);
            });
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                throw new RuntimeException('Matrícula ou CPF já utilizado por outro agente.', 0, $e);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function update(string $uuid, array $input, int $submittedLockVersion, CifUser $actor): array
    {
        $data = $this->validate($input);
        $protected = $this->protection->protectCpf($data['cpf']->digits());

        try {
            return $this->transactions->run(function () use ($uuid, $data, $protected, $submittedLockVersion, $actor): array {
                $locked = $this->repository->lockByUuid($uuid);
                if ($locked === null) {
                    throw new RuntimeException('Agente não localizado.');
                }
                if ((int) $locked['lock_version'] !== $submittedLockVersion) {
                    throw new RuntimeException('Este cadastro foi alterado por outro usuário. Recarregue a página antes de salvar novamente.');
                }

                $agentId = (int) $locked['id'];
                if ($this->repository->existsMatricula($data['matricula'], $agentId)) {
                    throw new RuntimeException('Já existe outro agente cadastrado com essa matrícula.');
                }
                if ($this->repository->existsCpfLookup($protected['lookup'], $agentId)) {
                    throw new RuntimeException('Já existe outro agente cadastrado com esse CPF.');
                }

                $current = $this->repository->currentBiographical($agentId);
                if ($current === null) {
                    throw new RuntimeException('O agente não possui versão biográfica válida.');
                }

                $currentCpf = Cpf::fromInput($this->protection->decryptCpf(
                    (string) $current['cpf_ciphertext'],
                    (string) $current['cpf_key_id']
                ));

                $bioChanged = $this->biographicalChanged($current, $currentCpf, $data);
                $statusChanged = (string) $locked['situacao_funcional_atual'] !== $data['situacao_funcional'];
                if (!$bioChanged && !$statusChanged) {
                    throw new RuntimeException('Nenhuma alteração foi identificada no cadastro.');
                }

                $newVersion = (int) $current['versao'];
                if ($bioChanged) {
                    ++$newVersion;
                    $this->repository->insertBiographical($agentId, $newVersion, [
                        'nome_completo' => $data['nome_completo'],
                        'matricula' => $data['matricula'],
                        'naturalidade' => $data['naturalidade'],
                        'data_nascimento' => $data['data_nascimento'],
                        'cpf_lookup_hmac' => $protected['lookup'],
                        'cpf_ciphertext' => $protected['ciphertext'],
                        'cpf_key_id' => $protected['key_id'],
                        'tipo_sanguineo' => $data['tipo_sanguineo'],
                        'data_admissao' => $data['data_admissao'],
                        'fonte' => 'ALTERACAO_MANUAL_CIF',
                        'fonte_referencia' => $data['fonte_referencia'],
                    ], $actor->id);
                }

                if ($statusChanged) {
                    $this->repository->closeCurrentFunctionalStatus($agentId);
                    $this->repository->insertFunctionalStatus(
                        $agentId,
                        $data['situacao_funcional'],
                        $actor->id,
                        'ALTERACAO_MANUAL_CIF'
                    );
                }

                if (!$this->repository->updateAgentCurrent(
                    $agentId,
                    $data['matricula'],
                    $protected['lookup'],
                    $data['situacao_funcional'],
                    $submittedLockVersion
                )) {
                    throw new RuntimeException('O cadastro foi alterado simultaneamente. Recarregue a página e tente novamente.');
                }

                $this->audit->log(
                    'AGENTE_ATUALIZADO',
                    'agente',
                    $agentId,
                    $uuid,
                    $actor,
                    'WEB',
                    $this->auditSnapshot([
                        'matricula' => (string) $locked['matricula'],
                        'nome_completo' => (string) $current['nome_completo'],
                        'naturalidade' => (string) $current['naturalidade'],
                        'data_nascimento' => (string) $current['data_nascimento'],
                        'tipo_sanguineo' => (string) $current['tipo_sanguineo'],
                        'data_admissao' => (string) $current['data_admissao'],
                        'situacao_funcional' => (string) $locked['situacao_funcional_atual'],
                        'fonte_referencia' => $current['fonte_referencia'],
                        'cpf' => $currentCpf,
                    ], (int) $current['versao']),
                    $this->auditSnapshot($data, $newVersion)
                );

                return $this->requireCurrent($uuid);
            });
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                throw new RuntimeException('Matrícula ou CPF já utilizado por outro agente.', 0, $e);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function details(string $uuid, bool $includeSensitive = false): array
    {
        $current = $this->requireCurrent($uuid);
        $agentId = (int) $current['id'];
        $cpf = Cpf::fromInput($this->protection->decryptCpf(
            (string) $current['cpf_ciphertext'],
            (string) $current['cpf_key_id']
        ));
        $current['cpf_masked'] = $cpf->masked();
        if ($includeSensitive) {
            $current['cpf'] = $cpf->formatted();
        }
        $current['situacao_funcional_label'] = FunctionalStatus::label((string) $current['situacao_funcional_atual']);

        $history = [];
        foreach ($this->repository->biographicalHistory($agentId) as $row) {
            try {
                $historyCpf = Cpf::fromInput($this->protection->decryptCpf(
                    (string) $row['cpf_ciphertext'],
                    (string) $row['cpf_key_id']
                ));
                $row['cpf_masked'] = $historyCpf->masked();
            } catch (\Throwable) {
                $row['cpf_masked'] = 'Protegido';
            }
            unset($row['cpf_ciphertext'], $row['cpf_key_id']);
            $history[] = $row;
        }

        $functional = $this->repository->functionalHistory($agentId);
        foreach ($functional as &$row) {
            $row['label'] = FunctionalStatus::label((string) $row['codigo']);
        }
        unset($row);

        $current['biographical_history'] = $history;
        $current['functional_history'] = $functional;
        return $current;
    }



    public function setRegistrationActive(string $uuid, bool $active, CifUser $actor): array
    {
        return $this->transactions->run(function () use ($uuid, $active, $actor): array {
            $locked = $this->repository->lockByUuid(trim($uuid));
            if ($locked === null) {
                throw new RuntimeException('Agente não localizado.');
            }
            $before = (bool) $locked['ativo'];
            if ($before === $active) {
                return $this->requireCurrent($uuid);
            }
            $this->repository->setRegistrationActive((int) $locked['id'], $active);
            $this->audit->log(
                $active ? 'AGENTE_CADASTRO_REATIVADO' : 'AGENTE_CADASTRO_ARQUIVADO',
                'agente',
                (int) $locked['id'],
                (string) $locked['uuid'],
                $actor,
                'WEB',
                ['ativo' => $before],
                ['ativo' => $active],
                $active ? 'Reativação do cadastro operacional do agente.' : 'Arquivamento do cadastro operacional do agente.'
            );
            return $this->requireCurrent($uuid);
        });
    }

    public function discardBeforeFirstEmission(string $uuid, string $reason, CifUser $actor): void
    {
        $reason = trim($reason);
        if ($this->length($reason) < 10 || $this->length($reason) > 500) {
            throw new RuntimeException('Informe uma justificativa entre 10 e 500 caracteres para descartar o cadastro.');
        }

        $filesToDelete = $this->transactions->run(function () use ($uuid, $reason, $actor): array {
            $locked = $this->repository->lockByUuid(trim($uuid));
            if ($locked === null) {
                throw new RuntimeException('Agente não localizado.');
            }
            if ($this->repository->hasIssuedEmission((int) $locked['id'])) {
                throw new RuntimeException('Este agente já possui CIF emitida. O cadastro e seu histórico não podem mais ser excluídos; corrija os dados e, quando necessário, invalide/substitua a emissão existente.');
            }

            $snapshot = $this->repository->findCurrentByUuid((string) $locked['uuid']);
            $counts = $this->repository->dependencyCounts((int) $locked['id']);
            $candidateFiles = $this->repository->preEmissionPrivateFiles((int) $locked['id']);

            $this->repository->discardPreEmissionAgent((int) $locked['id']);

            $storageKeys = [];
            foreach ($candidateFiles as $file) {
                if ($this->repository->deletePrivateFileIfOrphan((int) $file['id'])) {
                    $storageKeys[] = (string) $file['storage_key'];
                }
            }

            $this->audit->log(
                'AGENTE_CADASTRO_DESCARTADO_PRE_EMISSAO',
                'agente',
                (int) $locked['id'],
                (string) $locked['uuid'],
                $actor,
                'WEB',
                $snapshot === null ? null : [
                    'matricula' => (string) ($snapshot['matricula'] ?? ''),
                    'nome_completo' => (string) ($snapshot['nome_completo'] ?? ''),
                    'vinculos_preparatorios' => $counts,
                ],
                null,
                $reason
            );

            return $storageKeys;
        });

        foreach ($filesToDelete as $storageKey) {
            $this->storage->delete((string) $storageKey);
        }
    }

    /** @return array<string,string> */
    public function functionalStatusOptions(): array
    {
        return FunctionalStatus::options();
    }

    /** @return list<string> */
    public function bloodTypes(): array
    {
        return self::BLOOD_TYPES;
    }

    /** @return array<string,mixed> */
    private function requireCurrent(string $uuid): array
    {
        $current = $this->repository->findCurrentByUuid(trim($uuid));
        if ($current === null) {
            throw new RuntimeException('Agente não localizado.');
        }
        return $current;
    }

    /** @return array<string,mixed> */
    private function validate(array $input): array
    {
        $matricula = strtoupper(trim((string) ($input['matricula'] ?? '')));
        $name = $this->cleanText((string) ($input['nome_completo'] ?? ''), 200, 'Nome completo');
        $naturalidade = $this->cleanText((string) ($input['naturalidade'] ?? ''), 150, 'Naturalidade');
        $birth = $this->validDate((string) ($input['data_nascimento'] ?? ''), 'Data de nascimento');
        $admission = $this->validDate((string) ($input['data_admissao'] ?? ''), 'Data de admissão');
        $cpf = Cpf::fromInput((string) ($input['cpf'] ?? ''));
        $blood = strtoupper(trim((string) ($input['tipo_sanguineo'] ?? '')));
        $status = FunctionalStatus::normalize((string) ($input['situacao_funcional'] ?? ''));
        $sourceRef = trim((string) ($input['fonte_referencia'] ?? ''));

        if ($matricula === '' || $this->length($matricula) > 30) {
            throw new InvalidArgumentException('Matrícula é obrigatória e deve possuir até 30 caracteres.');
        }
        if (preg_match('/^[A-Z0-9._\/-]+$/u', $matricula) !== 1) {
            throw new InvalidArgumentException('Matrícula contém caracteres não permitidos.');
        }
        if (!in_array($blood, self::BLOOD_TYPES, true)) {
            throw new InvalidArgumentException('Tipo sanguíneo inválido.');
        }
        if ($birth > $admission) {
            throw new InvalidArgumentException('A data de admissão não pode ser anterior à data de nascimento.');
        }
        $today = new DateTimeImmutable('today');
        if ($birth > $today || $admission > $today) {
            throw new InvalidArgumentException('Datas futuras não são permitidas no cadastro biográfico.');
        }
        if ($sourceRef !== '' && $this->length($sourceRef) > 150) {
            throw new InvalidArgumentException('Referência da fonte deve possuir até 150 caracteres.');
        }

        return [
            'matricula' => $matricula,
            'nome_completo' => $name,
            'naturalidade' => $naturalidade,
            'data_nascimento' => $birth->format('Y-m-d'),
            'cpf' => $cpf,
            'tipo_sanguineo' => $blood,
            'data_admissao' => $admission->format('Y-m-d'),
            'situacao_funcional' => $status,
            'fonte_referencia' => $sourceRef !== '' ? $sourceRef : null,
        ];
    }

    private function cleanText(string $value, int $max, string $label): string
    {
        $value = trim(wp_strip_all_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if ($value === '' || $this->length($value) > $max) {
            throw new InvalidArgumentException($label . ' é obrigatório e deve possuir até ' . $max . ' caracteres.');
        }
        return $value;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function validDate(string $value, string $label): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' inválida.');
        }
        return $date;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $data */
    private function biographicalChanged(array $current, Cpf $currentCpf, array $data): bool
    {
        return
            (string) $current['nome_completo'] !== $data['nome_completo'] ||
            (string) $current['matricula_snapshot'] !== $data['matricula'] ||
            (string) $current['naturalidade'] !== $data['naturalidade'] ||
            (string) $current['data_nascimento'] !== $data['data_nascimento'] ||
            $currentCpf->digits() !== $data['cpf']->digits() ||
            (string) $current['tipo_sanguineo'] !== $data['tipo_sanguineo'] ||
            (string) $current['data_admissao'] !== $data['data_admissao'] ||
            (string) ($current['fonte_referencia'] ?? '') !== (string) ($data['fonte_referencia'] ?? '');
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function auditSnapshot(array $data, int $version): array
    {
        $cpf = $data['cpf'] ?? null;
        return [
            'versao_biografica' => $version,
            'matricula' => (string) ($data['matricula'] ?? ''),
            'nome_completo' => (string) ($data['nome_completo'] ?? ''),
            'naturalidade' => (string) ($data['naturalidade'] ?? ''),
            'data_nascimento' => (string) ($data['data_nascimento'] ?? ''),
            'cpf_presente' => $cpf instanceof Cpf,
            'tipo_sanguineo' => (string) ($data['tipo_sanguineo'] ?? ''),
            'data_admissao' => (string) ($data['data_admissao'] ?? ''),
            'situacao_funcional' => (string) ($data['situacao_funcional'] ?? ''),
            'fonte_referencia' => $data['fonte_referencia'] ?? null,
        ];
    }
}
