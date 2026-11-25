<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Sync\SyncSigningKeyStore;

final class SyncConfigService
{
    public const PORTAL_URL = 'sync.portal_base_url';
    public const ENVIRONMENT = 'sync.environment';
    public const ENABLED = 'sync.enabled';
    public const HEARTBEAT_INTERVAL = 'sync.heartbeat_interval_seconds';

    public function __construct(
        private readonly SyncRepository $repository,
        private readonly SyncSigningKeyStore $keys,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $keys = $this->repository->signingKeys();
        foreach ($keys as &$key) {
            try {
                $key['public_key_base64'] = base64_encode($this->keys->publicKey((string)$key['key_id']));
            } catch (\Throwable) {
                $key['public_key_base64'] = null;
            }
        }
        unset($key);
        $active = $this->repository->activeSigningKey();
        $fileActive = $this->keys->activeKeyId();
        $consistent = ($active === null && $fileActive === null)
            || ($active !== null && $fileActive === (string)$active['key_id']);
        return [
            'portal_base_url' => (string)$this->repository->getConfig(self::PORTAL_URL, ''),
            'environment' => (string)$this->repository->getConfig(self::ENVIRONMENT, 'homologation'),
            'enabled' => (bool)$this->repository->getConfig(self::ENABLED, false),
            'heartbeat_interval' => (int)$this->repository->getConfig(self::HEARTBEAT_INTERVAL, 300),
            'keys' => $keys,
            'active_key' => $active,
            'keyring_active_key_id' => $fileActive,
            'key_consistent' => $consistent,
            'keyring_path' => $this->keys->path(),
        ];
    }

    public function save(array $input, CifUser $actor): void
    {
        $url = rtrim(trim((string)($input['portal_base_url'] ?? '')), '/');
        $environment = strtolower(trim((string)($input['environment'] ?? 'homologation')));
        $enabled = !empty($input['enabled']);
        $heartbeat = (int)($input['heartbeat_interval'] ?? 300);
        if (!in_array($environment, ['homologation', 'production'], true)) {
            throw new InvalidArgumentException('Ambiente de sincronização inválido.');
        }
        if ($url !== '') {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                throw new InvalidArgumentException('Informe a URL absoluta do portal público.');
            }
            $scheme = strtolower((string)$parts['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('A URL do portal deve utilizar HTTP ou HTTPS.');
            }
            $host = strtolower(rtrim((string)$parts['host'], '.'));
            if ($scheme !== 'https') {
                $localHost = $host === 'localhost'
                    || $host === '127.0.0.1'
                    || $host === '::1'
                    || str_ends_with($host, '.local')
                    || str_ends_with($host, '.test');
                if ($environment === 'production' || !$localHost) {
                    throw new InvalidArgumentException('A sincronização exige HTTPS; HTTP é aceito somente em homologação local/loopback.');
                }
            }
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
                throw new InvalidArgumentException('Informe somente a URL base do portal, sem credenciais, query string ou fragmento.');
            }
        }
        if ($heartbeat < 60 || $heartbeat > 3600) {
            throw new InvalidArgumentException('O heartbeat deve ficar entre 60 e 3600 segundos.');
        }
        if ($enabled && $url === '') {
            throw new InvalidArgumentException('Informe o portal público antes de habilitar a sincronização.');
        }
        if ($enabled) {
            $active = $this->repository->activeSigningKey();
            if ($active === null) {
                throw new InvalidArgumentException('Ative uma chave Ed25519 antes de habilitar a sincronização.');
            }
            if ($this->keys->activeKeyId() !== (string)$active['key_id']) {
                throw new RuntimeException('O keyring privado e o banco discordam sobre a chave Ed25519 ativa. Corrija essa inconsistência antes de habilitar o worker.');
            }
            // Também confirma que o segredo correspondente é legível antes de habilitar o envio.
            $secret = $this->keys->secret((string)$active['key_id']);
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }

        $before = $this->overview();
        $this->transactions->run(function () use ($url, $environment, $enabled, $heartbeat, $actor): void {
            $this->repository->setConfig(self::PORTAL_URL, $url, 'URL base do WordPress público da validação CIF.', $actor->id);
            $this->repository->setConfig(self::ENVIRONMENT, $environment, 'Ambiente criptográfico da sincronização CIF.', $actor->id);
            $this->repository->setConfig(self::ENABLED, $enabled, 'Habilita o worker de sincronização com o portal público.', $actor->id);
            $this->repository->setConfig(self::HEARTBEAT_INTERVAL, $heartbeat, 'Intervalo mínimo entre heartbeats, em segundos.', $actor->id);
        });
        $this->audit->log('SINCRONIZACAO_CONFIGURADA', 'sincronizacao', 1, null, $actor, 'WEB', [
            'portal_base_url' => $before['portal_base_url'], 'environment' => $before['environment'], 'enabled' => $before['enabled'],
        ], [
            'portal_base_url' => $url, 'environment' => $environment, 'enabled' => $enabled, 'heartbeat_interval' => $heartbeat,
        ], null);
    }

    /** @return array{key_id:string,public_key_base64:string,fingerprint:string} */
    public function generateCandidate(CifUser $actor): array
    {
        $environment = (string)$this->repository->getConfig(self::ENVIRONMENT, 'homologation');
        if (!in_array($environment, ['homologation', 'production'], true)) {
            $environment = 'homologation';
        }
        $seed = bin2hex(random_bytes(4));
        $temporaryId = 'smtt-cif-' . ($environment === 'production' ? 'prod' : 'hml') . '-' . gmdate('Ymd') . '-' . $seed;
        $created = $this->keys->generate($temporaryId);
        $public = $created['public'];
        $fingerprint = hash('sha256', $public, true);
        $reference = 'FILE:' . basename($this->keys->path()) . '#' . $temporaryId;
        try {
            $this->repository->insertSigningKey($temporaryId, $reference, $fingerprint);
        } catch (\Throwable $e) {
            throw new RuntimeException('A chave privada foi criada, mas o registro público da chave não pôde ser persistido. Não a ative; gere outra após corrigir o banco.', 0, $e);
        }
        $this->audit->log('CHAVE_SYNC_ED25519_GERADA', 'sync_chave', null, $temporaryId, $actor, 'WEB', null, [
            'key_id' => $temporaryId,
            'fingerprint' => bin2hex($fingerprint),
            'status' => 'PENDENTE',
        ], null);
        return [
            'key_id' => $temporaryId,
            'public_key_base64' => base64_encode($public),
            'fingerprint' => bin2hex($fingerprint),
        ];
    }

    public function activateKey(string $keyId, CifUser $actor): void
    {
        $row = $this->repository->signingKey($keyId);
        if ($row === null) {
            throw new RuntimeException('Chave de sincronização não localizada.');
        }
        $this->transactions->run(function () use ($keyId): void {
            $this->repository->activateSigningKey($keyId);
            $this->keys->activate($keyId);
        });
        $this->audit->log('CHAVE_SYNC_ED25519_ATIVADA', 'sync_chave', null, $keyId, $actor, 'WEB', null, [
            'key_id' => $keyId, 'status' => 'ATIVA',
        ], 'A ativação só deve ocorrer depois que a chave pública correspondente estiver instalada no portal público.');
    }

    public function endpoint(string $kind): string
    {
        $base = rtrim((string)$this->repository->getConfig(self::PORTAL_URL, ''), '/');
        if ($base === '') {
            throw new RuntimeException('A URL do portal público ainda não foi configurada.');
        }
        $paths = [
            'events' => '/wp-json/smtt-cif/v1/sync/events',
            'heartbeat' => '/wp-json/smtt-cif/v1/sync/heartbeat',
            'reconcile' => '/wp-json/smtt-cif/v1/sync/reconcile',
        ];
        if (!isset($paths[$kind])) {
            throw new RuntimeException('Endpoint de sincronização desconhecido.');
        }
        return $base . $paths[$kind];
    }

    public function environment(): string
    {
        return (string)$this->repository->getConfig(self::ENVIRONMENT, 'homologation');
    }

    public function enabled(): bool
    {
        return (bool)$this->repository->getConfig(self::ENABLED, false);
    }

    public function heartbeatInterval(): int
    {
        return max(60, (int)$this->repository->getConfig(self::HEARTBEAT_INTERVAL, 300));
    }
}
