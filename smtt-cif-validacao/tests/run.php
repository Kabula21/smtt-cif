<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap/Autoloader.php';
\SMTT\CIF\Validacao\Bootstrap\Autoloader::register('SMTT\\CIF\\Validacao\\', dirname(__DIR__) . '/src');

use SMTT\CIF\Validacao\Security\Base64Url;
use SMTT\CIF\Validacao\Security\CanonicalSignature;
use SMTT\CIF\Validacao\Sync\PayloadException;
use SMTT\CIF\Validacao\Sync\PayloadValidator;

$tests = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$tests, &$failures): void {
    ++$tests;
    if (!$condition) {
        $failures[] = $message;
    }
};

$bytes = random_bytes(32);
$encoded = Base64Url::encode($bytes);
$assert(strlen($encoded) === 43, 'Token Base64URL de 32 bytes deve ter 43 caracteres.');
$assert(hash_equals($bytes, Base64Url::decode($encoded)), 'Base64URL round-trip falhou.');

$body = '{"ok":true}';
$hash = CanonicalSignature::contentHash($body);
$keyPair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keyPair);
$public = sodium_crypto_sign_publickey($keyPair);
$canonical = CanonicalSignature::build(
    'POST',
    '/wp-json/smtt-cif/v1/sync/events',
    'homologation',
    'smtt-cif-hml-20260921-test',
    '2026-09-21T15:00:00Z',
    '11111111-1111-4111-8111-111111111111',
    $hash
);
$expectedCanonical = "SMTT-CIF-SIGNATURE-V1\nPOST\n/wp-json/smtt-cif/v1/sync/events\n1\nhomologation\nsmtt-cif-hml-20260921-test\n2026-09-21T15:00:00Z\n11111111-1111-4111-8111-111111111111\n" . $hash;
$assert($canonical === $expectedCanonical, 'String canônica divergiu do contrato do Gestão.');
$signature = sodium_crypto_sign_detached($canonical, $secret);
$assert(sodium_crypto_sign_verify_detached($signature, $canonical, $public), 'Assinatura Ed25519 canônica não validou.');
$assert(!sodium_crypto_sign_verify_detached($signature, $canonical . 'x', $public), 'Assinatura alterada deveria falhar.');
sodium_memzero($secret);

$validator = new PayloadValidator();
$token = Base64Url::encode(random_bytes(32));
$event = [
    'protocol_version' => 1,
    'event_id' => '22222222-2222-4222-8222-222222222222',
    'event_type' => 'CIF_PROJECTION_BATCH',
    'event_created_at' => '2026-09-21T15:00:00Z',
    'payload_version' => 1,
    'items' => [[
        'emission_id' => '33333333-3333-4333-8333-333333333333',
        'record_version' => 4,
        'token' => $token,
        'code' => 'AJU01SEBR0185',
        'name' => 'Agente de Teste',
        'registration' => '12345',
        'admission_date' => '2014-02-10',
        'issued_at' => '2026-09-21T14:00:00Z',
        'public_status' => 'VALIDA',
        'source_updated_at' => '2026-09-21T15:00:00Z',
        'projection_hash' => str_repeat('a', 64),
    ]],
];
$validated = $validator->events(json_encode($event, JSON_THROW_ON_ERROR));
$assert($validated['items'][0]['code'] === 'AJU01SEBR0185', 'Evento válido não preservou o código CIF.');
$assert(strlen($validated['items'][0]['token_hash']) === 32, 'Hash binário do token deve ter 32 bytes.');
$assert($validated['items'][0]['public_status'] === 'VALIDA', 'Status público válido não foi aceito.');
$assert($validated['items'][0]['admission_date_db'] === '2014-02-10', 'Data de admissão válida não foi preservada.');
$legacyEvent = $event;
unset($legacyEvent['items'][0]['admission_date']);
$legacyValidated = $validator->events(json_encode($legacyEvent, JSON_THROW_ON_ERROR));
$assert($legacyValidated['items'][0]['admission_date_db'] === null, 'Payload legado sem data de admissão deve continuar aceito.');

$badAdmission = $event;
$badAdmission['items'][0]['admission_date'] = '2026-99-99';
$rejected = false;
try {
    $validator->events(json_encode($badAdmission, JSON_THROW_ON_ERROR));
} catch (PayloadException $e) {
    $rejected = $e->errorCode === 'DATE_INVALID';
}
$assert($rejected, 'Data de admissão inválida deveria ser rejeitada.');


$bad = $event;
$bad['unexpected'] = true;
$rejected = false;
try {
    $validator->events(json_encode($bad, JSON_THROW_ON_ERROR));
} catch (PayloadException $e) {
    $rejected = $e->errorCode === 'SCHEMA_FIELDS_INVALID';
}
$assert($rejected, 'Campo inesperado deveria ser rejeitado pelo schema estrito.');

$bad = $event;
$bad['items'][0]['token'] = 'abc';
$rejected = false;
try {
    $validator->events(json_encode($bad, JSON_THROW_ON_ERROR));
} catch (PayloadException $e) {
    $rejected = $e->errorCode === 'TOKEN_INVALID';
}
$assert($rejected, 'Token de tamanho inválido deveria ser rejeitado.');

$heartbeat = [
    'protocol_version' => 1,
    'event_type' => 'HEARTBEAT',
    'source_time' => '2026-09-21T15:00:00Z',
    'plugin_version' => '1.0.0',
    'pending_events' => 2,
    'attention_required' => 0,
    'oldest_pending_age_seconds' => null,
];
$validatedHeartbeat = $validator->heartbeat(json_encode($heartbeat, JSON_THROW_ON_ERROR));
$assert($validatedHeartbeat['event_type'] === 'HEARTBEAT', 'Heartbeat válido foi rejeitado.');

$reconcile = [
    'protocol_version' => 1,
    'request_type' => 'RECONCILE',
    'source_time' => '2026-09-21T15:00:00Z',
    'items' => [[
        'emission_id' => '44444444-4444-4444-8444-444444444444',
        'desired_version' => 8,
        'desired_hash' => str_repeat('b', 64),
    ]],
];
$validatedReconcile = $validator->reconcile(json_encode($reconcile, JSON_THROW_ON_ERROR));
$assert($validatedReconcile['items'][0]['desired_version'] === 8, 'Reconciliação válida foi rejeitada.');


$modeReflection = new ReflectionClass(\SMTT\CIF\Validacao\Service\RuntimeModeService::class);
/** @var \SMTT\CIF\Validacao\Service\RuntimeModeService $modeService */
$modeService = $modeReflection->newInstanceWithoutConstructor();
$assert($modeService->keyEnvironmentHint('smtt-cif-hml-20260921-abcd') === 'homologation', 'Key ID hml deve ser identificado como homologation.');
$assert($modeService->keyEnvironmentHint('smtt-cif-prod-20260921-abcd') === 'production', 'Key ID prod deve ser identificado como production.');
$assert($modeService->keyEnvironmentHint('legacy-key-id') === null, 'Key ID legado/customizado não deve receber ambiente inventado.');

$migration = require dirname(__DIR__) . '/migrations/20260921_001_initial.php';
$assert(($migration['version'] ?? '') === '20260921_001_initial', 'Versão da migration inicial inesperada.');
$sql = implode("\n", $migration['up'] ?? []);
foreach (['public_issuances','sync_events_received','sync_replay_guard','sync_public_keys','sync_log','plugin_settings','validation_rate_limits'] as $table) {
    $assert(str_contains($sql, 'CREATE TABLE IF NOT EXISTS ' . $table), 'Migration não cria a tabela ' . $table . '.');
}
$assert(preg_match('/\b(DROP|TRUNCATE)\b/i', $sql) !== 1, 'Migration inicial não deve conter operação destrutiva.');

$migration2 = require dirname(__DIR__) . '/migrations/20260921_002_runtime_mode.php';
$assert(($migration2['version'] ?? '') === '20260921_002_runtime_mode', 'Versão da migration de ambiente inesperada.');
$sql2 = implode("\n", $migration2['up'] ?? []);
$assert(str_contains($sql2, 'runtime.mode'), 'Migration de ambiente deve persistir runtime.mode.');
$assert(str_contains($sql2, 'sync.environment'), 'Migration de ambiente deve preservar compatibilidade com sync.environment.');
$assert(preg_match('/\b(DROP|TRUNCATE)\b/i', $sql2) !== 1, 'Migration de ambiente não deve conter operação destrutiva.');


$migration3 = require dirname(__DIR__) . '/migrations/20260923_003_admission_date.php';
$assert(($migration3['version'] ?? '') === '20260923_003_admission_date', 'Versão da migration de admissão inesperada.');
$sql3 = implode("\n", $migration3['up'] ?? []);
$assert(str_contains($sql3, 'ADD COLUMN admission_date DATE NULL'), 'Migration deve adicionar admission_date à projeção pública.');
$assert(preg_match('/\b(DROP|TRUNCATE)\b/i', $sql3) !== 1, 'Migration de admissão não deve conter operação destrutiva.');

$mainPlugin = file_get_contents(dirname(__DIR__) . '/smtt-cif-validacao.php');
$assert(is_string($mainPlugin) && str_contains($mainPlugin, 'Version: 0.4.0'), 'Cabeçalho do plugin deve declarar versão 0.3.4.');
$assert(is_string($mainPlugin) && str_contains($mainPlugin, "SMTT_CIF_VALIDACAO_VERSION', '0.4.0"), 'Constante do plugin deve declarar versão 0.3.4.');

$publicController = file_get_contents(dirname(__DIR__) . '/src/Web/PublicValidationController.php');
$assert(is_string($publicController) && str_contains($publicController, 'assets/img/smtt-logo-color.png'), 'Página pública deve usar a marca SMTT empacotada.');
$assert(is_string($publicController) && str_contains($publicController, 'Validação da Carteira de Identificação Funcional'), 'Página pública deve preservar o título institucional.');
$assert(is_string($publicController) && str_contains($publicController, 'cif-card'), 'Página pública deve usar o card central da versão 0.4.0.');
$assert(is_string($publicController) && !str_contains($publicController, 'validation_code'), 'Consulta pública 0.4.0 não deve solicitar código CIF.');
$assert(is_string($publicController) && !str_contains($publicController, '<form'), 'Consulta pública 0.4.0 não deve conter formulário de pesquisa.');
$assert(is_string($publicController) && str_contains($publicController, "{43}"), 'Rota pública deve restringir token Base64URL a 43 caracteres.');
$assert(is_string($publicController) && str_contains($publicController, 'Consulta pública da situação registrada'), 'Página pública deve explicar que exibe diretamente a situação registrada.');
$assert(is_string($publicController) && str_contains($publicController, '<dt>Admissão</dt>'), 'Resultado público deve exibir a data de admissão.');
$assert(is_string($publicController) && str_contains($publicController, 'não oferece pesquisa pública por nome, CPF, matrícula ou código da CIF'), 'Página pública deve declarar ausência de pesquisa enumerável.');
$assert(is_string($publicController) && str_contains($publicController, 'Cross-Origin-Resource-Policy: same-origin'), 'Página pública deve enviar Cross-Origin-Resource-Policy.');
$assert(is_string($publicController) && str_contains($publicController, 'Permissions-Policy:'), 'Página pública deve enviar Permissions-Policy restritiva.');

$validationService = file_get_contents(dirname(__DIR__) . '/src/Service/ValidationService.php');
$assert(is_string($validationService) && str_contains($validationService, 'findByTokenHash($tokenHash)'), 'Validação pública deve localizar emissão somente pelo hash do token.');
$assert(is_string($validationService) && !str_contains($validationService, 'findByTokenAndCode'), 'Validação pública não deve depender de token + código.');
$assert(is_string($validationService) && str_contains($validationService, "preg_match('/^[A-Za-z0-9_-]{43}$/D'"), 'Serviço deve exigir o formato exato do token de 256 bits.');

$issuanceRepository = file_get_contents(dirname(__DIR__) . '/src/Repository/PublicIssuanceRepository.php');
$assert(is_string($issuanceRepository) && str_contains($issuanceRepository, 'findByTokenHash'), 'Repositório público deve suportar lookup por token_hash.');
$assert(is_string($issuanceRepository) && !str_contains($issuanceRepository, 'findByTokenAndCode'), 'Repositório não deve expor lookup público por token + código.');

$publicCss = file_get_contents(dirname(__DIR__) . '/assets/css/public.css');
$assert(is_string($publicCss) && str_contains($publicCss, '.cif-card'), 'CSS público deve conter o card central.');
$assert(is_file(dirname(__DIR__) . '/assets/img/smtt-logo-color.png'), 'Arquivo da marca SMTT deve existir no pacote.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL: " . count($failures) . " de {$tests} testes falharam.\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "OK: {$tests} testes passaram.\n";
