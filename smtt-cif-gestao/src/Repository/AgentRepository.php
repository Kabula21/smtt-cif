<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;

final class AgentRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function countAgents(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM agentes');
        $row = $result->fetch_assoc();
        $result->free();
        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{total:int,items:list<array<string,mixed>>}
     */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildSearchWhere($filters);
        $base = <<<'SQL'
FROM agentes a
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
LEFT JOIN agentes_dados_biograficos newer ON newer.agente_id = b.agente_id AND newer.versao > b.versao
SQL;

        [$countSql, $countParams] = $this->buildSearchCount($filters, $base, $whereSql, $params);
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        $countResult->free();
        $countStmt->close();
        $total = (int) ($countRow['total'] ?? 0);

        $sortMap = [
            'nome' => 'b.nome_completo',
            'matricula' => 'a.matricula',
            'situacao' => 'a.situacao_funcional_atual',
            'ativo' => 'a.ativo',
            'admissao' => 'b.data_admissao',
            'cadastro' => 'a.criado_em',
            'atualizado' => 'a.atualizado_em',
        ];
        $sort = isset($filters['ordenar']) && isset($sortMap[(string) $filters['ordenar']])
            ? $sortMap[(string) $filters['ordenar']]
            : $sortMap['nome'];
        $direction = strtoupper((string) ($filters['direcao'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $sql = <<<'SQL'
SELECT
    a.id, a.uuid, a.matricula, a.ativo, a.situacao_funcional_atual,
    a.criado_em, a.atualizado_em, a.lock_version,
    b.versao AS dados_versao, b.nome_completo, b.tipo_sanguineo, b.data_admissao,
    b.fonte, b.fonte_referencia
SQL;
        $sql .= ' ' . $base . $whereSql . ' ORDER BY ' . $sort . ' ' . $direction . ', a.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset;
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


    /**
     * Evita percorrer o histórico biográfico na contagem quando a pesquisa usa
     * apenas colunas da tabela de agentes. A consulta completa continua sendo
     * usada quando nome, fonte ou admissão participam do filtro.
     *
     * @param list<mixed> $params
     * @return array{0:string,1:list<mixed>}
     */
    private function buildSearchCount(array $filters, string $base, string $whereSql, array $params): array
    {
        $needsBiography = trim((string)($filters['q'] ?? '')) !== ''
            || trim((string)($filters['fonte'] ?? '')) !== ''
            || trim((string)($filters['admissao'] ?? '')) !== '';

        if ($needsBiography) {
            return ['SELECT COUNT(*) AS total ' . $base . $whereSql, $params];
        }

        $conditions = [];
        $countParams = [];
        $cpfLookup = $filters['cpf_lookup'] ?? null;
        if (is_string($cpfLookup) && $cpfLookup !== '') {
            $conditions[] = 'a.cpf_lookup_hmac = ?';
            $countParams[] = $cpfLookup;
        }
        $status = trim((string)($filters['situacao'] ?? ''));
        if ($status !== '') {
            $conditions[] = 'a.situacao_funcional_atual = ?';
            $countParams[] = $status;
        }
        foreach (['cadastro' => 'a.criado_em', 'atualizado' => 'a.atualizado_em'] as $key => $column) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $conditions[] = $column . ' >= ? AND ' . $column . ' < DATE_ADD(?, INTERVAL 1 DAY)';
                $countParams[] = $value . ' 00:00:00';
                $countParams[] = $value . ' 00:00:00';
            }
        }
        $active = (string)($filters['ativo'] ?? '');
        if ($active === '1' || $active === '0') {
            $conditions[] = 'a.ativo = ?';
            $countParams[] = (int)$active;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        return ['SELECT COUNT(*) AS total FROM agentes a' . $where, $countParams];
    }

    /** Compatibilidade interna com a busca simples da versão anterior. */
    public function paginate(string $search, int $page, int $perPage = 25): array
    {
        return $this->search(['q' => $search], $page, $perPage);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function exportRows(array $filters, int $limit = 5000): array
    {
        $limit = max(1, min(5000, $limit));
        [$whereSql, $params] = $this->buildSearchWhere($filters);
        $sql = <<<'SQL'
SELECT
    a.uuid, a.matricula, a.situacao_funcional_atual, a.criado_em, a.atualizado_em,
    b.versao AS dados_versao, b.nome_completo, b.data_admissao, b.fonte, b.fonte_referencia
FROM agentes a
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
LEFT JOIN agentes_dados_biograficos newer ON newer.agente_id = b.agente_id AND newer.versao > b.versao
SQL;
        $sql .= $whereSql . ' ORDER BY b.nome_completo ASC, a.matricula ASC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return $rows;
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function buildSearchWhere(array $filters): array
    {
        $conditions = ['newer.id IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(a.matricula LIKE ? OR b.nome_completo LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $cpfLookup = $filters['cpf_lookup'] ?? null;
        if (is_string($cpfLookup) && $cpfLookup !== '') {
            $conditions[] = 'a.cpf_lookup_hmac = ?';
            $params[] = $cpfLookup;
        }

        $status = trim((string) ($filters['situacao'] ?? ''));
        if ($status !== '') {
            $conditions[] = 'a.situacao_funcional_atual = ?';
            $params[] = $status;
        }

        $source = trim((string) ($filters['fonte'] ?? ''));
        if ($source !== '') {
            $conditions[] = 'b.fonte = ?';
            $params[] = $source;
        }

        $admission = trim((string) ($filters['admissao'] ?? ''));
        if ($admission !== '') {
            $conditions[] = 'b.data_admissao = ?';
            $params[] = $admission;
        }

        foreach ([
            'cadastro' => 'a.criado_em',
            'atualizado' => 'a.atualizado_em',
        ] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $conditions[] = $column . ' >= ? AND ' . $column . ' < DATE_ADD(?, INTERVAL 1 DAY)';
                $params[] = $value . ' 00:00:00';
                $params[] = $value . ' 00:00:00';
            }
        }

        $active = (string) ($filters['ativo'] ?? '');
        if ($active === '1' || $active === '0') {
            $conditions[] = 'a.ativo = ?';
            $params[] = (int) $active;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array<string,mixed>|null */
    public function findCurrentByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT
    a.id, a.uuid, a.matricula, a.cpf_lookup_hmac, a.ativo,
    a.situacao_funcional_atual, a.criado_em, a.atualizado_em, a.lock_version,
    b.id AS dados_id, b.versao AS dados_versao, b.nome_completo,
    b.matricula_snapshot, b.naturalidade, b.data_nascimento,
    b.cpf_ciphertext, b.cpf_key_id, b.tipo_sanguineo, b.data_admissao,
    b.fonte, b.fonte_referencia, b.fonte_atualizada_em, b.criado_por,
    b.criado_em AS dados_criado_em
FROM agentes a
JOIN agentes_dados_biograficos b ON b.agente_id = a.id
LEFT JOIN agentes_dados_biograficos newer ON newer.agente_id = b.agente_id AND newer.versao > b.versao
WHERE a.uuid = ? AND newer.id IS NULL
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

    /** @return array<string,mixed>|null */
    public function lockByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, uuid, matricula, cpf_lookup_hmac, ativo, situacao_funcional_atual, lock_version ' .
            'FROM agentes WHERE uuid = ? FOR UPDATE'
        );
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function currentBiographical(int $agentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, agente_id, versao, nome_completo, matricula_snapshot, naturalidade, data_nascimento, ' .
            'cpf_ciphertext, cpf_key_id, tipo_sanguineo, data_admissao, fonte, fonte_referencia, fonte_atualizada_em, criado_por, criado_em ' .
            'FROM agentes_dados_biograficos WHERE agente_id = ? ORDER BY versao DESC LIMIT 1'
        );
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function biographicalHistory(int $agentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, versao, nome_completo, matricula_snapshot, naturalidade, data_nascimento, ' .
            'cpf_ciphertext, cpf_key_id, tipo_sanguineo, data_admissao, fonte, fonte_referencia, criado_por, criado_em ' .
            'FROM agentes_dados_biograficos WHERE agente_id = ? ORDER BY versao DESC'
        );
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

    /** @return list<array<string,mixed>> */
    public function functionalHistory(int $agentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, codigo, inicio_em, fim_em, fonte, fonte_referencia, registrado_por, registrado_em ' .
            'FROM agentes_situacoes_funcionais WHERE agente_id = ? ORDER BY inicio_em DESC, id DESC'
        );
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

    public function existsMatricula(string $matricula, ?int $exceptAgentId = null): bool
    {
        if ($exceptAgentId === null) {
            $stmt = $this->db->prepare('SELECT 1 FROM agentes WHERE matricula = ? LIMIT 1');
            $stmt->bind_param('s', $matricula);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM agentes WHERE matricula = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $matricula, $exceptAgentId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result->fetch_row() !== null;
        $result->free();
        $stmt->close();
        return $found;
    }

    public function existsCpfLookup(string $lookup, ?int $exceptAgentId = null): bool
    {
        if ($exceptAgentId === null) {
            $stmt = $this->db->prepare('SELECT 1 FROM agentes WHERE cpf_lookup_hmac = ? LIMIT 1');
            $stmt->bind_param('s', $lookup);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM agentes WHERE cpf_lookup_hmac = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $lookup, $exceptAgentId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result->fetch_row() !== null;
        $result->free();
        $stmt->close();
        return $found;
    }

    public function insertAgent(
        string $uuid,
        string $matricula,
        string $cpfLookup,
        string $situacaoFuncional
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO agentes (uuid, matricula, cpf_lookup_hmac, ativo, situacao_funcional_atual, criado_em, atualizado_em, lock_version) ' .
            'VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), 0)'
        );
        $stmt->bind_param('ssss', $uuid, $matricula, $cpfLookup, $situacaoFuncional);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function insertBiographical(int $agentId, int $version, array $data, int $actorId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO agentes_dados_biograficos (
    agente_id, versao, nome_completo, matricula_snapshot, naturalidade, data_nascimento,
    cpf_lookup_hmac, cpf_ciphertext, cpf_key_id, tipo_sanguineo, data_admissao,
    fonte, fonte_referencia, fonte_atualizada_em, criado_por, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6))
SQL);
        $name = (string) $data['nome_completo'];
        $matricula = (string) $data['matricula'];
        $naturalidade = (string) $data['naturalidade'];
        $birth = (string) $data['data_nascimento'];
        $lookup = (string) $data['cpf_lookup_hmac'];
        $cipher = (string) $data['cpf_ciphertext'];
        $keyId = (string) $data['cpf_key_id'];
        $blood = (string) $data['tipo_sanguineo'];
        $admission = (string) $data['data_admissao'];
        $source = (string) $data['fonte'];
        $sourceRef = $data['fonte_referencia'] !== null ? (string) $data['fonte_referencia'] : null;
        $stmt->bind_param(
            'iisssssssssssi',
            $agentId,
            $version,
            $name,
            $matricula,
            $naturalidade,
            $birth,
            $lookup,
            $cipher,
            $keyId,
            $blood,
            $admission,
            $source,
            $sourceRef,
            $actorId
        );
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function insertFunctionalStatus(
        int $agentId,
        string $status,
        int $actorId,
        string $source = 'CADASTRO_MANUAL_CIF'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO agentes_situacoes_funcionais ' .
            '(agente_id, codigo, inicio_em, fim_em, fonte, fonte_referencia, registrado_por, registrado_em) ' .
            'VALUES (?, ?, UTC_TIMESTAMP(6), NULL, ?, NULL, ?, UTC_TIMESTAMP(6))'
        );
        $stmt->bind_param('issi', $agentId, $status, $source, $actorId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function closeCurrentFunctionalStatus(int $agentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE agentes_situacoes_funcionais SET fim_em = UTC_TIMESTAMP(6) ' .
            'WHERE agente_id = ? AND fim_em IS NULL'
        );
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();
    }

    public function updateAgentCurrent(
        int $agentId,
        string $matricula,
        string $cpfLookup,
        string $status,
        int $expectedLockVersion
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE agentes SET matricula = ?, cpf_lookup_hmac = ?, situacao_funcional_atual = ?, ' .
            'atualizado_em = UTC_TIMESTAMP(6), lock_version = lock_version + 1 ' .
            'WHERE id = ? AND lock_version = ?'
        );
        $stmt->bind_param('sssii', $matricula, $cpfLookup, $status, $agentId, $expectedLockVersion);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        return $changed;
    }

    /** @return array<string,int> */
    public function dependencyCounts(int $agentId): array
    {
        $queries = [
            'biometrias' => 'SELECT COUNT(*) total FROM coletas_biometricas WHERE agente_id = ?',
            'processos' => 'SELECT COUNT(*) total FROM processos_emissao WHERE agente_id = ?',
            'emissoes' => 'SELECT COUNT(*) total FROM emissoes_cif WHERE agente_id = ?',
            'ocorrencias' => 'SELECT COUNT(*) total FROM ocorrencias_cif WHERE agente_id = ?',
            'importacoes' => 'SELECT COUNT(*) total FROM importacoes_agentes_itens WHERE agente_id = ?',
        ];
        $counts = [];
        foreach ($queries as $key => $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $agentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $result->free();
            $stmt->close();
            $counts[$key] = (int) ($row['total'] ?? 0);
        }
        return $counts;
    }

    /** @return array<string,int> */
    public function operationalSummary(int $agentId): array
    {
        $sql = <<<'SQL'
SELECT
    (SELECT COUNT(*) FROM coletas_biometricas c WHERE c.agente_id = ? AND c.status = 'VALIDA') AS biometrias_validas,
    (SELECT COUNT(*) FROM processos_emissao p WHERE p.agente_id = ? AND p.status_codigo NOT IN ('CONCLUIDO','INDEFERIDO','CANCELADO')) AS processos_abertos,
    (SELECT COUNT(*) FROM emissoes_cif e WHERE e.agente_id = ?) AS emissoes_total,
    (SELECT COUNT(*) FROM agentes_emissoes_ativas aa WHERE aa.agente_id = ?) AS cif_ativa
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iiii', $agentId, $agentId, $agentId, $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $stmt->close();
        return array_map('intval', $row);
    }

    /** @return list<array<string,mixed>> */
    public function recentProcesses(int $agentId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare(
            'SELECT uuid, ciclo_numero, tipo_codigo, status_codigo, numero_protocolo, protocolo_externo, criado_em, atualizado_em ' .
            'FROM processos_emissao WHERE agente_id = ? ORDER BY criado_em DESC, id DESC LIMIT ' . $limit
        );
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function recentEmissions(int $agentId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare(
            'SELECT e.uuid, e.codigo_validacao, e.tipo_emissao_codigo, e.emitida_em, st.status_validade, st.status_fisico ' .
            'FROM emissoes_cif e JOIN emissoes_cif_estado st ON st.emissao_id = e.id ' .
            'WHERE e.agente_id = ? ORDER BY e.emitida_em DESC, e.id DESC LIMIT ' . $limit
        );
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function recentOccurrences(int $agentId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare(
            'SELECT o.uuid, o.tipo_codigo, o.status, o.data_comunicacao, o.recuperada_em, e.codigo_validacao ' .
            'FROM ocorrencias_cif o JOIN emissoes_cif e ON e.id = o.emissao_id ' .
            'WHERE o.agente_id = ? ORDER BY o.data_comunicacao DESC, o.id DESC LIMIT ' . $limit
        );
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        return $rows;
    }

    public function setRegistrationActive(int $agentId, bool $active): void
    {
        $value = $active ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE agentes SET ativo = ?, atualizado_em = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?');
        $stmt->bind_param('ii', $value, $agentId);
        $stmt->execute();
        $stmt->close();
    }

    public function hasIssuedEmission(int $agentId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM emissoes_cif WHERE agente_id = ? LIMIT 1');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_row() !== null;
        $result->free();
        $stmt->close();
        return $exists;
    }

    /** @return list<array{id:int,storage_key:string}> */
    public function preEmissionPrivateFiles(int $agentId): array
    {
        $sql = <<<'SQL'
SELECT DISTINCT a.id, a.storage_key
FROM arquivos_privados a
JOIN (
    SELECT b.arquivo_id AS arquivo_id
    FROM biometrias b
    JOIN coletas_biometricas c ON c.id = b.coleta_id
    WHERE c.agente_id = ?
    UNION
    SELECT pd.arquivo_id AS arquivo_id
    FROM processos_documentos pd
    JOIN processos_emissao p ON p.id = pd.processo_id
    WHERE p.agente_id = ?
    UNION
    SELECT v.termo_arquivo_id AS arquivo_id
    FROM validacoes_dados_biograficos v
    WHERE v.agente_id = ? AND v.termo_arquivo_id IS NOT NULL
) refs ON refs.arquivo_id = a.id
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iii', $agentId, $agentId, $agentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = ['id' => (int) $row['id'], 'storage_key' => (string) $row['storage_key']];
        }
        $result->free();
        $stmt->close();
        return $rows;
    }

    public function discardPreEmissionAgent(int $agentId): void
    {
        // O histórico do lote de importação é preservado, mas deixa de apontar para um cadastro removido.
        $stmt = $this->db->prepare('UPDATE importacoes_agentes_itens SET agente_id = NULL WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        // Validações dependem simultaneamente do agente, dos dados biográficos e, opcionalmente, do processo.
        $stmt = $this->db->prepare('DELETE FROM validacoes_dados_biograficos WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        // Remove filhos dos processos antes dos próprios processos.
        $stmt = $this->db->prepare('DELETE pd FROM processos_documentos pd JOIN processos_emissao p ON p.id = pd.processo_id WHERE p.agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE pe FROM processos_eventos pe JOIN processos_emissao p ON p.id = pe.processo_id WHERE p.agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE po FROM processos_emissoes_origem po JOIN processos_emissao p ON p.id = po.processo_id WHERE p.agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM processos_emissao WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        // Biométricos e coleta são preparatórios enquanto nenhuma CIF foi emitida.
        $stmt = $this->db->prepare('DELETE b FROM biometrias b JOIN coletas_biometricas c ON c.id = b.coleta_id WHERE c.agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM coletas_biometricas WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        // O contador de ciclo é parte do histórico preparatório enquanto não houve emissão.
        $stmt = $this->db->prepare('DELETE FROM controle_ciclo_agente WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM agentes_situacoes_funcionais WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM agentes_dados_biograficos WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM agentes WHERE id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();
    }

    public function deletePrivateFileIfOrphan(int $fileId): bool
    {
        $sql = <<<'SQL'
DELETE a FROM arquivos_privados a
LEFT JOIN biometrias b ON b.arquivo_id = a.id
LEFT JOIN processos_documentos pd ON pd.arquivo_id = a.id
LEFT JOIN validacoes_dados_biograficos v ON v.termo_arquivo_id = a.id
LEFT JOIN signatarios_institucionais s ON s.assinatura_arquivo_id = a.id
LEFT JOIN ocorrencias_documentos od ON od.arquivo_id = a.id
LEFT JOIN artefatos_emissao ae ON ae.arquivo_id = a.id
WHERE a.id = ?
  AND b.id IS NULL
  AND pd.id IS NULL
  AND v.id IS NULL
  AND s.id IS NULL
  AND od.ocorrencia_id IS NULL
  AND ae.id IS NULL
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();
        return $deleted;
    }

    public function deleteUnusedAgent(int $agentId): void
    {
        $stmt = $this->db->prepare('DELETE FROM controle_ciclo_agente WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM agentes_situacoes_funcionais WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM agentes_dados_biograficos WHERE agente_id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM agentes WHERE id = ?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();
    }

}
