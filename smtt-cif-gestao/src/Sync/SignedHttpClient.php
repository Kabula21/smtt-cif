<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use RuntimeException;
use SMTT\CIF\Gestao\Support\Uuid;

final class SignedHttpClient
{
    public function __construct(private readonly MessageSigner $signer)
    {
    }

    /** @return array{request_id:string,request_time:string,key_id:string,http_status:?int,body:string,duration_ms:int,error_type:?string,error_summary:?string} */
    public function post(string $url, string $body, string $environment, int $timeout = 15): array
    {
        if (!function_exists('wp_remote_post')) {
            throw new RuntimeException('A API HTTP do WordPress não está disponível.');
        }
        $this->assertAllowedSyncUrl($url, $environment);

        $requestId = Uuid::v4();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $headers = $this->signer->headers('POST', $url, $body, $environment, $requestId, $timestamp);
        $keyId = (string)$headers['X-SMTT-CIF-Key-Id'];
        $started = microtime(true);
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => max(5, min($timeout, 60)),
            'redirection' => 0,
            'sslverify' => true,
            'blocking' => true,
            'user-agent' => 'SMTT-CIF-Gestao/' . SMTT_CIF_GESTAO_VERSION,
            // A URL já é validada explicitamente por assertAllowedSyncUrl().
            // Evita wp_http_validate_url() bloquear destinos HTTPS legítimos em redes legadas/intranet.
            'reject_unsafe_urls' => false,
        ]);
        $duration = (int)round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            return [
                'request_id' => $requestId,
                'request_time' => $timestamp,
                'key_id' => $keyId,
                'http_status' => null,
                'body' => '',
                'duration_ms' => $duration,
                'error_type' => (string)$response->get_error_code(),
                'error_summary' => $this->truncate((string)$response->get_error_message()),
            ];
        }
        return [
            'request_id' => $requestId,
            'request_time' => $timestamp,
            'key_id' => $keyId,
            'http_status' => (int)wp_remote_retrieve_response_code($response),
            'body' => (string)wp_remote_retrieve_body($response),
            'duration_ms' => $duration,
            'error_type' => null,
            'error_summary' => null,
        ];
    }

    private function assertAllowedSyncUrl(string $url, string $environment): void
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('URL de sincronização inválida.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL de sincronização inválida ou não permitida.');
        }

        if ($environment === 'production' && $scheme !== 'https') {
            throw new RuntimeException('Em produção, a sincronização exige HTTPS.');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Protocolo da URL de sincronização não permitido.');
        }

        if (!preg_match('#/wp-json/smtt-cif/v1/sync/(?:events|heartbeat|reconcile)/?$#', $path)) {
            throw new RuntimeException('Endpoint de sincronização SMTT CIF não permitido.');
        }
    }

    private function truncate(string $value): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, 900, 'UTF-8') : substr($value, 0, 900);
    }
}
