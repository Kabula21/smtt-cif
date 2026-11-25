<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use JsonException;
use RuntimeException;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Service\SyncConfigService;

final class HeartbeatService
{
    public function __construct(
        private readonly SyncRepository $repository,
        private readonly SyncConfigService $config,
        private readonly SignedHttpClient $http
    ) {
    }

    /** @return array<string,mixed> */
    public function send(): array
    {
        if (!$this->config->enabled()) {
            throw new RuntimeException('A sincronização está desabilitada.');
        }
        $stats = $this->repository->queueStats();
        $oldestAge = null;
        if (is_string($stats['oldest_open'] ?? null) && $stats['oldest_open'] !== '') {
            $ts = strtotime((string)$stats['oldest_open'] . ' UTC');
            if ($ts !== false) {
                $oldestAge = max(0, time() - $ts);
            }
        }
        $body = json_encode([
            'protocol_version' => MessageSigner::PROTOCOL_VERSION,
            'event_type' => 'HEARTBEAT',
            'source_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'plugin_version' => SMTT_CIF_GESTAO_VERSION,
            'pending_events' => (int)$stats['pending'] + (int)$stats['retrying'] + (int)$stats['processing'] + (int)$stats['attention'],
            'attention_required' => (int)$stats['attention'],
            'oldest_pending_age_seconds' => $oldestAge,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->http->post($this->config->endpoint('heartbeat'), $body, $this->config->environment());
        $httpOk = (int)($response['http_status'] ?? 0) === 200;
        if (!$httpOk) {
            $this->repository->recordHeartbeatAttempt(false);

            if ($response['http_status'] === null) {
                $errorType = trim((string)($response['error_type'] ?? ''));
                $errorSummary = trim((string)($response['error_summary'] ?? ''));

                $details = [];
                if ($errorType !== '') {
                    $details[] = $errorType;
                }
                if ($errorSummary !== '') {
                    $details[] = $errorSummary;
                }

                $suffix = $details !== [] ? ': ' . implode(' — ', $details) : '';
                throw new RuntimeException('Heartbeat não confirmado pelo portal (falha de transporte' . $suffix . ').');
            }

            $httpStatus = (int)$response['http_status'];
            $portalCode = '';
            try {
                $errorBody = json_decode((string)($response['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
                if (is_array($errorBody) && isset($errorBody['code']) && is_scalar($errorBody['code'])) {
                    $portalCode = trim((string)$errorBody['code']);
                }
            } catch (JsonException) {
                // A resposta HTTP já é suficiente para o diagnóstico.
            }

            $suffix = $portalCode !== '' ? ' — ' . $portalCode : '';
            throw new RuntimeException('Heartbeat não confirmado pelo portal (HTTP ' . $httpStatus . $suffix . ').');
        }
        try {
            $decoded = json_decode((string)$response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->repository->recordHeartbeatAttempt(false);
            throw new RuntimeException('O portal respondeu ao heartbeat com JSON inválido.', 0, $e);
        }
        if (!is_array($decoded) || strtoupper((string)($decoded['result'] ?? '')) !== 'OK') {
            $this->repository->recordHeartbeatAttempt(false);
            throw new RuntimeException('O portal respondeu ao heartbeat sem a confirmação protocolar esperada.');
        }
        $this->repository->recordHeartbeatAttempt(true);
        $this->repository->updateRemoteInfo(
            isset($decoded['plugin_version']) ? (string)$decoded['plugin_version'] : null,
            isset($decoded['schema_version']) ? (string)$decoded['schema_version'] : null
        );
        return ['ok' => true, 'http_status' => 200, 'request_id' => $response['request_id']];
    }
}
