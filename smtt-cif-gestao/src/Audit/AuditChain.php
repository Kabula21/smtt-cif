<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Audit;

use JsonException;

final class AuditChain
{
    public const VERSION = 'SMTT-CIF-AUDIT-V1';

    /** @param array<string,mixed> $record */
    public static function canonical(array $record): string
    {
        $normalized = [
            'uuid' => (string)($record['uuid'] ?? ''),
            'ocorrido_em' => (string)($record['ocorrido_em'] ?? ''),
            'usuario_cif_id' => self::nullableInt($record['usuario_cif_id'] ?? null),
            'actor_wp_user_id' => self::nullableInt($record['actor_wp_user_id'] ?? null),
            'actor_login' => self::nullableString($record['actor_login'] ?? null),
            'actor_nome' => self::nullableString($record['actor_nome'] ?? null),
            'operacao' => (string)($record['operacao'] ?? ''),
            'entidade_tipo' => (string)($record['entidade_tipo'] ?? ''),
            'entidade_id' => self::nullableInt($record['entidade_id'] ?? null),
            'entidade_uuid' => self::nullableString($record['entidade_uuid'] ?? null),
            'valor_anterior' => self::normalizedJson($record['valor_anterior'] ?? null),
            'valor_posterior' => self::normalizedJson($record['valor_posterior'] ?? null),
            'justificativa' => self::nullableString($record['justificativa'] ?? null),
            'ip_origem_hex' => self::binaryHex($record['ip_origem'] ?? null),
            'user_agent' => self::nullableString($record['user_agent'] ?? null),
            'origem' => (string)($record['origem'] ?? ''),
            'request_id' => self::nullableString($record['request_id'] ?? null),
        ];

        try {
            return json_encode(
                $normalized,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new \RuntimeException('Não foi possível formar o registro canônico da auditoria.', 0, $e);
        }
    }

    /** @param array<string,mixed> $record */
    public static function hash(string $previousHash, array $record): string
    {
        return hash('sha256', self::VERSION . "\0" . $previousHash . self::canonical($record), true);
    }


    private static function normalizedJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return self::sortRecursive($value);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return self::sortRecursive($decoded);
        } catch (JsonException) {
            return $value;
        }
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    private static function binaryHex(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : bin2hex((string)$value);
    }

    private function __construct()
    {
    }
}
