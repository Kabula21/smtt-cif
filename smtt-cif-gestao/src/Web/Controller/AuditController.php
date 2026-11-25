<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\AuditRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\AuditIntegrityService;
use SMTT\CIF\Gestao\Web\View;

final class AuditController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly AuditRepository $repository,
        private readonly AuditIntegrityService $integrity
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::AUDITORIA_VISUALIZAR);
        $filters = $this->filters();
        $page = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int)$_GET['por_pagina'] : 25;
        $perPage = in_array($perPage, [25,50,100], true) ? $perPage : 25;
        $result = $this->repository->search($filters, $page, $perPage);
        $pages = max(1, (int)ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $page = $pages;
            $result = $this->repository->search($filters, $page, $perPage);
        }

        $this->view->render('audit/index', [
            'title' => 'Auditoria',
            'currentUser' => $user,
            'activeNav' => 'audit',
            'filters' => $filters,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'operations' => $this->repository->operations(),
            'entityTypes' => $this->repository->entityTypes(),
            'integrity' => $this->integrity->verify(),
        ]);
    }

    /** @return array<string,string> */
    private function filters(): array
    {
        $get = static fn(string $key): string => isset($_GET[$key]) ? sanitize_text_field((string)wp_unslash($_GET[$key])) : '';
        return [
            'q' => trim($get('q')),
            'operacao' => trim($get('operacao')),
            'entidade' => trim($get('entidade')),
            'de' => $this->date($get('de')),
            'ate' => $this->date($get('ate')),
        ];
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
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
