<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStateMachine;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStatus;
use SMTT\CIF\Gestao\Domain\Emission\PhysicalStatus;
use SMTT\CIF\Gestao\Domain\Occurrence\OccurrenceStatus;
use SMTT\CIF\Gestao\Domain\Occurrence\OccurrenceType;
use SMTT\CIF\Gestao\Domain\Recollection\RecollectionReason;
use SMTT\CIF\Gestao\Domain\Recollection\RecollectionStatus;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Repository\OccurrenceRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class OccurrenceService
{
    public function __construct(
        private readonly OccurrenceRepository $repository,
        private readonly EmissionRepository $emissions,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PrivateStorage $storage
    ) {
    }

    /** @return array<string,string> */
    public function occurrenceTypes(): array { return OccurrenceType::options(); }

    /** @return array<string,string> */
    public function occurrenceStatuses(): array
    {
        return [
            OccurrenceStatus::ABERTA => OccurrenceStatus::label(OccurrenceStatus::ABERTA),
            OccurrenceStatus::REGISTRADA => OccurrenceStatus::label(OccurrenceStatus::REGISTRADA),
            OccurrenceStatus::RECUPERADA_REATIVADA => OccurrenceStatus::label(OccurrenceStatus::RECUPERADA_REATIVADA),
            OccurrenceStatus::RECUPERADA_APOS_SUBSTITUICAO => OccurrenceStatus::label(OccurrenceStatus::RECUPERADA_APOS_SUBSTITUICAO),
        ];
    }

    /** @return array<string,string> */
    public function recollectionReasons(): array { return RecollectionReason::administrativeOptions(); }

    /** @return array<string,string> */
    public function recollectionStatuses(): array
    {
        return [
            RecollectionStatus::PENDENTE => RecollectionStatus::label(RecollectionStatus::PENDENTE),
            RecollectionStatus::RECOLHIDA => RecollectionStatus::label(RecollectionStatus::RECOLHIDA),
            RecollectionStatus::NAO_RECOLHIDA_JUSTIFICADA => RecollectionStatus::label(RecollectionStatus::NAO_RECOLHIDA_JUSTIFICADA),
            RecollectionStatus::INUTILIZADA => RecollectionStatus::label(RecollectionStatus::INUTILIZADA),
        ];
    }

    /** @param array<string,string> $filters @return array{items:list<array<string,mixed>>,total:int} */
    public function occurrences(array $filters, int $page = 1, int $perPage = 25): array
    {
        $result = $this->repository->searchOccurrences($filters, $page, $perPage);
        foreach ($result['items'] as &$row) {
            $row['tipo_label'] = OccurrenceType::label((string)$row['tipo_codigo']);
            $row['status_label'] = OccurrenceStatus::label((string)$row['status']);
        }
        unset($row);
        return $result;
    }

    /** @return array<string,mixed> */
    public function occurrence(string $uuid): array
    {
        $row = $this->repository->occurrenceByUuid($uuid);
        if ($row === null) {
            throw new RuntimeException('Ocorrência não localizada.');
        }
        $row['tipo_label'] = OccurrenceType::label((string)$row['tipo_codigo']);
        $row['status_label'] = OccurrenceStatus::label((string)$row['status']);
        $row['documents'] = $this->repository->documents((int)$row['id']);
        return $row;
    }

    /** @param array<string,string> $filters @return array{items:list<array<string,mixed>>,total:int} */
    public function recollections(array $filters, int $page = 1, int $perPage = 25): array
    {
        $result = $this->repository->searchRecollections($filters, $page, $perPage);
        foreach ($result['items'] as &$row) {
            $row['motivo_label'] = RecollectionReason::label((string)$row['motivo_codigo']);
            $row['status_label'] = RecollectionStatus::label((string)$row['status']);
        }
        unset($row);
        return $result;
    }

    /** @return array<string,mixed> */
    public function recollection(string $uuid): array
    {
        $row = $this->repository->recollectionByUuid($uuid);
        if ($row === null) {
            throw new RuntimeException('Recolhimento não localizado.');
        }
        $row['motivo_label'] = RecollectionReason::label((string)$row['motivo_codigo']);
        $row['status_label'] = RecollectionStatus::label((string)$row['status']);
        return $row;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $upload */
    public function registerOccurrence(string $emissionUuid, array $input, array $upload, CifUser $actor): array
    {
        $type = OccurrenceType::normalize((string)($input['tipo_codigo'] ?? ''));
        $fact = $this->requiredLocalDateTime((string)($input['data_fato'] ?? ''), 'Data/hora do fato');
        $communication = $this->requiredLocalDateTime((string)($input['data_comunicacao'] ?? ''), 'Data/hora da comunicação');
        if ($communication < $fact) {
            throw new InvalidArgumentException('A comunicação não pode ser anterior ao fato.');
        }
        if ($communication > new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('A data/hora da comunicação não pode estar no futuro.');
        }
        $details = $this->optionalText((string)($input['detalhes'] ?? ''), 3000);
        $boNumber = $this->optionalText((string)($input['bo_numero'] ?? ''), 100);
        $boAgency = $this->optionalText((string)($input['bo_orgao'] ?? ''), 150);
        $boDate = $this->optionalDate((string)($input['bo_data'] ?? ''), 'Data do BO');

        $isExtravio = OccurrenceType::isExtravio($type);
        if ($isExtravio && ($boNumber === '' || $boAgency === '' || $boDate === '')) {
            throw new InvalidArgumentException('Perda, furto e roubo exigem número, data e órgão do boletim de ocorrência.');
        }

        $stored = null;
        if ($this->hasUpload($upload)) {
            $stored = $this->prepareAndStoreDocument($upload);
        } elseif ($isExtravio) {
            throw new InvalidArgumentException('Anexe o boletim de ocorrência em PDF, JPEG ou PNG.');
        }

        try {
            return $this->transactions->run(function () use (
                $emissionUuid, $type, $fact, $communication, $details, $boNumber, $boAgency, $boDate,
                $isExtravio, $stored, $upload, $actor
            ): array {
                $emission = $this->emissions->lockEmissionByUuid($emissionUuid);
                if ($emission === null) {
                    throw new RuntimeException('Emissão CIF não localizada.');
                }
                if ((string)$emission['status_validade'] !== EmissionStatus::ATIVA || (string)$emission['status_fisico'] !== PhysicalStatus::ENTREGUE) {
                    throw new RuntimeException('Ocorrências pós-entrega só podem ser registradas sobre uma CIF atualmente ATIVA / ENTREGUE.');
                }

                if ($isExtravio && $this->repository->hasOpenExtravio((int)$emission['id'])) {
                    throw new RuntimeException('Esta emissão já possui uma ocorrência de extravio aberta.');
                }

                $occurrenceUuid = Uuid::v4();
                $status = $isExtravio ? OccurrenceStatus::ABERTA : OccurrenceStatus::REGISTRADA;
                $occurrenceId = $this->repository->insertOccurrence([
                    'uuid' => $occurrenceUuid,
                    'agente_id' => (int)$emission['agente_id'],
                    'emissao_id' => (int)$emission['id'],
                    'processo_id' => null,
                    'tipo_codigo' => $type,
                    'status' => $status,
                    'data_fato' => $fact->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'data_comunicacao' => $communication->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'bo_numero' => $boNumber !== '' ? $boNumber : null,
                    'bo_data' => $boDate !== '' ? $boDate : null,
                    'bo_orgao' => $boAgency !== '' ? $boAgency : null,
                    'motivo_encerramento' => $isExtravio ? null : 'REGISTRO_CONCLUIDO',
                    'encerrada_em' => $isExtravio ? null : gmdate('Y-m-d H:i:s'),
                    'detalhes' => $details !== '' ? $details : null,
                    'registrado_por' => $actor->id,
                ]);

                if ($stored !== null) {
                    $fileId = $this->repository->insertPrivateFile([
                        ...$stored,
                        'categoria' => 'OCORRENCIA_DOCUMENTO',
                        'nome_original' => $this->safeOriginalName((string)($upload['name'] ?? 'documento')),
                    ], $actor->id);
                    $this->repository->insertOccurrenceDocument($occurrenceId, $fileId, $isExtravio ? 'BO' : 'COMPROVANTE', $actor->id);
                }

                if ($isExtravio) {
                    EmissionStateMachine::assertValidityTransition(EmissionStatus::ATIVA, EmissionStatus::SUSPENSA);
                    EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA);
                    $active = $this->emissions->lockActiveLinkForAgent((int)$emission['agente_id']);
                    if ($active === null || (int)$active['emissao_id'] !== (int)$emission['id']) {
                        throw new RuntimeException('A emissão não corresponde à CIF ativa vinculada ao agente.');
                    }
                    $version = $this->emissions->suspendEmissionForExtravio((int)$emission['id'], $type);
                    $this->emissions->removeActiveLink((int)$emission['agente_id']);

                    $this->emissions->insertEvent(
                        (int)$emission['id'], null, Uuid::v4(), $type . '_COMUNICADO',
                        EmissionStatus::ATIVA, EmissionStatus::SUSPENSA,
                        ['ocorrencia_uuid' => $occurrenceUuid, 'bo_numero' => $boNumber, 'data_comunicacao' => $communication->format(DATE_ATOM)],
                        $actor->id
                    );
                    $this->emissions->insertEvent(
                        (int)$emission['id'], null, Uuid::v4(), 'CIF_NAO_LOCALIZADA_POR_EXTRAVIO',
                        PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA,
                        ['ocorrencia_uuid' => $occurrenceUuid], $actor->id
                    );

                    $hash = $this->projectionHash($emission, $version, 'NAO_VALIDA');
                    $this->emissions->upsertProjectionState((string)$emission['uuid'], $version, $hash);
                    $this->emissions->enqueueProjectionRefresh([['emission_uuid' => (string)$emission['uuid'], 'version' => $version, 'public_status' => 'NAO_VALIDA']]);
                } else {
                    $this->emissions->insertEvent(
                        (int)$emission['id'], null, Uuid::v4(), 'OCORRENCIA_' . $type . '_REGISTRADA',
                        (string)$emission['status_validade'], (string)$emission['status_validade'],
                        ['ocorrencia_uuid' => $occurrenceUuid], $actor->id
                    );
                }

                $this->audit->log(
                    'OCORRENCIA_CIF_REGISTRADA', 'ocorrencia_cif', $occurrenceId, $occurrenceUuid,
                    $actor, 'WEB', null,
                    ['emissao_uuid' => $emissionUuid, 'tipo' => $type, 'status' => $status, 'suspendeu_cif' => $isExtravio],
                    $details !== '' ? $details : null
                );

                $result = $this->repository->occurrenceByUuid($occurrenceUuid);
                if ($result === null) {
                    throw new RuntimeException('A ocorrência foi registrada, mas não pôde ser relida.');
                }
                return $result;
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->storage->delete((string)$stored['storage_key']);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function recover(string $occurrenceUuid, CifUser $actor): array
    {
        return $this->transactions->run(function () use ($occurrenceUuid, $actor): array {
            $occurrence = $this->repository->lockOccurrenceByUuid($occurrenceUuid);
            if ($occurrence === null) {
                throw new RuntimeException('Ocorrência não localizada.');
            }
            if (!OccurrenceType::isExtravio((string)$occurrence['tipo_codigo']) || (string)$occurrence['status'] !== OccurrenceStatus::ABERTA) {
                throw new RuntimeException('Somente uma ocorrência de perda, furto ou roubo ainda aberta pode ser encerrada por recuperação.');
            }
            $emission = $this->emissions->lockEmissionById((int)$occurrence['emissao_id']);
            if ($emission === null) {
                throw new RuntimeException('Emissão vinculada à ocorrência não localizada.');
            }

            if ((string)$emission['status_validade'] === EmissionStatus::SUSPENSA) {
                EmissionStateMachine::assertValidityTransition(EmissionStatus::SUSPENSA, EmissionStatus::ATIVA);
                EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::NAO_LOCALIZADA, PhysicalStatus::ENTREGUE);
                $active = $this->emissions->lockActiveLinkForAgent((int)$emission['agente_id']);
                if ($active !== null && (int)$active['emissao_id'] !== (int)$emission['id']) {
                    throw new RuntimeException('O agente já possui outra CIF ativa; esta emissão não pode ser reativada.');
                }
                $version = $this->emissions->reactivateRecoveredEmission((int)$emission['id']);
                $this->emissions->setActiveLink((int)$emission['agente_id'], (int)$emission['id']);
                $this->repository->markRecovered((int)$occurrence['id'], OccurrenceStatus::RECUPERADA_REATIVADA, 'RECUPERADA_ANTES_SUBSTITUICAO');
                $this->emissions->insertEvent(
                    (int)$emission['id'], null, Uuid::v4(), 'CIF_RECUPERADA_E_REATIVADA',
                    EmissionStatus::SUSPENSA, EmissionStatus::ATIVA,
                    ['ocorrencia_uuid' => $occurrenceUuid], $actor->id
                );
                $hash = $this->projectionHash($emission, $version, 'VALIDA');
                $this->emissions->upsertProjectionState((string)$emission['uuid'], $version, $hash);
                $this->emissions->enqueueProjectionRefresh([['emission_uuid' => (string)$emission['uuid'], 'version' => $version, 'public_status' => 'VALIDA']]);
                $outcome = 'REATIVADA';
            } elseif ((string)$emission['status_validade'] === EmissionStatus::ENCERRADA) {
                if ((string)$emission['status_fisico'] !== PhysicalStatus::NAO_LOCALIZADA) {
                    throw new RuntimeException('A emissão encerrada não está marcada como não localizada.');
                }
                EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::NAO_LOCALIZADA, PhysicalStatus::RECOLHIDA);
                $this->emissions->updatePhysicalStateOnly((int)$emission['id'], PhysicalStatus::NAO_LOCALIZADA, PhysicalStatus::RECOLHIDA);
                $recollectionId = $this->repository->insertRecollection([
                    'uuid' => Uuid::v4(),
                    'emissao_id' => (int)$emission['id'],
                    'processo_id' => null,
                    'motivo_codigo' => RecollectionReason::RECUPERACAO_APOS_SUBSTITUICAO,
                    'fato_em' => gmdate('Y-m-d H:i:s'),
                    'recolhimento_solicitado_em' => gmdate('Y-m-d H:i:s'),
                    'recolhimento_devido_em' => gmdate('Y-m-d H:i:s'),
                    'recolhida_em' => gmdate('Y-m-d H:i:s'),
                    'inutilizacao_permitida_em' => gmdate('Y-m-d H:i:s'),
                    'status' => RecollectionStatus::RECOLHIDA,
                    'referencia' => null,
                    'observacoes' => 'Documento recuperado após a emissão de nova CIF.',
                    'registrado_por' => $actor->id,
                    'recolhida_por' => $actor->id,
                ]);
                $this->repository->markRecovered((int)$occurrence['id'], OccurrenceStatus::RECUPERADA_APOS_SUBSTITUICAO, 'RECUPERADA_APOS_SUBSTITUICAO');
                $this->emissions->insertEvent(
                    (int)$emission['id'], null, Uuid::v4(), 'CIF_RECUPERADA_APOS_SUBSTITUICAO',
                    PhysicalStatus::NAO_LOCALIZADA, PhysicalStatus::RECOLHIDA,
                    ['ocorrencia_uuid' => $occurrenceUuid, 'recolhimento_id' => $recollectionId], $actor->id
                );
                $outcome = 'RECOLHIDA_PARA_INUTILIZACAO';
            } else {
                throw new RuntimeException('A situação atual da emissão não permite registrar recuperação por este fluxo.');
            }

            $this->audit->log(
                'OCORRENCIA_CIF_RECUPERADA', 'ocorrencia_cif', (int)$occurrence['id'], $occurrenceUuid,
                $actor, 'WEB', ['status' => OccurrenceStatus::ABERTA], ['resultado' => $outcome], null
            );
            return $this->occurrence($occurrenceUuid);
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function registerAdministrativeRecollection(string $emissionUuid, array $input, CifUser $actor): array
    {
        $reason = RecollectionReason::normalizeAdministrative((string)($input['motivo_codigo'] ?? ''));
        $fact = $this->requiredLocalDateTime((string)($input['fato_em'] ?? ''), 'Data/hora do fato administrativo');
        $reference = $this->optionalText((string)($input['referencia'] ?? ''), 200);
        $observations = $this->optionalText((string)($input['observacoes'] ?? ''), 2000);
        $collectedNow = !empty($input['recolhida_agora']);
        if ($reason !== RecollectionReason::FALECIMENTO && !$collectedNow) {
            throw new InvalidArgumentException('Para este motivo, o recolhimento físico deve ser confirmado nesta operação.');
        }

        return $this->transactions->run(function () use ($emissionUuid, $reason, $fact, $reference, $observations, $collectedNow, $actor): array {
            $emission = $this->emissions->lockEmissionByUuid($emissionUuid);
            if ($emission === null) {
                throw new RuntimeException('Emissão CIF não localizada.');
            }
            if (!in_array((string)$emission['status_validade'], [EmissionStatus::ATIVA, EmissionStatus::SUSPENSA], true)) {
                throw new RuntimeException('Somente uma CIF ativa ou suspensa pode iniciar este recolhimento administrativo.');
            }
            if ($this->repository->hasOpenRecollection((int)$emission['id'])) {
                throw new RuntimeException('A emissão já possui recolhimento pendente ou aguardando inutilização.');
            }

            $oldValidity = (string)$emission['status_validade'];
            $oldPhysical = (string)$emission['status_fisico'];
            $newPhysical = $oldPhysical;
            if ($collectedNow) {
                if (!in_array($oldPhysical, [PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA], true)) {
                    throw new RuntimeException('A situação física atual não permite registrar recolhimento.');
                }
                EmissionStateMachine::assertPhysicalTransition($oldPhysical, PhysicalStatus::RECOLHIDA);
                $newPhysical = PhysicalStatus::RECOLHIDA;
            } elseif ($reason !== RecollectionReason::FALECIMENTO) {
                throw new RuntimeException('O recolhimento precisa ser confirmado.');
            }

            EmissionStateMachine::assertValidityTransition($oldValidity, EmissionStatus::ENCERRADA);
            $version = $this->emissions->updateEmissionState(
                (int)$emission['id'], EmissionStatus::ENCERRADA, $newPhysical, $reason,
                false, false, true
            );
            $this->emissions->removeActiveLink((int)$emission['agente_id']);

            $factUtc = $fact->setTimezone(new DateTimeZone('UTC'));
            $requestedAt = gmdate('Y-m-d H:i:s');
            $dueAt = $requestedAt;
            $invalidAllowedAt = null;
            $status = $collectedNow ? RecollectionStatus::RECOLHIDA : RecollectionStatus::PENDENTE;
            if ($reason === RecollectionReason::FALECIMENTO) {
                $dueAt = $factUtc->add(new DateInterval('P60D'))->format('Y-m-d H:i:s');
                if ($collectedNow) {
                    $invalidAllowedAt = gmdate('Y-m-d H:i:s');
                }
            } elseif (in_array($reason, [RecollectionReason::EXONERACAO, RecollectionReason::VACANCIA], true)) {
                $invalidAllowedAt = $factUtc->add(new DateInterval('P2Y'))->format('Y-m-d H:i:s');
            } else {
                $invalidAllowedAt = gmdate('Y-m-d H:i:s');
            }

            $recollectionUuid = Uuid::v4();
            $recollectionId = $this->repository->insertRecollection([
                'uuid' => $recollectionUuid,
                'emissao_id' => (int)$emission['id'],
                'processo_id' => null,
                'motivo_codigo' => $reason,
                'fato_em' => $factUtc->format('Y-m-d H:i:s'),
                'recolhimento_solicitado_em' => $requestedAt,
                'recolhimento_devido_em' => $dueAt,
                'recolhida_em' => $collectedNow ? $requestedAt : null,
                'inutilizacao_permitida_em' => $invalidAllowedAt,
                'status' => $status,
                'referencia' => $reference !== '' ? $reference : null,
                'observacoes' => $observations !== '' ? $observations : null,
                'registrado_por' => $actor->id,
                'recolhida_por' => $collectedNow ? $actor->id : null,
            ]);

            $this->emissions->insertEvent(
                (int)$emission['id'], null, Uuid::v4(), 'CIF_ENCERRADA_POR_' . $reason,
                $oldValidity, EmissionStatus::ENCERRADA,
                ['recolhimento_uuid' => $recollectionUuid, 'status_fisico_anterior' => $oldPhysical, 'status_fisico_novo' => $newPhysical],
                $actor->id
            );
            if ($collectedNow && $oldPhysical !== $newPhysical) {
                $this->emissions->insertEvent(
                    (int)$emission['id'], null, Uuid::v4(), 'CIF_RECOLHIDA', $oldPhysical, $newPhysical,
                    ['recolhimento_uuid' => $recollectionUuid, 'motivo' => $reason], $actor->id
                );
            }

            $hash = $this->projectionHash($emission, $version, 'NAO_VALIDA');
            $this->emissions->upsertProjectionState((string)$emission['uuid'], $version, $hash);
            $this->emissions->enqueueProjectionRefresh([['emission_uuid' => (string)$emission['uuid'], 'version' => $version, 'public_status' => 'NAO_VALIDA']]);

            $this->audit->log(
                'RECOLHIMENTO_CIF_INICIADO', 'recolhimento_cif', $recollectionId, $recollectionUuid,
                $actor, 'WEB', null,
                ['emissao_uuid' => $emissionUuid, 'motivo' => $reason, 'status' => $status, 'recolhida_agora' => $collectedNow],
                $observations !== '' ? $observations : null
            );
            return $this->recollection($recollectionUuid);
        });
    }

    /** @param array<string,mixed> $input */
    public function collectPending(string $uuid, array $input, CifUser $actor): array
    {
        $reference = $this->optionalText((string)($input['referencia'] ?? ''), 200);
        $observations = $this->optionalText((string)($input['observacoes'] ?? ''), 2000);
        return $this->transactions->run(function () use ($uuid, $reference, $observations, $actor): array {
            $recollection = $this->repository->lockRecollectionByUuid($uuid);
            if ($recollection === null) {
                throw new RuntimeException('Recolhimento não localizado.');
            }
            if ((string)$recollection['status'] !== RecollectionStatus::PENDENTE) {
                throw new RuntimeException('Este recolhimento não está pendente.');
            }
            $emission = $this->emissions->lockEmissionById((int)$recollection['emissao_id']);
            if ($emission === null || (string)$emission['status_validade'] !== EmissionStatus::ENCERRADA) {
                throw new RuntimeException('A emissão vinculada não está encerrada como esperado.');
            }
            $fromPhysical = (string)$emission['status_fisico'];
            if (!in_array($fromPhysical, [PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA], true)) {
                throw new RuntimeException('A situação física atual não permite confirmar o recolhimento.');
            }
            EmissionStateMachine::assertPhysicalTransition($fromPhysical, PhysicalStatus::RECOLHIDA);
            $this->emissions->updatePhysicalStateOnly((int)$emission['id'], $fromPhysical, PhysicalStatus::RECOLHIDA);
            $allowedAt = gmdate('Y-m-d H:i:s');
            $this->repository->markCollected((int)$recollection['id'], $actor->id, $reference !== '' ? $reference : null, $observations !== '' ? $observations : null, $allowedAt);
            $this->emissions->insertEvent(
                (int)$emission['id'], null, Uuid::v4(), 'CIF_RECOLHIDA', $fromPhysical, PhysicalStatus::RECOLHIDA,
                ['recolhimento_uuid' => $uuid], $actor->id
            );
            $this->audit->log(
                'RECOLHIMENTO_CIF_CONFIRMADO', 'recolhimento_cif', (int)$recollection['id'], $uuid,
                $actor, 'WEB', ['status' => RecollectionStatus::PENDENTE], ['status' => RecollectionStatus::RECOLHIDA],
                $observations !== '' ? $observations : null
            );
            return $this->recollection($uuid);
        });
    }

    public function justifyNotCollected(string $uuid, string $reason, CifUser $actor): array
    {
        $reason = $this->optionalText($reason, 2000);
        if ($this->length($reason) < 10) {
            throw new InvalidArgumentException('Informe uma justificativa com pelo menos 10 caracteres.');
        }
        return $this->transactions->run(function () use ($uuid, $reason, $actor): array {
            $recollection = $this->repository->lockRecollectionByUuid($uuid);
            if ($recollection === null) {
                throw new RuntimeException('Recolhimento não localizado.');
            }
            if ((string)$recollection['status'] !== RecollectionStatus::PENDENTE || (string)$recollection['motivo_codigo'] !== RecollectionReason::FALECIMENTO) {
                throw new RuntimeException('A justificativa de não recolhimento está disponível somente para falecimento com recolhimento pendente.');
            }
            $due = new DateTimeImmutable((string)$recollection['recolhimento_devido_em'], new DateTimeZone('UTC'));
            if (new DateTimeImmutable('now', new DateTimeZone('UTC')) < $due) {
                throw new RuntimeException('O prazo de 60 dias para tentativa de devolução ainda não terminou.');
            }
            $emission = $this->emissions->lockEmissionById((int)$recollection['emissao_id']);
            if ($emission === null) {
                throw new RuntimeException('Emissão vinculada não localizada.');
            }
            $fromPhysical = (string)$emission['status_fisico'];
            if ($fromPhysical === PhysicalStatus::ENTREGUE) {
                EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA);
                $this->emissions->updatePhysicalStateOnly((int)$emission['id'], PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA);
                $this->emissions->insertEvent(
                    (int)$emission['id'], null, Uuid::v4(), 'CIF_NAO_RECOLHIDA_POR_FALECIMENTO',
                    PhysicalStatus::ENTREGUE, PhysicalStatus::NAO_LOCALIZADA, ['recolhimento_uuid' => $uuid], $actor->id
                );
            }
            $this->repository->markNotCollected((int)$recollection['id'], $actor->id, $reason);
            $this->audit->log(
                'RECOLHIMENTO_CIF_NAO_REALIZADO_JUSTIFICADO', 'recolhimento_cif', (int)$recollection['id'], $uuid,
                $actor, 'WEB', ['status' => RecollectionStatus::PENDENTE], ['status' => RecollectionStatus::NAO_RECOLHIDA_JUSTIFICADA], $reason
            );
            return $this->recollection($uuid);
        });
    }

    public function invalidate(string $uuid, string $observations, CifUser $actor): array
    {
        $observations = $this->optionalText($observations, 2000);
        return $this->transactions->run(function () use ($uuid, $observations, $actor): array {
            $recollection = $this->repository->lockRecollectionByUuid($uuid);
            if ($recollection === null) {
                throw new RuntimeException('Recolhimento não localizado.');
            }
            if ((string)$recollection['status'] !== RecollectionStatus::RECOLHIDA) {
                throw new RuntimeException('Somente uma CIF fisicamente recolhida pode ser inutilizada.');
            }
            if (empty($recollection['inutilizacao_permitida_em'])) {
                throw new RuntimeException('Ainda não existe data autorizada para inutilização desta CIF.');
            }
            $allowed = new DateTimeImmutable((string)$recollection['inutilizacao_permitida_em'], new DateTimeZone('UTC'));
            if (new DateTimeImmutable('now', new DateTimeZone('UTC')) < $allowed) {
                throw new RuntimeException('A inutilização ainda não é permitida. Aguarde a data registrada no recolhimento.');
            }
            $emission = $this->emissions->lockEmissionById((int)$recollection['emissao_id']);
            if ($emission === null || (string)$emission['status_validade'] !== EmissionStatus::ENCERRADA || (string)$emission['status_fisico'] !== PhysicalStatus::RECOLHIDA) {
                throw new RuntimeException('A emissão não está em ENCERRADA / RECOLHIDA.');
            }
            EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::RECOLHIDA, PhysicalStatus::INUTILIZADA);
            $this->emissions->updatePhysicalStateOnly((int)$emission['id'], PhysicalStatus::RECOLHIDA, PhysicalStatus::INUTILIZADA);
            $this->repository->markInvalidated((int)$recollection['id'], $actor->id, $observations !== '' ? $observations : null);
            $this->emissions->insertEvent(
                (int)$emission['id'], null, Uuid::v4(), 'CIF_INUTILIZADA', PhysicalStatus::RECOLHIDA, PhysicalStatus::INUTILIZADA,
                ['recolhimento_uuid' => $uuid], $actor->id
            );
            $this->audit->log(
                'CIF_INUTILIZADA', 'recolhimento_cif', (int)$recollection['id'], $uuid,
                $actor, 'WEB', ['status' => RecollectionStatus::RECOLHIDA], ['status' => RecollectionStatus::INUTILIZADA],
                $observations !== '' ? $observations : null
            );
            return $this->recollection($uuid);
        });
    }

    /** @return array{bytes:string,mime_type:string,size:int,name:string} */
    public function privateDocument(string $fileUuid): array
    {
        $file = $this->repository->privateFileByUuid($fileUuid);
        if ($file === null) {
            throw new RuntimeException('Documento da ocorrência não localizado.');
        }
        if ((int)$file['criptografado'] !== 1 || (string)$file['storage_backend'] !== 'LOCAL_ENCRYPTED') {
            throw new RuntimeException('O documento da ocorrência não está em um backend privado reconhecido.');
        }
        $bytes = $this->storage->read((string)$file['storage_key'], (string)$file['key_id'], (string)$file['sha256']);
        return [
            'bytes' => $bytes,
            'mime_type' => (string)$file['mime_type'],
            'size' => strlen($bytes),
            'name' => (string)($file['nome_original'] ?: 'documento'),
        ];
    }

    /** @param array<string,mixed> $upload @return array<string,mixed> */
    private function prepareAndStoreDocument(array $upload): array
    {
        $tmp = isset($upload['tmp_name']) ? (string)$upload['tmp_name'] : '';
        $size = isset($upload['size']) ? (int)$upload['size'] : 0;
        $error = isset($upload['error']) ? (int)$upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Selecione um documento válido para a ocorrência.');
        }
        if ($size < 1 || $size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('O documento deve possuir no máximo 10 MB.');
        }
        if (class_exists('finfo')) {
            $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        } elseif (function_exists('mime_content_type')) {
            $mime = (string)mime_content_type($tmp);
        } else {
            throw new RuntimeException('O servidor não consegue identificar com segurança o tipo do documento.');
        }
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('Formato não permitido. Utilize PDF, JPEG ou PNG.');
        }
        if ($mime === 'application/pdf') {
            $header = @file_get_contents($tmp, false, null, 0, 5);
            if ($header !== '%PDF-') {
                throw new InvalidArgumentException('O arquivo informado como PDF não possui assinatura PDF válida.');
            }
        } elseif (!is_array(@getimagesize($tmp))) {
            throw new InvalidArgumentException('A imagem anexada não pôde ser validada.');
        }
        return $this->storage->storeFile($tmp, $mime, 'ocorrencia_documento', $extensions[$mime]);
    }

    /** @param array<string,mixed> $upload */
    private function hasUpload(array $upload): bool
    {
        return isset($upload['error']) && (int)$upload['error'] !== UPLOAD_ERR_NO_FILE;
    }

    private function requiredLocalDateTime(string $value, string $label): DateTimeImmutable
    {
        $value = trim($value);
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException($label . ' inválida.');
        }
        return $date;
    }

    private function optionalDate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' inválida.');
        }
        return $value;
    }

    private function optionalText(string $value, int $max): string
    {
        $value = trim(wp_strip_all_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if ($this->length($value) > $max) {
            throw new InvalidArgumentException('Um dos campos de texto excede o tamanho permitido.');
        }
        return $value;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'documento';
        return function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
    }

    /** @param array<string,mixed> $emission */
    private function projectionHash(array $emission, int $version, string $publicStatus): string
    {
        $canonical = json_encode([
            'emission_uuid' => (string)$emission['uuid'],
            'code' => (string)$emission['codigo_validacao'],
            'token_hash' => bin2hex((string)$emission['qr_token_hash']),
            'name' => (string)$emission['nome_completo'],
            'registration' => (string)$emission['matricula_snapshot'],
            'admission_date' => (string)($emission['data_admissao'] ?? ''),
            'issued_at' => (string)$emission['emitida_em'],
            'public_status' => $publicStatus,
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $canonical, true);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
