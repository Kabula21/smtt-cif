<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;
use RuntimeException;

final class ReportRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        $sql = <<<'SQL'
SELECT
  (SELECT COUNT(*) FROM agentes WHERE ativo=1) AS agentes_ativos,
  (SELECT COUNT(*) FROM agentes WHERE ativo=0) AS agentes_arquivados,
  (SELECT COUNT(*) FROM processos_emissao) AS processos_total,
  (SELECT COUNT(*) FROM processos_emissao WHERE status_codigo NOT IN ('CONCLUIDO','INDEFERIDO','CANCELADO')) AS processos_abertos,
  (SELECT COUNT(*) FROM emissoes_cif) AS emissoes_total,
  (SELECT COUNT(*) FROM emissoes_cif_estado WHERE status_validade='ATIVA') AS cif_ativas,
  (SELECT COUNT(*) FROM emissoes_cif_estado WHERE status_validade='PENDENTE_ATIVACAO') AS cif_pendentes,
  (SELECT COUNT(*) FROM ocorrencias_cif WHERE status='ABERTA') AS ocorrencias_abertas,
  (SELECT COUNT(*) FROM recolhimentos_cif WHERE status NOT IN ('RECOLHIDA','INUTILIZADA','NAO_RECOLHIDA_JUSTIFICADA')) AS recolhimentos_pendentes
SQL;
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string)$key] = (int)$value;
        }
        return $out;
    }

    /**
     * @param array<string,string> $filters
     * @return array{total:int,items:list<array<string,mixed>>,columns:array<string,string>,sortable:list<string>,sort:string,direction:string}
     */
    public function search(string $type, array $filters, int $page = 1, int $perPage = 25): array
    {
        $definition = $this->definition($type);
        [$where, $params] = $this->filters($filters, $definition['date'], $definition['status'], $definition['q']);

        $count = $this->db->prepare('SELECT COUNT(*) AS total ' . $definition['from'] . $where);
        $count->execute($params);
        $row = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int)($row['total'] ?? 0);

        [$sortKey, $direction, $orderSql] = $this->order($definition, $filters);
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare($definition['select'] . ' ' . $definition['from'] . $where . ' ORDER BY ' . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $stmt->execute($params);
        $result = $stmt->get_result();
        $items = [];
        while ($item = $result->fetch_assoc()) {
            $items[] = $item;
        }
        $result->free();
        $stmt->close();

        return [
            'total' => $total,
            'items' => $items,
            'columns' => $definition['columns'],
            'sortable' => array_keys($definition['sort']),
            'sort' => $sortKey,
            'direction' => $direction,
        ];
    }

    /** @param array<string,string> $filters @return array{items:list<array<string,mixed>>,columns:array<string,string>} */
    public function export(string $type, array $filters, int $limit = 10000): array
    {
        $definition = $this->definition($type);
        [$where, $params] = $this->filters($filters, $definition['date'], $definition['status'], $definition['q']);
        [, , $orderSql] = $this->order($definition, $filters);
        $limit = max(1, min(50000, $limit));
        $stmt = $this->db->prepare($definition['select'] . ' ' . $definition['from'] . $where . ' ORDER BY ' . $orderSql . ' LIMIT ' . $limit);
        $stmt->execute($params);
        $result = $stmt->get_result();
        $items = [];
        while ($item = $result->fetch_assoc()) {
            $items[] = $item;
        }
        $result->free();
        $stmt->close();
        return ['items' => $items, 'columns' => $definition['columns']];
    }

    /**
     * @return array{select:string,from:string,date:string,status:?string,columns:array<string,string>,q:string,sort:array<string,string>,default_sort:string}
     */
    private function definition(string $type): array
    {
        return match ($type) {
            'agentes' => [
                'select' => "SELECT a.uuid AS __uuid, a.matricula, b.nome_completo, a.situacao_funcional_atual, IF(a.ativo=1,'ATIVO','ARQUIVADO') AS cadastro_status, b.data_admissao, a.criado_em, a.atualizado_em",
                'from' => "FROM agentes a JOIN agentes_dados_biograficos b ON b.agente_id=a.id AND b.versao=(SELECT MAX(b2.versao) FROM agentes_dados_biograficos b2 WHERE b2.agente_id=a.id)",
                'date' => 'a.criado_em',
                'status' => 'a.situacao_funcional_atual',
                'columns' => ['nome_completo'=>'Nome','matricula'=>'Matrícula','situacao_funcional_atual'=>'Situação funcional','cadastro_status'=>'Cadastro','data_admissao'=>'Admissão','criado_em'=>'Cadastrado em','atualizado_em'=>'Atualizado em'],
                'q' => '(a.matricula LIKE ? OR b.nome_completo LIKE ?)',
                'sort' => ['nome_completo'=>'b.nome_completo','matricula'=>'a.matricula','situacao_funcional_atual'=>'a.situacao_funcional_atual','cadastro_status'=>'a.ativo','data_admissao'=>'b.data_admissao','criado_em'=>'a.criado_em','atualizado_em'=>'a.atualizado_em'],
                'default_sort' => 'criado_em',
            ],
            'processos' => [
                'select' => 'SELECT p.uuid AS __uuid, a.matricula, b.nome_completo, p.ciclo_numero, p.tipo_codigo, p.status_codigo, p.numero_protocolo, p.protocolo_externo, p.criado_em, p.atualizado_em',
                'from' => "FROM processos_emissao p JOIN agentes a ON a.id=p.agente_id JOIN agentes_dados_biograficos b ON b.agente_id=a.id AND b.versao=(SELECT MAX(b2.versao) FROM agentes_dados_biograficos b2 WHERE b2.agente_id=a.id)",
                'date' => 'p.criado_em',
                'status' => 'p.status_codigo',
                'columns' => ['numero_protocolo'=>'Protocolo CIF','ciclo_numero'=>'Ciclo','nome_completo'=>'Agente','matricula'=>'Matrícula','tipo_codigo'=>'Tipo','status_codigo'=>'Status','protocolo_externo'=>'Protocolo externo','criado_em'=>'Criado em','atualizado_em'=>'Atualizado em'],
                'q' => '(a.matricula LIKE ? OR b.nome_completo LIKE ? OR p.numero_protocolo LIKE ? OR p.protocolo_externo LIKE ?)',
                'sort' => ['numero_protocolo'=>'p.numero_protocolo','ciclo_numero'=>'p.ciclo_numero','nome_completo'=>'b.nome_completo','matricula'=>'a.matricula','tipo_codigo'=>'p.tipo_codigo','status_codigo'=>'p.status_codigo','protocolo_externo'=>'p.protocolo_externo','criado_em'=>'p.criado_em','atualizado_em'=>'p.atualizado_em'],
                'default_sort' => 'criado_em',
            ],
            'emissoes' => [
                'select' => 'SELECT e.uuid AS __uuid, s.matricula, s.nome_completo, COALESCE(e.ciclo_numero_agente,0) AS ciclo_numero, e.sequencial_sistema, e.codigo_validacao, e.tipo_emissao_codigo, es.status_validade, es.status_fisico, e.emitida_em, es.ativada_em, es.entregue_em',
                'from' => 'FROM emissoes_cif e JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id JOIN emissoes_cif_estado es ON es.emissao_id=e.id',
                'date' => 'e.emitida_em',
                'status' => 'es.status_validade',
                'columns' => ['codigo_validacao'=>'Código CIF','ciclo_numero'=>'Ciclo','sequencial_sistema'=>'Sequencial global','nome_completo'=>'Agente','matricula'=>'Matrícula','tipo_emissao_codigo'=>'Tipo','status_validade'=>'Validade','status_fisico'=>'Situação física','emitida_em'=>'Emitida em','ativada_em'=>'Ativada em','entregue_em'=>'Entregue em'],
                'q' => '(s.matricula LIKE ? OR s.nome_completo LIKE ? OR e.codigo_validacao LIKE ?)',
                'sort' => ['codigo_validacao'=>'e.codigo_validacao','ciclo_numero'=>'e.ciclo_numero_agente','sequencial_sistema'=>'e.sequencial_sistema','nome_completo'=>'s.nome_completo','matricula'=>'s.matricula','tipo_emissao_codigo'=>'e.tipo_emissao_codigo','status_validade'=>'es.status_validade','status_fisico'=>'es.status_fisico','emitida_em'=>'e.emitida_em','ativada_em'=>'es.ativada_em','entregue_em'=>'es.entregue_em'],
                'default_sort' => 'emitida_em',
            ],
            'ocorrencias' => [
                'select' => 'SELECT o.uuid AS __uuid, s.matricula, s.nome_completo, e.codigo_validacao, o.tipo_codigo, o.status, o.data_fato, o.data_comunicacao, o.bo_numero, o.recuperada_em',
                'from' => 'FROM ocorrencias_cif o JOIN emissoes_cif e ON e.id=o.emissao_id JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id',
                'date' => 'o.data_comunicacao',
                'status' => 'o.status',
                'columns' => ['data_comunicacao'=>'Comunicação','nome_completo'=>'Agente','matricula'=>'Matrícula','codigo_validacao'=>'Código CIF','tipo_codigo'=>'Ocorrência','status'=>'Status','data_fato'=>'Fato','bo_numero'=>'BO','recuperada_em'=>'Recuperada em'],
                'q' => '(s.matricula LIKE ? OR s.nome_completo LIKE ? OR e.codigo_validacao LIKE ? OR o.bo_numero LIKE ?)',
                'sort' => ['data_comunicacao'=>'o.data_comunicacao','nome_completo'=>'s.nome_completo','matricula'=>'s.matricula','codigo_validacao'=>'e.codigo_validacao','tipo_codigo'=>'o.tipo_codigo','status'=>'o.status','data_fato'=>'o.data_fato','bo_numero'=>'o.bo_numero','recuperada_em'=>'o.recuperada_em'],
                'default_sort' => 'data_comunicacao',
            ],
            'recolhimentos' => [
                'select' => 'SELECT r.uuid AS __uuid, s.matricula, s.nome_completo, e.codigo_validacao, r.motivo_codigo, r.status, r.recolhimento_solicitado_em, r.recolhimento_devido_em, r.recolhida_em, r.inutilizada_em',
                'from' => 'FROM recolhimentos_cif r JOIN emissoes_cif e ON e.id=r.emissao_id JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id',
                'date' => 'r.recolhimento_solicitado_em',
                'status' => 'r.status',
                'columns' => ['recolhimento_solicitado_em'=>'Solicitado em','nome_completo'=>'Agente','matricula'=>'Matrícula','codigo_validacao'=>'Código CIF','motivo_codigo'=>'Motivo','status'=>'Status','recolhimento_devido_em'=>'Devido em','recolhida_em'=>'Recolhida em','inutilizada_em'=>'Inutilizada em'],
                'q' => '(s.matricula LIKE ? OR s.nome_completo LIKE ? OR e.codigo_validacao LIKE ?)',
                'sort' => ['recolhimento_solicitado_em'=>'r.recolhimento_solicitado_em','nome_completo'=>'s.nome_completo','matricula'=>'s.matricula','codigo_validacao'=>'e.codigo_validacao','motivo_codigo'=>'r.motivo_codigo','status'=>'r.status','recolhimento_devido_em'=>'r.recolhimento_devido_em','recolhida_em'=>'r.recolhida_em','inutilizada_em'=>'r.inutilizada_em'],
                'default_sort' => 'recolhimento_solicitado_em',
            ],
            'atividade_usuarios' => [
                'select' => "SELECT a.id AS __audit_id, a.uuid AS __audit_uuid, a.ocorrido_em, COALESCE(a.actor_nome,'Sistema') actor_nome, COALESCE(a.actor_login,'—') actor_login, COALESCE(u.setor,'—') setor, COALESCE((SELECT GROUP_CONCAT(pa.nome ORDER BY pa.nome SEPARATOR ', ') FROM usuario_perfis up JOIN perfis_acesso pa ON pa.id=up.perfil_id WHERE up.usuario_id=u.id),'—') AS perfil, a.operacao, a.entidade_tipo, COALESCE(a.entidade_uuid,'') entidade_uuid, a.entidade_id, a.origem, a.request_id, a.valor_anterior, a.valor_posterior, a.justificativa, INET6_NTOA(a.ip_origem) AS ip_origem_texto, a.previous_hash, a.record_hash",
                'from' => 'FROM auditoria a LEFT JOIN usuarios_cif u ON u.id=a.usuario_cif_id',
                'date' => 'a.ocorrido_em',
                'status' => null,
                'columns' => ['ocorrido_em'=>'Data/hora','actor_nome'=>'Usuário','setor'=>'Setor','perfil'=>'Perfil','operacao'=>'Ação','entidade_tipo'=>'Registro afetado'],
                'q' => "(a.actor_nome LIKE ? OR a.actor_login LIKE ? OR u.setor LIKE ? OR a.operacao LIKE ? OR a.entidade_tipo LIKE ? OR a.entidade_uuid LIKE ?)",
                'sort' => ['ocorrido_em'=>'a.ocorrido_em','actor_nome'=>'a.actor_nome','setor'=>'u.setor','operacao'=>'a.operacao','entidade_tipo'=>'a.entidade_tipo'],
                'default_sort' => 'ocorrido_em',
            ],
            default => throw new RuntimeException('Tipo de relatório inválido.'),
        };
    }

    /** @param array<string,string> $filters @return array{0:string,1:list<string>} */
    private function filters(array $filters, string $dateColumn, ?string $statusColumn, string $qClause): array
    {
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = $qClause;
            $like = '%' . $q . '%';
            for ($i = 0; $i < substr_count($qClause, '?'); ++$i) {
                $params[] = $like;
            }
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && $statusColumn !== null) {
            $where[] = $statusColumn . ' = ?';
            $params[] = $status;
        }
        $date = trim((string)($filters['data'] ?? ''));
        if ($date !== '') {
            $where[] = $dateColumn . ' >= ? AND ' . $dateColumn . ' < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 00:00:00';
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array{sort:array<string,string>,default_sort:string} $definition
     * @param array<string,string> $filters
     * @return array{0:string,1:string,2:string}
     */
    private function order(array $definition, array $filters): array
    {
        $requested = trim((string)($filters['ordem'] ?? ''));
        $sortKey = array_key_exists($requested, $definition['sort']) ? $requested : $definition['default_sort'];
        $direction = strtoupper(trim((string)($filters['direcao'] ?? 'DESC')));
        $direction = $direction === 'ASC' ? 'ASC' : 'DESC';
        $column = $definition['sort'][$sortKey];
        // Desempate estável reduz saltos entre páginas quando valores são iguais.
        $tie = $column === $definition['date'] ? '' : ', ' . $definition['date'] . ' DESC';
        return [$sortKey, $direction, $column . ' ' . $direction . $tie];
    }
}
