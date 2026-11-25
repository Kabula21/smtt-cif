<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;

final class AgentImportRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function createBatch(
        string $uuid,
        string $filename,
        string $format,
        string $sha256,
        int $actorId
    ): int {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO importacoes_agentes (
    uuid, nome_arquivo, formato, arquivo_sha256, status,
    total_linhas, linhas_prontas, linhas_duplicadas, linhas_com_erro, linhas_importadas,
    criado_por, criado_em
) VALUES (?, ?, ?, ?, 'PREVIEW', 0, 0, 0, 0, 0, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('ssssi', $uuid, $filename, $format, $sha256, $actorId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string,mixed>|null $data */
    public function addItem(
        int $batchId,
        int $line,
        string $status,
        ?string $matricula,
        ?string $cpfLookup,
        ?string $cpfCiphertext,
        ?string $cpfKeyId,
        ?array $data,
        ?string $errorCode,
        ?string $errorMessage
    ): int {
        $json = $data === null
            ? null
            : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO importacoes_agentes_itens (
    importacao_id, numero_linha, status, matricula,
    cpf_lookup_hmac, cpf_ciphertext, cpf_key_id, dados_json,
    codigo_erro, mensagem_erro, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'iissssssss',
            $batchId,
            $line,
            $status,
            $matricula,
            $cpfLookup,
            $cpfCiphertext,
            $cpfKeyId,
            $json,
            $errorCode,
            $errorMessage
        );
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function updatePreviewCounts(int $batchId, int $total, int $ready, int $duplicates, int $errors): void
    {
        $stmt = $this->db->prepare(
            'UPDATE importacoes_agentes SET total_linhas = ?, linhas_prontas = ?, linhas_duplicadas = ?, linhas_com_erro = ? WHERE id = ?'
        );
        $stmt->bind_param('iiiii', $total, $ready, $duplicates, $errors, $batchId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function findBatchByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT i.*, u.login_snapshot AS criado_por_login, u.nome_snapshot AS criado_por_nome
FROM importacoes_agentes i
JOIN usuarios_cif u ON u.id = i.criado_por
WHERE i.uuid = ?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listItems(int $batchId, int $limit = 5000): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT id, numero_linha, status, matricula, dados_json, codigo_erro, mensagem_erro, agente_id, criado_em, processado_em
FROM importacoes_agentes_itens
WHERE importacao_id = ?
ORDER BY numero_linha ASC
LIMIT ?
SQL);
        $stmt->bind_param('ii', $batchId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $decoded = [];
            if (is_string($row['dados_json']) && $row['dados_json'] !== '') {
                $decoded = json_decode($row['dados_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            unset($row['dados_json']);
            $row['dados'] = is_array($decoded) ? $decoded : [];
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function readyItems(int $batchId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT id, numero_linha, status, matricula, cpf_lookup_hmac, cpf_ciphertext, cpf_key_id, dados_json
FROM importacoes_agentes_itens
WHERE importacao_id = ? AND (status = 'READY' OR (status = 'PROCESSING' AND processado_em < (UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE)))
ORDER BY numero_linha ASC
SQL);
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $decoded = json_decode((string) $row['dados_json'], true, 512, JSON_THROW_ON_ERROR);
            $row['dados'] = is_array($decoded) ? $decoded : [];
            unset($row['dados_json']);
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return $rows;
    }

    public function lockBatchByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM importacoes_agentes WHERE uuid = ? FOR UPDATE');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function markConfirmed(int $batchId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE importacoes_agentes SET status = 'PROCESSING', confirmado_em = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'PREVIEW'"
        );
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $stmt->close();
    }

    public function claimItem(int $itemId): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE importacoes_agentes_itens
SET status = 'PROCESSING', processado_em = UTC_TIMESTAMP(6)
WHERE id = ? AND (status = 'READY' OR (status = 'PROCESSING' AND processado_em < (UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE)))
SQL);
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }

    public function markItemImported(int $itemId, int $agentId): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE importacoes_agentes_itens
SET status = 'IMPORTED', agente_id = ?, processado_em = UTC_TIMESTAMP(6),
    cpf_ciphertext = NULL, cpf_key_id = NULL, mensagem_erro = NULL, codigo_erro = NULL
WHERE id = ?
SQL);
        $stmt->bind_param('ii', $agentId, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    public function markItemConflict(int $itemId, string $code, string $message): void
    {
        $status = 'CONFLICT';
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE importacoes_agentes_itens
SET status = ?, codigo_erro = ?, mensagem_erro = ?, processado_em = UTC_TIMESTAMP(6),
    cpf_ciphertext = NULL, cpf_key_id = NULL
WHERE id = ?
SQL);
        $stmt->bind_param('sssi', $status, $code, $message, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    public function markItemError(int $itemId, string $code, string $message): void
    {
        $status = 'ERROR';
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE importacoes_agentes_itens
SET status = ?, codigo_erro = ?, mensagem_erro = ?, processado_em = UTC_TIMESTAMP(6),
    cpf_ciphertext = NULL, cpf_key_id = NULL
WHERE id = ?
SQL);
        $stmt->bind_param('sssi', $status, $code, $message, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    public function completeBatch(int $batchId): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE importacoes_agentes i
SET
    linhas_importadas = (SELECT COUNT(*) FROM importacoes_agentes_itens x WHERE x.importacao_id = i.id AND x.status = 'IMPORTED'),
    linhas_duplicadas = (SELECT COUNT(*) FROM importacoes_agentes_itens x WHERE x.importacao_id = i.id AND x.status IN ('DUPLICATE_DB','DUPLICATE_FILE','CONFLICT')),
    linhas_com_erro = (SELECT COUNT(*) FROM importacoes_agentes_itens x WHERE x.importacao_id = i.id AND x.status = 'ERROR'),
    status = CASE
        WHEN EXISTS (SELECT 1 FROM importacoes_agentes_itens x WHERE x.importacao_id = i.id AND x.status IN ('READY','PROCESSING'))
            THEN 'PROCESSING'
        WHEN EXISTS (SELECT 1 FROM importacoes_agentes_itens x WHERE x.importacao_id = i.id AND x.status IN ('ERROR','CONFLICT'))
            THEN 'COMPLETED_WITH_ISSUES'
        ELSE 'COMPLETED'
    END,
    concluido_em = CASE
        WHEN EXISTS (SELECT 1 FROM importacoes_agentes_itens x WHERE x.importacao_id = i.id AND x.status IN ('READY','PROCESSING'))
            THEN NULL
        ELSE UTC_TIMESTAMP(6)
    END
WHERE i.id = ?
SQL);
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return list<array<string,mixed>> */
    public function recentBatches(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(<<<'SQL'
SELECT i.*, u.nome_snapshot AS criado_por_nome
FROM importacoes_agentes i
JOIN usuarios_cif u ON u.id = i.criado_por
ORDER BY i.criado_em DESC, i.id DESC
LIMIT ?
SQL);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return $rows;
    }
}
