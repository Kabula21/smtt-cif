<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use JsonException;
use mysqli;

final class EmissionRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function countEmissions(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM emissoes_cif');
        $row = $result->fetch_assoc();
        $result->free();
        return (int) ($row['total'] ?? 0);
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $conditions = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(e.codigo_validacao LIKE ? OR a.matricula LIKE ? OR s.nome_completo LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $conditions[] = 'es.status_validade = ?';
            $params[] = $status;
        }
        $type = trim((string) ($filters['tipo'] ?? ''));
        if ($type !== '') {
            $conditions[] = 'e.tipo_emissao_codigo = ?';
            $params[] = $type;
        }
        $issued = trim((string) ($filters['emitida'] ?? ''));
        if ($issued !== '') {
            $conditions[] = 'e.emitida_em >= ? AND e.emitida_em < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $issued . ' 00:00:00';
            $params[] = $issued . ' 00:00:00';
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $base = <<<'SQL'
FROM emissoes_cif e
JOIN emissoes_cif_snapshot s ON s.emissao_id = e.id
JOIN emissoes_cif_estado es ON es.emissao_id = e.id
JOIN agentes a ON a.id = e.agente_id
LEFT JOIN ciclos_emissao c ON c.id = e.ciclo_id
SQL;
        $countBase = ' FROM emissoes_cif e ';
        if ($q !== '') {
            $countBase .= 'JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id JOIN agentes a ON a.id=e.agente_id ';
        }
        if ($status !== '') {
            $countBase .= 'JOIN emissoes_cif_estado es ON es.emissao_id=e.id ';
        }
        $count = $this->db->prepare('SELECT COUNT(*) AS total ' . $countBase . $where);
        $count->execute($params);
        $row = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int) ($row['total'] ?? 0);

        $sql = <<<'SQL'
SELECT e.id, e.uuid, e.codigo_validacao, e.tipo_emissao_codigo, e.emitida_em,
       e.sequencial_sistema, COALESCE(e.ciclo_numero_agente, c.numero) AS ciclo_numero,
       a.matricula, s.nome_completo,
       es.status_validade, es.status_fisico, es.public_version
SQL;
        $sortMap = [
            'codigo' => 'e.codigo_validacao',
            'nome' => 's.nome_completo',
            'matricula' => 'a.matricula',
            'tipo' => 'e.tipo_emissao_codigo',
            'emitida' => 'e.emitida_em',
            'validade' => 'es.status_validade',
            'fisico' => 'es.status_fisico',
        ];
        $sort = $sortMap[(string) ($filters['ordenar'] ?? 'emitida')] ?? 'e.emitida_em';
        $direction = strtoupper((string) ($filters['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= ' ' . $base . $where . ' ORDER BY ' . $sort . ' ' . $direction . ', e.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
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

    /** @return array<string,mixed>|null */
    public function detailsByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.*, COALESCE(e.ciclo_numero_agente, c.numero) AS ciclo_numero,
       s.nome_completo, s.matricula AS matricula_snapshot, s.naturalidade, s.data_nascimento,
       s.tipo_sanguineo, s.data_admissao, s.situacao_funcional, s.signatario_nome,
       s.signatario_cargo, s.snapshot_hash,
       es.status_validade, es.status_fisico, es.motivo_suspensao, es.suspensa_em,
       es.motivo_encerramento, es.encerrada_em, es.ativada_em, es.entregue_em,
       es.public_version, es.lock_version AS estado_lock_version,
       p.uuid AS processo_uuid, p.numero_protocolo,
       u.nome_snapshot AS emitida_por_nome
FROM emissoes_cif e
LEFT JOIN ciclos_emissao c ON c.id = e.ciclo_id
JOIN emissoes_cif_snapshot s ON s.emissao_id = e.id
JOIN emissoes_cif_estado es ON es.emissao_id = e.id
JOIN processos_emissao p ON p.id = e.processo_id
JOIN usuarios_cif u ON u.id = e.emitida_por
WHERE e.uuid = ?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** Dados mínimos do termo, usando a fotografia biográfica imutável da emissão.
     *  O CPF permanece criptografado nesta camada e só é aberto no serviço autorizado.
     *  @return array<string,mixed>|null
     */
    public function receiptTermDataByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.uuid, e.codigo_validacao, e.tipo_emissao_codigo, e.emitida_em,
       s.nome_completo, s.matricula, s.cpf_ciphertext, s.cpf_key_id
FROM emissoes_cif e
JOIN emissoes_cif_snapshot s ON s.emissao_id = e.id
WHERE e.uuid = ?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function occurrencesForEmission(int $emissionId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT o.uuid, o.tipo_codigo, o.status, o.data_fato, o.data_comunicacao,
       o.bo_numero, o.recuperada_em, o.encerrada_em, o.detalhes,
       p.uuid AS processo_uuid,
       u.nome_snapshot AS registrado_por_nome
FROM ocorrencias_cif o
LEFT JOIN processos_emissao p ON p.id = o.processo_id
JOIN usuarios_cif u ON u.id = o.registrado_por
WHERE o.emissao_id = ?
ORDER BY o.data_comunicacao DESC, o.id DESC
SQL);
        $stmt->bind_param('i', $emissionId);
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
    public function recollectionsForEmission(int $emissionId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT r.uuid, r.motivo_codigo, r.status, r.fato_em, r.recolhimento_solicitado_em,
       r.recolhimento_devido_em, r.recolhida_em, r.inutilizacao_permitida_em,
       r.inutilizada_em, r.motivo_nao_recolhimento, r.referencia, r.observacoes,
       ur.nome_snapshot AS registrado_por_nome,
       uc.nome_snapshot AS recolhida_por_nome,
       ui.nome_snapshot AS inutilizada_por_nome
FROM recolhimentos_cif r
JOIN usuarios_cif ur ON ur.id = r.registrado_por
LEFT JOIN usuarios_cif uc ON uc.id = r.recolhida_por
LEFT JOIN usuarios_cif ui ON ui.id = r.inutilizada_por
WHERE r.emissao_id = ?
ORDER BY r.criado_em DESC, r.id DESC
SQL);
        $stmt->bind_param('i', $emissionId);
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
    public function events(int $emissionId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT ev.*, u.nome_snapshot AS usuario_nome
FROM eventos_cif ev
LEFT JOIN usuarios_cif u ON u.id = ev.usuario_id
WHERE ev.emissao_id = ?
ORDER BY ev.ocorrido_em DESC, ev.id DESC
SQL);
        $stmt->bind_param('i', $emissionId);
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
    public function lockNumberingControl(): ?array
    {
        $result = $this->db->query('SELECT * FROM controle_numeracao_cif WHERE id = 1 FOR UPDATE');
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function activeCycleById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ciclos_emissao WHERE id = ? AND status = 'ATIVO' LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function currentNumbering(): ?array
    {
        $result = $this->db->query(<<<'SQL'
SELECT n.ultimo_sequencial, n.lock_version, n.atualizado_em
FROM controle_numeracao_cif n
WHERE n.id = 1
LIMIT 1
SQL);
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }

    public function maxCycleNumber(): int
    {
        $result = $this->db->query('SELECT COALESCE(MAX(numero), 0) AS max_numero FROM ciclos_emissao');
        $row = $result->fetch_assoc();
        $result->free();
        return (int) ($row['max_numero'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function cycles(): array
    {
        $result = $this->db->query(<<<'SQL'
SELECT c.*, u.nome_snapshot AS aberto_por_nome, ue.nome_snapshot AS encerrado_por_nome
FROM ciclos_emissao c
JOIN usuarios_cif u ON u.id = c.aberto_por
LEFT JOIN usuarios_cif ue ON ue.id = c.encerrado_por
ORDER BY c.numero DESC
SQL);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public function closeActiveCycle(int $actorId): void
    {
        $stmt = $this->db->prepare("UPDATE ciclos_emissao SET status='ENCERRADO', encerrado_por=?, encerrado_em=UTC_TIMESTAMP(6) WHERE status='ATIVO'");
        $stmt->bind_param('i', $actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function insertCycle(string $uuid, int $number, ?string $description, ?string $actReference, string $justification, int $actorId): int
    {
        $status = 'ATIVO';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO ciclos_emissao (
    uuid, numero, descricao, ato_referencia, justificativa, status,
    aberto_por, aberto_em, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('sissssi', $uuid, $number, $description, $actReference, $justification, $status, $actorId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function setActiveCycle(int $cycleId): void
    {
        $stmt = $this->db->prepare('UPDATE controle_numeracao_cif SET ciclo_ativo_id=?, atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1 WHERE id=1');
        $stmt->bind_param('i', $cycleId);
        $stmt->execute();
        $stmt->close();
    }

    public function updateLastSequence(int $sequence): void
    {
        $stmt = $this->db->prepare('UPDATE controle_numeracao_cif SET ultimo_sequencial=?, atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1 WHERE id=1');
        $stmt->bind_param('i', $sequence);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function currentSigner(): ?array
    {
        $result = $this->db->query(<<<'SQL'
SELECT s.*, f.uuid AS assinatura_arquivo_uuid, f.storage_key, f.key_id, f.sha256, f.mime_type
FROM signatarios_institucionais s
JOIN arquivos_privados f ON f.id = s.assinatura_arquivo_id
WHERE s.vigencia_inicio <= UTC_TIMESTAMP(6)
  AND (s.vigencia_fim IS NULL OR s.vigencia_fim > UTC_TIMESTAMP(6))
ORDER BY s.vigencia_inicio DESC, s.id DESC
LIMIT 1
SQL);
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function signers(): array
    {
        $result = $this->db->query(<<<'SQL'
SELECT s.*, f.uuid AS assinatura_arquivo_uuid, f.mime_type,
       u.nome_snapshot AS criado_por_nome
FROM signatarios_institucionais s
JOIN arquivos_privados f ON f.id = s.assinatura_arquivo_id
JOIN usuarios_cif u ON u.id = s.criado_por
ORDER BY s.vigencia_inicio DESC, s.id DESC
SQL);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
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
        $stmt->bind_param('ssssssisissi',
            $file['uuid'], $file['categoria'], $file['storage_backend'], $file['storage_key'],
            $file['nome_original'], $file['mime_type'], $file['size'], $file['sha256'],
            $encrypted, $file['key_id'], $status, $actorId
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function closeCurrentSigners(): void
    {
        $this->db->query('UPDATE signatarios_institucionais SET vigencia_fim=UTC_TIMESTAMP(6) WHERE vigencia_fim IS NULL OR vigencia_fim > UTC_TIMESTAMP(6)');
    }

    public function insertSigner(string $uuid, string $name, string $role, int $signatureFileId, ?string $actReference, int $actorId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO signatarios_institucionais (
    uuid, nome, cargo, assinatura_arquivo_id, vigencia_inicio, vigencia_fim,
    ato_referencia, criado_por, criado_em
) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), NULL, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('sssisi', $uuid, $name, $role, $signatureFileId, $actReference, $actorId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function emissionForProcess(int $processId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM emissoes_cif WHERE processo_id=? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('i', $processId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function activeEmissionForAgent(int $agentId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.* FROM agentes_emissoes_ativas aa
JOIN emissoes_cif e ON e.id = aa.emissao_id
WHERE aa.agente_id=? LIMIT 1
SQL);
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function pendingEmissionForAgent(int $agentId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.*
FROM emissoes_cif e
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
WHERE e.agente_id=? AND es.status_validade='PENDENTE_ATIVACAO'
ORDER BY e.emitida_em DESC, e.id DESC
LIMIT 1
SQL);
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function latestEmissionForAgent(int $agentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM emissoes_cif WHERE agente_id=? ORDER BY emitida_em DESC, id DESC LIMIT 1');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function latestValidBiometry(int $agentId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT c.id AS coleta_id, c.uuid AS coleta_uuid, c.coletada_em,
       foto.id AS foto_id, foto.dpi AS foto_dpi,
       assinatura.id AS assinatura_id, assinatura.dpi AS assinatura_dpi
FROM coletas_biometricas c
JOIN biometrias foto ON foto.coleta_id=c.id AND foto.tipo='FOTO' AND foto.conforme_padrao=1
JOIN biometrias assinatura ON assinatura.coleta_id=c.id AND assinatura.tipo='ASSINATURA' AND assinatura.conforme_padrao=1
WHERE c.agente_id=? AND c.status='VALIDA'
ORDER BY c.coletada_em DESC, c.id DESC
LIMIT 1
SQL);
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function currentFunctionalStatus(int $agentId): string
    {
        $stmt = $this->db->prepare('SELECT situacao_funcional_atual FROM agentes WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new \RuntimeException('Situação funcional atual do agente não localizada.');
        }
        return (string) $row['situacao_funcional_atual'];
    }

    /** @return array<string,mixed>|null */
    public function currentBiography(int $agentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM agentes_dados_biograficos WHERE agente_id=? ORDER BY versao DESC LIMIT 1');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function hasCurrentValidation(int $processId, int $bioId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM validacoes_dados_biograficos WHERE processo_emissao_id=? AND dados_biograficos_id=? AND validado_pelo_titular=1 LIMIT 1');
        $stmt->bind_param('ii', $processId, $bioId);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    }

    public function linkProcessOrigin(int $processId, int $emissionId): void
    {
        $stmt = $this->db->prepare('INSERT INTO processos_emissoes_origem (processo_id, emissao_origem_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE emissao_origem_id=VALUES(emissao_origem_id)');
        $stmt->bind_param('ii', $processId, $emissionId);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,mixed> $data */
    public function insertEmission(array $data): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO emissoes_cif (
    uuid, generation_key, agente_id, processo_id, emissao_anterior_id, tipo_emissao_codigo,
    ciclo_id, ciclo_numero_agente, sequencial_sistema, codigo_validacao, regra_codigo_version,
    qr_token_hash, qr_token_ciphertext, qr_key_id, qr_token_version,
    signatario_id, template_version, emitida_por, emitida_em, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 2, ?, ?, ?, 1, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'ssiiisiiissssisi',
            $data['uuid'], $data['generation_key'], $data['agente_id'], $data['processo_id'],
            $data['emissao_anterior_id'], $data['tipo_emissao_codigo'], $data['ciclo_id'],
            $data['ciclo_numero_agente'], $data['sequencial_sistema'], $data['codigo_validacao'], $data['qr_token_hash'],
            $data['qr_token_ciphertext'], $data['qr_key_id'], $data['signatario_id'],
            $data['template_version'], $data['emitida_por']
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function insertSnapshot(int $emissionId, array $data): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO emissoes_cif_snapshot (
    emissao_id, dados_biograficos_id, nome_completo, matricula, naturalidade,
    data_nascimento, cpf_ciphertext, cpf_key_id, tipo_sanguineo, data_admissao,
    situacao_funcional, signatario_nome, signatario_cargo, template_version,
    dados_extras, snapshot_hash, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('iissssssssssssss',
            $emissionId, $data['dados_biograficos_id'], $data['nome_completo'], $data['matricula'],
            $data['naturalidade'], $data['data_nascimento'], $data['cpf_ciphertext'], $data['cpf_key_id'],
            $data['tipo_sanguineo'], $data['data_admissao'], $data['situacao_funcional'],
            $data['signatario_nome'], $data['signatario_cargo'], $data['template_version'],
            $data['dados_extras'], $data['snapshot_hash']
        );
        $stmt->execute();
        $stmt->close();
    }

    public function linkBiometry(int $emissionId, int $biometricId, string $purpose): void
    {
        $stmt = $this->db->prepare('INSERT INTO emissoes_cif_biometrias (emissao_id, biometria_id, finalidade) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $emissionId, $biometricId, $purpose);
        $stmt->execute();
        $stmt->close();
    }

    public function insertInitialState(int $emissionId, string $validityStatus, string $physicalStatus): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO emissoes_cif_estado (
    emissao_id, status_validade, status_fisico, public_version, lock_version, atualizado_em
) VALUES (?, ?, ?, 0, 0, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('iss', $emissionId, $validityStatus, $physicalStatus);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,mixed>|null $data */
    public function insertEvent(int $emissionId, ?int $processId, string $uuid, string $type, ?string $before, ?string $after, ?array $data, ?int $actorId): void
    {
        $json = $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO eventos_cif (uuid, emissao_id, processo_id, tipo_evento, status_anterior, status_novo, dados, usuario_id, ocorrido_em)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('siissssi', $uuid, $emissionId, $processId, $type, $before, $after, $json, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function changeProcessStatusDirect(int $processId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE processos_emissao SET status_codigo=?, atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1 WHERE id=?');
        $stmt->bind_param('si', $status, $processId);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,mixed>|null $data */
    public function insertProcessEvent(int $processId, string $uuid, string $type, ?string $before, ?string $after, ?array $data, ?int $actorId): void
    {
        $json = $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO processos_eventos (uuid, processo_id, tipo_evento, estado_anterior, estado_novo, dados, usuario_id, ocorrido_em)
VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('sissssi', $uuid, $processId, $type, $before, $after, $json, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function lockEmissionByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.*, es.status_validade, es.status_fisico, es.public_version, es.lock_version AS estado_lock_version,
       es.motivo_suspensao, es.motivo_encerramento, es.ativada_em, es.entregue_em,
       s.nome_completo, s.matricula AS matricula_snapshot, s.data_admissao
FROM emissoes_cif e
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
WHERE e.uuid=?
LIMIT 1
FOR UPDATE
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockEmissionById(int $id): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.*, es.status_validade, es.status_fisico, es.public_version, es.lock_version AS estado_lock_version,
       es.motivo_suspensao, es.motivo_encerramento, es.ativada_em, es.entregue_em,
       s.nome_completo, s.matricula AS matricula_snapshot, s.data_admissao
FROM emissoes_cif e
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
WHERE e.id=?
LIMIT 1
FOR UPDATE
SQL);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockActiveLinkForAgent(int $agentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM agentes_emissoes_ativas WHERE agente_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function updateEmissionState(
        int $emissionId,
        string $validity,
        string $physical,
        ?string $closureReason,
        bool $setActivatedAt,
        bool $setDeliveredAt,
        bool $incrementPublicVersion
    ): int {
        $publicIncrement = $incrementPublicVersion ? ', public_version=public_version+1' : '';
        $activated = $setActivatedAt ? ', ativada_em=COALESCE(ativada_em, UTC_TIMESTAMP(6))' : '';
        $delivered = $setDeliveredAt ? ', entregue_em=COALESCE(entregue_em, UTC_TIMESTAMP(6))' : '';
        $closed = $validity === 'ENCERRADA'
            ? ', encerrada_em=COALESCE(encerrada_em, UTC_TIMESTAMP(6))'
            : '';
        $sql = "UPDATE emissoes_cif_estado SET status_validade=?, status_fisico=?, motivo_encerramento=?, atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1{$publicIncrement}{$activated}{$delivered}{$closed} WHERE emissao_id=?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sssi', $validity, $physical, $closureReason, $emissionId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('SELECT public_version FROM emissoes_cif_estado WHERE emissao_id=? LIMIT 1');
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['public_version'] ?? 0);
    }

    public function removeActiveLink(int $agentId): void
    {
        $stmt = $this->db->prepare('DELETE FROM agentes_emissoes_ativas WHERE agente_id=?');
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->close();
    }

    public function setActiveLink(int $agentId, int $emissionId): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO agentes_emissoes_ativas (agente_id, emissao_id, vinculada_em)
VALUES (?, ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE emissao_id=VALUES(emissao_id), vinculada_em=VALUES(vinculada_em)
SQL);
        $stmt->bind_param('ii', $agentId, $emissionId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function latestSignedDeliveryTerm(int $emissionId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT t.id, t.emissao_id, t.assinaturas_conferidas, t.anexado_em,
       u.nome_snapshot AS anexado_por_nome,
       f.uuid AS arquivo_uuid, f.storage_key, f.key_id, f.sha256,
       f.nome_original, f.mime_type, f.tamanho_bytes, f.criptografado, f.storage_backend
FROM emissoes_termos_entrega t
JOIN arquivos_privados f ON f.id=t.arquivo_id AND f.status='ATIVO'
JOIN usuarios_cif u ON u.id=t.anexado_por
WHERE t.emissao_id=?
ORDER BY t.id DESC LIMIT 1
SQL);
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($result) ? $result : null;
    }

    /** @return array<string,mixed>|null */
    public function signedDeliveryTermByFileUuid(int $emissionId, string $fileUuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT t.id, t.emissao_id, t.assinaturas_conferidas, t.anexado_em,
       f.uuid AS arquivo_uuid, f.storage_key, f.key_id, f.sha256,
       f.nome_original, f.mime_type, f.tamanho_bytes, f.criptografado, f.storage_backend
FROM emissoes_termos_entrega t
JOIN arquivos_privados f ON f.id=t.arquivo_id AND f.status='ATIVO'
WHERE t.emissao_id=? AND f.uuid=? LIMIT 1
SQL);
        $stmt->bind_param('is', $emissionId, $fileUuid);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($result) ? $result : null;
    }

    /** @param array<string,mixed> $file */
    public function insertSignedTermPrivateFile(array $file, int $actorId): int
    {
        $encrypted = 1;
        $status = 'ATIVO';
        $category = 'TERMO_ENTREGA_ASSINADO';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO arquivos_privados (
    uuid, categoria, storage_backend, storage_key, nome_original, mime_type,
    tamanho_bytes, sha256, criptografado, key_id, status, criado_por, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'ssssssisissi',
            $file['uuid'], $category, $file['storage_backend'], $file['storage_key'],
            $file['nome_original'], $file['mime_type'], $file['size'], $file['sha256'],
            $encrypted, $file['key_id'], $status, $actorId
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function insertSignedDeliveryTerm(int $emissionId, int $fileId, int $actorId): int
    {
        $confirmed = 1;
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO emissoes_termos_entrega (emissao_id, arquivo_id, assinaturas_conferidas, anexado_por, anexado_em)
VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('iiii', $emissionId, $fileId, $confirmed, $actorId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function insertDelivery(array $data): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO entregas_cif (
    uuid, emissao_id, emissao_anterior_id, titular_confirmou, metodo_confirmacao,
    referencia, anterior_recolhida, observacoes, entregue_por, entregue_em, criado_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'siiissisi',
            $data['uuid'], $data['emissao_id'], $data['emissao_anterior_id'], $data['titular_confirmou'],
            $data['metodo_confirmacao'], $data['referencia'], $data['anterior_recolhida'],
            $data['observacoes'], $data['entregue_por']
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function deliveryForEmission(int $emissionId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT d.*, u.nome_snapshot AS entregue_por_nome, old.codigo_validacao AS codigo_anterior
FROM entregas_cif d
JOIN usuarios_cif u ON u.id=d.entregue_por
LEFT JOIN emissoes_cif old ON old.id=d.emissao_anterior_id
WHERE d.emissao_id=?
LIMIT 1
SQL);
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function previousEmissionDetails(int $emissionId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT old.id, old.uuid, old.codigo_validacao, old.tipo_emissao_codigo,
       es.status_validade, es.status_fisico, es.public_version, old.emitida_em
FROM emissoes_cif cur
JOIN emissoes_cif old ON old.id=cur.emissao_anterior_id
JOIN emissoes_cif_estado es ON es.emissao_id=old.id
WHERE cur.id=?
LIMIT 1
SQL);
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function insertRecollection(
        string $uuid,
        int $emissionId,
        ?int $processId,
        string $reason,
        int $actorId
    ): int {
        $status = 'RECOLHIDA';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO recolhimentos_cif (
    uuid, emissao_id, processo_id, motivo_codigo, fato_em,
    recolhimento_solicitado_em, recolhimento_devido_em, recolhida_em,
    inutilizacao_permitida_em, inutilizada_em, status, motivo_nao_recolhimento,
    registrado_por, recolhida_por, criado_em, atualizado_em
) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL, ?, NULL, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('siissii', $uuid, $emissionId, $processId, $reason, $status, $actorId, $actorId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }


    public function suspendEmissionForExtravio(int $emissionId, string $reason): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE emissoes_cif_estado
SET status_validade='SUSPENSA', status_fisico='NAO_LOCALIZADA', motivo_suspensao=?,
    suspensa_em=UTC_TIMESTAMP(6), atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1,
    public_version=public_version+1
WHERE emissao_id=? AND status_validade='ATIVA' AND status_fisico='ENTREGUE'
SQL);
        $stmt->bind_param('si', $reason, $emissionId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('A CIF não está mais em condição de ser suspensa por extravio.');
        }
        $stmt->close();
        return $this->publicVersion($emissionId);
    }

    public function reactivateRecoveredEmission(int $emissionId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE emissoes_cif_estado
SET status_validade='ATIVA', status_fisico='ENTREGUE', motivo_suspensao=NULL,
    atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1, public_version=public_version+1
WHERE emissao_id=? AND status_validade='SUSPENSA' AND status_fisico='NAO_LOCALIZADA'
SQL);
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('A CIF não está mais em condição de ser reativada após recuperação.');
        }
        $stmt->close();
        return $this->publicVersion($emissionId);
    }

    public function updatePhysicalStateOnly(int $emissionId, string $from, string $to): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE emissoes_cif_estado
SET status_fisico=?, atualizado_em=UTC_TIMESTAMP(6), lock_version=lock_version+1
WHERE emissao_id=? AND status_fisico=?
SQL);
        $stmt->bind_param('sis', $to, $emissionId, $from);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new \RuntimeException('A situação física da CIF foi alterada por outra operação.');
        }
        $stmt->close();
    }

    private function publicVersion(int $emissionId): int
    {
        $stmt = $this->db->prepare('SELECT public_version FROM emissoes_cif_estado WHERE emissao_id=? LIMIT 1');
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['public_version'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function publicProjectionSourceByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT e.id, e.uuid, e.codigo_validacao, e.qr_token_hash, e.qr_token_ciphertext, e.qr_key_id,
       e.emitida_em, s.nome_completo, s.matricula AS matricula_snapshot, s.data_admissao,
       es.status_validade, es.public_version, es.atualizado_em
FROM emissoes_cif e
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
WHERE e.uuid=?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @param list<array{emission_uuid:string,version:int,public_status?:string}> $items */
    public function enqueueProjectionRefresh(array $items): void
    {
        if ($items === []) {
            return;
        }
        $eventUuid = \SMTT\CIF\Gestao\Support\Uuid::v4();
        $normalized = [];
        foreach ($items as $item) {
            $row = [
                'emission_uuid' => (string) $item['emission_uuid'],
                'version' => (int) $item['version'],
            ];
            if (isset($item['public_status']) && is_string($item['public_status']) && $item['public_status'] !== '') {
                $row['public_status'] = $item['public_status'];
            }
            $normalized[] = $row;
        }
        $payload = json_encode([
            'kind' => 'CIF_PUBLIC_PROJECTION_REFRESH',
            'materialized' => count($normalized) === count(array_filter($normalized, static fn(array $item): bool => isset($item['public_status']))),
            'items' => $normalized,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload, true);
        $status = 'PENDING';
        $aggregateType = 'CIF_BATCH';
        $eventType = 'CIF_PROJECTION_REFRESH';
        $payloadVersion = 1;
        $aggregateVersion = max(array_map(static fn(array $item): int => (int) $item['version'], $normalized));
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sync_outbox (
    event_uuid, aggregate_type, aggregate_uuid, event_type, aggregate_version,
    payload_version, payload, payload_hash, status, tentativas, criado_em, disponivel_em
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('ssssiisss', $eventUuid, $aggregateType, $eventUuid, $eventType, $aggregateVersion, $payloadVersion, $payload, $hash, $status);
        $stmt->execute();
        $stmt->close();
    }

    public function upsertProjectionState(string $emissionUuid, int $version, string $desiredHash): void
    {
        $type = 'CIF';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sync_projection_state (
    aggregate_type, aggregate_uuid, desired_version, synced_version, desired_hash, last_enqueued_at
) VALUES (?, ?, ?, 0, ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE desired_version=VALUES(desired_version), desired_hash=VALUES(desired_hash), last_enqueued_at=VALUES(last_enqueued_at)
SQL);
        $stmt->bind_param('ssis', $type, $emissionUuid, $version, $desiredHash);
        $stmt->execute();
        $stmt->close();
    }

}
