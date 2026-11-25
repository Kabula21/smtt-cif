<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;

final class BiometricRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{total:int,items:list<array<string,mixed>>}
     */
    public function searchAgents(array $filters, int $page, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(a.matricula LIKE ? OR b.nome_completo LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $biometryStatus = (string) ($filters['biometria'] ?? '');
        if ($biometryStatus === 'valida') {
            $conditions[] = 'COALESCE(c.coletas_validas, 0) > 0';
        } elseif ($biometryStatus === 'sem_valida') {
            $conditions[] = 'COALESCE(c.coletas_validas, 0) = 0';
        } elseif ($biometryStatus === 'sem_coleta') {
            $conditions[] = 'COALESCE(c.total_coletas, 0) = 0';
        } elseif ($biometryStatus === 'somente_invalidada') {
            $conditions[] = 'COALESCE(c.total_coletas, 0) > 0 AND COALESCE(c.coletas_validas, 0) = 0';
        }

        $functionalStatus = trim((string) ($filters['situacao'] ?? ''));
        if ($functionalStatus !== '') {
            $conditions[] = 'a.situacao_funcional_atual = ?';
            $params[] = $functionalStatus;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $base = <<<'SQL'
FROM agentes a
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
JOIN (
    SELECT agente_id, MAX(versao) AS versao
    FROM agentes_dados_biograficos
    GROUP BY agente_id
) latest ON latest.agente_id = b.agente_id AND latest.versao = b.versao
LEFT JOIN (
    SELECT
        agente_id,
        COUNT(*) AS total_coletas,
        SUM(CASE WHEN status = 'VALIDA' THEN 1 ELSE 0 END) AS coletas_validas,
        MAX(CASE WHEN status = 'VALIDA' THEN coletada_em ELSE NULL END) AS ultima_coleta_valida_em,
        MAX(coletada_em) AS ultima_coleta_em
    FROM coletas_biometricas
    GROUP BY agente_id
) c ON c.agente_id = a.id
SQL;

        $countStmt = $this->db->prepare('SELECT COUNT(*) AS total ' . $base . $where);
        $countStmt->execute($params);
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        $countResult->free();
        $countStmt->close();
        $total = (int) ($countRow['total'] ?? 0);

        $sql = <<<'SQL'
SELECT
    a.uuid, a.matricula, a.situacao_funcional_atual,
    b.nome_completo,
    COALESCE(c.total_coletas, 0) AS total_coletas,
    COALESCE(c.coletas_validas, 0) AS coletas_validas,
    c.ultima_coleta_valida_em,
    c.ultima_coleta_em
SQL;
        $sql .= ' ' . $base . $where . ' ORDER BY b.nome_completo ASC, a.matricula ASC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $result->free();
        $stmt->close();

        return ['total' => $total, 'items' => $items];
    }

    /** @return array<string,mixed>|null */
    public function agentIdentityByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT a.id, a.uuid, a.matricula, a.situacao_funcional_atual, b.nome_completo
FROM agentes a
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
JOIN (
    SELECT agente_id, MAX(versao) AS versao
    FROM agentes_dados_biograficos
    GROUP BY agente_id
) latest ON latest.agente_id = b.agente_id AND latest.versao = b.versao
WHERE a.uuid = ?
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

    /** @param array<string,mixed> $file */
    public function insertPrivateFile(array $file, int $actorId): int
    {
        $status = 'ATIVO';
        $encrypted = 1;
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO arquivos_privados (
    uuid, categoria, storage_backend, storage_key, nome_original, mime_type,
    tamanho_bytes, sha256, criptografado, key_id, status, criado_por, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'ssssssisissi',
            $file['uuid'],
            $file['categoria'],
            $file['storage_backend'],
            $file['storage_key'],
            $file['nome_original'],
            $file['mime_type'],
            $file['size'],
            $file['sha256'],
            $encrypted,
            $file['key_id'],
            $status,
            $actorId
        );
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function insertCollection(
        string $uuid,
        int $agentId,
        int $actorId,
        ?string $location,
        ?string $notes
    ): int {
        $status = 'VALIDA';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO coletas_biometricas (
    uuid, agente_id, coletada_em, reutilizavel_ate, operador_id, local_coleta,
    status, observacoes, criado_em
) VALUES (?, ?, UTC_TIMESTAMP(6), NULL, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('siisss', $uuid, $agentId, $actorId, $location, $status, $notes);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function insertBiometric(int $collectionId, string $type, int $fileId, array $data): int
    {
        $validation = json_encode($data['validation'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $conform = $data['conform'] ? 1 : 0;
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO biometrias (
    coleta_id, tipo, arquivo_id, dpi, dpi_origem, largura_px, altura_px,
    conforme_padrao, validacao, observacoes, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'isiisiiiss',
            $collectionId,
            $type,
            $fileId,
            $data['dpi'],
            $data['dpi_origin'],
            $data['width'],
            $data['height'],
            $conform,
            $validation,
            $data['notes']
        );
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function listCollections(int $agentId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT
    c.id, c.uuid, c.coletada_em, c.local_coleta, c.status, c.observacoes,
    c.invalidada_em, c.motivo_invalidacao,
    u.nome_snapshot AS operador_nome,
    bp.id AS foto_biometria_id, bp.dpi AS foto_dpi, bp.dpi_origem AS foto_dpi_origem,
    bp.largura_px AS foto_largura, bp.altura_px AS foto_altura, bp.conforme_padrao AS foto_conforme,
    fp.uuid AS foto_arquivo_uuid,
    bs.id AS assinatura_biometria_id, bs.dpi AS assinatura_dpi, bs.dpi_origem AS assinatura_dpi_origem,
    bs.largura_px AS assinatura_largura, bs.altura_px AS assinatura_altura, bs.conforme_padrao AS assinatura_conforme,
    fs.uuid AS assinatura_arquivo_uuid
FROM coletas_biometricas c
JOIN usuarios_cif u ON u.id = c.operador_id
LEFT JOIN biometrias bp ON bp.coleta_id = c.id AND bp.tipo = 'FOTO'
LEFT JOIN arquivos_privados fp ON fp.id = bp.arquivo_id
LEFT JOIN biometrias bs ON bs.coleta_id = c.id AND bs.tipo = 'ASSINATURA'
LEFT JOIN arquivos_privados fs ON fs.id = bs.arquivo_id
WHERE c.agente_id = ?
ORDER BY c.coletada_em DESC, c.id DESC
SQL);
        $stmt->bind_param('i', $agentId);
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

    /** @return array<string,mixed>|null */
    public function lockCollection(string $uuid, int $agentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM coletas_biometricas WHERE uuid = ? AND agente_id = ? FOR UPDATE');
        $stmt->bind_param('si', $uuid, $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function collectionUsedInEmission(int $collectionId): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT 1
FROM emissoes_cif_biometrias eb
JOIN biometrias b ON b.id = eb.biometria_id
WHERE b.coleta_id = ?
LIMIT 1
SQL);
        $stmt->bind_param('i', $collectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $used = $result->fetch_row() !== null;
        $result->free();
        $stmt->close();
        return $used;
    }

    public function invalidateCollection(int $collectionId, int $actorId, string $reason): void
    {
        $status = 'INVALIDADA';
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE coletas_biometricas
SET status = ?, invalidada_por = ?, invalidada_em = UTC_TIMESTAMP(6), motivo_invalidacao = ?
WHERE id = ?
SQL);
        $stmt->bind_param('sisi', $status, $actorId, $reason, $collectionId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function privateBiometricFileByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT f.id, f.uuid, f.categoria, f.storage_backend, f.storage_key, f.mime_type,
       f.tamanho_bytes, f.sha256, f.criptografado, f.key_id, f.status
FROM arquivos_privados f
WHERE f.uuid = ? AND f.categoria IN ('FOTO', 'ASSINATURA_AGENTE') AND f.status = 'ATIVO'
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
}
