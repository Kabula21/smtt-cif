<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var list<\SMTT\CIF\Gestao\Security\CifUser> $users */
/** @var list<array{code:string,name:string,description:string}> $profiles */
/** @var array<int,bool> $deletable */
$bodyClass = 'cif-page--list';
$profileNames = [];
foreach ($profiles as $profile) { $profileNames[$profile['code']] = $profile['name']; }
$queryBase = ['q'=>$q,'por_pagina'=>$perPage];
$from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$to = min($total, $page * $perPage);
$fmt = static function (?string $value): string {
    if ($value === null || $value === '') return '—';
    $ts = strtotime($value . ' UTC');
    return $ts ? wp_date('d/m/Y H:i', $ts) : $value;
};
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div>
        <p class="cif-eyebrow">Segurança e autorização · v<?= esc_html(SMTT_CIF_GESTAO_VERSION) ?></p>
        <h1>Usuários e acessos</h1>
        <p>Contas próprias do Sistema CIF armazenadas no banco <strong>smtt_cif</strong>. Nenhum usuário operacional é criado no WordPress.</p>
    </div>
    <div class="cif-inline-actions">
        <button class="cif-button cif-button--secondary" type="button" data-modal-open="cif-permission-matrix-modal">Matriz de permissões</button>
        <?php if ($canManage): ?><button class="cif-button cif-button--primary" type="button" data-modal-open="cif-user-create-modal">Novo usuário CIF</button><?php endif; ?>
    </div>
</section>
<?php if ($notice): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>

<section class="cif-list-screen">
    <div class="cif-list-filters">
        <form method="get" action="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>" class="cif-filter-row">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr($q) ?>" placeholder="Nome, login, e-mail, setor ou perfil"></label>
            <label class="cif-field"><span>Por página</span><select name="por_pagina"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= selected($perPage,$size,false) ?>><?= $size ?></option><?php endforeach; ?></select></label>
            <div class="cif-filter-row__actions"><button class="cif-button cif-button--primary" type="submit">Buscar</button><a class="cif-button cif-button--ghost" data-no-modal href="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>">Limpar</a></div>
        </form>
    </div>

    <div class="cif-list-meta"><div><strong>Usuários CIF</strong><span><?= esc_html((string)$total) ?> cadastrado(s)</span></div><div class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></div></div>
    <div class="cif-list-scroll"><table class="cif-table cif-table--users"><thead><tr><th>Usuário</th><th>Setor</th><th>Perfil</th><th>Último login</th><th>Status</th><th class="cif-table__actions">Ações</th></tr></thead><tbody>
    <?php if ($users === []): ?><tr><td colspan="6" class="cif-empty">Nenhum usuário localizado.</td></tr><?php else: foreach ($users as $managedUser): $modalId='cif-user-modal-'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)$managedUser->uuid); ?>
    <tr>
        <td><strong><?= esc_html($managedUser->name) ?></strong><?= $managedUser->id === $currentUser->id ? ' <span class="cif-badge">Você</span>' : '' ?><br><span class="cif-muted cif-small"><?= esc_html($managedUser->login) ?> · <?= esc_html((string)($managedUser->email ?? '—')) ?></span></td>
        <td><?= esc_html((string)($managedUser->department ?? '—')) ?></td>
        <td><div class="cif-profile-tags"><?php foreach ($managedUser->profiles as $code): ?><span class="cif-badge"><?= esc_html($profileNames[$code] ?? $code) ?></span><?php endforeach; ?></div></td>
        <td><?= esc_html($fmt($managedUser->lastLoginAt)) ?><?php if ($managedUser->mustChangePassword): ?><br><span class="cif-badge cif-badge--warning">Troca de senha pendente</span><?php endif; ?></td>
        <td><span class="cif-badge <?= $managedUser->active ? 'cif-badge--success' : 'cif-badge--warning' ?>"><?= $managedUser->active ? 'Ativo' : 'Inativo' ?></span></td>
        <td class="cif-table__actions"><?php if ($canManage): ?><button type="button" class="cif-button cif-button--small cif-button--secondary" data-modal-open="<?= esc_attr($modalId) ?>">Gerenciar</button><?php else: ?>—<?php endif; ?></td>
    </tr>
    <?php endforeach; endif; ?></tbody></table></div>
    <div class="cif-list-footer"><span><?= esc_html((string)$total) ?> usuário(s) · página <?= esc_html((string)$page) ?> de <?= esc_html((string)$pages) ?></span><?php if ($pages > 1): ?><nav class="cif-pagination" aria-label="Paginação"><?php for ($i=1;$i<=$pages;$i++): $args=$queryBase; $args['pagina']=$i; ?><a class="<?= $i===$page?'is-current':'' ?>" href="<?= esc_url(add_query_arg($args,home_url('/cif/seguranca/usuarios'))) ?>"><?= esc_html((string)$i) ?></a><?php endfor; ?></nav><?php endif; ?></div>
</section>

<?php if ($canManage): foreach ($users as $managedUser): $modalId='cif-user-modal-'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)$managedUser->uuid); $canDelete=(bool)($deletable[$managedUser->id] ?? false); ?>
<div class="cif-modal" id="<?= esc_attr($modalId) ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="<?= esc_attr($modalId) ?>-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Usuário CIF</span><h2 id="<?= esc_attr($modalId) ?>-title"><?= esc_html($managedUser->name) ?></h2><p><?= esc_html($managedUser->login) ?> · <?= esc_html((string)($managedUser->department ?? 'Setor não informado')) ?></p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body cif-user-modal">
            <section class="cif-user-modal__section">
                <div class="cif-user-modal__section-head"><div><span>Dados do usuário</span><h3>Identificação e perfil</h3></div><p>Atualize os dados cadastrais e o nível de acesso desta conta.</p></div>
                <form method="post" action="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>" class="cif-form">
                    <?php \smtt_cif_nonce_field('smtt_cif_manage_users','smtt_cif_nonce'); ?>
                    <input type="hidden" name="operation" value="update"><input type="hidden" name="target_uuid" value="<?= esc_attr($managedUser->uuid) ?>">
                    <div class="cif-form-grid">
                    <label class="cif-field"><span>Nome completo</span><input type="text" name="name" maxlength="200" required value="<?= esc_attr($managedUser->name) ?>"></label>
                    <label class="cif-field"><span>Login</span><input type="text" name="login" maxlength="100" required value="<?= esc_attr($managedUser->login) ?>" autocomplete="off"></label>
                    <label class="cif-field"><span>E-mail</span><input type="email" name="email" maxlength="190" required value="<?= esc_attr((string)$managedUser->email) ?>"></label>
                    <label class="cif-field"><span>Setor</span><input type="text" name="department" maxlength="150" required value="<?= esc_attr((string)$managedUser->department) ?>"></label>
                    <label class="cif-field"><span>Perfil</span><select name="profile" required><?php foreach ($profiles as $profile): ?><option value="<?= esc_attr($profile['code']) ?>" <?= selected(in_array($profile['code'],$managedUser->profiles,true),true,false) ?>><?= esc_html($profile['name']) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <div class="cif-form-actions"><button type="submit" class="cif-button cif-button--primary">Salvar alterações</button></div>
                </form>
            </section>

            <section class="cif-user-modal__section">
                <div class="cif-user-modal__section-head"><div><span>Credencial</span><h3>Redefinir senha</h3></div><p>Ao redefinir, todas as sessões serão encerradas e a troca será exigida no próximo acesso.</p></div>
                <form method="post" action="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>" class="cif-form">
                    <?php \smtt_cif_nonce_field('smtt_cif_manage_users','smtt_cif_nonce'); ?>
                    <input type="hidden" name="operation" value="reset_password"><input type="hidden" name="target_uuid" value="<?= esc_attr($managedUser->uuid) ?>">
                    <div class="cif-form-grid">
                        <label class="cif-field"><span>Nova senha</span><input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                        <label class="cif-field"><span>Confirmar nova senha</span><input type="password" name="password_confirm" minlength="10" required autocomplete="new-password"></label>
                    </div>
                    <div class="cif-form-actions"><button class="cif-button cif-button--secondary" type="submit">Redefinir senha</button></div>
                </form>
            </section>

            <?php if ($managedUser->id !== $currentUser->id): ?>
            <section class="cif-user-modal__section cif-user-modal__section--state">
                <div class="cif-user-modal__section-head"><div><span>Acesso</span><h3>Estado do usuário</h3></div><p>Controle o acesso sem perder o histórico e a autoria das operações.</p></div>
                <div class="cif-inline-actions">
                    <form method="post" action="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>">
                        <?php \smtt_cif_nonce_field('smtt_cif_manage_users','smtt_cif_nonce'); ?>
                        <input type="hidden" name="operation" value="status"><input type="hidden" name="target_uuid" value="<?= esc_attr($managedUser->uuid) ?>"><input type="hidden" name="active" value="<?= $managedUser->active?'0':'1' ?>">
                        <button type="submit" class="cif-button <?= $managedUser->active?'cif-button--secondary':'cif-button--primary' ?>"><?= $managedUser->active?'Inativar usuário':'Reativar usuário' ?></button>
                    </form>
                </div>
                <?php if ($canDelete): ?>
                    <p class="cif-muted cif-small">Este usuário ainda não possui histórico operacional e pode ser excluído definitivamente.</p>
                    <form method="post" action="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>" class="cif-form">
                        <?php \smtt_cif_nonce_field('smtt_cif_manage_users','smtt_cif_nonce'); ?>
                        <input type="hidden" name="operation" value="delete"><input type="hidden" name="target_uuid" value="<?= esc_attr($managedUser->uuid) ?>">
                        <label class="cif-field"><span>Para excluir definitivamente, digite EXCLUIR</span><input type="text" name="confirm_delete" autocomplete="off"></label>
                        <button type="submit" class="cif-button cif-button--danger">Excluir definitivamente</button>
                    </form>
                <?php else: ?>
                    <div class="cif-alert cif-alert--info"><strong>Histórico preservado.</strong> Este usuário já realizou ações no Sistema CIF e não pode ser apagado. Utilize a inativação para impedir novos acessos mantendo a autoria dos registros.</div>
                <?php endif; ?>
            </section>
            <?php else: ?><div class="cif-alert cif-alert--info">Seu próprio usuário não pode ser inativado ou excluído durante a sessão atual.</div><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="cif-modal" id="cif-user-create-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-user-create-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Novo usuário</span><h2 id="cif-user-create-title">Cadastrar usuário CIF</h2><p>A conta será criada somente no banco próprio do Sistema CIF.</p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body cif-user-modal">
            <section class="cif-user-modal__section">
                <div class="cif-user-modal__section-head"><div><span>Nova conta</span><h3>Dados do usuário</h3></div><p>Cadastre a identidade, setor, perfil e a senha inicial do usuário.</p></div>
                <form method="post" action="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>" class="cif-form">
                <?php \smtt_cif_nonce_field('smtt_cif_manage_users','smtt_cif_nonce'); ?>
                <input type="hidden" name="operation" value="create">
                <div class="cif-form-grid">
                    <label class="cif-field"><span>Nome completo</span><input type="text" name="name" maxlength="200" required></label>
                    <label class="cif-field"><span>Login</span><input type="text" name="login" maxlength="100" placeholder="ex.: afernandes" required autocomplete="off"></label>
                    <label class="cif-field"><span>E-mail</span><input type="email" name="email" maxlength="190" required></label>
                    <label class="cif-field"><span>Setor</span><input type="text" name="department" maxlength="150" placeholder="ex.: Recursos Humanos" required></label>
                    <label class="cif-field"><span>Perfil</span><select name="profile" required><option value="">Selecione</option><?php foreach ($profiles as $profile): ?><option value="<?= esc_attr($profile['code']) ?>"><?= esc_html($profile['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="cif-field"><span>Senha inicial</span><input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                    <label class="cif-field"><span>Confirmar senha</span><input type="password" name="password_confirm" minlength="10" required autocomplete="new-password"></label>
                </div>
                <p class="cif-muted cif-small">A senha é armazenada apenas como hash. O usuário deverá substituí-la no primeiro acesso.</p>
                <div class="cif-form-actions"><button type="button" class="cif-button cif-button--ghost" data-modal-close>Cancelar</button><button type="submit" class="cif-button cif-button--primary">Criar usuário</button></div>
                </form>
            </section>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="cif-modal" id="cif-permission-matrix-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-permission-matrix-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Governança</span><h2 id="cif-permission-matrix-title">Matriz de permissões CIF</h2><p>Os três perfis são internos ao Sistema CIF e independem das roles do WordPress.</p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <div class="cif-permission-legend"><span><strong>Administrador CIF</strong> controle integral do sistema.</span><span><strong>Editor CIF</strong> operação autorizada sem configurações administrativas.</span><span><strong>Consulta CIF</strong> leitura somente.</span></div>
            <div class="cif-table-wrap"><table class="cif-table cif-table--permissions"><thead><tr><th>Módulo</th><th>Permissão</th><th>ADMIN_CIF</th><th>EDITOR_CIF</th><th>CONSULTA_CIF</th></tr></thead><tbody>
            <?php foreach ($permissionMatrix as $permission): ?><tr><td><?= esc_html(ucfirst(str_replace('_',' ',$permission['module']))) ?></td><td><strong><?= esc_html($permission['name']) ?></strong><br><code><?= esc_html($permission['code']) ?></code></td><?php foreach (['ADMIN_CIF','EDITOR_CIF','CONSULTA_CIF'] as $profileCode): ?><td><span class="cif-permission-state <?= in_array($profileCode,$permission['profiles'],true)?'is-allowed':'is-denied' ?>"><?= in_array($profileCode,$permission['profiles'],true)?'Permitido':'—' ?></span></td><?php endforeach; ?></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
</div>

<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
