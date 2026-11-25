<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Sync;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use SMTT\CIF\Validacao\Security\Base64Url;
use SMTT\CIF\Validacao\Security\CanonicalSignature;

final class PayloadValidator
{
    /** @return array<string,mixed> */
    public function events(string $body): array
    {
        $data = $this->decode($body);
        $this->exactKeys($data, ['protocol_version','event_id','event_type','event_created_at','payload_version','items']);
        if (($data['protocol_version'] ?? null) !== CanonicalSignature::PROTOCOL_VERSION) {
            throw new PayloadException('Protocolo do payload não suportado.', 'PROTOCOL_MISMATCH', 400);
        }
        $this->uuid((string)($data['event_id'] ?? ''), 'event_id');
        if (($data['event_type'] ?? null) !== 'CIF_PROJECTION_BATCH') {
            throw new PayloadException('Tipo de evento não suportado.', 'EVENT_TYPE_UNSUPPORTED');
        }
        if (($data['payload_version'] ?? null) !== 1) {
            throw new PayloadException('Versão de payload não suportada.', 'PAYLOAD_VERSION_UNSUPPORTED');
        }
        $this->iso((string)($data['event_created_at'] ?? ''), 'event_created_at');
        if (!is_array($data['items'] ?? null) || $data['items'] === [] || count($data['items']) > 200) {
            throw new PayloadException('Lista de projeções inválida.', 'ITEMS_INVALID');
        }

        $items = [];
        $seen = [];
        foreach ($data['items'] as $raw) {
            if (!is_array($raw)) {
                throw new PayloadException('Item de projeção inválido.', 'ITEM_INVALID');
            }
            $itemKeys = ['emission_id','record_version','token','code','name','registration','issued_at','public_status','source_updated_at','projection_hash'];
            if (array_key_exists('admission_date', $raw)) {
                $itemKeys[] = 'admission_date';
            }
            $this->exactKeys($raw, $itemKeys);
            $id = (string)($raw['emission_id'] ?? '');
            $this->uuid($id, 'emission_id');
            if (isset($seen[$id])) {
                throw new PayloadException('Emissão duplicada no mesmo evento.', 'DUPLICATE_ITEM');
            }
            $seen[$id] = true;
            if (!is_int($raw['record_version'] ?? null) || $raw['record_version'] < 1) {
                throw new PayloadException('record_version inválido.', 'RECORD_VERSION_INVALID');
            }
            $token = (string)($raw['token'] ?? '');
            try {
                $decodedToken = Base64Url::decode($token);
            } catch (\InvalidArgumentException) {
                throw new PayloadException('Token de QR inválido.', 'TOKEN_INVALID');
            }
            if (strlen($decodedToken) !== 32) {
                throw new PayloadException('Token de QR fora do formato esperado.', 'TOKEN_INVALID');
            }
            $tokenHash = hash('sha256', $token, true);
            if (function_exists('sodium_memzero')) {
                sodium_memzero($decodedToken);
            }
            if (preg_match('/^AJU\d{2}SEBR\d{4}$/', (string)($raw['code'] ?? '')) !== 1) {
                throw new PayloadException('Código CIF inválido.', 'CODE_INVALID');
            }
            $name = trim((string)($raw['name'] ?? ''));
            $registration = trim((string)($raw['registration'] ?? ''));
            if ($name === '' || $this->length($name) > 200) {
                throw new PayloadException('Nome da projeção inválido.', 'NAME_INVALID');
            }
            if ($registration === '' || $this->length($registration) > 80) {
                throw new PayloadException('Matrícula da projeção inválida.', 'REGISTRATION_INVALID');
            }
            $admissionDate = null;
            if (array_key_exists('admission_date', $raw) && $raw['admission_date'] !== null && $raw['admission_date'] !== '') {
                $admissionDate = $this->dateOnly((string)$raw['admission_date'], 'admission_date');
            }
            $issued = $this->iso((string)($raw['issued_at'] ?? ''), 'issued_at');
            $sourceUpdated = $this->iso((string)($raw['source_updated_at'] ?? ''), 'source_updated_at');
            $status = (string)($raw['public_status'] ?? '');
            if (!in_array($status, ['VALIDA', 'NAO_VALIDA'], true)) {
                throw new PayloadException('Status público inválido.', 'PUBLIC_STATUS_INVALID');
            }
            $projectionHash = strtolower((string)($raw['projection_hash'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/', $projectionHash) !== 1) {
                throw new PayloadException('Hash de projeção inválido.', 'PROJECTION_HASH_INVALID');
            }
            $rawProjectionHash = hex2bin($projectionHash);
            if (!is_string($rawProjectionHash) || strlen($rawProjectionHash) !== 32) {
                throw new PayloadException('Hash de projeção inválido.', 'PROJECTION_HASH_INVALID');
            }
            $items[] = [
                'emission_id' => $id,
                'record_version' => $raw['record_version'],
                'token_hash' => $tokenHash,
                'code' => (string)$raw['code'],
                'name' => $name,
                'registration' => $registration,
                'admission_date_db' => $admissionDate,
                'issued_at_db' => $issued->format('Y-m-d H:i:s'),
                'public_status' => $status,
                'source_updated_at_db' => $sourceUpdated->format('Y-m-d H:i:s'),
                'projection_hash' => $projectionHash,
                'projection_hash_raw' => $rawProjectionHash,
            ];
            if (function_exists('sodium_memzero')) {
                sodium_memzero($token);
            }
        }
        $data['items'] = $items;
        return $data;
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $body): array
    {
        $data = $this->decode($body);
        $allowed = ['protocol_version','event_type','source_time','plugin_version','pending_events','attention_required','oldest_pending_age_seconds'];
        $this->exactKeys($data, $allowed);
        if (($data['protocol_version'] ?? null) !== 1 || ($data['event_type'] ?? null) !== 'HEARTBEAT') {
            throw new PayloadException('Heartbeat incompatível.', 'HEARTBEAT_INVALID');
        }
        $this->iso((string)($data['source_time'] ?? ''), 'source_time');
        if (!is_string($data['plugin_version'] ?? null) || strlen($data['plugin_version']) > 50) {
            throw new PayloadException('Versão do emissor inválida.', 'PLUGIN_VERSION_INVALID');
        }
        foreach (['pending_events','attention_required'] as $key) {
            if (!is_int($data[$key] ?? null) || $data[$key] < 0) {
                throw new PayloadException('Contador de heartbeat inválido.', 'HEARTBEAT_INVALID');
            }
        }
        if (($data['oldest_pending_age_seconds'] ?? null) !== null && (!is_int($data['oldest_pending_age_seconds']) || $data['oldest_pending_age_seconds'] < 0)) {
            throw new PayloadException('Idade de fila inválida.', 'HEARTBEAT_INVALID');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function reconcile(string $body): array
    {
        $data = $this->decode($body);
        $this->exactKeys($data, ['protocol_version','request_type','source_time','items']);
        if (($data['protocol_version'] ?? null) !== 1 || ($data['request_type'] ?? null) !== 'RECONCILE') {
            throw new PayloadException('Reconciliação incompatível.', 'RECONCILE_INVALID');
        }
        $this->iso((string)($data['source_time'] ?? ''), 'source_time');
        if (!is_array($data['items'] ?? null) || count($data['items']) > 500) {
            throw new PayloadException('Itens de reconciliação inválidos.', 'RECONCILE_ITEMS_INVALID');
        }
        $items = [];
        $seen = [];
        foreach ($data['items'] as $raw) {
            if (!is_array($raw)) {
                throw new PayloadException('Item de reconciliação inválido.', 'RECONCILE_ITEM_INVALID');
            }
            $this->exactKeys($raw, ['emission_id','desired_version','desired_hash']);
            $id = (string)($raw['emission_id'] ?? '');
            $this->uuid($id, 'emission_id');
            if (isset($seen[$id])) {
                throw new PayloadException('Emissão duplicada na reconciliação.', 'RECONCILE_DUPLICATE_ITEM');
            }
            $seen[$id] = true;
            if (!is_int($raw['desired_version'] ?? null) || $raw['desired_version'] < 1) {
                throw new PayloadException('desired_version inválido.', 'RECONCILE_VERSION_INVALID');
            }
            $hash = strtolower((string)($raw['desired_hash'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new PayloadException('desired_hash inválido.', 'RECONCILE_HASH_INVALID');
            }
            $items[] = ['emission_id' => $id, 'desired_version' => $raw['desired_version'], 'desired_hash' => $hash];
        }
        $data['items'] = $items;
        return $data;
    }

    /** @return array<string,mixed> */
    private function decode(string $body): array
    {
        if ($body === '' || strlen($body) > 1_000_000) {
            throw new PayloadException('Corpo ausente ou excessivo.', 'BODY_INVALID', 400);
        }
        try {
            $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PayloadException('JSON inválido.', 'JSON_INVALID', 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new PayloadException('Objeto JSON esperado.', 'JSON_OBJECT_REQUIRED', 400);
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data @param list<string> $allowed */
    private function exactKeys(array $data, array $allowed): void
    {
        $actual = array_keys($data);
        sort($actual);
        $expected = $allowed;
        sort($expected);
        if ($actual !== $expected) {
            throw new PayloadException('Campos do payload não correspondem ao schema.', 'SCHEMA_FIELDS_INVALID');
        }
    }

    private function uuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) !== 1) {
            throw new PayloadException($field . ' inválido.', 'UUID_INVALID');
        }
    }

    private function dateOnly(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new PayloadException($field . ' inválido.', 'DATE_INVALID');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d') !== $value) {
            throw new PayloadException($field . ' inválido.', 'DATE_INVALID');
        }
        return $value;
    }

    private function iso(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new PayloadException($field . ' inválido.', 'DATE_INVALID');
        }
        return $date;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
