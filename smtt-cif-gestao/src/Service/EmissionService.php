<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStatus;
use SMTT\CIF\Gestao\Domain\Emission\PhysicalStatus;
use SMTT\CIF\Gestao\Domain\Emission\ValidationCode;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStateMachine;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessStatus;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessType;
use SMTT\CIF\Gestao\Domain\Occurrence\OccurrenceType;
use SMTT\CIF\Gestao\Domain\Occurrence\OccurrenceStatus;
use SMTT\CIF\Gestao\Domain\Recollection\RecollectionReason;
use SMTT\CIF\Gestao\Domain\Recollection\RecollectionStatus;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Repository\IssuanceProcessRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\DataProtection;
use SMTT\CIF\Gestao\Support\Base64Url;
use SMTT\CIF\Gestao\Support\Uuid;

final class EmissionService
{
    private const TEMPLATE_VERSION = 'CIF-V1';

    public function __construct(
        private readonly EmissionRepository $repository,
        private readonly IssuanceProcessRepository $processes,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly DataProtection $protection,
        private readonly EmissionSetupService $setup
    ) {
    }

    /** @return array<string,mixed> */
    public function generateFromProcess(string $processUuid, CifUser $actor): array
    {
        return $this->transactions->run(function () use ($processUuid, $actor): array {
            $process = $this->processes->lockByUuid($processUuid);
            if ($process === null) {
                throw new RuntimeException('Processo de emissão não localizado.');
            }

            $existing = $this->repository->emissionForProcess((int) $process['id']);
            if ($existing !== null) {
                $details = $this->repository->detailsByUuid((string) $existing['uuid']);
                if ($details === null) {
                    throw new RuntimeException('A emissão já gerada não pôde ser recuperada.');
                }
                return $details;
            }

            if ((string) $process['status_codigo'] !== ProcessStatus::APROVADO) {
                throw new RuntimeException('Somente processos aprovados podem gerar uma emissão CIF.');
            }

            $bio = $this->repository->currentBiography((int) $process['agente_id']);
            if ($bio === null) {
                throw new RuntimeException('Dados biográficos atuais do agente não foram localizados.');
            }
            if (!$this->repository->hasCurrentValidation((int) $process['id'], (int) $bio['id'])) {
                throw new RuntimeException('A versão biográfica atual ainda não foi validada pelo titular neste processo.');
            }
            $biometry = $this->repository->latestValidBiometry((int) $process['agente_id']);
            if ($biometry === null) {
                throw new RuntimeException('Não existe coleta biométrica válida e conforme para gerar a CIF.');
            }
            // O ciclo pertence ao agente e é reservado quando o novo processo é criado.
            // Aqui o lock global protege somente o sequencial final da CIF, que nunca reinicia.
            $control = $this->repository->lockNumberingControl();
            if ($control === null) {
                throw new RuntimeException('Controle do sequencial global da CIF não localizado.');
            }
            $signer = $this->repository->currentSigner();
            if ($signer === null) {
                throw new RuntimeException('Não existe signatário institucional vigente. Cadastre o signatário antes de gerar a CIF.');
            }
            $cycleNumber = (int) ($process['ciclo_numero'] ?? 0);
            if ($cycleNumber < 1 || $cycleNumber > 99) {
                throw new RuntimeException('O processo não possui um ciclo individual válido para o agente.');
            }
            $sequence = (int) $control['ultimo_sequencial'] + 1;
            if ($sequence > 9999) {
                throw new RuntimeException('O sequencial global atingiu 9999. Nova emissão bloqueada até definição normativa para continuidade da numeração.');
            }

            $type = (string) $process['tipo_codigo'];
            $pending = $this->repository->pendingEmissionForAgent((int) $process['agente_id']);
            if ($pending !== null) {
                throw new RuntimeException('O agente já possui uma emissão pendente de ativação. Conclua ou encerre essa emissão antes de gerar outra.');
            }
            $activePrevious = $this->repository->activeEmissionForAgent((int) $process['agente_id']);
            $previous = $activePrevious ?? $this->repository->latestEmissionForAgent((int) $process['agente_id']);
            if ($type === ProcessType::PRIMEIRA_EMISSAO && $previous !== null) {
                throw new RuntimeException('O agente já possui histórico de emissão. Utilize um processo de nova via/renovação/substituição em vez de primeira emissão.');
            }
            if ($type !== ProcessType::PRIMEIRA_EMISSAO && $previous === null) {
                throw new RuntimeException('Este tipo de processo exige uma emissão anterior do agente.');
            }

            $code = ValidationCode::build($cycleNumber, $sequence);
            $token = Base64Url::encode(random_bytes(32));
            $tokenHash = hash('sha256', $token, true);
            $protectedToken = $this->protection->protectQrToken($token);

            $emissionUuid = Uuid::v4();
            $generationKey = Uuid::v4();
            $snapshotArray = [
                'dados_biograficos_id' => (int) $bio['id'],
                'nome_completo' => (string) $bio['nome_completo'],
                'matricula' => (string) $bio['matricula_snapshot'],
                'naturalidade' => (string) $bio['naturalidade'],
                'data_nascimento' => (string) $bio['data_nascimento'],
                'tipo_sanguineo' => $bio['tipo_sanguineo'] !== null ? (string) $bio['tipo_sanguineo'] : null,
                'data_admissao' => $bio['data_admissao'] !== null ? (string) $bio['data_admissao'] : null,
                'cpf_ciphertext_sha256' => hash('sha256', (string) $bio['cpf_ciphertext']),
                'situacao_funcional' => $this->repository->currentFunctionalStatus((int) $process['agente_id']),
                'signatario_nome' => (string) $signer['nome'],
                'signatario_cargo' => (string) $signer['cargo'],
                'template_version' => self::TEMPLATE_VERSION,
                'processo_uuid' => $processUuid,
                'coleta_biometrica_uuid' => (string) $biometry['coleta_uuid'],
                'ciclo' => $cycleNumber,
                'sequencial' => $sequence,
                'codigo_validacao' => $code,
            ];
            $canonical = json_encode($snapshotArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $snapshotHash = hash('sha256', $canonical, true);
            $extras = json_encode([
                'processo_uuid' => $processUuid,
                'coleta_biometrica_uuid' => (string) $biometry['coleta_uuid'],
                'ciclo_numero' => $cycleNumber,
                'sequencial_sistema' => $sequence,
                'regra_codigo_version' => 2,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($previous !== null) {
                $this->repository->linkProcessOrigin((int) $process['id'], (int) $previous['id']);
            }

            $this->repository->changeProcessStatusDirect((int) $process['id'], ProcessStatus::EM_EXECUCAO);
            $this->repository->insertProcessEvent((int) $process['id'], Uuid::v4(), 'PROCESSO_EM_EXECUCAO', ProcessStatus::APROVADO, ProcessStatus::EM_EXECUCAO, null, $actor->id);

            $emissionId = $this->repository->insertEmission([
                'uuid' => $emissionUuid,
                'generation_key' => $generationKey,
                'agente_id' => (int) $process['agente_id'],
                'processo_id' => (int) $process['id'],
                'emissao_anterior_id' => $previous !== null ? (int) $previous['id'] : null,
                'tipo_emissao_codigo' => $type,
                'ciclo_id' => null,
                'ciclo_numero_agente' => $cycleNumber,
                'sequencial_sistema' => $sequence,
                'codigo_validacao' => $code,
                'qr_token_hash' => $tokenHash,
                'qr_token_ciphertext' => $protectedToken['ciphertext'],
                'qr_key_id' => $protectedToken['key_id'],
                'signatario_id' => (int) $signer['id'],
                'template_version' => self::TEMPLATE_VERSION,
                'emitida_por' => $actor->id,
            ]);
            $this->repository->insertSnapshot($emissionId, [
                'dados_biograficos_id' => (int) $bio['id'],
                'nome_completo' => (string) $bio['nome_completo'],
                'matricula' => (string) $bio['matricula_snapshot'],
                'naturalidade' => (string) $bio['naturalidade'],
                'data_nascimento' => (string) $bio['data_nascimento'],
                'cpf_ciphertext' => (string) $bio['cpf_ciphertext'],
                'cpf_key_id' => (string) $bio['cpf_key_id'],
                'tipo_sanguineo' => $bio['tipo_sanguineo'] !== null ? (string) $bio['tipo_sanguineo'] : null,
                'data_admissao' => $bio['data_admissao'] !== null ? (string) $bio['data_admissao'] : null,
                'situacao_funcional' => $snapshotArray['situacao_funcional'],
                'signatario_nome' => (string) $signer['nome'],
                'signatario_cargo' => (string) $signer['cargo'],
                'template_version' => self::TEMPLATE_VERSION,
                'dados_extras' => $extras,
                'snapshot_hash' => $snapshotHash,
            ]);
            $this->repository->linkBiometry($emissionId, (int) $biometry['foto_id'], 'FOTO');
            $this->repository->linkBiometry($emissionId, (int) $biometry['assinatura_id'], 'ASSINATURA_TITULAR');
            $this->repository->insertInitialState($emissionId, EmissionStatus::PENDENTE_ATIVACAO, PhysicalStatus::NAO_PRODUZIDA);
            $this->repository->insertEvent($emissionId, (int) $process['id'], Uuid::v4(), 'EMISSAO_GERADA', null, EmissionStatus::PENDENTE_ATIVACAO, [
                'codigo_validacao' => $code,
                'ciclo' => $cycleNumber,
                'sequencial' => $sequence,
                'template_version' => self::TEMPLATE_VERSION,
            ], $actor->id);
            $this->repository->updateLastSequence($sequence);

            $this->repository->changeProcessStatusDirect((int) $process['id'], ProcessStatus::CONCLUIDO);
            $this->repository->insertProcessEvent((int) $process['id'], Uuid::v4(), 'EMISSAO_GERADA', ProcessStatus::EM_EXECUCAO, ProcessStatus::CONCLUIDO, [
                'emissao_uuid' => $emissionUuid,
                'codigo_validacao' => $code,
            ], $actor->id);

            $this->audit->log(
                'EMISSAO_CIF_GERADA', 'emissao_cif', $emissionId, $emissionUuid, $actor, 'WEB', null,
                [
                    'processo_uuid' => $processUuid,
                    'codigo_validacao' => $code,
                    'ciclo' => $cycleNumber,
                    'sequencial' => $sequence,
                    'status_validade' => EmissionStatus::PENDENTE_ATIVACAO,
                    'token_hash_sha256' => bin2hex($tokenHash),
                ], null
            );

            $details = $this->repository->detailsByUuid($emissionUuid);
            if ($details === null) {
                throw new RuntimeException('A emissão foi criada, mas não pôde ser relida na mesma transação.');
            }
            $details['qr_token_plain'] = $token;
            return $details;
        });
    }


    /** @return array<string,string> */
    public static function cancellationReasons(): array
    {
        return [
            'ERRO_MATERIAL' => 'Erro material na emissão',
            'ERRO_CADASTRAL' => 'Erro cadastral identificado após a emissão',
            'EMISSAO_INDEVIDA' => 'Emissão indevida',
            'DETERMINACAO_ADMINISTRATIVA' => 'Determinação administrativa',
            'OUTRO' => 'Outro motivo formal',
        ];
    }

    /** @return array<string,mixed> */
    public function cancelPending(string $uuid, array $input, CifUser $actor): array
    {
        $reason = strtoupper(trim((string)($input['motivo_codigo'] ?? '')));
        if (!isset(self::cancellationReasons()[$reason])) {
            throw new \InvalidArgumentException('Selecione um motivo válido para o cancelamento da emissão.');
        }
        $justification = trim((string)($input['justificativa'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($justification, 'UTF-8') : strlen($justification);
        if ($length < 10 || $length > 3000) {
            throw new \InvalidArgumentException('Informe uma justificativa administrativa entre 10 e 3000 caracteres.');
        }

        return $this->transactions->run(function () use ($uuid, $reason, $justification, $actor): array {
            $emission = $this->repository->lockEmissionByUuid($uuid);
            if ($emission === null) {
                throw new RuntimeException('Emissão CIF não localizada.');
            }
            if ((string)$emission['status_validade'] !== EmissionStatus::PENDENTE_ATIVACAO) {
                throw new RuntimeException('Somente uma emissão ainda pendente de ativação pode ser cancelada diretamente. Para CIF ativa/suspensa utilize os fluxos de ocorrência, recolhimento ou substituição.');
            }
            $physical = (string)$emission['status_fisico'];
            if (!in_array($physical, [PhysicalStatus::NAO_PRODUZIDA, PhysicalStatus::EM_GUARDA_SMTT], true)) {
                throw new RuntimeException('A situação física atual da CIF não permite cancelamento administrativo direto.');
            }
            $newPhysical = $physical === PhysicalStatus::EM_GUARDA_SMTT
                ? PhysicalStatus::INUTILIZADA
                : PhysicalStatus::NAO_PRODUZIDA;
            EmissionStateMachine::assertValidityTransition(EmissionStatus::PENDENTE_ATIVACAO, EmissionStatus::ENCERRADA);
            if ($physical === PhysicalStatus::EM_GUARDA_SMTT) {
                EmissionStateMachine::assertPhysicalTransition($physical, $newPhysical);
            }

            $version = $this->repository->updateEmissionState(
                (int)$emission['id'],
                EmissionStatus::ENCERRADA,
                $newPhysical,
                $reason,
                false,
                false,
                true
            );
            $this->repository->insertEvent(
                (int)$emission['id'],
                (int)$emission['processo_id'],
                Uuid::v4(),
                'EMISSAO_CANCELADA',
                EmissionStatus::PENDENTE_ATIVACAO,
                EmissionStatus::ENCERRADA,
                [
                    'motivo_codigo' => $reason,
                    'motivo_label' => self::cancellationReasons()[$reason],
                    'justificativa' => $justification,
                    'status_fisico_anterior' => $physical,
                    'status_fisico_novo' => $newPhysical,
                ],
                $actor->id
            );

            $hash = $this->projectionHash($emission, $version, 'NAO_VALIDA');
            $this->repository->upsertProjectionState($uuid, $version, $hash);
            $this->repository->enqueueProjectionRefresh([
                ['emission_uuid' => $uuid, 'version' => $version, 'public_status' => 'NAO_VALIDA'],
            ]);

            $this->audit->log(
                'EMISSAO_CIF_CANCELADA',
                'emissao_cif',
                (int)$emission['id'],
                $uuid,
                $actor,
                'WEB',
                [
                    'status_validade' => EmissionStatus::PENDENTE_ATIVACAO,
                    'status_fisico' => $physical,
                ],
                [
                    'status_validade' => EmissionStatus::ENCERRADA,
                    'status_fisico' => $newPhysical,
                    'motivo_encerramento' => $reason,
                    'public_version' => $version,
                ],
                $justification
            );

            $details = $this->details($uuid);
            return $details;
        });
    }

    /** @return array<string,mixed> */
    public function details(string $uuid): array
    {
        $details = $this->repository->detailsByUuid($uuid);
        if ($details === null) {
            throw new RuntimeException('Emissão CIF não localizada.');
        }
        $details['status_validade_label'] = EmissionStatus::label((string) $details['status_validade']);
        $details['status_fisico_label'] = PhysicalStatus::label((string) $details['status_fisico']);
        $details['tipo_label'] = ProcessType::label((string) $details['tipo_emissao_codigo']);
        $details['events'] = $this->repository->events((int) $details['id']);
        $details['occurrences'] = $this->repository->occurrencesForEmission((int) $details['id']);
        foreach ($details['occurrences'] as &$occurrence) {
            $occurrence['tipo_label'] = OccurrenceType::label((string) $occurrence['tipo_codigo']);
            $occurrence['status_label'] = OccurrenceStatus::label((string) $occurrence['status']);
        }
        unset($occurrence);
        $details['recollections'] = $this->repository->recollectionsForEmission((int) $details['id']);
        foreach ($details['recollections'] as &$recollection) {
            $recollection['motivo_label'] = RecollectionReason::label((string) $recollection['motivo_codigo']);
            $recollection['status_label'] = RecollectionStatus::label((string) $recollection['status']);
        }
        unset($recollection);
        $details['delivery'] = $this->repository->deliveryForEmission((int) $details['id']);
        $details['previous_emission'] = $this->repository->previousEmissionDetails((int) $details['id']);
        $details['is_extravio'] = ProcessType::isExtravio((string) $details['tipo_emissao_codigo']);
        $details['requires_previous_collection'] = (string) $details['tipo_emissao_codigo'] !== ProcessType::PRIMEIRA_EMISSAO
            && !ProcessType::isExtravio((string) $details['tipo_emissao_codigo']);
        return $details;
    }

    /**
     * Preenche o termo exclusivamente com o snapshot da emissão selecionada.
     * Nunca retorna material criptografado ao template de impressão.
     *
     * @return array{nome:string,cpf:string,matricula:string,codigo:string,tipo:string,data_emissao:string}
     */
    public function receiptTermData(string $uuid): array
    {
        $row = $this->repository->receiptTermDataByUuid($uuid);
        if ($row === null) {
            throw new RuntimeException('Emissão CIF não localizada para emitir o termo de entrega.');
        }
        $cpf = preg_replace('/[^0-9]/', '', $this->protection->decryptCpf(
            (string) $row['cpf_ciphertext'],
            (string) $row['cpf_key_id']
        ));
        if (!is_string($cpf) || strlen($cpf) !== 11) {
            throw new RuntimeException('O CPF da emissão não está disponível para o termo.');
        }
        $emitidaEm = (string) ($row['emitida_em'] ?? '');
        $emissaoTimestamp = $emitidaEm !== '' ? strtotime($emitidaEm . ' UTC') : false;
        if ($emissaoTimestamp === false) {
            throw new RuntimeException('A data de emissão não está disponível para o termo.');
        }
        return [
            'nome' => function_exists('mb_strtoupper')
                ? mb_strtoupper((string) $row['nome_completo'], 'UTF-8')
                : strtoupper((string) $row['nome_completo']),
            'cpf' => substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2),
            'matricula' => (string) $row['matricula'],
            'codigo' => (string) $row['codigo_validacao'],
            'tipo' => ProcessType::label((string) $row['tipo_emissao_codigo']),
            'data_emissao' => wp_date('d/m/Y', $emissaoTimestamp),
        ];
    }

    public function qrToken(string $uuid): string
    {
        $details = $this->repository->detailsByUuid($uuid);
        if ($details === null) {
            throw new RuntimeException('Emissão CIF não localizada.');
        }
        return $this->protection->decryptQrToken((string) $details['qr_token_ciphertext'], (string) $details['qr_key_id']);
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


}
