<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\ReportRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Web\View;

final class ReportController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly ReportRepository $repository,
        private readonly AuditLogger $audit
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::RELATORIO_VISUALIZAR);
        $type = $this->reportType($user);
        $filters = $this->filters();
        $page = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int)$_GET['por_pagina'] : 25;
        $perPage = in_array($perPage, [25,50,100], true) ? $perPage : 25;
        $result = $this->repository->search($type, $filters, $page, $perPage);
        $pages = max(1, (int)ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $page = $pages;
            $result = $this->repository->search($type, $filters, $page, $perPage);
        }

        $this->view->render('reports/index', [
            'title' => 'Relatórios',
            'currentUser' => $user,
            'activeNav' => 'reports',
            'summary' => $this->repository->summary(),
            'type' => $type,
            'typeOptions' => $this->typeOptions($user),
            'statusOptions' => $this->statusOptions($type),
            'filters' => $filters,
            'items' => $result['items'],
            'columns' => $result['columns'],
            'sortable' => $result['sortable'],
            'sort' => $result['sort'],
            'direction' => $result['direction'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'canExport' => $user->can(PermissionCodes::RELATORIO_EXPORTAR),
        ]);
    }

    public function export(): never
    {
        $user = $this->require(PermissionCodes::RELATORIO_EXPORTAR);
        $type = $this->reportType($user);
        $filters = $this->filters();
        $result = $this->repository->export($type, $filters);

        $this->audit->log(
            'RELATORIO_EXPORTADO',
            'RELATORIO',
            null,
            null,
            $user,
            'WEB',
            null,
            ['tipo' => $type, 'filtros' => $filters, 'registros' => count($result['items'])]
        );

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="relatorio-cif-' . $type . '-' . gmdate('Ymd-His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        fputcsv($out, array_values($result['columns']), ';');
        foreach ($result['items'] as $row) {
            $line = [];
            foreach (array_keys($result['columns']) as $key) {
                $line[] = (string)($row[$key] ?? '');
            }
            fputcsv($out, $line, ';');
        }
        fclose($out);
        exit;
    }

    /** @return array<string,string> */
    private function filters(): array
    {
        $get = static fn(string $key): string => isset($_GET[$key])
            ? sanitize_text_field((string)wp_unslash($_GET[$key]))
            : '';
        $direction = strtoupper(trim($get('direcao')));
        return [
            'q' => trim($get('q')),
            'status' => strtoupper(trim($get('status'))),
            'data' => $this->date($get('data')),
            'ordem' => sanitize_key($get('ordem')),
            'direcao' => $direction === 'ASC' ? 'ASC' : 'DESC',
        ];
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function reportType(\SMTT\CIF\Gestao\Security\CifUser $user): string
    {
        $type = isset($_GET['tipo']) ? sanitize_key((string)wp_unslash($_GET['tipo'])) : 'emissoes';
        return array_key_exists($type, $this->typeOptions($user)) ? $type : 'emissoes';
    }

    /** @return array<string,string> */
    private function typeOptions(\SMTT\CIF\Gestao\Security\CifUser $user): array
    {
        $options = [
            'agentes' => 'Agentes',
            'processos' => 'Processos',
            'emissoes' => 'Emissões CIF',
        ];
        if ($user->isSystemAdministrator()) {
            $options['atividade_usuarios'] = 'Atividade dos usuários';
        }
        return $options;
    }

    /** @return array<string,string> */
    private function statusOptions(string $type): array
    {
        return match ($type) {
            'agentes' => ['ATIVO'=>'Ativo','AFASTADO'=>'Afastado','EXONERADO'=>'Exonerado','VACANCIA'=>'Vacância','FALECIDO'=>'Falecido'],
            'processos' => ['RASCUNHO'=>'Rascunho (legado)','EM_ANALISE'=>'Em tramitação','PENDENTE_DOCUMENTACAO'=>'Pendente de documentação','APROVADO'=>'Aprovado','EM_EXECUCAO'=>'Em execução','CONCLUIDO'=>'Concluído','INDEFERIDO'=>'Indeferido','CANCELADO'=>'Cancelado'],
            'emissoes' => ['PENDENTE_ATIVACAO'=>'Pendente de ativação','ATIVA'=>'Ativa','SUSPENSA'=>'Suspensa','ENCERRADA'=>'Encerrada'],
            'ocorrencias' => ['ABERTA'=>'Aberta','REGISTRADA'=>'Registrada','RECUPERADA_REATIVADA'=>'Recuperada e reativada','RECUPERADA_APOS_SUBSTITUICAO'=>'Recuperada após substituição'],
            'recolhimentos' => ['PENDENTE'=>'Pendente','RECOLHIDA'=>'Recolhida','NAO_RECOLHIDA_JUSTIFICADA'=>'Não recolhida — justificada','INUTILIZADA'=>'Inutilizada'],
            default => [],
        };
    }

    private function require(string $permission): \SMTT\CIF\Gestao\Security\CifUser
    {
        try {
            return $this->gate->requirePermission($permission);
        } catch (AuthorizationException $e) {
            if ($this->gate->user() === null) {
                wp_safe_redirect(home_url('/cif/login'));
                exit;
            }
            $this->view->render('error/forbidden', [
                'title' => 'Acesso negado',
                'message' => $e->getMessage(),
                'currentUser' => $this->gate->user(),
            ], 403);
        }
    }
}
