<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Service;

use SMTT\CIF\Validacao\Database\TransactionManager;
use SMTT\CIF\Validacao\Repository\PublicIssuanceRepository;
use SMTT\CIF\Validacao\Repository\SyncRepository;
use SMTT\CIF\Validacao\Sync\PayloadException;

final class SyncService
{
    public function __construct(
        private readonly PublicIssuanceRepository $issuances,
        private readonly SyncRepository $sync,
        private readonly TransactionManager $transactions
    ) {
    }

    /** @param array<string,mixed> $payload @return array{event_id:string,result:string} */
    public function applyEvent(array $payload, string $rawBody, string $requestId): array
    {
        return $this->transactions->run(function () use ($payload, $rawBody, $requestId): array {
            $eventId = (string)$payload['event_id'];
            $bodyHash = hash('sha256', $rawBody, true);
            $existing = $this->sync->event($eventId);
            if ($existing !== null) {
                if (!hash_equals($existing['body_hash'], $bodyHash)) {
                    throw new PayloadException('event_id já recebido com conteúdo diferente.', 'EVENT_ID_CONFLICT', 409);
                }
                return ['event_id' => $eventId, 'result' => 'DUPLICATE'];
            }

            $applied = 0;
            foreach ($payload['items'] as $item) {
                $current = $this->issuances->findForUpdate((string)$item['emission_id']);
                if ($current === null) {
                    $this->issuances->insert($item);
                    ++$applied;
                    continue;
                }

                $currentVersion = (int)$current['record_version'];
                $incomingVersion = (int)$item['record_version'];
                if ($incomingVersion < $currentVersion) {
                    continue;
                }
                if ($incomingVersion === $currentVersion) {
                    if (hash_equals((string)$current['projection_hash'], (string)$item['projection_hash_raw'])) {
                        continue;
                    }
                    if ($this->isAdmissionDateEnrichment($current, $item)) {
                        $this->issuances->update($item);
                        ++$applied;
                        continue;
                    }
                    throw new PayloadException('Mesma versão recebida com hash de projeção diferente.', 'VERSION_HASH_CONFLICT', 409);
                }

                $this->issuances->update($item);
                ++$applied;
            }

            $result = $applied > 0 ? 'APPLIED' : 'STALE_IGNORED';
            $this->sync->recordEvent(
                $eventId,
                (string)$payload['event_type'],
                (int)$payload['payload_version'],
                $bodyHash,
                $result,
                $requestId
            );
            return ['event_id' => $eventId, 'result' => $result];
        });
    }

    /** @param list<array{emission_id:string,desired_version:int,desired_hash:string}> $items @return list<array{emission_id:string,status:string}> */
    public function reconcile(array $items): array
    {
        $response = [];
        foreach ($items as $item) {
            $state = $this->issuances->reconcileState($item['emission_id']);
            if ($state === null) {
                $status = 'MISSING';
            } elseif ($state['record_version'] > $item['desired_version']) {
                $status = 'REMOTE_AHEAD';
            } elseif ($state['record_version'] < $item['desired_version']) {
                $status = 'VERSION_MISMATCH';
            } elseif (!hash_equals($state['projection_hash'], $item['desired_hash'])) {
                $status = 'HASH_MISMATCH';
            } else {
                $status = 'MATCH';
            }
            $response[] = ['emission_id' => $item['emission_id'], 'status' => $status];
        }
        return $response;
    }
    /** @param array<string,mixed> $current @param array<string,mixed> $item */
    private function isAdmissionDateEnrichment(array $current, array $item): bool
    {
        if (($current['admission_date'] ?? null) !== null && (string)$current['admission_date'] !== '') {
            return false;
        }
        if (($item['admission_date_db'] ?? null) === null || (string)$item['admission_date_db'] === '') {
            return false;
        }
        if (!hash_equals((string)$current['token_hash'], (string)$item['token_hash'])) {
            return false;
        }
        foreach ([
            'code' => 'code',
            'name' => 'name',
            'registration' => 'registration',
            'issued_at' => 'issued_at_db',
            'public_status' => 'public_status',
        ] as $currentKey => $itemKey) {
            if ((string)($current[$currentKey] ?? '') !== (string)($item[$itemKey] ?? '')) {
                return false;
            }
        }
        return true;
    }

}
