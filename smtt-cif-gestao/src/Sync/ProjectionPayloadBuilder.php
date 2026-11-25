<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use JsonException;
use RuntimeException;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Security\DataProtection;

final class ProjectionPayloadBuilder
{
    public function __construct(
        private readonly EmissionRepository $emissions,
        private readonly SyncRepository $sync,
        private readonly DataProtection $protection
    ) {
    }

    /** @param array<string,mixed> $outbox @return array{body:string,items:list<array{emission_id:string,record_version:int,projection_hash:string}>} */
    public function build(array $outbox): array
    {
        $payload = $this->decodePayload((string)$outbox['payload']);
        if (($payload['kind'] ?? null) !== 'CIF_PUBLIC_PROJECTION_REFRESH' || !is_array($payload['items'] ?? null)) {
            throw new RuntimeException('Evento da outbox possui payload não suportado pelo protocolo v1.');
        }

        $materialized = !empty($payload['materialized']);
        $refs = [];
        foreach ($payload['items'] as $item) {
            if (!is_array($item) || empty($item['emission_uuid'])) {
                throw new RuntimeException('Evento de sincronização contém item inválido.');
            }
            $source = $this->emissions->publicProjectionSourceByUuid((string)$item['emission_uuid']);
            if ($source === null) {
                throw new RuntimeException('A emissão referenciada pela outbox não existe mais no banco oficial.');
            }
            $version = (int)($item['version'] ?? 0);
            $status = isset($item['public_status']) ? (string)$item['public_status'] : '';
            if (!$materialized || $status === '' || $version < 1) {
                // Compatibilidade com eventos criados nas versões 0.8/0.9, antes de o worker existir.
                // Como o portal ainda não era consumido, congelamos o estado público atual no primeiro processamento.
                $version = (int)$source['public_version'];
                $status = (string)$source['status_validade'] === 'ATIVA' ? 'VALIDA' : 'NAO_VALIDA';
            }
            if (!in_array($status, ['VALIDA', 'NAO_VALIDA'], true)) {
                throw new RuntimeException('Status público inválido no evento de sincronização.');
            }
            $refs[] = [
                'emission_uuid' => (string)$source['uuid'],
                'version' => $version,
                'public_status' => $status,
            ];
        }

        if (!$materialized) {
            $payload['materialized'] = true;
            $payload['items'] = $refs;
            $aggregateVersion = max(array_map(static fn(array $i): int => $i['version'], $refs));
            $frozen = $this->sync->freezeOutboxPayload((int)$outbox['id'], $payload, $aggregateVersion);
            $outbox['payload'] = $frozen['payload'];
        }

        $wireItems = [];
        $ackItems = [];
        foreach ($refs as $ref) {
            $source = $this->emissions->publicProjectionSourceByUuid($ref['emission_uuid']);
            if ($source === null) {
                throw new RuntimeException('A emissão referenciada pela outbox não foi localizada.');
            }
            $token = $this->protection->decryptQrToken((string)$source['qr_token_ciphertext'], (string)$source['qr_key_id']);
            $projectionHash = $this->projectionHash($source, $ref['version'], $ref['public_status']);
            $wireItems[] = [
                'emission_id' => (string)$source['uuid'],
                'record_version' => $ref['version'],
                'token' => $token,
                'code' => (string)$source['codigo_validacao'],
                'name' => (string)$source['nome_completo'],
                'registration' => (string)$source['matricula_snapshot'],
                'admission_date' => (string)($source['data_admissao'] ?? ''),
                'issued_at' => $this->isoUtc((string)$source['emitida_em']),
                'public_status' => $ref['public_status'],
                'source_updated_at' => $this->isoUtc((string)$outbox['criado_em']),
                'projection_hash' => $projectionHash,
            ];
            $ackItems[] = [
                'emission_id' => (string)$source['uuid'],
                'record_version' => $ref['version'],
                'projection_hash' => $projectionHash,
            ];
            if (function_exists('sodium_memzero')) {
                sodium_memzero($token);
            }
        }

        $body = json_encode([
            'protocol_version' => MessageSigner::PROTOCOL_VERSION,
            'event_id' => (string)$outbox['event_uuid'],
            'event_type' => 'CIF_PROJECTION_BATCH',
            'event_created_at' => $this->isoUtc((string)$outbox['criado_em']),
            'payload_version' => (int)$outbox['payload_version'],
            'items' => $wireItems,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'items' => $ackItems];
    }

    /** @param array<string,mixed> $source */
    private function projectionHash(array $source, int $version, string $publicStatus): string
    {
        $canonical = json_encode([
            'emission_uuid' => (string)$source['uuid'],
            'code' => (string)$source['codigo_validacao'],
            'token_hash' => bin2hex((string)$source['qr_token_hash']),
            'name' => (string)$source['nome_completo'],
            'registration' => (string)$source['matricula_snapshot'],
            'admission_date' => (string)($source['data_admissao'] ?? ''),
            'issued_at' => (string)$source['emitida_em'],
            'public_status' => $publicStatus,
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $canonical);
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Payload JSON da outbox está corrompido.', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Payload da outbox inválido.');
        }
        return $decoded;
    }

    private function isoUtc(string $databaseDate): string
    {
        $ts = strtotime($databaseDate . ' UTC');
        return $ts === false ? gmdate('Y-m-d\TH:i:s\Z') : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
