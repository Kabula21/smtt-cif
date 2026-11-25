<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use JsonException;
use mysqli;

final class IssuanceProcessRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @param array<string,mixed> $filters @return array{total:int,items:list<array<string,mixed>>} */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $conditions = ['newer.id IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(a.matricula LIKE ? OR b.nome_completo LIKE ? OR p.numero_protocolo LIKE ? OR p.protocolo_externo LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        foreach (['tipo' => 'p.tipo_codigo', 'status' => 'p.status_codigo'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $conditions[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        if (!empty($filters['criado'])) {
            $conditions[] = 'p.criado_em >= ? AND p.criado_em < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = (string) $filters['criado'] . ' 00:00:00';
            $params[] = (string) $filters['criado'] . ' 00:00:00';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $base = <<<'SQL'
FROM processos_emissao p
JOIN agentes a ON a.id = p.agente_id
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
LEFT JOIN agentes_dados_biograficos newer ON newer.agente_id = b.agente_id AND newer.versao > b.versao
SQL;

        if ($q === '') {
            $countConditions = array_values(array_filter($conditions, static fn(string $condition): bool => $condition !== 'newer.id IS NULL'));
            $countWhere = $countConditions === [] ? '' : ' WHERE ' . implode(' AND ', $countConditions);
            $countSql = 'SELECT COUNT(*) AS total FROM processos_emissao p' . $countWhere;
        } else {
            $countSql = 'SELECT COUNT(*) AS total ' . $base . $where;
        }
        $count = $this->db->prepare($countSql);
        $count->execute($params);
        $row = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int) ($row['total'] ?? 0);

        $orderMap = [
            'criado' => 'p.criado_em',
            'nome' => 'b.nome_completo',
            'matricula' => 'a.matricula',
            'status' => 'p.status_codigo',
            'tipo' => 'p.tipo_codigo',
            'protocolo' => 'p.numero_protocolo',
        ];
        $order = $orderMap[(string) ($filters['ordenar'] ?? 'criado')] ?? 'p.criado_em';
        $direction = strtoupper((string) ($filters['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $sql = <<<'SQL'
SELECT
    p.id, p.uuid, p.ciclo_numero, p.tipo_codigo, p.status_codigo, p.numero_protocolo, p.protocolo_externo,
    p.criado_em, p.atualizado_em, p.elegivel_emissao_em,
    a.uuid AS agente_uuid, a.matricula, a.situacao_funcional_atual,
    b.nome_completo
SQL;
        $sql .= ' ' . $base . $where . ' ORDER BY ' . $order . ' ' . $direction . ', p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
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

    public function countProcesses(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM processos_emissao');
        $row = $result->fetch_assoc();
        $result->free();
        return (int) ($row['total'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function agentByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT a.id, a.uuid, a.matricula, a.ativo, a.situacao_funcional_atual,
       b.id AS dados_biograficos_id, b.versao AS dados_versao, b.nome_completo, b.matricula_snapshot AS matricula_biografica,
       b.naturalidade, b.data_nascimento, b.tipo_sanguineo, b.data_admissao
FROM agentes a
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
LEFT JOIN agentes_dados_biograficos newer ON newer.agente_id = b.agente_id AND newer.versao > b.versao
WHERE a.uuid = ? AND newer.id IS NULL
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockOccurrenceForProcess(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT o.id, o.uuid, o.agente_id, o.processo_id, o.tipo_codigo,
       o.data_fato, o.data_comunicacao, o.bo_numero, o.bo_data, o.bo_orgao,
       o.status, e.uuid AS emissao_uuid
FROM ocorrencias_cif o
JOIN emissoes_cif e ON e.id = o.emissao_id
WHERE o.uuid = ?
LIMIT 1
FOR UPDATE
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function linkOccurrenceToProcess(int $occurrenceId, int $processId): void
    {
        $stmt = $this->db->prepare('UPDATE ocorrencias_cif SET processo_id = ?, atualizado_em = UTC_TIMESTAMP(6) WHERE id = ? AND processo_id IS NULL');
        $stmt->bind_param('ii', $processId, $occurrenceId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('A ocorrência já foi vinculada a outro processo.');
        }
        $stmt->close();
    }

    public function copyOccurrenceDocumentsToProcess(int $occurrenceId, int $processId, int $actorId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT IGNORE INTO processos_documentos (
    processo_id, arquivo_id, tipo_documento, numero_documento, emitido_em, incluido_por, incluido_em
)
SELECT ?, od.arquivo_id,
       CASE WHEN od.tipo_documento = 'BO' THEN 'BO' ELSE 'OUTRO' END,
       CASE WHEN od.tipo_documento = 'BO' THEN o.bo_numero ELSE NULL END,
       CASE WHEN od.tipo_documento = 'BO' THEN o.bo_data ELSE NULL END,
       ?, UTC_TIMESTAMP(6)
FROM ocorrencias_documentos od
JOIN ocorrencias_cif o ON o.id = od.ocorrencia_id
WHERE od.ocorrencia_id = ?
SQL);
        $stmt->bind_param('iii', $processId, $actorId, $occurrenceId);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return max(0, $count);
    }

    /**
     * Reserva o próximo ciclo individual do agente sob lock transacional.
     * Cada novo processo de emissão incrementa o ciclo daquele agente; ocorrências
     * e recolhimentos não alteram este contador.
     */
    public function nextAgentCycle(int $agentId): int
    {
        $stmt = $this->db->prepare('SELECT ultimo_ciclo FROM controle_ciclo_agente WHERE agente_id = ? FOR UPDATE');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            $zero = 0;
            $insert = $this->db->prepare('INSERT IGNORE INTO controle_ciclo_agente (agente_id, ultimo_ciclo, atualizado_em, lock_version) VALUES (?, ?, UTC_TIMESTAMP(6), 0)');
            $insert->bind_param('ii', $agentId, $zero);
            $insert->execute();
            $insert->close();

            $stmt = $this->db->prepare('SELECT ultimo_ciclo FROM controle_ciclo_agente WHERE agente_id = ? FOR UPDATE');
            $stmt->bind_param('i', $agentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!is_array($row)) {
            throw new \RuntimeException('Controle de ciclo individual do agente não pôde ser inicializado.');
        }

        $next = (int) $row['ultimo_ciclo'] + 1;
        if ($next > 99) {
            throw new \RuntimeException('O agente atingiu o limite de 99 ciclos de emissão previsto no código CIF.');
        }
        $update = $this->db->prepare('UPDATE controle_ciclo_agente SET ultimo_ciclo = ?, atualizado_em = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE agente_id = ?');
        $update->bind_param('ii', $next, $agentId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new \RuntimeException('Não foi possível reservar o ciclo individual do agente.');
        }
        $update->close();
        return $next;
    }

    /**
     * Reserva o próximo sequencial global de processo sob lock transacional.
     * O chamador deve executar dentro de TransactionManager::run().
     */
    public function nextProtocolSequence(): int
    {
        $result = $this->db->query('SELECT ultimo_sequencial FROM controle_numeracao_processos WHERE id = 1 FOR UPDATE');
        $row = $result->fetch_assoc();
        $result->free();

        if (!is_array($row)) {
            $this->db->query("INSERT IGNORE INTO controle_numeracao_processos (id, ultimo_sequencial, atualizado_em, lock_version) VALUES (1, 0, UTC_TIMESTAMP(6), 0)");
            $result = $this->db->query('SELECT ultimo_sequencial FROM controle_numeracao_processos WHERE id = 1 FOR UPDATE');
            $row = $result->fetch_assoc();
            $result->free();
        }
        if (!is_array($row)) {
            throw new \RuntimeException('Controle de numeração dos processos CIF não localizado.');
        }

        $next = (int) $row['ultimo_sequencial'] + 1;
        $stmt = $this->db->prepare('UPDATE controle_numeracao_processos SET ultimo_sequencial = ?, atualizado_em = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = 1');
        $stmt->bind_param('i', $next);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('Não foi possível reservar o número do processo CIF.');
        }
        $stmt->close();
        return $next;
    }

    /** @param array<string,mixed> $data */
    public function insertProcess(array $data): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO processos_emissao (
    uuid, agente_id, ciclo_numero, tipo_codigo, status_codigo, numero_protocolo, protocolo_externo, justificativa,
    fato_em, comunicado_em, elegivel_emissao_em, criado_por, criado_em,
    atualizado_em, lock_version
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), 0)
SQL);
        $stmt->bind_param(
            'siissssssssi',
            $data['uuid'],
            $data['agente_id'],
            $data['ciclo_numero'],
            $data['tipo_codigo'],
            $data['status_codigo'],
            $data['numero_protocolo'],
            $data['protocolo_externo'],
            $data['justificativa'],
            $data['fato_em'],
            $data['comunicado_em'],
            $data['elegivel_emissao_em'],
            $data['criado_por']
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function lockByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM processos_emissao WHERE uuid = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function updateProcess(int $id, int $expectedLockVersion, array $data): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE processos_emissao
SET tipo_codigo = ?, protocolo_externo = ?, justificativa = ?, fato_em = ?, comunicado_em = ?,
    elegivel_emissao_em = ?, atualizado_em = UTC_TIMESTAMP(6), lock_version = lock_version + 1
WHERE id = ? AND lock_version = ?
SQL);
        $stmt->bind_param(
            'ssssssii',
            $data['tipo_codigo'],
            $data['protocolo_externo'],
            $data['justificativa'],
            $data['fato_em'],
            $data['comunicado_em'],
            $data['elegivel_emissao_em'],
            $id,
            $expectedLockVersion
        );
        $stmt->execute();
        $ok = $stmt->affected_rows === 1;
        $stmt->close();
        return $ok;
    }

    public function changeStatus(
        int $id,
        string $status,
        int $expectedLockVersion,
        ?int $approvedBy = null,
        bool $setApproved = false,
        ?int $cancelledBy = null,
        bool $setCancelled = false
    ): bool {
        $approvedSql = $setApproved ? ', aprovado_por = ?, aprovado_em = UTC_TIMESTAMP(6)' : '';
        $cancelledSql = $setCancelled ? ', cancelado_por = ?, cancelado_em = UTC_TIMESTAMP(6)' : '';
        $sql = 'UPDATE processos_emissao SET status_codigo = ?, atualizado_em = UTC_TIMESTAMP(6), lock_version = lock_version + 1' . $approvedSql . $cancelledSql . ' WHERE id = ? AND lock_version = ?';
        $stmt = $this->db->prepare($sql);
        $params = [$status];
        if ($setApproved) {
            $params[] = $approvedBy;
        }
        if ($setCancelled) {
            $params[] = $cancelledBy;
        }
        $params[] = $id;
        $params[] = $expectedLockVersion;
        $stmt->execute($params);
        $ok = $stmt->affected_rows === 1;
        $stmt->close();
        return $ok;
    }

    /** @param array<string,mixed>|null $data */
    public function insertEvent(int $processId, string $uuid, string $type, ?string $before, ?string $after, ?array $data, ?int $userId): void
    {
        $json = $data === null ? null : $this->json($data);
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO processos_eventos (
    uuid, processo_id, tipo_evento, estado_anterior, estado_novo, dados, usuario_id, ocorrido_em
) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('sissssi', $uuid, $processId, $type, $before, $after, $json, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function insertValidation(int $agentId, int $bioId, int $processId, string $method, int $actorId, ?string $observations): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO validacoes_dados_biograficos (
    agente_id, dados_biograficos_id, processo_emissao_id, validado_pelo_titular,
    metodo, validado_em, registrado_por, termo_arquivo_id, observacoes
) VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP(6), ?, NULL, ?)
SQL);
        $stmt->bind_param('iiisis', $agentId, $bioId, $processId, $method, $actorId, $observations);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function currentValidation(int $processId, int $currentBioId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT v.*, u.nome_snapshot AS registrado_por_nome
FROM validacoes_dados_biograficos v
JOIN usuarios_cif u ON u.id = v.registrado_por
WHERE v.processo_emissao_id = ? AND v.dados_biograficos_id = ? AND v.validado_pelo_titular = 1
ORDER BY v.validado_em DESC, v.id DESC
LIMIT 1
SQL);
        $stmt->bind_param('ii', $processId, $currentBioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function hasValidBiometry(int $agentId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM coletas_biometricas WHERE agente_id = ? AND status = 'VALIDA' LIMIT 1");
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ok = $result->fetch_row() !== null;
        $result->free();
        $stmt->close();
        return $ok;
    }

    /** @return array<string,mixed>|null */
    public function currentBiometrySummary(int $agentId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT c.uuid AS coleta_uuid, c.coletada_em, c.local_coleta, u.nome_snapshot AS operador_nome,
       foto.dpi AS foto_dpi, foto.largura_px AS foto_largura, foto.altura_px AS foto_altura,
       ff.uuid AS foto_arquivo_uuid,
       assinatura.dpi AS assinatura_dpi, assinatura.largura_px AS assinatura_largura, assinatura.altura_px AS assinatura_altura
FROM coletas_biometricas c
JOIN usuarios_cif u ON u.id = c.operador_id
JOIN biometrias foto ON foto.coleta_id = c.id AND foto.tipo = 'FOTO' AND foto.conforme_padrao = 1
JOIN arquivos_privados ff ON ff.id = foto.arquivo_id AND ff.status = 'ATIVO'
JOIN biometrias assinatura ON assinatura.coleta_id = c.id AND assinatura.tipo = 'ASSINATURA' AND assinatura.conforme_padrao = 1
WHERE c.agente_id = ? AND c.status = 'VALIDA'
ORDER BY c.coletada_em DESC, c.id DESC
LIMIT 1
SQL);
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function hasDocumentType(int $processId, string $type): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM processos_documentos WHERE processo_id = ? AND tipo_documento = ? LIMIT 1');
        $stmt->bind_param('is', $processId, $type);
        $stmt->execute();
        $result = $stmt->get_result();
        $ok = $result->fetch_row() !== null;
        $result->free();
        $stmt->close();
        return $ok;
    }

    /** @return array<string,mixed>|null */
    public function detailsByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT p.*,
       a.uuid AS agente_uuid, a.matricula, a.situacao_funcional_atual, a.ativo AS agente_ativo,
       b.id AS dados_biograficos_id, b.versao AS dados_versao, b.nome_completo, b.matricula_snapshot AS matricula_biografica,
       b.naturalidade, b.data_nascimento, b.tipo_sanguineo, b.data_admissao,
       b.cpf_ciphertext, b.cpf_key_id, b.fonte, b.fonte_referencia, b.fonte_atualizada_em,
       uc.nome_snapshot AS criado_por_nome,
       ua.nome_snapshot AS aprovado_por_nome,
       ucan.nome_snapshot AS cancelado_por_nome
FROM processos_emissao p
JOIN agentes a ON a.id = p.agente_id
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
JOIN (
    SELECT agente_id, MAX(versao) AS versao
    FROM agentes_dados_biograficos
    GROUP BY agente_id
) latest ON latest.agente_id = b.agente_id AND latest.versao = b.versao
JOIN usuarios_cif uc ON uc.id = p.criado_por
LEFT JOIN usuarios_cif ua ON ua.id = p.aprovado_por
LEFT JOIN usuarios_cif ucan ON ucan.id = p.cancelado_por
WHERE p.uuid = ?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function events(int $processId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.*, u.nome_snapshot AS usuario_nome
FROM processos_eventos e
LEFT JOIN usuarios_cif u ON u.id = e.usuario_id
WHERE e.processo_id = ?
ORDER BY e.ocorrido_em DESC, e.id DESC
SQL);
        $stmt->bind_param('i', $processId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $result->free();
        $stmt->close();
        return $items;
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $processId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT d.id, d.tipo_documento, d.numero_documento, d.emitido_em, d.incluido_em,
       f.uuid AS arquivo_uuid, f.nome_original, f.mime_type, f.tamanho_bytes,
       u.nome_snapshot AS incluido_por_nome
FROM processos_documentos d
JOIN arquivos_privados f ON f.id = d.arquivo_id
JOIN usuarios_cif u ON u.id = d.incluido_por
WHERE d.processo_id = ?
ORDER BY d.incluido_em DESC, d.id DESC
SQL);
        $stmt->bind_param('i', $processId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $result->free();
        $stmt->close();
        return $items;
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
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function insertDocument(int $processId, int $fileId, string $type, ?string $number, ?string $issuedAt, int $actorId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO processos_documentos (
    processo_id, arquivo_id, tipo_documento, numero_documento, emitido_em, incluido_por, incluido_em
) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('iisssi', $processId, $fileId, $type, $number, $issuedAt, $actorId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function privateDocumentByUuid(string $fileUuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT f.*
FROM arquivos_privados f
JOIN processos_documentos d ON d.arquivo_id = f.id
WHERE f.uuid = ? AND f.status = 'ATIVO'
LIMIT 1
SQL);
        $stmt->bind_param('s', $fileUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \RuntimeException('Não foi possível serializar os dados do evento do processo.', 0, $e);
        }
    }
}
