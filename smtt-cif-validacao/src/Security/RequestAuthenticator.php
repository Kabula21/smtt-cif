<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Security;

use DateTimeImmutable;
use DateTimeZone;
use SMTT\CIF\Validacao\Repository\SettingsRepository;
use SMTT\CIF\Validacao\Repository\SyncRepository;
use SMTT\CIF\Validacao\Service\RuntimeModeService;

final class RequestAuthenticator
{
    public function __construct(
        private readonly SyncRepository $sync,
        private readonly SettingsRepository $settings,
        private readonly RuntimeModeService $runtimeMode
    )
    {
    }

    /** @return array{request_id:string,key_id:string,timestamp:string,timestamp_db:string,environment:string} */
    public function authenticate(\WP_REST_Request $request, string $canonicalPath): array
    {
        $protocol = trim((string)$request->get_header('X-SMTT-CIF-Protocol'));
        $environment = trim((string)$request->get_header('X-SMTT-CIF-Environment'));
        $keyId = trim((string)$request->get_header('X-SMTT-CIF-Key-Id'));
        $timestamp = trim((string)$request->get_header('X-SMTT-CIF-Timestamp'));
        $requestId = trim((string)$request->get_header('X-SMTT-CIF-Request-Id'));
        $contentHash = trim((string)$request->get_header('X-SMTT-CIF-Content-SHA256'));
        $signatureText = trim((string)$request->get_header('X-SMTT-CIF-Signature'));

        if ($protocol !== (string)CanonicalSignature::PROTOCOL_VERSION) {
            throw new AuthenticationException('Versão de protocolo não aceita.', 400, 'PROTOCOL_MISMATCH');
        }
        $expectedEnvironment = $this->runtimeMode->syncEnvironment();
        if (!in_array($environment, ['homologation', 'production'], true) || !hash_equals($expectedEnvironment, $environment)) {
            throw new AuthenticationException('Ambiente de sincronização não aceito.', 403, 'ENVIRONMENT_MISMATCH');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $keyId) !== 1) {
            throw new AuthenticationException('Identificador de chave inválido.', 401, 'KEY_NOT_ACCEPTED');
        }
        if (!$this->runtimeMode->keyIdCompatible($keyId)) {
            throw new AuthenticationException('A chave informada pertence a outro ambiente.', 403, 'KEY_ENVIRONMENT_MISMATCH');
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $requestId) !== 1) {
            throw new AuthenticationException('Request ID inválido.', 400, 'REQUEST_ID_INVALID');
        }

        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$time instanceof DateTimeImmutable || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new AuthenticationException('Timestamp inválido.', 400, 'TIMESTAMP_INVALID');
        }
        $window = min(max((int)$this->settings->get('security.timestamp_window_seconds', 300), 60), 900);
        if (abs(time() - $time->getTimestamp()) > $window) {
            throw new AuthenticationException('Timestamp fora da janela aceita.', 401, 'TIMESTAMP_OUTSIDE_WINDOW');
        }

        $body = (string)$request->get_body();
        $expectedHash = CanonicalSignature::contentHash($body);
        if ($contentHash === '' || !hash_equals($expectedHash, $contentHash)) {
            throw new AuthenticationException('Hash do conteúdo não confere.', 400, 'CONTENT_HASH_MISMATCH');
        }

        $key = $this->sync->acceptedKey($keyId);
        if ($key === null) {
            throw new AuthenticationException('Chave não aceita.', 401, 'KEY_NOT_ACCEPTED');
        }
        try {
            $signature = Base64Url::decode($signatureText);
        } catch (\InvalidArgumentException) {
            throw new AuthenticationException('Assinatura inválida.', 401, 'SIGNATURE_INVALID');
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new AuthenticationException('Assinatura inválida.', 401, 'SIGNATURE_INVALID');
        }
        $canonical = CanonicalSignature::build('POST', $canonicalPath, $environment, $keyId, $timestamp, $requestId, $contentHash);
        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $key['public_key'])) {
            throw new AuthenticationException('Assinatura não confere.', 401, 'SIGNATURE_INVALID');
        }

        return [
            'request_id' => $requestId,
            'key_id' => $keyId,
            'timestamp' => $timestamp,
            'timestamp_db' => $time->format('Y-m-d H:i:s'),
            'environment' => $environment,
        ];
    }
}
