<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStatus;
use SMTT\CIF\Gestao\Domain\Emission\PhysicalStatus;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Storage\PrivateStorage;

/**
 * Comprovante digitalizado de entrega: privado, auditável e vinculado a uma emissão.
 * Novas digitalizações não apagam as anteriores; antes da entrega vale a mais recente.
 */
final class SignedDeliveryTermService
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        private readonly EmissionRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PrivateStorage $storage
    ) {
    }

    /** @param array<string,mixed> $upload */
    public function attach(string $emissionUuid, array $upload, bool $signaturesVerified, CifUser $actor): void
    {
        if (!$signaturesVerified) {
            throw new InvalidArgumentException('Confirme que o agente e o responsável RH-SMTT assinaram o termo antes de anexar.');
        }
        if (!is_string($upload['tmp_name'] ?? null) || !is_scalar($upload['error'] ?? null) ||
            !is_scalar($upload['size'] ?? null)) {
            throw new InvalidArgumentException('Envie apenas um arquivo de termo assinado por vez.');
        }
        $tmp = $upload['tmp_name'];
        $error = (int) $upload['error'];
        $size = (int) $upload['size'];
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Selecione o termo assinado digitalizado para anexação.');
        }
        if ($size < 1 || $size > self::MAX_BYTES || !is_file($tmp) || filesize($tmp) !== $size) {
            throw new InvalidArgumentException('O termo assinado deve possuir no máximo 10 MB.');
        }
        if (!class_exists('finfo')) {
            throw new RuntimeException('O servidor precisa da extensão fileinfo para validar documentos.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Formato não permitido. Anexe o termo assinado em PDF, JPG ou PNG.');
        }
        if ($mime === 'application/pdf') {
            if (@file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
                throw new InvalidArgumentException('O arquivo PDF não possui assinatura válida.');
            }
        } elseif (!is_array(@getimagesize($tmp))) {
            throw new InvalidArgumentException('A imagem digitalizada do termo é inválida.');
        }

        // Fazemos a leitura prévia da emissão antes de criptografar o arquivo para evitar
        // gravações privadas desnecessárias (a condição é reconferida sob lock depois).
        $emission = $this->repository->detailsByUuid($emissionUuid);
        if ($emission === null) {
            throw new RuntimeException('Emissão CIF não localizada.');
        }
        $this->assertAcceptsTerm($emission);

        $stored = $this->storage->storeFile($tmp, $mime, 'termo_entrega_assinado', self::MIME_EXTENSIONS[$mime]);
        try {
            $this->transactions->run(function () use ($emissionUuid, $actor, $upload, $stored): void {
                $emission = $this->repository->lockEmissionByUuid($emissionUuid);
                if ($emission === null) {
                    throw new RuntimeException('Emissão CIF não localizada.');
                }
                $this->assertAcceptsTerm($emission);
                $previous = $this->repository->latestSignedDeliveryTerm((int) $emission['id']);
                $original = sanitize_file_name(is_string($upload['name'] ?? null) ? $upload['name'] : 'termo-assinado.pdf');
                $fileId = $this->repository->insertSignedTermPrivateFile([
                    ...$stored,
                    'nome_original' => $original !== '' ? $original : 'termo-assinado.' . self::MIME_EXTENSIONS[$stored['mime_type']],
                ], $actor->id);
                $termId = $this->repository->insertSignedDeliveryTerm((int) $emission['id'], $fileId, $actor->id);
                $this->repository->insertEvent(
                    (int) $emission['id'], (int) $emission['processo_id'],
                    \SMTT\CIF\Gestao\Support\Uuid::v4(), 'TERMO_ENTREGA_ASSINADO_ANEXADO',
                    (string) $emission['status_validade'], (string) $emission['status_validade'],
                    ['arquivo_uuid' => $stored['uuid'], 'anterior_arquivo_uuid' => $previous['arquivo_uuid'] ?? null, 'assinaturas_conferidas' => true],
                    $actor->id
                );
                $this->audit->log(
                    'TERMO_ENTREGA_ASSINADO_ANEXADO', 'emissao_termo_entrega', $termId, $emissionUuid,
                    $actor, 'WEB', null,
                    ['arquivo_uuid' => $stored['uuid'], 'assinaturas_conferidas' => true, 'mime_type' => $stored['mime_type'], 'substituiu' => $previous['arquivo_uuid'] ?? null],
                    null
                );
            });
        } catch (\Throwable $e) {
            $this->storage->delete((string) $stored['storage_key']);
            throw $e;
        }
    }

    /** @return array{bytes:string,mime_type:string,size:int,name:string} */
    public function read(string $emissionUuid, string $fileUuid): array
    {
        $emission = $this->repository->detailsByUuid($emissionUuid);
        if ($emission === null) {
            throw new RuntimeException('Emissão CIF não localizada.');
        }
        $file = $this->repository->signedDeliveryTermByFileUuid((int) $emission['id'], $fileUuid);
        if ($file === null || (int) $file['criptografado'] !== 1 || (string) $file['storage_backend'] !== 'LOCAL_ENCRYPTED') {
            throw new RuntimeException('Termo de entrega assinado não localizado.');
        }
        $bytes = $this->storage->read((string) $file['storage_key'], (string) $file['key_id'], (string) $file['sha256']);
        return [
            'bytes' => $bytes,
            'mime_type' => (string) $file['mime_type'],
            'size' => strlen($bytes),
            'name' => (string) ($file['nome_original'] ?: 'termo-entrega-assinado.pdf'),
        ];
    }

    /** @param array<string,mixed> $emission */
    private function assertAcceptsTerm(array $emission): void
    {
        if ((string) $emission['status_validade'] !== EmissionStatus::PENDENTE_ATIVACAO ||
            (string) $emission['status_fisico'] !== PhysicalStatus::EM_GUARDA_SMTT) {
            throw new RuntimeException('O termo assinado só pode ser anexado após a produção física e antes da entrega/ativação da CIF.');
        }
        if ($this->repository->deliveryForEmission((int) $emission['id']) !== null) {
            throw new RuntimeException('Não é permitido alterar o termo de uma CIF já entregue.');
        }
    }
}
