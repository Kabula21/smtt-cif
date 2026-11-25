<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Web;

use SMTT\CIF\Validacao\Repository\SyncRepository;
use SMTT\CIF\Validacao\Security\AuthenticationException;
use SMTT\CIF\Validacao\Security\CanonicalSignature;
use SMTT\CIF\Validacao\Security\RequestAuthenticator;
use SMTT\CIF\Validacao\Service\RuntimeModeService;
use SMTT\CIF\Validacao\Service\SyncService;
use SMTT\CIF\Validacao\Sync\PayloadException;
use SMTT\CIF\Validacao\Sync\PayloadValidator;

final class RestController
{
    public function __construct(
        private readonly RequestAuthenticator $authenticator,
        private readonly PayloadValidator $validator,
        private readonly SyncService $service,
        private readonly SyncRepository $sync,
        private readonly RuntimeModeService $runtimeMode,
        private readonly \Closure $schemaVersion
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('smtt-cif/v1', '/sync/events', [
                'methods' => 'POST', 'callback' => [$this, 'events'], 'permission_callback' => '__return_true',
            ]);
            register_rest_route('smtt-cif/v1', '/sync/heartbeat', [
                'methods' => 'POST', 'callback' => [$this, 'heartbeat'], 'permission_callback' => '__return_true',
            ]);
            register_rest_route('smtt-cif/v1', '/sync/reconcile', [
                'methods' => 'POST', 'callback' => [$this, 'reconcile'], 'permission_callback' => '__return_true',
            ]);
        });
    }

    public function events(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, $this->canonicalPath('/wp-json/smtt-cif/v1/sync/events'), 'events', function (array $auth) use ($request): array {
            $payload = $this->validator->events((string)$request->get_body());
            $result = $this->service->applyEvent($payload, (string)$request->get_body(), $auth['request_id']);
            $result['plugin_version'] = SMTT_CIF_VALIDACAO_VERSION;
            $result['schema_version'] = ($this->schemaVersion)();
            return $result;
        });
    }

    public function heartbeat(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, $this->canonicalPath('/wp-json/smtt-cif/v1/sync/heartbeat'), 'heartbeat', function () use ($request): array {
            $this->validator->heartbeat((string)$request->get_body());
            return [
                'result' => 'OK',
                'plugin_version' => SMTT_CIF_VALIDACAO_VERSION,
                'protocol_version' => CanonicalSignature::PROTOCOL_VERSION,
                'environment' => $this->runtimeMode->syncEnvironment(),
                'runtime_mode' => $this->runtimeMode->mode(),
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'schema_version' => ($this->schemaVersion)(),
            ];
        });
    }

    public function reconcile(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, $this->canonicalPath('/wp-json/smtt-cif/v1/sync/reconcile'), 'reconcile', function () use ($request): array {
            $payload = $this->validator->reconcile((string)$request->get_body());
            return [
                'result' => 'OK',
                'items' => $this->service->reconcile($payload['items']),
                'plugin_version' => SMTT_CIF_VALIDACAO_VERSION,
                'schema_version' => ($this->schemaVersion)(),
            ];
        });
    }

    /** @param callable(array<string,string>):array<string,mixed> $callback */
    private function handle(\WP_REST_Request $request, string $path, string $endpoint, callable $callback): \WP_REST_Response
    {
        $auth = null;
        try {
            $auth = $this->authenticator->authenticate($request, $path);
            if (!$this->sync->rememberRequest($auth['request_id'], $auth['key_id'], $auth['timestamp_db'], $endpoint)) {
                throw new AuthenticationException('Request ID já utilizado.', 409, 'REPLAY_DETECTED');
            }
            $data = $callback($auth);
            $this->sync->log($endpoint, $auth['request_id'], $auth['key_id'], (string)($data['result'] ?? 'OK'), 200, null);
            return $this->response($data, 200);
        } catch (AuthenticationException $e) {
            $this->safeLog($endpoint, $auth, $e->errorCode, $e->httpStatus, $e->getMessage());
            return $this->response(['result' => 'ERROR', 'code' => $e->errorCode], $e->httpStatus);
        } catch (PayloadException $e) {
            $this->safeLog($endpoint, $auth, $e->errorCode, $e->httpStatus, $e->getMessage());
            return $this->response(['result' => 'ERROR', 'code' => $e->errorCode], $e->httpStatus);
        } catch (\Throwable $e) {
            error_log('[SMTT CIF Validação] Erro no endpoint ' . $endpoint . ': ' . $e->getMessage());
            $this->safeLog($endpoint, $auth, 'INTERNAL_ERROR', 500, 'Falha interna processando requisição assinada.');
            return $this->response(['result' => 'ERROR', 'code' => 'INTERNAL_ERROR'], 500);
        }
    }

    /** @param array<string,string>|null $auth */
    private function safeLog(string $endpoint, ?array $auth, string $result, int $status, string $summary): void
    {
        try {
            $this->sync->log($endpoint, $auth['request_id'] ?? null, $auth['key_id'] ?? null, $result, $status, $summary);
        } catch (\Throwable) {
            // Logging técnico nunca deve alterar a resposta protocolar.
        }
    }

    private function canonicalPath(string $relative): string
    {
        $url = home_url($relative);
        $path = wp_parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : $relative;
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, max-age=0');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
