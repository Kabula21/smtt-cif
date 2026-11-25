<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;

final class AuditRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @param array<string,string> $filters @return array{total:int,items:list<array<string,mixed>>} */
    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.operacao LIKE ? OR a.entidade_tipo LIKE ? OR a.entidade_uuid LIKE ? OR a.actor_login LIKE ? OR a.actor_nome LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $operation = trim((string)($filters['operacao'] ?? ''));
        if ($operation !== '') {
            $where[] = 'a.operacao = ?';
            $params[] = $operation;
        }
        $entity = trim((string)($filters['entidade'] ?? ''));
        if ($entity !== '') {
            $where[] = 'a.entidade_tipo = ?';
            $params[] = $entity;
        }
        $from = trim((string)($filters['de'] ?? ''));
        if ($from !== '') {
            $where[] = 'a.ocorrido_em >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = trim((string)($filters['ate'] ?? ''));
        if ($to !== '') {
            $where[] = 'a.ocorrido_em < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $to . ' 00:00:00';
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) AS total FROM auditoria a' . $whereSql);
        $count->execute($params);
        $row = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int)($row['total'] ?? 0);

        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            'SELECT a.id, a.uuid, a.ocorrido_em, a.actor_login, a.actor_nome, a.operacao, a.entidade_tipo, '
            . 'a.entidade_id, a.entidade_uuid, a.valor_anterior, a.valor_posterior, a.justificativa, '
            . 'a.origem, a.request_id, a.previous_hash, a.record_hash, INET6_NTOA(a.ip_origem) AS ip_origem_texto '
            . 'FROM auditoria a' . $whereSql . ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $result = $stmt->get_result();
        $items = [];
        while ($item = $result->fetch_assoc()) {
            $items[] = $item;
        }
        $result->free();
        $stmt->close();
        return ['total' => $total, 'items' => $items];
    }

    /** @return list<string> */
    public function operations(): array
    {
        $result = $this->db->query('SELECT DISTINCT operacao FROM auditoria ORDER BY operacao');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = (string)$row['operacao'];
        }
        $result->free();
        return $rows;
    }

    /** @return list<string> */
    public function entityTypes(): array
    {
        $result = $this->db->query('SELECT DISTINCT entidade_tipo FROM auditoria ORDER BY entidade_tipo');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = (string)$row['entidade_tipo'];
        }
        $result->free();
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function chainAnchor(): ?array
    {
        try {
            $result = $this->db->query('SELECT * FROM auditoria_chain_anchor WHERE id=1 LIMIT 1');
        } catch (\Throwable) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function chainedRecords(int $afterId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT id, uuid, DATE_FORMAT(ocorrido_em, '%Y-%m-%d %H:%i:%s.%f') AS ocorrido_em,
       usuario_cif_id, actor_wp_user_id, actor_login, actor_nome,
       operacao, entidade_tipo, entidade_id, entidade_uuid,
       CAST(valor_anterior AS CHAR) AS valor_anterior,
       CAST(valor_posterior AS CHAR) AS valor_posterior,
       justificativa, ip_origem, user_agent, origem, request_id,
       previous_hash, record_hash
FROM auditoria
WHERE id > ?
ORDER BY id ASC
SQL);
        $stmt->bind_param('i', $afterId);
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
