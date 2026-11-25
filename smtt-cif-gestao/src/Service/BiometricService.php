<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Biometry\ImageInspector;
use SMTT\CIF\Gestao\Biometry\ImageNormalizer;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Repository\BiometricRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class BiometricService
{
    public function __construct(
        private readonly BiometricRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly ImageInspector $inspector,
        private readonly ImageNormalizer $normalizer,
        private readonly PrivateStorage $storage
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{total:int,items:list<array<string,mixed>>}
     */
    public function catalog(array $filters, int $page, int $perPage): array
    {
        return $this->repository->searchAgents($filters, $page, $perPage);
    }

    /** @return array{agent:array<string,mixed>,collections:list<array<string,mixed>>,current:?array<string,mixed>} */
    public function overview(string $agentUuid): array
    {
        $agent = $this->repository->agentIdentityByUuid($agentUuid);
        if ($agent === null) {
            throw new RuntimeException('Agente não localizado.');
        }
        $collections = $this->repository->listCollections((int) $agent['id']);
        $current = null;
        foreach ($collections as $collection) {
            if ((string) $collection['status'] === 'VALIDA') {
                $current = $collection;
                break;
            }
        }
        return ['agent' => $agent, 'collections' => $collections, 'current' => $current];
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $photoUpload
     * @param array<string,mixed> $signatureUpload
     * @return array<string,mixed>
     */
    public function capture(
        string $agentUuid,
        array $form,
        array $photoUpload,
        array $signatureUpload,
        CifUser $actor
    ): array {
        $agent = $this->repository->agentIdentityByUuid($agentUuid);
        if ($agent === null) {
            throw new RuntimeException('Agente não localizado.');
        }

        $location = $this->cleanText((string) ($form['local_coleta'] ?? ''), 150);
        $notes = $this->cleanText((string) ($form['observacoes'] ?? ''), 2000);
        $photoChecks = [
            'fundo_branco' => $this->checked($form, 'foto_fundo_branco'),
            'padrao_icao' => $this->checked($form, 'foto_padrao_icao'),
            'apresentacao_pessoal' => $this->checked($form, 'foto_apresentacao_pessoal'),
            'camisa_caqui' => $this->checked($form, 'foto_camisa_caqui'),
        ];
        foreach ($photoChecks as $label => $checked) {
            if (!$checked) {
                throw new InvalidArgumentException('Confirme todos os requisitos normativos da fotografia antes de concluir a coleta.');
            }
        }
        if (!$this->checked($form, 'assinatura_coleta_eletronica')) {
            throw new InvalidArgumentException('Confirme que a assinatura foi obtida por captura eletrônica.');
        }

        $photoOriginalInfo = $this->inspector->inspectUpload($photoUpload);
        $signatureOriginalInfo = $this->inspector->inspectUpload($signatureUpload);
        $photoPrepared = $this->normalizer->prepare((string) $photoUpload['tmp_name'], $photoOriginalInfo, 'photo');
        $signaturePrepared = $this->normalizer->prepare((string) $signatureUpload['tmp_name'], $signatureOriginalInfo, 'signature');
        $photoInfo = $this->inspector->inspectUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $photoPrepared['path']]);
        $signatureInfo = $this->inspector->inspectUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $signaturePrepared['path']]);
        $photoDpi = $this->metadataDpi($photoInfo);
        $signatureDpi = $this->metadataDpi($signatureInfo);

        $photoStored = null;
        $signatureStored = null;
        try {
            $photoStored = $this->storage->storeImage((string) $photoPrepared['path'], $photoInfo['mime_type'], 'foto');
            $signatureStored = $this->storage->storeImage((string) $signaturePrepared['path'], $signatureInfo['mime_type'], 'assinatura_agente');

            $collection = $this->transactions->run(function () use (
                $agent,
                $agentUuid,
                $actor,
                $location,
                $notes,
                $photoUpload,
                $signatureUpload,
                $photoStored,
                $signatureStored,
                $photoInfo,
                $signatureInfo,
                $photoOriginalInfo,
                $signatureOriginalInfo,
                $photoPrepared,
                $signaturePrepared,
                $photoDpi,
                $signatureDpi,
                $photoChecks
            ): array {
                $photoFileId = $this->repository->insertPrivateFile([
                    ...$photoStored,
                    'categoria' => 'FOTO',
                    'nome_original' => $this->safeOriginalName((string) ($photoUpload['name'] ?? 'foto')),
                ], $actor->id);
                $signatureFileId = $this->repository->insertPrivateFile([
                    ...$signatureStored,
                    'categoria' => 'ASSINATURA_AGENTE',
                    'nome_original' => $this->safeOriginalName((string) ($signatureUpload['name'] ?? 'assinatura')),
                ], $actor->id);

                $collectionUuid = Uuid::v4();
                $collectionId = $this->repository->insertCollection(
                    $collectionUuid,
                    (int) $agent['id'],
                    $actor->id,
                    $location !== '' ? $location : null,
                    $notes !== '' ? $notes : null
                );

                $this->repository->insertBiometric($collectionId, 'FOTO', $photoFileId, [
                    'dpi' => $photoDpi['dpi'],
                    'dpi_origin' => $photoDpi['origin'],
                    'width' => $photoInfo['width'],
                    'height' => $photoInfo['height'],
                    'conform' => true,
                    'validation' => [
                        'quality_basis' => 'PIXEL_DIMENSIONS',
                        'minimum_short_side_px' => 360,
                        'minimum_long_side_px' => 480,
                        'metadata_dpi_x' => $photoInfo['dpi_x'],
                        'metadata_dpi_y' => $photoInfo['dpi_y'],
                        'metadata_source' => $photoInfo['dpi_source'],
                        'optimized' => (bool) $photoPrepared['optimized'],
                        'original_width_px' => (int) $photoOriginalInfo['width'],
                        'original_height_px' => (int) $photoOriginalInfo['height'],
                        'checks' => $photoChecks,
                    ],
                    'notes' => null,
                ]);
                $this->repository->insertBiometric($collectionId, 'ASSINATURA', $signatureFileId, [
                    'dpi' => $signatureDpi['dpi'],
                    'dpi_origin' => $signatureDpi['origin'],
                    'width' => $signatureInfo['width'],
                    'height' => $signatureInfo['height'],
                    'conform' => true,
                    'validation' => [
                        'quality_basis' => 'PIXEL_DIMENSIONS',
                        'minimum_width_px' => 300,
                        'minimum_height_px' => 60,
                        'metadata_dpi_x' => $signatureInfo['dpi_x'],
                        'metadata_dpi_y' => $signatureInfo['dpi_y'],
                        'metadata_source' => $signatureInfo['dpi_source'],
                        'optimized' => (bool) $signaturePrepared['optimized'],
                        'original_width_px' => (int) $signatureOriginalInfo['width'],
                        'original_height_px' => (int) $signatureOriginalInfo['height'],
                        'checks' => ['captura_eletronica' => true],
                    ],
                    'notes' => null,
                ]);

                $this->audit->log(
                    'BIOMETRIA_COLETADA',
                    'coleta_biometrica',
                    $collectionId,
                    $collectionUuid,
                    $actor,
                    'WEB',
                    null,
                    [
                        'agente_uuid' => $agentUuid,
                        'foto' => [
                            'arquivo_uuid' => $photoStored['uuid'],
                            'mime_type' => $photoInfo['mime_type'],
                            'largura_px' => $photoInfo['width'],
                            'altura_px' => $photoInfo['height'],
                            'dpi' => $photoDpi['dpi'],
                            'dpi_origem' => $photoDpi['origin'],
                        ],
                        'assinatura' => [
                            'arquivo_uuid' => $signatureStored['uuid'],
                            'mime_type' => $signatureInfo['mime_type'],
                            'largura_px' => $signatureInfo['width'],
                            'altura_px' => $signatureInfo['height'],
                            'dpi' => $signatureDpi['dpi'],
                            'dpi_origem' => $signatureDpi['origin'],
                        ],
                        'local_coleta' => $location !== '' ? $location : null,
                    ]
                );

                return ['id' => $collectionId, 'uuid' => $collectionUuid];
            });
        } catch (\Throwable $e) {
            if (is_array($photoStored)) {
                $this->storage->delete((string) $photoStored['storage_key']);
            }
            if (is_array($signatureStored)) {
                $this->storage->delete((string) $signatureStored['storage_key']);
            }
            throw $e;
        } finally {
            if (!empty($photoPrepared['temporary'])) {
                @unlink((string) $photoPrepared['path']);
            }
            if (!empty($signaturePrepared['temporary'])) {
                @unlink((string) $signaturePrepared['path']);
            }
        }

        return $collection;
    }

    public function invalidate(string $agentUuid, string $collectionUuid, string $reason, CifUser $actor): void
    {
        $reason = $this->cleanText($reason, 500);
        if ($this->length($reason) < 10) {
            throw new InvalidArgumentException('Informe uma justificativa de invalidação com pelo menos 10 caracteres.');
        }
        $agent = $this->repository->agentIdentityByUuid($agentUuid);
        if ($agent === null) {
            throw new RuntimeException('Agente não localizado.');
        }

        $this->transactions->run(function () use ($agent, $agentUuid, $collectionUuid, $reason, $actor): void {
            $collection = $this->repository->lockCollection($collectionUuid, (int) $agent['id']);
            if ($collection === null) {
                throw new RuntimeException('Coleta biométrica não localizada.');
            }
            if ((string) $collection['status'] !== 'VALIDA') {
                throw new RuntimeException('Essa coleta biométrica já está invalidada.');
            }
            if ($this->repository->collectionUsedInEmission((int) $collection['id'])) {
                throw new RuntimeException('A coleta já foi utilizada por uma emissão CIF e não pode ser invalidada. O vínculo histórico deve permanecer íntegro.');
            }

            $this->repository->invalidateCollection((int) $collection['id'], $actor->id, $reason);
            $this->audit->log(
                'BIOMETRIA_INVALIDADA',
                'coleta_biometrica',
                (int) $collection['id'],
                $collectionUuid,
                $actor,
                'WEB',
                ['status' => 'VALIDA', 'agente_uuid' => $agentUuid],
                ['status' => 'INVALIDADA', 'agente_uuid' => $agentUuid],
                $reason
            );
        });
    }

    /** @return array{bytes:string,mime_type:string,size:int} */
    public function privateFile(string $fileUuid): array
    {
        $file = $this->repository->privateBiometricFileByUuid($fileUuid);
        if ($file === null) {
            throw new RuntimeException('Arquivo biométrico não localizado.');
        }
        if ((int) $file['criptografado'] !== 1 || (string) $file['storage_backend'] !== 'LOCAL_ENCRYPTED') {
            throw new RuntimeException('O arquivo biométrico não está em um backend privado reconhecido.');
        }
        $bytes = $this->storage->read(
            (string) $file['storage_key'],
            (string) $file['key_id'],
            (string) $file['sha256']
        );
        return ['bytes' => $bytes, 'mime_type' => (string) $file['mime_type'], 'size' => strlen($bytes)];
    }

    /** @param array<string,mixed> $imageInfo @return array{dpi:int,origin:string} */
    private function metadataDpi(array $imageInfo): array
    {
        $x = $imageInfo['dpi_x'] ?? null;
        $y = $imageInfo['dpi_y'] ?? null;
        if (is_float($x) && is_float($y) && $x > 0 && $y > 0) {
            return [
                'dpi' => min((int) round(min($x, $y)), 65535),
                'origin' => (string) ($imageInfo['dpi_source'] ?: 'METADADO'),
            ];
        }

        return ['dpi' => 0, 'origin' => 'NAO_INFORMADO'];
    }

    /** @param array<string,mixed> $form */
    private function checked(array $form, string $key): bool
    {
        return isset($form[$key]) && in_array((string) $form[$key], ['1', 'on', 'yes'], true);
    }

    private function cleanText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($this->length($value) > $max) {
            throw new InvalidArgumentException('Um dos campos de texto excede o tamanho máximo permitido.');
        }
        return $value;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'arquivo';
        return function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

