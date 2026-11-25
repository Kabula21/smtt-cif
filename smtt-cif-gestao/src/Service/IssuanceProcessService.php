<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessProtocol;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessStatus;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessType;
use SMTT\CIF\Gestao\Repository\IssuanceProcessRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\DataProtection;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class IssuanceProcessService
{
    private const DOCUMENT_TYPES = [
        'REQUERIMENTO' => 'Requerimento',
        'BO' => 'Boletim de ocorrência (BO)',
        'COMPROVANTE_RETIFICACAO' => 'Comprovante de retificação',
        'ATO_FUNCIONAL' => 'Ato funcional',
        'TERMO' => 'Termo / declaração',
        'OUTRO' => 'Outro documento',
    ];

    private const VALIDATION_METHODS = [
        'PRESENCIAL' => 'Conferência presencial com o titular',
        'TERMO_ASSINADO' => 'Termo de conferência assinado',
        'OUTRO_REGISTRADO' => 'Outro método formalmente registrado',
    ];

    private const PENDING_REASONS = [
        'BO_AUSENTE' => 'Boletim de ocorrência ausente',
        'DOCUMENTO_OBRIGATORIO_AUSENTE' => 'Documento obrigatório ausente',
        'DOCUMENTO_INVALIDO' => 'Documento ilegível, vencido ou incorreto',
        'DADOS_DIVERGENTES' => 'Dados cadastrais divergentes',
        'BIOMETRIA_PENDENTE' => 'Biometria pendente ou inválida',
        'OUTRO' => 'Outra pendência',
    ];

    private const DENIAL_REASONS = [
        'SOLICITACAO_SEM_AMPARO' => 'Solicitação sem amparo administrativo',
        'REQUISITOS_NAO_ATENDIDOS' => 'Requisitos obrigatórios não atendidos',
        'INCONSISTENCIA_NAO_REGULARIZADA' => 'Inconsistência não regularizada',
        'DETERMINACAO_ADMINISTRATIVA' => 'Determinação administrativa',
        'OUTRO' => 'Outro motivo',
    ];

    private const CANCELLATION_REASONS = [
        'DUPLICIDADE' => 'Processo aberto em duplicidade',
        'AGENTE_INCORRETO' => 'Processo aberto para o agente incorreto',
        'ABERTURA_INDEVIDA' => 'Abertura indevida / erro administrativo',
        'DESISTENCIA' => 'Desistência da solicitação',
        'OUTRO' => 'Outro motivo',
    ];

    public function __construct(
        private readonly IssuanceProcessRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PrivateStorage $storage,
        private readonly DataProtection $protection
    ) {
    }

    /** @return array<string,mixed> */
    public function agentForCreation(string $uuid): array
    {
        $agent = $this->repository->agentByUuid($uuid);
        if ($agent === null) {
            throw new RuntimeException('Agente não localizado.');
        }
        return $agent;
    }

    /** @return array<string,string> */
    public function typeOptions(): array
    {
        return ProcessType::options();
    }

    /** @return array<string,string> */
    public function statusOptions(): array
    {
        return ProcessStatus::options();
    }

    /** @return array<string,string> */
    public function documentTypeOptions(): array
    {
        return self::DOCUMENT_TYPES;
    }

    /** @return array<string,string> */
    public function validationMethodOptions(): array
    {
        return self::VALIDATION_METHODS;
    }

    /** @return array<string,string> */
    public function pendingReasonOptions(): array
    {
        return self::PENDING_REASONS;
    }

    /** @return array<string,string> */
    public function denialReasonOptions(): array
    {
        return self::DENIAL_REASONS;
    }

    /** @return array<string,string> */
    public function cancellationReasonOptions(): array
    {
        return self::CANCELLATION_REASONS;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(string $agentUuid, array $input, CifUser $actor): array
    {
        $agent = $this->repository->agentByUuid($agentUuid);
        if ($agent === null) {
            throw new RuntimeException('Agente não localizado.');
        }
        $data = $this->validateProcessInput($input, null);
        $occurrenceUuid = trim((string) ($input['occurrence_uuid'] ?? ''));
        $processUuid = Uuid::v4();

        $this->transactions->run(function () use ($agent, $data, $occurrenceUuid, $processUuid, $actor): void {
            $occurrence = null;
            if ($occurrenceUuid !== '') {
                $occurrence = $this->repository->lockOccurrenceForProcess($occurrenceUuid);
                if ($occurrence === null) {
                    throw new RuntimeException('A ocorrência de origem não foi localizada.');
                }
                if ((int) $occurrence['agente_id'] !== (int) $agent['id']) {
                    throw new RuntimeException('A ocorrência informada pertence a outro Agente de Trânsito.');
                }
                if (!empty($occurrence['processo_id'])) {
                    throw new RuntimeException('Esta ocorrência já está vinculada a um processo de emissão.');
                }
                $expectedType = (string) $occurrence['tipo_codigo'] === 'DESGASTE'
                    ? ProcessType::DANO
                    : (string) $occurrence['tipo_codigo'];
                if ((string) $data['tipo_codigo'] !== $expectedType) {
                    throw new RuntimeException('O tipo do processo deve corresponder à ocorrência que o originou.');
                }

                // O fato e a comunicação tornam-se derivados da ocorrência oficial, evitando
                // divergência entre o registro do extravio/dano e o processo administrativo.
                $data['fato_em'] = (string) $occurrence['data_fato'];
                $data['comunicado_em'] = (string) $occurrence['data_comunicacao'];
                // Não há carência automática de dias para nova emissão. A ocorrência
                // continua fornecendo fato/comunicação, mas a liberação depende dos
                // requisitos administrativos e da aprovação do processo.
                $data['elegivel_emissao_em'] = null;
            }

            $cycleNumber = $this->repository->nextAgentCycle((int) $agent['id']);
            $protocolSequence = $this->repository->nextProtocolSequence();
            $protocolYear = function_exists('wp_date') ? (int) wp_date('Y') : (int) gmdate('Y');
            $protocol = ProcessProtocol::build($protocolYear, $protocolSequence);

            $processId = $this->repository->insertProcess([
                'uuid' => $processUuid,
                'agente_id' => (int) $agent['id'],
                'ciclo_numero' => $cycleNumber,
                'tipo_codigo' => $data['tipo_codigo'],
                'status_codigo' => ProcessStatus::EM_ANALISE,
                'numero_protocolo' => $protocol,
                'protocolo_externo' => $data['protocolo_externo'],
                'justificativa' => $data['justificativa'],
                'fato_em' => $data['fato_em'],
                'comunicado_em' => $data['comunicado_em'],
                'elegivel_emissao_em' => $data['elegivel_emissao_em'],
                'criado_por' => $actor->id,
            ]);

            if ($occurrence !== null) {
                $this->repository->linkOccurrenceToProcess((int) $occurrence['id'], $processId);
                $copiedDocuments = $this->repository->copyOccurrenceDocumentsToProcess((int) $occurrence['id'], $processId, $actor->id);
                $this->repository->insertEvent(
                    $processId,
                    Uuid::v4(),
                    'PROCESSO_VINCULADO_A_OCORRENCIA',
                    ProcessStatus::EM_ANALISE,
                    ProcessStatus::EM_ANALISE,
                    [
                        'ocorrencia_uuid' => $occurrenceUuid,
                        'emissao_origem_uuid' => (string) $occurrence['emissao_uuid'],
                        'documentos_reutilizados' => $copiedDocuments,
                    ],
                    $actor->id
                );
            }

            $this->repository->insertEvent(
                $processId,
                Uuid::v4(),
                'PROCESSO_CRIADO',
                null,
                ProcessStatus::EM_ANALISE,
                [
                    'tipo' => $data['tipo_codigo'],
                    'agente_uuid' => (string) $agent['uuid'],
                    'numero_protocolo' => $protocol,
                    'ciclo_numero' => $cycleNumber,
                    'protocolo_externo' => $data['protocolo_externo'],
                    'ocorrencia_uuid' => $occurrenceUuid !== '' ? $occurrenceUuid : null,
                ],
                $actor->id
            );
            $this->audit->log(
                'PROCESSO_EMISSAO_CRIADO',
                'processo_emissao',
                $processId,
                $processUuid,
                $actor,
                'WEB',
                null,
                [
                    'agente_uuid' => (string) $agent['uuid'],
                    'tipo' => $data['tipo_codigo'],
                    'status' => ProcessStatus::EM_ANALISE,
                    'numero_protocolo' => $protocol,
                    'ciclo_numero' => $cycleNumber,
                    'protocolo_externo' => $data['protocolo_externo'],
                    'ocorrencia_uuid' => $occurrenceUuid !== '' ? $occurrenceUuid : null,
                ],
                $data['justificativa']
            );
        });

        return $this->details($processUuid);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $uuid, array $input, int $lockVersion, CifUser $actor): array
    {
        return $this->transactions->run(function () use ($uuid, $input, $lockVersion, $actor): array {
            $process = $this->requireLocked($uuid);
            if ((int) $process['lock_version'] !== $lockVersion) {
                throw new RuntimeException('O processo foi alterado por outro usuário. Recarregue a página antes de continuar.');
            }
            $status = (string) $process['status_codigo'];
            if (!in_array($status, [ProcessStatus::RASCUNHO, ProcessStatus::EM_ANALISE, ProcessStatus::PENDENTE_DOCUMENTACAO], true)) {
                throw new RuntimeException('Os dados básicos deste processo não podem mais ser editados no estado atual.');
            }

            $data = $this->validateProcessInput($input, $status === ProcessStatus::RASCUNHO ? null : (string) $process['tipo_codigo']);
            if (!$this->repository->updateProcess((int) $process['id'], $lockVersion, $data)) {
                throw new RuntimeException('O processo foi alterado simultaneamente. Recarregue a página e tente novamente.');
            }

            $this->repository->insertEvent(
                (int) $process['id'], Uuid::v4(), 'PROCESSO_ATUALIZADO', $status, $status,
                ['tipo' => $data['tipo_codigo'], 'protocolo_externo' => $data['protocolo_externo']], $actor->id
            );
            $this->audit->log(
                'PROCESSO_EMISSAO_ATUALIZADO', 'processo_emissao', (int) $process['id'], $uuid,
                $actor, 'WEB',
                ['tipo' => $process['tipo_codigo'], 'numero_protocolo' => $process['numero_protocolo'], 'protocolo_externo' => $process['protocolo_externo'] ?? null],
                ['tipo' => $data['tipo_codigo'], 'numero_protocolo' => $process['numero_protocolo'], 'protocolo_externo' => $data['protocolo_externo']],
                $data['justificativa']
            );
            return $this->details($uuid);
        });
    }

    public function validateBiography(string $uuid, string $method, string $observations, CifUser $actor): void
    {
        $method = strtoupper(trim($method));
        if (!array_key_exists($method, self::VALIDATION_METHODS)) {
            throw new InvalidArgumentException('Método de validação biográfica inválido.');
        }
        $observations = $this->optionalText($observations, 1000);

        $this->transactions->run(function () use ($uuid, $method, $observations, $actor): void {
            $process = $this->requireLocked($uuid);
            if (!in_array((string) $process['status_codigo'], [ProcessStatus::RASCUNHO, ProcessStatus::EM_ANALISE, ProcessStatus::PENDENTE_DOCUMENTACAO], true)) {
                throw new RuntimeException('A validação dos dados não pode ser registrada no estado atual do processo.');
            }
            $details = $this->repository->detailsByUuid($uuid);
            if ($details === null) {
                throw new RuntimeException('Processo não localizado.');
            }
            $existing = $this->repository->currentValidation((int) $process['id'], (int) $details['dados_biograficos_id']);
            if ($existing !== null) {
                throw new RuntimeException('A versão cadastral atual já possui conferência registrada neste processo.');
            }

            $validationId = $this->repository->insertValidation(
                (int) $process['agente_id'], (int) $details['dados_biograficos_id'], (int) $process['id'],
                $method, $actor->id, $observations !== '' ? $observations : null
            );
            $this->repository->insertEvent(
                (int) $process['id'], Uuid::v4(), 'DADOS_BIOGRAFICOS_VALIDADO_PELO_TITULAR',
                (string) $process['status_codigo'], (string) $process['status_codigo'],
                ['dados_biograficos_id' => (int) $details['dados_biograficos_id'], 'versao' => (int) $details['dados_versao'], 'metodo' => $method],
                $actor->id
            );
            $this->audit->log(
                'DADOS_BIOGRAFICOS_VALIDADO_PELO_TITULAR', 'validacao_biografica', $validationId, null,
                $actor, 'WEB', null,
                ['processo_uuid' => $uuid, 'agente_uuid' => (string) $details['agente_uuid'], 'versao_biografica' => (int) $details['dados_versao'], 'metodo' => $method],
                $observations !== '' ? $observations : null
            );
        });
    }

    public function transition(string $uuid, string $target, string $reason, int $lockVersion, CifUser $actor, array $approvalValidation = [], array $transitionContext = []): void
    {
        $target = strtoupper(trim($target));
        $reason = $this->optionalText($reason, 1000);
        $this->transactions->run(function () use ($uuid, $target, $reason, $lockVersion, $actor, $approvalValidation, $transitionContext): void {
            $process = $this->requireLocked($uuid);
            if ((int) $process['lock_version'] !== $lockVersion) {
                throw new RuntimeException('O processo foi alterado por outro usuário. Recarregue a página antes de continuar.');
            }
            $from = (string) $process['status_codigo'];
            ProcessStatus::assertTransition($from, $target);

            if (in_array($target, [ProcessStatus::PENDENTE_DOCUMENTACAO, ProcessStatus::INDEFERIDO, ProcessStatus::CANCELADO], true) && $this->length($reason) < 10) {
                throw new InvalidArgumentException('Informe uma justificativa com pelo menos 10 caracteres para esta decisão.');
            }
            $decisionCode = strtoupper(trim((string) ($transitionContext['reason_code'] ?? '')));
            $deadline = null;
            if ($target === ProcessStatus::PENDENTE_DOCUMENTACAO) {
                if (!array_key_exists($decisionCode, self::PENDING_REASONS)) {
                    throw new InvalidArgumentException('Selecione o tipo da pendência documental.');
                }
                $deadline = $this->optionalDate((string) ($transitionContext['deadline'] ?? ''), 'Prazo para regularização');
            } elseif ($target === ProcessStatus::INDEFERIDO) {
                if (!array_key_exists($decisionCode, self::DENIAL_REASONS)) {
                    throw new InvalidArgumentException('Selecione o motivo do indeferimento.');
                }
            } elseif ($target === ProcessStatus::CANCELADO) {
                if (!array_key_exists($decisionCode, self::CANCELLATION_REASONS)) {
                    throw new InvalidArgumentException('Selecione o motivo do cancelamento.');
                }
            }
            if ($target === ProcessStatus::APROVADO) {
                $details = $this->repository->detailsByUuid($uuid);
                if ($details === null) {
                    throw new RuntimeException('Processo não localizado.');
                }
                $existingValidation = $this->repository->currentValidation((int) $process['id'], (int) $details['dados_biograficos_id']);
                if ($existingValidation === null) {
                    if (!$actor->can(PermissionCodes::PROCESSO_VALIDAR_DADOS)) {
                        throw new RuntimeException('Os dados da emissão ainda precisam ser conferidos por um usuário autorizado antes da aprovação.');
                    }
                    $confirmed = !empty($approvalValidation['confirmed']);
                    if (!$confirmed) {
                        throw new InvalidArgumentException('Confirme a conferência dos dados da emissão para aprovar o processo.');
                    }
                    $method = strtoupper(trim((string) ($approvalValidation['method'] ?? '')));
                    if (!array_key_exists($method, self::VALIDATION_METHODS)) {
                        throw new InvalidArgumentException('Método de conferência inválido.');
                    }
                    $observations = $this->optionalText((string) ($approvalValidation['observations'] ?? ''), 1000);
                    $validationId = $this->repository->insertValidation(
                        (int) $process['agente_id'],
                        (int) $details['dados_biograficos_id'],
                        (int) $process['id'],
                        $method,
                        $actor->id,
                        $observations !== '' ? $observations : null
                    );
                    $this->repository->insertEvent(
                        (int) $process['id'], Uuid::v4(), 'DADOS_BIOGRAFICOS_VALIDADO_PELO_TITULAR',
                        $from, $from,
                        ['dados_biograficos_id' => (int) $details['dados_biograficos_id'], 'versao' => (int) $details['dados_versao'], 'metodo' => $method, 'origem' => 'APROVACAO_PROCESSO'],
                        $actor->id
                    );
                    $this->audit->log(
                        'DADOS_BIOGRAFICOS_VALIDADO_PELO_TITULAR', 'validacao_biografica', $validationId, null,
                        $actor, 'WEB', null,
                        ['processo_uuid' => $uuid, 'agente_uuid' => (string) $details['agente_uuid'], 'versao_biografica' => (int) $details['dados_versao'], 'metodo' => $method, 'origem' => 'APROVACAO_PROCESSO'],
                        $observations !== '' ? $observations : null
                    );
                }
                $this->assertApprovalRequirements($uuid, $process);
            }

            $ok = $this->repository->changeStatus(
                (int) $process['id'],
                $target,
                $lockVersion,
                $target === ProcessStatus::APROVADO ? $actor->id : null,
                $target === ProcessStatus::APROVADO,
                $target === ProcessStatus::CANCELADO ? $actor->id : null,
                $target === ProcessStatus::CANCELADO
            );
            if (!$ok) {
                throw new RuntimeException('O processo foi alterado simultaneamente. Recarregue a página e tente novamente.');
            }

            $eventType = match ($target) {
                ProcessStatus::EM_ANALISE => $from === ProcessStatus::RASCUNHO ? 'PROCESSO_INICIOU_TRAMITACAO' : 'PROCESSO_RETORNOU_TRAMITACAO',
                ProcessStatus::PENDENTE_DOCUMENTACAO => 'PROCESSO_PENDENTE_DOCUMENTACAO',
                ProcessStatus::APROVADO => 'PROCESSO_APROVADO',
                ProcessStatus::INDEFERIDO => 'PROCESSO_INDEFERIDO',
                ProcessStatus::CANCELADO => 'PROCESSO_CANCELADO',
                ProcessStatus::EM_EXECUCAO => 'PROCESSO_EM_EXECUCAO',
                ProcessStatus::CONCLUIDO => 'PROCESSO_CONCLUIDO',
                default => 'PROCESSO_STATUS_ALTERADO',
            };
            $eventData = $reason !== '' ? ['justificativa' => $reason] : [];
            $auditAfter = ['status' => $target];
            if ($decisionCode !== '') {
                $options = match ($target) {
                    ProcessStatus::PENDENTE_DOCUMENTACAO => self::PENDING_REASONS,
                    ProcessStatus::INDEFERIDO => self::DENIAL_REASONS,
                    ProcessStatus::CANCELADO => self::CANCELLATION_REASONS,
                    default => [],
                };
                if (isset($options[$decisionCode])) {
                    $eventData['motivo_codigo'] = $decisionCode;
                    $eventData['motivo_label'] = $options[$decisionCode];
                    $auditAfter['motivo_codigo'] = $decisionCode;
                }
            }
            if ($target === ProcessStatus::PENDENTE_DOCUMENTACAO && is_string($deadline) && $deadline !== '') {
                $eventData['prazo_regularizacao'] = $deadline;
                $auditAfter['prazo_regularizacao'] = $deadline;
            }
            $this->repository->insertEvent(
                (int) $process['id'], Uuid::v4(), $eventType, $from, $target,
                $eventData !== [] ? $eventData : null, $actor->id
            );
            $this->audit->log(
                $eventType, 'processo_emissao', (int) $process['id'], $uuid, $actor, 'WEB',
                ['status' => $from], $auditAfter, $reason !== '' ? $reason : null
            );
        });
    }

    /** @param array<string,mixed> $upload */
    public function addDocument(string $uuid, array $upload, string $type, string $number, string $issuedAt, CifUser $actor): void
    {
        $type = strtoupper(trim($type));
        if (!array_key_exists($type, self::DOCUMENT_TYPES)) {
            throw new InvalidArgumentException('Tipo de documento inválido.');
        }
        $number = $this->optionalText($number, 100);
        $issuedAt = $this->optionalDate($issuedAt, 'Data de emissão do documento');
        $tmp = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        $size = isset($upload['size']) ? (int) $upload['size'] : 0;
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Selecione um arquivo válido para anexar ao processo.');
        }
        if ($size < 1 || $size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('O documento deve possuir no máximo 10 MB.');
        }
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmp);
        } elseif (function_exists('mime_content_type')) {
            $mime = (string) mime_content_type($tmp);
        } else {
            throw new RuntimeException('O servidor não possui mecanismo disponível para identificar com segurança o tipo do documento.');
        }
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('Formato não permitido. Utilize PDF, JPEG ou PNG.');
        }
        if ($mime === 'application/pdf') {
            $header = @file_get_contents($tmp, false, null, 0, 5);
            if ($header !== '%PDF-') {
                throw new InvalidArgumentException('O arquivo informado como PDF não possui uma assinatura PDF válida.');
            }
        } else {
            $imageInfo = @getimagesize($tmp);
            if (!is_array($imageInfo)) {
                throw new InvalidArgumentException('A imagem anexada não pôde ser validada.');
            }
        }

        $stored = $this->storage->storeFile($tmp, $mime, 'processo_documento', $extensions[$mime]);
        try {
            $this->transactions->run(function () use ($uuid, $type, $number, $issuedAt, $actor, $upload, $stored): void {
                $process = $this->requireLocked($uuid);
                if (ProcessStatus::isTerminal((string) $process['status_codigo'])) {
                    throw new RuntimeException('Não é possível anexar documentos a um processo encerrado.');
                }
                $fileId = $this->repository->insertPrivateFile([
                    ...$stored,
                    'categoria' => 'PROCESSO_DOCUMENTO',
                    'nome_original' => $this->safeOriginalName((string) ($upload['name'] ?? 'documento')),
                ], $actor->id);
                $documentId = $this->repository->insertDocument(
                    (int) $process['id'], $fileId, $type,
                    $number !== '' ? $number : null, $issuedAt !== '' ? $issuedAt : null, $actor->id
                );
                $this->repository->insertEvent(
                    (int) $process['id'], Uuid::v4(), 'DOCUMENTO_ANEXADO',
                    (string) $process['status_codigo'], (string) $process['status_codigo'],
                    ['tipo_documento' => $type, 'arquivo_uuid' => $stored['uuid'], 'numero_documento' => $number !== '' ? $number : null],
                    $actor->id
                );
                $this->audit->log(
                    'PROCESSO_DOCUMENTO_ANEXADO', 'processo_documento', $documentId, null, $actor, 'WEB', null,
                    ['processo_uuid' => $uuid, 'tipo_documento' => $type, 'arquivo_uuid' => $stored['uuid'], 'mime_type' => $stored['mime_type']],
                    null
                );
            });
        } catch (\Throwable $e) {
            $this->storage->delete((string) $stored['storage_key']);
            throw $e;
        }
    }

    /** @return array{bytes:string,mime_type:string,size:int,name:string} */
    public function privateDocument(string $fileUuid): array
    {
        $file = $this->repository->privateDocumentByUuid($fileUuid);
        if ($file === null) {
            throw new RuntimeException('Documento privado não localizado.');
        }
        if ((int) $file['criptografado'] !== 1 || (string) $file['storage_backend'] !== 'LOCAL_ENCRYPTED') {
            throw new RuntimeException('O documento não está em um backend privado reconhecido.');
        }
        $bytes = $this->storage->read((string) $file['storage_key'], (string) $file['key_id'], (string) $file['sha256']);
        return [
            'bytes' => $bytes,
            'mime_type' => (string) $file['mime_type'],
            'size' => strlen($bytes),
            'name' => (string) ($file['nome_original'] ?: 'documento'),
        ];
    }

    /** @return array<string,mixed> */
    public function details(string $uuid, bool $includeSensitive = false): array
    {
        $process = $this->repository->detailsByUuid($uuid);
        if ($process === null) {
            throw new RuntimeException('Processo de emissão não localizado.');
        }
        $process['tipo_label'] = ProcessType::label((string) $process['tipo_codigo']);
        $process['status_label'] = ProcessStatus::label((string) $process['status_codigo']);
        $process['cpf_display'] = null;
        if ($includeSensitive && !empty($process['cpf_ciphertext']) && !empty($process['cpf_key_id'])) {
            try {
                $process['cpf_display'] = $this->formatCpf($this->protection->decryptCpf((string) $process['cpf_ciphertext'], (string) $process['cpf_key_id']));
            } catch (RuntimeException) {
                $process['cpf_display'] = 'Indisponível';
            }
        }
        unset($process['cpf_ciphertext'], $process['cpf_key_id']);
        $process['current_validation'] = $this->repository->currentValidation((int) $process['id'], (int) $process['dados_biograficos_id']);
        $process['current_biometry'] = $this->repository->currentBiometrySummary((int) $process['agente_id']);
        $process['has_valid_biometry'] = is_array($process['current_biometry']);
        $process['has_bo'] = $this->repository->hasDocumentType((int) $process['id'], 'BO');
        $process['documents'] = $this->repository->documents((int) $process['id']);
        $events = $this->repository->events((int) $process['id']);
        foreach ($events as &$event) {
            $decoded = null;
            if (is_string($event['dados'] ?? null) && $event['dados'] !== '') {
                $candidate = json_decode((string) $event['dados'], true);
                $decoded = is_array($candidate) ? $candidate : null;
            }
            $event['dados_decodificados'] = $decoded;
        }
        unset($event);
        $process['events'] = $events;
        $process['active_pending'] = null;
        if ((string) $process['status_codigo'] === ProcessStatus::PENDENTE_DOCUMENTACAO) {
            foreach ($events as $event) {
                if ((string) ($event['tipo_evento'] ?? '') === 'PROCESSO_PENDENTE_DOCUMENTACAO') {
                    $process['active_pending'] = $event;
                    break;
                }
            }
        }
        $process['approval_checks'] = $this->approvalChecks($process);
        return $process;
    }

    /** @return array<string,bool> */
    private function approvalChecks(array $process): array
    {
        $extravio = ProcessType::isExtravio((string) $process['tipo_codigo']);
        return [
            'dados_validados' => is_array($process['current_validation'] ?? null),
            'biometria_valida' => (bool) ($process['has_valid_biometry'] ?? false),
            'bo_quando_exigido' => !$extravio || (bool) ($process['has_bo'] ?? false),
            'comunicacao_extravio' => !$extravio || !empty($process['comunicado_em']),
        ];
    }

    /** @param array<string,mixed> $locked */
    private function assertApprovalRequirements(string $uuid, array $locked): void
    {
        $details = $this->details($uuid);
        $checks = $details['approval_checks'];
        if (!$checks['dados_validados']) {
            throw new RuntimeException('Antes da aprovação, confirme a conferência da versão biográfica atual.');
        }
        if (!$checks['biometria_valida']) {
            throw new RuntimeException('Antes da aprovação, o agente precisa possuir uma coleta biométrica válida.');
        }
        if (!$checks['bo_quando_exigido']) {
            throw new RuntimeException('Processos de perda, furto ou roubo exigem boletim de ocorrência anexado.');
        }
        if (!$checks['comunicacao_extravio']) {
            throw new RuntimeException('Processos de perda, furto ou roubo exigem a data da comunicação do extravio.');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validateProcessInput(array $input, ?string $lockedType): array
    {
        $type = $lockedType ?? ProcessType::normalize((string) ($input['tipo_codigo'] ?? ''));
        if ($lockedType !== null && isset($input['tipo_codigo']) && trim((string) $input['tipo_codigo']) !== '' && strtoupper(trim((string) $input['tipo_codigo'])) !== $lockedType) {
            throw new RuntimeException('O tipo do processo não pode ser alterado depois da criação.');
        }
        $externalProtocol = $this->optionalText((string) ($input['protocolo_externo'] ?? ''), 150);
        $justification = $this->optionalText((string) ($input['justificativa'] ?? ''), 4000);
        if ($type !== ProcessType::PRIMEIRA_EMISSAO && $this->length($justification) < 10) {
            throw new InvalidArgumentException('Informe uma justificativa com pelo menos 10 caracteres para este tipo de processo.');
        }

        $fact = $this->optionalLocalDateTime((string) ($input['fato_em'] ?? ''), 'Data/hora do fato');
        $communicated = $this->optionalLocalDateTime((string) ($input['comunicado_em'] ?? ''), 'Data/hora da comunicação');
        if (ProcessType::isExtravio($type)) {
            if ($fact === null || $communicated === null) {
                throw new InvalidArgumentException('Perda, furto e roubo exigem data/hora do fato e da comunicação.');
            }
            if ($communicated < $fact) {
                throw new InvalidArgumentException('A comunicação do extravio não pode ser anterior ao fato.');
            }
        }

        return [
            'tipo_codigo' => $type,
            'protocolo_externo' => $externalProtocol !== '' ? $externalProtocol : null,
            'justificativa' => $justification !== '' ? $justification : null,
            'fato_em' => $fact?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'comunicado_em' => $communicated?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'elegivel_emissao_em' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function requireLocked(string $uuid): array
    {
        $process = $this->repository->lockByUuid($uuid);
        if ($process === null) {
            throw new RuntimeException('Processo de emissão não localizado.');
        }
        return $process;
    }

    private function optionalText(string $value, int $max): string
    {
        $value = trim(wp_strip_all_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if ($this->length($value) > $max) {
            throw new InvalidArgumentException('Um dos campos de texto excede o tamanho máximo permitido.');
        }
        return $value;
    }

    private function optionalDate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' inválida.');
        }
        return $value;
    }

    private function optionalLocalDateTime(string $value, string $label): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException($label . ' inválida.');
        }
        return $date;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'documento';
        return function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
    }

    private function formatCpf(string $digits): string
    {
        return preg_match('/^\d{11}$/', $digits) === 1
            ? substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2)
            : $digits;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
