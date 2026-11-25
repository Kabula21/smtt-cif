<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStateMachine;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStatus;
use SMTT\CIF\Gestao\Domain\Emission\PhysicalStatus;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessType;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class DeliveryService
{
    private const METHODS = [
        'TERMO_ASSINADO' => 'Termo de entrega assinado e conferido pelo RH',
    ];

    public function __construct(
        private readonly EmissionRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PrivateStorage $storage
    ) {
    }

    /** @return array<string,string> */
    public static function methods(): array
    {
        return self::METHODS;
    }

    /** @return array<string,mixed> */
    public function deliverAndActivate(string $emissionUuid, array $input, CifUser $actor): array
    {
        $method = strtoupper(trim((string) ($input['metodo_confirmacao'] ?? '')));
        if (!isset(self::METHODS[$method])) {
            throw new InvalidArgumentException('Selecione um meio válido de confirmação da entrega.');
        }
        $holderConfirmed = !empty($input['titular_confirmou']);
        if (!$holderConfirmed) {
            throw new InvalidArgumentException('Confirme que a CIF foi efetivamente entregue ao titular antes de concluir a operação.');
        }
        $reference = $this->cleanOptional((string) ($input['referencia'] ?? ''), 200);
        $observations = $this->cleanOptional((string) ($input['observacoes'] ?? ''), 2000);
        $previousCollected = !empty($input['anterior_recolhida']);

        return $this->transactions->run(function () use (
            $emissionUuid,
            $method,
            $holderConfirmed,
            $reference,
            $observations,
            $previousCollected,
            $actor
        ): array {
            $emission = $this->repository->lockEmissionByUuid($emissionUuid);
            if ($emission === null) {
                throw new RuntimeException('Emissão CIF não localizada.');
            }
            if ($this->repository->deliveryForEmission((int) $emission['id']) !== null) {
                throw new RuntimeException('Esta emissão já possui entrega registrada.');
            }
            if ((string) $emission['status_validade'] !== EmissionStatus::PENDENTE_ATIVACAO) {
                throw new RuntimeException('Somente uma emissão pendente de ativação pode ser entregue e ativada.');
            }
            if ((string) $emission['status_fisico'] !== PhysicalStatus::EM_GUARDA_SMTT) {
                throw new RuntimeException('A CIF deve possuir produção física concluída e estar em guarda na SMTT antes da entrega.');
            }

            // Guarda obrigatória também no servidor: nenhuma chamada HTTP pode ativar
            // uma CIF sem o termo assinado e conferido, preservado de forma privada.
            $signedTerm = $this->repository->latestSignedDeliveryTerm((int) $emission['id']);
            if ($signedTerm === null || (int) $signedTerm['assinaturas_conferidas'] !== 1) {
                throw new RuntimeException('Anexe e confira o termo assinado pelo agente e pelo RH-SMTT antes de registrar a entrega e ativar a CIF.');
            }
            if ((int) $signedTerm['criptografado'] !== 1 || (string) $signedTerm['storage_backend'] !== 'LOCAL_ENCRYPTED') {
                throw new RuntimeException('O termo de entrega não está armazenado no formato privado esperado.');
            }
            // Verifica também que o anexo físico criptografado continua íntegro.
            $this->storage->read((string) $signedTerm['storage_key'], (string) $signedTerm['key_id'], (string) $signedTerm['sha256']);

            EmissionStateMachine::assertValidityTransition((string) $emission['status_validade'], EmissionStatus::ATIVA);
            EmissionStateMachine::assertPhysicalTransition((string) $emission['status_fisico'], PhysicalStatus::ENTREGUE);

            $agentId = (int) $emission['agente_id'];
            $activeLink = $this->repository->lockActiveLinkForAgent($agentId);
            $previous = null;
            if (!empty($emission['emissao_anterior_id'])) {
                $previous = $this->repository->lockEmissionById((int) $emission['emissao_anterior_id']);
                if ($previous === null) {
                    throw new RuntimeException('A emissão anterior vinculada não pôde ser localizada.');
                }
                if ((int) $previous['agente_id'] !== $agentId) {
                    throw new RuntimeException('A emissão anterior não pertence ao mesmo agente.');
                }
            }

            $type = (string) $emission['tipo_emissao_codigo'];
            $isFirst = $type === ProcessType::PRIMEIRA_EMISSAO;
            $isExtravio = ProcessType::isExtravio($type);

            if ($isFirst) {
                if ($previous !== null || $activeLink !== null) {
                    throw new RuntimeException('Primeira emissão não pode substituir uma emissão anterior ou coexistir com uma CIF ativa.');
                }
            } else {
                if ($previous === null) {
                    throw new RuntimeException('A nova emissão não possui referência à emissão anterior que será substituída.');
                }

                if (!$isExtravio) {
                    if (!$previousCollected) {
                        throw new InvalidArgumentException('Para esta substituição, confirme o recolhimento físico da CIF anterior no ato da entrega.');
                    }
                    if ((string) $previous['status_validade'] !== EmissionStatus::ATIVA) {
                        throw new RuntimeException('A emissão anterior precisa estar ativa para ser substituída por este fluxo.');
                    }
                    if ((string) $previous['status_fisico'] !== PhysicalStatus::ENTREGUE) {
                        throw new RuntimeException('A emissão anterior deve estar marcada como entregue antes de registrar seu recolhimento na substituição.');
                    }
                    if ($activeLink === null || (int) $activeLink['emissao_id'] !== (int) $previous['id']) {
                        throw new RuntimeException('A emissão anterior não corresponde à CIF ativa atualmente vinculada ao agente.');
                    }
                } else {
                    if (!in_array((string) $previous['status_validade'], [EmissionStatus::ATIVA, EmissionStatus::SUSPENSA], true)) {
                        throw new RuntimeException('A emissão extraviada anterior já está encerrada ou não está em estado substituível.');
                    }
                    if (!in_array((string) $previous['status_fisico'], [PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA], true)) {
                        throw new RuntimeException('A situação física da emissão anterior não é compatível com um extravio.');
                    }
                }
            }

            $refreshItems = [];
            if ($previous !== null) {
                $oldValidity = (string) $previous['status_validade'];
                $oldPhysical = (string) $previous['status_fisico'];
                EmissionStateMachine::assertValidityTransition($oldValidity, EmissionStatus::ENCERRADA);

                $newOldPhysical = $oldPhysical;
                if ($isExtravio) {
                    if ($oldPhysical === PhysicalStatus::ENTREGUE) {
                        EmissionStateMachine::assertPhysicalTransition($oldPhysical, PhysicalStatus::NAO_LOCALIZADA);
                        $newOldPhysical = PhysicalStatus::NAO_LOCALIZADA;
                    }
                } else {
                    EmissionStateMachine::assertPhysicalTransition($oldPhysical, PhysicalStatus::RECOLHIDA);
                    $newOldPhysical = PhysicalStatus::RECOLHIDA;
                }

                $closureReason = $this->closureReason($type);
                $oldVersion = $this->repository->updateEmissionState(
                    (int) $previous['id'],
                    EmissionStatus::ENCERRADA,
                    $newOldPhysical,
                    $closureReason,
                    false,
                    false,
                    true
                );
                $this->repository->insertEvent(
                    (int) $previous['id'],
                    (int) $emission['processo_id'],
                    Uuid::v4(),
                    'EMISSAO_SUBSTITUIDA',
                    $oldValidity,
                    EmissionStatus::ENCERRADA,
                    [
                        'nova_emissao_uuid' => $emissionUuid,
                        'motivo_encerramento' => $closureReason,
                        'status_fisico_anterior' => $oldPhysical,
                        'status_fisico_novo' => $newOldPhysical,
                    ],
                    $actor->id
                );

                if ($isExtravio && $oldPhysical === PhysicalStatus::ENTREGUE) {
                    $this->repository->insertEvent(
                        (int) $previous['id'],
                        (int) $emission['processo_id'],
                        Uuid::v4(),
                        'CIF_NAO_LOCALIZADA_POR_EXTRAVIO',
                        PhysicalStatus::ENTREGUE,
                        PhysicalStatus::NAO_LOCALIZADA,
                        ['nova_emissao_uuid' => $emissionUuid],
                        $actor->id
                    );
                }

                if (!$isExtravio) {
                    $this->repository->insertRecollection(
                        Uuid::v4(),
                        (int) $previous['id'],
                        (int) $emission['processo_id'],
                        'SUBSTITUICAO_CIF',
                        $actor->id
                    );
                    $this->repository->insertEvent(
                        (int) $previous['id'],
                        (int) $emission['processo_id'],
                        Uuid::v4(),
                        'CIF_RECOLHIDA',
                        $oldPhysical,
                        PhysicalStatus::RECOLHIDA,
                        ['nova_emissao_uuid' => $emissionUuid],
                        $actor->id
                    );
                }

                $this->repository->removeActiveLink($agentId);
                $oldHash = $this->projectionHash($previous, $oldVersion, 'NAO_VALIDA');
                $this->repository->upsertProjectionState((string) $previous['uuid'], $oldVersion, $oldHash);
                $refreshItems[] = ['emission_uuid' => (string) $previous['uuid'], 'version' => $oldVersion, 'public_status' => 'NAO_VALIDA'];
            } elseif ($activeLink !== null) {
                throw new RuntimeException('O agente já possui outra CIF ativa, mas a nova emissão não está vinculada a ela como emissão anterior.');
            }

            $newVersion = $this->repository->updateEmissionState(
                (int) $emission['id'],
                EmissionStatus::ATIVA,
                PhysicalStatus::ENTREGUE,
                null,
                true,
                true,
                true
            );
            $this->repository->setActiveLink($agentId, (int) $emission['id']);

            $deliveryId = $this->repository->insertDelivery([
                'uuid' => Uuid::v4(),
                'emissao_id' => (int) $emission['id'],
                'emissao_anterior_id' => $previous !== null ? (int) $previous['id'] : null,
                'titular_confirmou' => 1,
                'metodo_confirmacao' => $method,
                'referencia' => $reference,
                'anterior_recolhida' => (!$isFirst && !$isExtravio && $previousCollected) ? 1 : 0,
                'observacoes' => $observations,
                'entregue_por' => $actor->id,
            ]);

            $this->repository->insertEvent(
                (int) $emission['id'],
                (int) $emission['processo_id'],
                Uuid::v4(),
                'CIF_ATIVADA',
                EmissionStatus::PENDENTE_ATIVACAO,
                EmissionStatus::ATIVA,
                ['public_version' => $newVersion],
                $actor->id
            );
            $this->repository->insertEvent(
                (int) $emission['id'],
                (int) $emission['processo_id'],
                Uuid::v4(),
                'CIF_ENTREGUE',
                PhysicalStatus::EM_GUARDA_SMTT,
                PhysicalStatus::ENTREGUE,
                [
                    'metodo_confirmacao' => $method,
                    'referencia' => $reference,
                    'termo_assinado_arquivo_uuid' => $signedTerm['arquivo_uuid'],
                    'emissao_anterior_uuid' => $previous['uuid'] ?? null,
                    'anterior_recolhida' => (!$isFirst && !$isExtravio && $previousCollected),
                ],
                $actor->id
            );

            $newHash = $this->projectionHash($emission, $newVersion, 'VALIDA');
            $this->repository->upsertProjectionState($emissionUuid, $newVersion, $newHash);
            $refreshItems[] = ['emission_uuid' => $emissionUuid, 'version' => $newVersion, 'public_status' => 'VALIDA'];
            $this->repository->enqueueProjectionRefresh($refreshItems);

            $this->audit->log(
                'CIF_ENTREGUE_E_ATIVADA',
                'emissao_cif',
                (int) $emission['id'],
                $emissionUuid,
                $actor,
                'WEB',
                [
                    'status_validade' => EmissionStatus::PENDENTE_ATIVACAO,
                    'status_fisico' => PhysicalStatus::EM_GUARDA_SMTT,
                    'emissao_anterior_uuid' => $previous['uuid'] ?? null,
                ],
                [
                    'status_validade' => EmissionStatus::ATIVA,
                    'status_fisico' => PhysicalStatus::ENTREGUE,
                    'public_version' => $newVersion,
                    'delivery_id' => $deliveryId,
                    'termo_assinado_arquivo_uuid' => $signedTerm['arquivo_uuid'],
                    'emissao_anterior_recolhida' => (!$isFirst && !$isExtravio && $previousCollected),
                ],
                $observations
            );

            $details = $this->repository->detailsByUuid($emissionUuid);
            if ($details === null) {
                throw new RuntimeException('A entrega foi registrada, mas a emissão não pôde ser relida.');
            }
            return $details;
        });
    }

    private function closureReason(string $type): string
    {
        return match ($type) {
            ProcessType::SEGUNDA_VIA => 'SEGUNDA_VIA',
            ProcessType::RENOVACAO => 'RENOVACAO',
            ProcessType::RETIFICACAO => 'RETIFICACAO',
            ProcessType::DANO => 'DANO',
            ProcessType::PERDA, ProcessType::FURTO, ProcessType::ROUBO => 'EXTRAVIO',
            ProcessType::SUBSTITUICAO => 'SUBSTITUICAO',
            default => 'SUBSTITUICAO',
        };
    }

    /** @param array<string,mixed> $emission */
    private function projectionHash(array $emission, int $version, string $publicStatus): string
    {
        $canonical = json_encode([
            'emission_uuid' => (string) $emission['uuid'],
            'code' => (string) $emission['codigo_validacao'],
            'token_hash' => bin2hex((string) $emission['qr_token_hash']),
            'name' => (string) $emission['nome_completo'],
            'registration' => (string) $emission['matricula_snapshot'],
            'admission_date' => (string)($emission['data_admissao'] ?? ''),
            'issued_at' => (string) $emission['emitida_em'],
            'public_status' => $publicStatus,
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $canonical, true);
    }

    private function cleanOptional(string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max) {
            throw new InvalidArgumentException('Campo excede o tamanho permitido.');
        }
        return $value;
    }
}
