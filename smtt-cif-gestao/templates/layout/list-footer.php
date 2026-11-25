<?php
/**
 * Rodapé padronizado das listagens CIF.
 * Espera: $listUrl, $queryBase, $page, $pages, $perPage, $total, $itemLabel.
 */
$listUrl = (string)($listUrl ?? home_url('/cif'));
$queryBase = is_array($queryBase ?? null) ? $queryBase : [];
$page = max(1, (int)($page ?? 1));
$pages = max(1, (int)($pages ?? 1));
$perPage = (int)($perPage ?? 25);
$total = max(0, (int)($total ?? 0));
$itemLabel = (string)($itemLabel ?? 'registro(s)');

$hidden = $queryBase;
unset($hidden['pagina'], $hidden['por_pagina']);

$window = [];
if ($pages <= 7) {
    $window = range(1, $pages);
} else {
    $wanted = [1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages];
    $wanted = array_values(array_unique(array_filter($wanted, static fn($n): bool => $n >= 1 && $n <= $pages)));
    sort($wanted, SORT_NUMERIC);
    $previous = null;
    foreach ($wanted as $number) {
        if ($previous !== null && $number > $previous + 1) {
            $window[] = null;
        }
        $window[] = $number;
        $previous = $number;
    }
}
?>
<div class="cif-list-footer">
    <div class="cif-list-footer__summary">
        <strong><?= esc_html((string)$total) ?></strong> <?= esc_html($itemLabel) ?>
        <span>· página <?= esc_html((string)$page) ?> de <?= esc_html((string)$pages) ?></span>
    </div>

    <form method="get" action="<?= esc_url($listUrl) ?>" class="cif-page-size-form">
        <?php foreach ($hidden as $key => $value): if (!is_scalar($value) || (string)$value === '') { continue; } ?>
            <input type="hidden" name="<?= esc_attr((string)$key) ?>" value="<?= esc_attr((string)$value) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="pagina" value="1">
        <label><span>Por página</span><select name="por_pagina" data-cif-auto-submit><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= selected($perPage,$size,false) ?>><?= $size ?></option><?php endforeach; ?></select></label>
        <noscript><button class="cif-button cif-button--ghost cif-button--small" type="submit">Aplicar</button></noscript>
    </form>

    <?php if ($pages > 1): ?>
    <nav class="cif-pagination" aria-label="Paginação">
        <?php if ($page > 1): ?><a class="cif-pagination__edge" href="<?= esc_url(add_query_arg(array_merge($queryBase,['pagina'=>$page-1,'por_pagina'=>$perPage]),$listUrl)) ?>" aria-label="Página anterior">‹</a><?php endif; ?>
        <?php foreach ($window as $number): ?>
            <?php if ($number === null): ?><span class="cif-pagination__ellipsis" aria-hidden="true">…</span><?php else: ?>
                <a class="<?= $number === $page ? 'is-current' : '' ?>" href="<?= esc_url(add_query_arg(array_merge($queryBase,['pagina'=>$number,'por_pagina'=>$perPage]),$listUrl)) ?>"><?= esc_html((string)$number) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a class="cif-pagination__edge" href="<?= esc_url(add_query_arg(array_merge($queryBase,['pagina'=>$page+1,'por_pagina'=>$perPage]),$listUrl)) ?>" aria-label="Próxima página">›</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
