<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Biometry\ImageInspector;
use SMTT\CIF\Gestao\Biometry\ImageNormalizer;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class EmissionSetupService
{
    public function __construct(
        private readonly EmissionRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PrivateStorage $storage,
        private readonly ImageInspector $inspector,
        private readonly ImageNormalizer $normalizer
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(?CifUser $actor = null): array
    {
        return [
            'numbering' => $this->repository->currentNumbering(),
            'current_signer' => $this->repository->currentSigner(),
            'signers' => $this->repository->signers(),
        ];
    }

    /**
     * Compatibilidade legada: os ciclos globais deixaram de ser usados na regra de código.
     * O ciclo agora é individual por agente e nasce com cada novo processo.
     * Cria somente o ciclo inaugural, de forma automática, quando a instalação
     * ainda não possui qualquer ciclo. Mudanças posteriores continuam sendo
     * decisões administrativas extraordinárias e auditadas.
     */
    public function ensureInitialCycle(CifUser $actor): ?array
    {
        return $this->transactions->run(function () use ($actor): ?array {
            $control = $this->repository->lockNumberingControl();
            if ($control === null) {
                throw new RuntimeException('Controle de numeração CIF não localizado.');
            }

            if (!empty($control['ciclo_ativo_id'])) {
                return $this->repository->activeCycleById((int) $control['ciclo_ativo_id']);
            }

            // Se já houver histórico de ciclos, não tomamos uma decisão automática
            // sobre uma nova série. Esse cenário precisa de intervenção administrativa.
            if ($this->repository->maxCycleNumber() > 0) {
                return null;
            }

            $uuid = Uuid::v4();
            $description = 'Ciclo inicial automático do Sistema CIF';
            $justification = 'Ciclo 01 criado automaticamente na configuração inicial do Sistema CIF.';
            $id = $this->repository->insertCycle(
                $uuid,
                1,
                $description,
                null,
                $justification,
                $actor->id
            );
            $this->repository->setActiveCycle($id);
            $this->audit->log(
                'CICLO_EMISSAO_INICIAL_CRIADO_AUTOMATICAMENTE',
                'ciclo_emissao',
                $id,
                $uuid,
                $actor,
                'WEB',
                null,
                ['numero' => 1, 'ultimo_sequencial_global' => (int) $control['ultimo_sequencial']],
                $justification
            );

            return $this->repository->activeCycleById($id);
        });
    }

    /** @return array<string,mixed> */
    public function openNextCycle(array $input, CifUser $actor): array
    {
        $description = $this->text((string) ($input['descricao'] ?? ''), 200, false);
        $act = $this->text((string) ($input['ato_referencia'] ?? ''), 200, false);
        $justification = $this->text((string) ($input['justificativa'] ?? ''), 3000, true);
        if ($this->length($justification) < 10) {
            throw new InvalidArgumentException('Informe uma justificativa com pelo menos 10 caracteres para abrir o ciclo.');
        }

        return $this->transactions->run(function () use ($description, $act, $justification, $actor): array {
            $control = $this->repository->lockNumberingControl();
            if ($control === null) {
                throw new RuntimeException('Controle de numeração CIF não localizado.');
            }
            $current = null;
            if (!empty($control['ciclo_ativo_id'])) {
                $current = $this->repository->activeCycleById((int) $control['ciclo_ativo_id']);
            }
            $next = $current === null
                ? ($this->repository->maxCycleNumber() + 1)
                : ((int) $current['numero'] + 1);
            if ($next > 99) {
                throw new RuntimeException('O limite de 99 ciclos foi atingido. É necessária definição normativa antes de prosseguir.');
            }

            if ($current !== null) {
                $this->repository->closeActiveCycle($actor->id);
            }
            $uuid = Uuid::v4();
            $id = $this->repository->insertCycle(
                $uuid,
                $next,
                $description !== '' ? $description : null,
                $act !== '' ? $act : null,
                $justification,
                $actor->id
            );
            $this->repository->setActiveCycle($id);
            $this->audit->log(
                'CICLO_EMISSAO_ABERTO', 'ciclo_emissao', $id, $uuid, $actor, 'WEB',
                $current === null ? null : ['numero' => (int) $current['numero']],
                ['numero' => $next, 'ultimo_sequencial_global' => (int) $control['ultimo_sequencial']],
                $justification
            );
            return ['id' => $id, 'uuid' => $uuid, 'numero' => $next];
        });
    }

    /** @param array<string,mixed> $upload @return array<string,mixed> */
    public function createSigner(array $input, array $upload, CifUser $actor): array
    {
        $name = $this->text((string) ($input['nome'] ?? ''), 200, true);
        $role = $this->text((string) ($input['cargo'] ?? ''), 150, true);
        $act = $this->text((string) ($input['ato_referencia'] ?? ''), 200, false);
        if ($name === '' || $role === '') {
            throw new InvalidArgumentException('Nome e cargo do signatário são obrigatórios.');
        }

        $tmp = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Envie a imagem da assinatura institucional.');
        }
        if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('A assinatura institucional deve possuir no máximo 5 MB.');
        }
        $originalInfo = $this->inspector->inspectUpload($upload);
        $prepared = $this->normalizer->prepare($tmp, $originalInfo, 'signature');
        $normalizedInfo = $this->inspector->inspectUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $prepared['path']]);
        $stored = $this->storage->storeImage((string) $prepared['path'], (string) $normalizedInfo['mime_type'], 'assinatura_signatario');

        try {
            return $this->transactions->run(function () use ($name, $role, $act, $upload, $stored, $actor): array {
                // Serializa alterações institucionais críticas para impedir dois signatários vigentes simultaneamente.
                $this->repository->lockNumberingControl();
                $fileId = $this->repository->insertPrivateFile([
                    ...$stored,
                    'categoria' => 'ASSINATURA_SIGNATARIO',
                    'nome_original' => $this->safeOriginalName((string) ($upload['name'] ?? 'assinatura')),
                ], $actor->id);
                $this->repository->closeCurrentSigners();
                $uuid = Uuid::v4();
                $id = $this->repository->insertSigner($uuid, $name, $role, $fileId, $act !== '' ? $act : null, $actor->id);
                $this->audit->log(
                    'SIGNATARIO_INSTITUCIONAL_CADASTRADO', 'signatario_institucional', $id, $uuid, $actor, 'WEB', null,
                    ['nome' => $name, 'cargo' => $role, 'ato_referencia' => $act !== '' ? $act : null, 'assinatura_arquivo_uuid' => $stored['uuid']], null
                );
                return ['id' => $id, 'uuid' => $uuid, 'nome' => $name, 'cargo' => $role];
            });
        } catch (\Throwable $e) {
            $this->storage->delete((string) $stored['storage_key']);
            throw $e;
        } finally {
            if (!empty($prepared['temporary'])) {
                @unlink((string) $prepared['path']);
            }
        }
    }

    private function text(string $value, int $max, bool $required): string
    {
        $value = trim(wp_strip_all_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if ($required && $value === '') {
            throw new InvalidArgumentException('Preencha os campos obrigatórios.');
        }
        if ($this->length($value) > $max) {
            throw new InvalidArgumentException('Um dos campos excede o tamanho máximo permitido.');
        }
        return $value;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'assinatura';
        return function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
