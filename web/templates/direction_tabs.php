<?php
/**
 * "Inbound | Outbound" tabs over two pages that share one menu entry (Routes, Normalization).
 * A tab shows only to someone allowed to open its page.
 *
 * @var array{0: string, 1: string, 2: string, 3: string} $dirTabs inbound href, its module, outbound href, its module
 * @var string $dirActive 'in' or 'out'
 */
[$inHref, $inModule, $outHref, $outModule] = $dirTabs;
echo uiTabs(array_filter([
    'in' => hasModulePermission($inModule, 'view') ? ['label' => t('common.tab_inbound'), 'icon' => 'fa-arrow-right-to-bracket', 'href' => $inHref] : null,
    'out' => hasModulePermission($outModule, 'view') ? ['label' => t('common.tab_outbound'), 'icon' => 'fa-arrow-right-from-bracket', 'href' => $outHref] : null,
]), $dirActive);
