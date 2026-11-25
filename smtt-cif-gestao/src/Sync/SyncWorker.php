<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use JsonException;
use RuntimeException;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Service\SyncConfigService;

final class SyncWorker
{
    public function __construct(
        private readonly SyncRepository $repository,
        private readonly SyncConfigService $config,
        private readonly ProjectionPayloadBuilder $payloads,
        private readonly SignedHttpClient $http,
        private readonly RetryPolicy $retry,
        private readonly HeartbeatService $heartbeat
    ) {
    }

    /** @return array{processed:int,delivered:int,retry:int,attention:int,heartbeat:?array<string,mixed>} */
    public function run(int $limit = 20, bool $forceHeartbeat = false): array
    {
        $limit = min(max($limit, 1), 200);
        if (!$this->config->enabled()) {
            throw new RuntimeException('A sincronização está desabilitada. Configure e habilite o portal público antes de executar o worker.');
        }
        $workerId = $this->workerId();
        $summary = ['processed' => 0, 'delivered' => 0, 'retry' => 0, 'attention' => 0, 'heartbeat' => null];

        for ($i = 0; $i < $limit; ++$i) {
            $event = $this->repository->claimNext($workerId);
            if ($event === null) {
                break;
            }
            ++$summary['processed'];
            $attempt = $this->repository->nextAttemptNumber((int)$event['id']);
            try {
                $built = $this->payloads->build($event);
                $response = $this->http->post(
                    $this->config->endpoint('events'),
                    $built['body'],
                    $this->config->environment()
                );
                $result = $this->classify($event, $response);
                $this->repository->insertAttempt([
                    'outbox_id' => (int)$event['id'],
                    'numero_tentativa' => $attempt,
                    'request_id' => $response['request_id'],
                    'request_time' => $this->databaseTime($response['request_time']),
                    'key_id' => $response['key_id'],
                    'http_status' => $response['http_status'],
                    'resultado' => $result['result'],
                    'duracao_ms' => $response['duration_ms'],
                    'response_hash' => $response['body'] !== '' ? hash('sha256', $response['body'], true) : null,
                    'erro_tipo' => $result['error_code'],
                    'erro_resumo' => $result['summary'],
                ]);

                if ($result['action'] === 'DELIVERED') {
                    $this->repository->markDelivered((int)$event['id'], $built['items'], $attempt);
                    ++$summary['delivered'];
                    continue;
                }
                if ($result['action'] === 'RETRY' && $attempt < RetryPolicy::MAX_AUTOMATIC_ATTEMPTS) {
                    $this->repository->markRetry(
                        (int)$event['id'], $attempt, $this->retry->delaySeconds($attempt),
                        $result['error_code'] ?? 'TEMPORARY_FAILURE', $result['summary'] ?? 'Falha temporária de sincronização.'
                    );
                    ++$summary['retry'];
                    continue;
                }
                $this->repository->markAttention(
                    (int)$event['id'], $attempt,
                    $result['error_code'] ?? 'SYNC_FAILURE', $result['summary'] ?? 'Evento requer intervenção técnica.'
                );
                ++$summary['attention'];
            } catch (\Throwable $e) {
                $requestId = \SMTT\CIF\Gestao\Support\Uuid::v4();
                $key = $this->repository->activeSigningKey();
                $this->repository->insertAttempt([
                    'outbox_id' => (int)$event['id'],
                    'numero_tentativa' => $attempt,
                    'request_id' => $requestId,
                    'request_time' => gmdate('Y-m-d H:i:s'),
                    'key_id' => is_array($key) ? (string)$key['key_id'] : 'UNAVAILABLE',
                    'http_status' => null,
                    'resultado' => 'ERROR',
                    'duracao_ms' => null,
                    'response_hash' => null,
                    'erro_tipo' => 'WORKER_EXCEPTION',
                    'erro_resumo' => $this->truncate($e->getMessage()),
                ]);
                if ($attempt < RetryPolicy::MAX_AUTOMATIC_ATTEMPTS) {
                    $this->repository->markRetry((int)$event['id'], $attempt, $this->retry->delaySeconds($attempt), 'WORKER_EXCEPTION', $this->truncate($e->getMessage()));
                    ++$summary['retry'];
                } else {
                    $this->repository->markAttention((int)$event['id'], $attempt, 'WORKER_EXCEPTION', $this->truncate($e->getMessage()));
                    ++$summary['attention'];
                }
            }
        }

        if ($forceHeartbeat || $this->repository->heartbeatDue($this->config->heartbeatInterval())) {
            try {
                $summary['heartbeat'] = $this->heartbeat->send();
            } catch (\Throwable $e) {
                $summary['heartbeat'] = ['ok' => false, 'message' => $this->truncate($e->getMessage())];
            }
        }

        return $summary;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $response @return array{action:string,result:string,error_code:?string,summary:?string} */
    private function classify(array $event, array $response): array
    {
        if ($response['http_status'] === null) {
            return [
                'action' => 'RETRY', 'result' => 'TRANSPORT_ERROR',
                'error_code' => $response['error_type'] ?: 'TRANSPORT_ERROR',
                'summary' => $response['error_summary'] ?: 'Falha de transporte HTTP.',
            ];
        }
        $status = (int)$response['http_status'];
        if ($status === 200) {
            try {
                $decoded = json_decode((string)$response['body'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return ['action' => 'ATTENTION', 'result' => 'INVALID_RESPONSE', 'error_code' => 'INVALID_JSON_RESPONSE', 'summary' => 'O portal respondeu HTTP 200 com JSON inválido.'];
            }
            if (!is_array($decoded)) {
                return ['action' => 'ATTENTION', 'result' => 'INVALID_RESPONSE', 'error_code' => 'INVALID_PROTOCOL_RESPONSE', 'summary' => 'Resposta do portal em formato inesperado.'];
            }
            if (!isset($decoded['event_id']) || (string)$decoded['event_id'] !== (string)$event['event_uuid']) {
                return ['action' => 'ATTENTION', 'result' => 'INVALID_RESPONSE', 'error_code' => 'EVENT_ID_MISMATCH', 'summary' => 'O portal não confirmou exatamente o event_id enviado.'];
            }
            $remoteResult = strtoupper((string)($decoded['result'] ?? ''));
            if (!in_array($remoteResult, ['APPLIED', 'DUPLICATE', 'STALE_IGNORED'], true)) {
                return ['action' => 'ATTENTION', 'result' => 'INVALID_RESPONSE', 'error_code' => 'UNKNOWN_REMOTE_RESULT', 'summary' => 'O portal respondeu com resultado não reconhecido.'];
            }
            $this->repository->updateRemoteInfo(
                isset($decoded['plugin_version']) ? (string)$decoded['plugin_version'] : null,
                isset($decoded['schema_version']) ? (string)$decoded['schema_version'] : null
            );
            return ['action' => 'DELIVERED', 'result' => $remoteResult, 'error_code' => null, 'summary' => null];
        }
        if ($this->retry->isTransientHttpStatus($status)) {
            return ['action' => 'RETRY', 'result' => 'HTTP_' . $status, 'error_code' => 'HTTP_' . $status, 'summary' => $this->httpSummary($status, (string)$response['body'])];
        }
        return ['action' => 'ATTENTION', 'result' => 'HTTP_' . $status, 'error_code' => 'HTTP_' . $status, 'summary' => $this->httpSummary($status, (string)$response['body'])];
    }

    private function workerId(): string
    {
        $host = function_exists('gethostname') ? (string)gethostname() : 'host';
        return substr(preg_replace('/[^A-Za-z0-9_.-]/', '_', $host) ?: 'host', 0, 45) . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    private function databaseTime(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts === false ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $ts);
    }

    private function httpSummary(int $status, string $body): string
    {
        $body = trim(wp_strip_all_tags($body));
        return $this->truncate('HTTP ' . $status . ($body !== '' ? ': ' . $body : ''));
    }

    private function truncate(string $value): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, 900, 'UTF-8') : substr($value, 0, 900);
    }
}
