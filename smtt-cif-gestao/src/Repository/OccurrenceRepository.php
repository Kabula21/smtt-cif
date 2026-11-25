<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;

final class OccurrenceRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @param array<string,string> $filters @return array{items:list<array<string,mixed>>,total:int} */
    public function searchOccurrences(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $values = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.nome_completo LIKE ? OR s.matricula LIKE ? OR e.codigo_validacao LIKE ?)';
            $needle = '%' . $q . '%';
            array_push($values, $needle, $needle, $needle);
        }
        $type = strtoupper(trim((string)($filters['tipo'] ?? '')));
        if ($type !== '') {
            $where[] = 'o.tipo_codigo = ?';
            $values[] = $type;
        }
        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $values[] = $status;
        }

        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $joins = ' FROM ocorrencias_cif o '
            . 'JOIN emissoes_cif e ON e.id=o.emissao_id '
            . 'JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id '
            . 'JOIN emissoes_cif_estado es ON es.emissao_id=e.id ';
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countJoins = ' FROM ocorrencias_cif o ';
        if ($q !== '') {
            $countJoins .= 'JOIN emissoes_cif e ON e.id=o.emissao_id JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id ';
        }
        $count = $this->db->prepare('SELECT COUNT(*) AS total' . $countJoins . $whereSql);
        $count->execute($values);
        $countRow = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int)($countRow['total'] ?? 0);

        $sortMap = [
            'nome' => 's.nome_completo',
            'codigo' => 'e.codigo_validacao',
            'tipo' => 'o.tipo_codigo',
            'status' => 'o.status',
            'comunicacao' => 'o.data_comunicacao',
        ];
        $sort = $sortMap[(string)($filters['ordenar'] ?? 'comunicacao')] ?? 'o.data_comunicacao';
        $direction = strtoupper((string)($filters['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sql = 'SELECT o.*, e.uuid AS emissao_uuid, e.codigo_validacao, s.nome_completo, s.matricula, '
            . 'es.status_validade, es.status_fisico '
            . $joins . $whereSql
            . ' ORDER BY ' . $sort . ' ' . $direction . ', o.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return ['items' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function occurrenceByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT o.*, e.uuid AS emissao_uuid, e.codigo_validacao, e.processo_id AS emissao_processo_id,
       p.uuid AS processo_uuid,
       s.nome_completo, s.matricula, a.uuid AS agente_uuid,
       es.status_validade, es.status_fisico, es.public_version,
       u.nome_snapshot AS registrado_por_nome
FROM ocorrencias_cif o
JOIN emissoes_cif e ON e.id=o.emissao_id
LEFT JOIN processos_emissao p ON p.id=o.processo_id
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
JOIN agentes a ON a.id=o.agente_id
JOIN usuarios_cif u ON u.id=o.registrado_por
WHERE o.uuid=?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockOccurrenceByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT o.*, e.uuid AS emissao_uuid, e.codigo_validacao, e.processo_id AS emissao_processo_id,
       es.status_validade, es.status_fisico, es.public_version,
       s.nome_completo, s.matricula
FROM ocorrencias_cif o
JOIN emissoes_cif e ON e.id=o.emissao_id
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
WHERE o.uuid=?
LIMIT 1
FOR UPDATE
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function hasOpenExtravio(int $emissionId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM ocorrencias_cif WHERE emissao_id=? AND tipo_codigo IN ('PERDA','FURTO','ROUBO') AND status='ABERTA' LIMIT 1");
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    }

    /** @param array<string,mixed> $data */
    public function insertOccurrence(array $data): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO ocorrencias_cif (
    uuid, agente_id, emissao_id, processo_id, tipo_codigo, status,
    data_fato, data_comunicacao, bo_numero, bo_data, bo_orgao,
    recuperada_em, motivo_encerramento, encerrada_em, detalhes,
    registrado_por, registrado_em, atualizado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'siiissssssssssi',
            $data['uuid'], $data['agente_id'], $data['emissao_id'], $data['processo_id'],
            $data['tipo_codigo'], $data['status'], $data['data_fato'], $data['data_comunicacao'],
            $data['bo_numero'], $data['bo_data'], $data['bo_orgao'], $data['motivo_encerramento'],
            $data['encerrada_em'], $data['detalhes'], $data['registrado_por']
        );
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function markRecovered(int $id, string $status, string $reason): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE ocorrencias_cif
SET status=?, recuperada_em=UTC_TIMESTAMP(6), motivo_encerramento=?, encerrada_em=UTC_TIMESTAMP(6), atualizado_em=UTC_TIMESTAMP(6)
WHERE id=? AND status='ABERTA'
SQL);
        $stmt->bind_param('ssi', $status, $reason, $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('A ocorrência já foi encerrada ou alterada por outro usuário.');
        }
        $stmt->close();
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $occurrenceId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT od.tipo_documento, od.incluido_em,
       f.uuid AS arquivo_uuid, f.nome_original, f.mime_type, f.tamanho_bytes,
       u.nome_snapshot AS incluido_por_nome
FROM ocorrencias_documentos od
JOIN arquivos_privados f ON f.id=od.arquivo_id
JOIN usuarios_cif u ON u.id=od.incluido_por
WHERE od.ocorrencia_id=?
ORDER BY od.incluido_em DESC
SQL);
        $stmt->bind_param('i', $occurrenceId);
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

    /** @param array<string,mixed> $file */
    public function insertPrivateFile(array $file, int $actorId): int
    {
        $encrypted = 1;
        $status = 'ATIVO';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO arquivos_privados (
    uuid, categoria, storage_backend, storage_key, nome_original, mime_type,
    tamanho_bytes, sha256, criptografado, key_id, status, criado_por, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'ssssssisissi',
            $file['uuid'], $file['categoria'], $file['storage_backend'], $file['storage_key'],
            $file['nome_original'], $file['mime_type'], $file['size'], $file['sha256'],
            $encrypted, $file['key_id'], $status, $actorId
        );
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function insertOccurrenceDocument(int $occurrenceId, int $fileId, string $type, int $actorId): void
    {
        $stmt = $this->db->prepare('INSERT INTO ocorrencias_documentos (ocorrencia_id, arquivo_id, tipo_documento, incluido_por, incluido_em) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $stmt->bind_param('iisi', $occurrenceId, $fileId, $type, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function privateFileByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT f.*
FROM arquivos_privados f
JOIN ocorrencias_documentos od ON od.arquivo_id=f.id
WHERE f.uuid=?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,string> $filters @return array{items:list<array<string,mixed>>,total:int} */
    public function searchRecollections(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $values = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.nome_completo LIKE ? OR s.matricula LIKE ? OR e.codigo_validacao LIKE ?)';
            $needle = '%' . $q . '%';
            array_push($values, $needle, $needle, $needle);
        }
        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'r.status=?';
            $values[] = $status;
        }
        $reason = strtoupper(trim((string)($filters['motivo'] ?? '')));
        if ($reason !== '') {
            $where[] = 'r.motivo_codigo=?';
            $values[] = $reason;
        }

        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $joins = ' FROM recolhimentos_cif r '
            . 'JOIN emissoes_cif e ON e.id=r.emissao_id '
            . 'JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id '
            . 'JOIN emissoes_cif_estado es ON es.emissao_id=e.id ';
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countJoins = ' FROM recolhimentos_cif r ';
        if ($q !== '') {
            $countJoins .= 'JOIN emissoes_cif e ON e.id=r.emissao_id JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id ';
        }
        $count = $this->db->prepare('SELECT COUNT(*) AS total' . $countJoins . $whereSql);
        $count->execute($values);
        $countRow = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int)($countRow['total'] ?? 0);

        $sortMap = [
            'nome' => 's.nome_completo',
            'codigo' => 'e.codigo_validacao',
            'motivo' => 'r.motivo_codigo',
            'status' => 'r.status',
            'criado' => 'r.criado_em',
            'prazo' => 'r.inutilizacao_permitida_em',
        ];
        $sort = $sortMap[(string)($filters['ordenar'] ?? 'criado')] ?? 'r.criado_em';
        $direction = strtoupper((string)($filters['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sql = 'SELECT r.*, e.uuid AS emissao_uuid, e.codigo_validacao, s.nome_completo, s.matricula, '
            . 'es.status_validade, es.status_fisico '
            . $joins . $whereSql
            . ' ORDER BY ' . $sort . ' ' . $direction . ', r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return ['items' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function recollectionByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT r.*, e.uuid AS emissao_uuid, e.codigo_validacao, e.agente_id,
       s.nome_completo, s.matricula, a.uuid AS agente_uuid,
       es.status_validade, es.status_fisico,
       ur.nome_snapshot AS registrado_por_nome,
       uc.nome_snapshot AS recolhida_por_nome,
       ui.nome_snapshot AS inutilizada_por_nome,
       un.nome_snapshot AS nao_recolhimento_por_nome
FROM recolhimentos_cif r
JOIN emissoes_cif e ON e.id=r.emissao_id
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
JOIN agentes a ON a.id=e.agente_id
JOIN usuarios_cif ur ON ur.id=r.registrado_por
LEFT JOIN usuarios_cif uc ON uc.id=r.recolhida_por
LEFT JOIN usuarios_cif ui ON ui.id=r.inutilizada_por
LEFT JOIN usuarios_cif un ON un.id=r.nao_recolhimento_registrado_por
WHERE r.uuid=?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockRecollectionByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM recolhimentos_cif WHERE uuid=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function hasOpenRecollection(int $emissionId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM recolhimentos_cif WHERE emissao_id=? AND status IN ('PENDENTE','RECOLHIDA') LIMIT 1");
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    }

    /** @param array<string,mixed> $data */
    public function insertRecollection(array $data): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO recolhimentos_cif (
    uuid, emissao_id, processo_id, motivo_codigo, fato_em,
    recolhimento_solicitado_em, recolhimento_devido_em, recolhida_em,
    inutilizacao_permitida_em, inutilizada_em, status, motivo_nao_recolhimento,
    referencia, observacoes, registrado_por, recolhida_por, inutilizada_por,
    nao_recolhimento_registrado_por, criado_em, atualizado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, NULL, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'siisssssssssii',
            $data['uuid'], $data['emissao_id'], $data['processo_id'], $data['motivo_codigo'],
            $data['fato_em'], $data['recolhimento_solicitado_em'], $data['recolhimento_devido_em'],
            $data['recolhida_em'], $data['inutilizacao_permitida_em'], $data['status'],
            $data['referencia'], $data['observacoes'], $data['registrado_por'], $data['recolhida_por']
        );
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function markCollected(int $id, int $actorId, ?string $reference, ?string $observations, string $invalidationAllowedAt): void
    {
        $status = 'RECOLHIDA';
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE recolhimentos_cif
SET status=?, recolhida_em=UTC_TIMESTAMP(6), recolhida_por=?, referencia=COALESCE(?, referencia),
    observacoes=COALESCE(?, observacoes), inutilizacao_permitida_em=?, atualizado_em=UTC_TIMESTAMP(6)
WHERE id=? AND status='PENDENTE'
SQL);
        $stmt->bind_param('sisssi', $status, $actorId, $reference, $observations, $invalidationAllowedAt, $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('O recolhimento já foi alterado por outro usuário.');
        }
        $stmt->close();
    }

    public function markNotCollected(int $id, int $actorId, string $reason): void
    {
        $status = 'NAO_RECOLHIDA_JUSTIFICADA';
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE recolhimentos_cif
SET status=?, motivo_nao_recolhimento=?, nao_recolhimento_registrado_por=?, atualizado_em=UTC_TIMESTAMP(6)
WHERE id=? AND status='PENDENTE'
SQL);
        $stmt->bind_param('ssii', $status, $reason, $actorId, $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('O recolhimento já foi alterado por outro usuário.');
        }
        $stmt->close();
    }

    public function markInvalidated(int $id, int $actorId, ?string $observations): void
    {
        $status = 'INUTILIZADA';
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE recolhimentos_cif
SET status=?, inutilizada_em=UTC_TIMESTAMP(6), inutilizada_por=?, observacoes=COALESCE(?, observacoes), atualizado_em=UTC_TIMESTAMP(6)
WHERE id=? AND status='RECOLHIDA'
SQL);
        $stmt->bind_param('sisi', $status, $actorId, $observations, $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('O recolhimento já foi alterado por outro usuário.');
        }
        $stmt->close();
    }
}
