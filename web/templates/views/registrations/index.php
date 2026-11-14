<?php
/** @var array $reg */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$kinds = ['desk' => ['fa-phone', t('registrations.kind_desk')], 'web' => ['fa-globe', t('registrations.kind_web')], 'mobile' => ['fa-mobile-alt', t('registrations.kind_mobile')]];
$stateBadge = static function (string $status): string {
    $s = strtolower($status);
    $cls = match (true) {
        $s === 'avail' || str_starts_with($s, 'registered') => 'badge-success',
        $s === 'nonqual' || $s === '' => 'badge-info',
        default => 'badge-danger',
    };
    // Asterisk's words, said plainly; the raw one stays in the tooltip.
    $label = match (true) {
        $s === 'avail' => t('registrations.state_avail'),
        $s === 'nonqual' => t('registrations.state_nonqual'),
        $s === 'unavail', $s === 'unreachable', $s === 'unavailable' => t('registrations.state_unavail'),
        default => $status,
    };
    return '<span class="badge ' . $cls . '" title="' . htmlspecialchars($status) . '">' . htmlspecialchars($label !== '' ? $label : '—') . '</span>';
};
$ago = fn(?int $s) => $s === null ? '—' : ($s >= 3600 ? floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min' : ($s >= 60 ? floor($s / 60) . ' min' : $s . ' s'));
$c = $reg['counts'];
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-plug', t('registrations.title'), 'regHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
        <a href="/registrations" class="btn btn-secondary btn-sm"><i class="fas fa-sync-alt"></i> <?php echo t('registrations.refresh'); ?></a>
<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('regHelpBox', t('registrations.help_title')); ?>
        <p><?php echo t('registrations.help'); ?></p>
    <?php echo uiHelpBoxClose(); ?>
    <p class="u-fs-13">
        <span class="badge badge-success"><i class="fas fa-phone"></i> <?php echo (int) $c['desk']; ?></span>
        <span class="badge badge-success"><i class="fas fa-globe"></i> <?php echo (int) $c['web']; ?></span>
        <span class="badge badge-success"><i class="fas fa-mobile-alt"></i> <?php echo (int) $c['mobile']; ?></span>
        <span class="badge badge-secondary"><?php echo $h(sprintf(t('registrations.none_count'), (int) $c['none'])); ?></span>
    </p>
    <?php echo uiTableOpen([t('registrations.col_extension'), t('registrations.col_device'), t('registrations.col_state'),
        ['label' => t('registrations.col_address'), 'class' => 'col-hide-mobile'], ['label' => t('registrations.col_agent'), 'class' => 'col-hide-mobile'],
        ['label' => t('registrations.col_rtt'), 'class' => 'col-hide-mobile'], ['label' => t('registrations.col_expires'), 'class' => 'col-hide-mobile']], ['id' => 'regTable']); ?>
        <?php foreach ($reg['extensions'] as $e): ?>
            <?php $rows = [];
            // Only the devices that are registered; an extension with none is one row.
            foreach ($e['devices'] as $kind => $list) {
                foreach ($list as $dev) {
                    $rows[] = [$kind, $dev];
                }
            }
            if (!$rows) {
                $rows[] = [null, null];
            } ?>
            <?php foreach ($rows as [$kind, $dev]): ?>
                <tr>
                    <td><strong><?php echo $h($e['extension']); ?></strong> <span class="u-muted u-fs-12"><?php echo $h($e['name']); ?></span></td>
                    <td><?php echo $kind ? '<i class="fas ' . $kinds[$kind][0] . '"></i> ' . $h($kinds[$kind][1]) : '<span class="u-muted">—</span>'; ?></td>
                    <td><?php echo $dev ? $stateBadge((string) $dev['status']) : '<span class="badge badge-secondary">' . $h(t('registrations.not_registered')) . '</span>'; ?></td>
                    <td class="col-hide-mobile u-mono u-fs-12"><?php echo $h($dev['address'] ?? ''); ?></td>
                    <td class="col-hide-mobile u-fs-12"><?php echo $h($dev['user_agent'] ?? ''); ?></td>
                    <td class="col-hide-mobile u-fs-12"><?php echo isset($dev['rtt']) && $dev['rtt'] !== null ? $h($dev['rtt'] . ' ms') : '—'; ?></td>
                    <td class="col-hide-mobile u-fs-12"><?php echo $h($dev ? $ago($dev['expires_in']) : '—'); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="card u-mt-16">
    <?php echo uiPageHeader('fa-network-wired', t('registrations.trunks_title')); ?>
    <?php echo uiTableOpen([t('registrations.col_trunk'), ['label' => t('registrations.col_host'), 'class' => 'col-hide-mobile'],
        t('registrations.col_registration'), t('registrations.col_reach'), ['label' => t('registrations.col_rtt'), 'class' => 'col-hide-mobile']], ['search' => false]); ?>
        <?php if (empty($reg['trunks'])): ?>
            <?php echo uiTableEmptyRow(5, t('registrations.no_trunks'), 'fa-network-wired'); ?>
        <?php endif; ?>
        <?php foreach ($reg['trunks'] as $t): ?>
            <tr>
                <td><strong><?php echo $h($t['title'] !== '' ? $t['title'] : $t['name']); ?></strong> <code class="u-fs-11"><?php echo $h($t['name']); ?></code>
                    <?php if (!$t['active']): ?><span class="badge badge-secondary"><?php echo t('common.passive'); ?></span><?php endif; ?></td>
                <td class="col-hide-mobile u-mono u-fs-12"><?php echo $h($t['host']); ?></td>
                <td><?php echo !$t['registers'] ? '<span class="u-muted u-fs-12">' . $h(t('registrations.ip_trunk')) . '</span>'
                    : ($t['registration'] ? $stateBadge((string) $t['registration']['status']) : $stateBadge(t('registrations.no_registration'))); ?></td>
                <td><?php echo $t['reach'] ? $stateBadge((string) $t['reach']['status']) : $stateBadge((string) $t['state']); ?></td>
                <td class="col-hide-mobile u-fs-12"><?php echo isset($t['reach']['rtt']) && $t['reach']['rtt'] !== null ? $h($t['reach']['rtt'] . ' ms') : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>
