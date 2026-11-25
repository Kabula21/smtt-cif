<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use JsonException;
use RuntimeException;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Service\SyncConfigService;

final class ReconciliationService
{
    public function __construct(
        private readonly SyncRepository $repository,
        private readonly EmissionRepository $emissions,
        private readonly SyncConfigService $config,
        private readonly SignedHttpClient $http
    ) {
    }

    /** @return array<string,int|string> */
    public function reconcile(int $batchSize = 200): array
    {
        if (!$this->config->enabled()) {
            throw new RuntimeException('A sincronização está desabilitada.');
        }
        $batchSize = min(max($batchSize, 10), 500);
        $run = $this->repository->startReconciliation();
        $checked = 0;
        $divergences = 0;
        $reenqueued = 0;
        $errors = 0;
        $offset = 0;
        $notes = [];
        try {
            while (true) {
                $items = $this->repository->projectionStates($batchSize, $offset);
                if ($items === []) {
                    break;
                }
                $body = json_encode([
                    'protocol_version' => MessageSigner::PROTOCOL_VERSION,
                    'request_type' => 'RECONCILE',
                    'source_time' => gmdate('Y-m-d\TH:i:s\Z'),
                    'items' => $items,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $response = $this->http->post($this->config->endpoint('reconcile'), $body, $this->config->environment(), 30);
                if ((int)($response['http_status'] ?? 0) !== 200) {
                    throw new RuntimeException('O portal não confirmou a reconciliação.');
                }
                try {
                    $decoded = json_decode((string)$response['body'], true, 128, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException('O portal retornou JSON inválido na reconciliação.', 0, $e);
                }
                if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
                    throw new RuntimeException('Resposta de reconciliação em formato inválido.');
                }
                $expected = [];
                foreach ($items as $item) {
                    $expected[$item['emission_id']] = $item;
                }
                $seen = [];
                foreach ($decoded['items'] as $remote) {
                    if (!is_array($remote) || empty($remote['emission_id'])) {
                        ++$errors;
                        continue;
                    }
                    $uuid = (string)$remote['emission_id'];
                    $local = $expected[$uuid] ?? null;
                    if ($local === null || isset($seen[$uuid])) {
                        ++$errors;
                        $notes[] = $local === null
                            ? 'Portal retornou emissão não solicitada: ' . $uuid
                            : 'Portal retornou emissão duplicada na reconciliação: ' . $uuid;
                        continue;
                    }
                    $seen[$uuid] = true;
                    ++$checked;
                    $status = strtoupper((string)($remote['status'] ?? ''));
                    if ($status === 'MATCH') {
                        continue;
                    }
                    ++$divergences;
                    if ($status === 'REMOTE_AHEAD') {
                        ++$errors;
                        $notes[] = 'Portal reportou versão superior para ' . $uuid;
                        continue;
                    }
                    if (in_array($status, ['MISSING', 'VERSION_MISMATCH', 'HASH_MISMATCH'], true)) {
                        $source = $this->emissions->publicProjectionSourceByUuid($uuid);
                        if ($source === null) {
                            ++$errors;
                            $notes[] = 'Emissão oficial não localizada durante a reconciliação: ' . $uuid;
                            continue;
                        }
                        $public = (string)$source['status_validade'] === 'ATIVA' ? 'VALIDA' : 'NAO_VALIDA';
                        $version = (int)$source['public_version'];
                        $this->emissions->upsertProjectionState($uuid, $version, $this->projectionHash($source, $version, $public));
                        $this->emissions->enqueueProjectionRefresh([[
                            'emission_uuid' => $uuid,
                            'version' => $version,
                            'public_status' => $public,
                        ]]);
                        ++$reenqueued;
                        continue;
                    }
                    ++$errors;
                    $notes[] = 'Portal retornou status de reconciliação inválido para ' . $uuid . ': ' . ($status !== '' ? $status : '(vazio)');
                }
                // Uma resposta parcial não é aceita silenciosamente. Cada item solicitado precisa
                // aparecer exatamente uma vez no ACK de reconciliação.
                foreach ($expected as $uuid => $_local) {
                    if (!isset($seen[$uuid])) {
                        ++$errors;
                        ++$divergences;
                        $notes[] = 'Portal omitiu a emissão solicitada na reconciliação: ' . $uuid;
                    }
                }
                $offset += count($items);
                if (count($items) < $batchSize) {
                    break;
                }
            }
            $status = $errors > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED';
            $this->repository->finishReconciliation((int)$run['id'], $status, $checked, $divergences, $reenqueued, $errors, ['notes' => array_slice($notes, 0, 50)]);
            return ['status' => $status, 'checked' => $checked, 'divergences' => $divergences, 'reenqueued' => $reenqueued, 'errors' => $errors];
        } catch (\Throwable $e) {
            $this->repository->finishReconciliation((int)$run['id'], 'FAILED', $checked, $divergences, $reenqueued, $errors + 1, ['error' => $e->getMessage(), 'notes' => array_slice($notes, 0, 50)]);
            throw $e;
        }
    }

    /**
     * Reconciliação automática fatiada. Verifica apenas uma janela por execução e
     * avança um cursor persistente, evitando varrer toda a base em cada tick do cron.
     *
     * @return array{status:string,checked:int,divergences:int,reenqueued:int,errors:int,next_cursor:?string,cycle_complete:bool}
     */
    public function reconcileAutomaticSlice(int $limit = 200): array
    {
        if (!$this->config->enabled()) {
            throw new RuntimeException('A sincronização está desabilitada.');
        }
        $limit = min(max($limit, 10), 500);
        $cursor = $this->repository->automaticReconciliationCursor();
        $items = $this->repository->projectionStatesAfter($cursor, $limit);

        if ($items === []) {
            // Chegamos ao fim do conjunto. O próximo ciclo começa novamente da
            // primeira emissão, sem executar uma requisição HTTP vazia.
            if ($cursor !== null) {
                $this->repository->setAutomaticReconciliationCursor(null);
            }
            return [
                'status' => 'NO_ITEMS', 'checked' => 0, 'divergences' => 0,
                'reenqueued' => 0, 'errors' => 0, 'next_cursor' => null,
                'cycle_complete' => true,
            ];
        }

        $run = $this->repository->startReconciliation();
        $checked = 0;
        $divergences = 0;
        $reenqueued = 0;
        $errors = 0;
        $notes = [];
        try {
            $body = json_encode([
                'protocol_version' => MessageSigner::PROTOCOL_VERSION,
                'request_type' => 'RECONCILE',
                'source_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'items' => $items,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = $this->http->post($this->config->endpoint('reconcile'), $body, $this->config->environment(), 30);
            if ((int)($response['http_status'] ?? 0) !== 200) {
                throw new RuntimeException('O portal não confirmou a reconciliação automática.');
            }
            try {
                $decoded = json_decode((string)$response['body'], true, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('O portal retornou JSON inválido na reconciliação automática.', 0, $e);
            }
            if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
                throw new RuntimeException('Resposta de reconciliação automática em formato inválido.');
            }

            $expected = [];
            foreach ($items as $item) {
                $expected[$item['emission_id']] = $item;
            }
            $seen = [];
            foreach ($decoded['items'] as $remote) {
                if (!is_array($remote) || empty($remote['emission_id'])) {
                    ++$errors;
                    continue;
                }
                $uuid = (string)$remote['emission_id'];
                $local = $expected[$uuid] ?? null;
                if ($local === null || isset($seen[$uuid])) {
                    ++$errors;
                    $notes[] = $local === null
                        ? 'Portal retornou emissão não solicitada: ' . $uuid
                        : 'Portal retornou emissão duplicada na reconciliação: ' . $uuid;
                    continue;
                }
                $seen[$uuid] = true;
                ++$checked;
                $status = strtoupper((string)($remote['status'] ?? ''));
                if ($status === 'MATCH') {
                    continue;
                }
                ++$divergences;
                if ($status === 'REMOTE_AHEAD') {
                    ++$errors;
                    $notes[] = 'Portal reportou versão superior para ' . $uuid;
                    continue;
                }
                if (in_array($status, ['MISSING', 'VERSION_MISMATCH', 'HASH_MISMATCH'], true)) {
                    $source = $this->emissions->publicProjectionSourceByUuid($uuid);
                    if ($source === null) {
                        ++$errors;
                        $notes[] = 'Emissão oficial não localizada durante a reconciliação: ' . $uuid;
                        continue;
                    }
                    $public = (string)$source['status_validade'] === 'ATIVA' ? 'VALIDA' : 'NAO_VALIDA';
                    $version = (int)$source['public_version'];
                    $this->emissions->upsertProjectionState($uuid, $version, $this->projectionHash($source, $version, $public));
                    $this->emissions->enqueueProjectionRefresh([[
                        'emission_uuid' => $uuid,
                        'version' => $version,
                        'public_status' => $public,
                    ]]);
                    ++$reenqueued;
                    continue;
                }
                ++$errors;
                $notes[] = 'Portal retornou status de reconciliação inválido para ' . $uuid . ': ' . ($status !== '' ? $status : '(vazio)');
            }
            foreach ($expected as $uuid => $_local) {
                if (!isset($seen[$uuid])) {
                    ++$errors;
                    ++$divergences;
                    $notes[] = 'Portal omitiu a emissão solicitada na reconciliação: ' . $uuid;
                }
            }

            $last = end($items);
            $cycleComplete = count($items) < $limit;
            $nextCursor = $cycleComplete || !is_array($last) ? null : (string)$last['emission_id'];
            $this->repository->setAutomaticReconciliationCursor($nextCursor);

            $status = $errors > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED';
            $this->repository->finishReconciliation((int)$run['id'], $status, $checked, $divergences, $reenqueued, $errors, [
                'automatic_slice' => true,
                'next_cursor' => $nextCursor,
                'cycle_complete' => $cycleComplete,
                'notes' => array_slice($notes, 0, 50),
            ]);
            return [
                'status' => $status, 'checked' => $checked, 'divergences' => $divergences,
                'reenqueued' => $reenqueued, 'errors' => $errors,
                'next_cursor' => $nextCursor, 'cycle_complete' => $cycleComplete,
            ];
        } catch (\Throwable $e) {
            $this->repository->finishReconciliation((int)$run['id'], 'FAILED', $checked, $divergences, $reenqueued, $errors + 1, [
                'automatic_slice' => true,
                'error' => $e->getMessage(),
                'notes' => array_slice($notes, 0, 50),
            ]);
            throw $e;
        }
    }

    public function rebuildPublicQueue(int $batchSize = 50): int
    {
        $batchSize = min(max($batchSize, 10), 200);
        $offset = 0;
        $enqueued = 0;
        while (true) {
            $states = $this->repository->projectionStates($batchSize, $offset);
            if ($states === []) {
                break;
            }
            $items = [];
            foreach ($states as $state) {
                $source = $this->emissions->publicProjectionSourceByUuid($state['emission_id']);
                if ($source === null) {
                    continue;
                }
                $version = (int)$source['public_version'];
                $public = (string)$source['status_validade'] === 'ATIVA' ? 'VALIDA' : 'NAO_VALIDA';
                $this->emissions->upsertProjectionState((string)$state['emission_id'], $version, $this->projectionHash($source, $version, $public));
                $items[] = [
                    'emission_uuid' => $state['emission_id'],
                    'version' => $version,
                    'public_status' => $public,
                ];
            }
            if ($items !== []) {
                $this->emissions->enqueueProjectionRefresh($items);
                $enqueued += count($items);
            }
            $offset += count($states);
            if (count($states) < $batchSize) {
                break;
            }
        }
        return $enqueued;
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
        return hash('sha256', $canonical, true);
    }

}
