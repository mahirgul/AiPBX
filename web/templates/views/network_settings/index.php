<?php
/**
 * Network → Network settings: the server's network cards (editable where netplan manages them), its
 * addresses as AiPBX uses them, the addresses desk phones are told, and moving
 * them all after the server's IP changed.
 *
 * @var string $csrf
 * @var array $system   NetworkSettingsService::system()
 * @var array<string, string> $settings
 * @var list<string> $localIps
 * @var list<array{where: string, value: string}> $stale
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$whereLabel = fn(string $w) => t('netset.where_' . $w, $w);
?>

<?php if ($stale !== []): ?>
<div class="card"><div class="alert alert-warning u-m-16-20">
    <div>
    <strong><i class="fas fa-triangle-exclamation"></i> <?php echo t('netset.stale_title'); ?></strong>
    <ul class="u-m-8-0-8-20">
        <?php foreach ($stale as $s): ?>
            <li><?php echo $h($whereLabel($s['where'])); ?>: <code><?php echo $h($s['value']); ?></code></li>
        <?php endforeach; ?>
    </ul>
    <?php echo sprintf(t('netset.stale_pending'), '<code>' . $h(implode(', ', $localIps)) . '</code>'); ?>
    <a href="/pending-sync"><?php echo t('pending_sync.title'); ?></a>
    </div>
</div></div>
<?php endif; ?>

<?php
$netcfg = NetworkConfigService::status();
$change = $netcfg['change'];
$pending = ($change['state'] ?? '') === 'pending';
$cfgByName = array_column($netcfg['interfaces'], null, 'name');
$isVirtual = static fn(string $name): bool => NetworkSettingsService::isVirtualInterface($name);
$cards = array_values(array_filter($system['interfaces'], fn($i) => !$isVirtual($i['name'])));
// Every interface in one table: the cards first, then bridges, tunnels and container links.
$rows = array_merge($cards, array_values(array_filter($system['interfaces'], fn($i) => $isVirtual($i['name']))));
?>
<div class="card" id="netcfg">
    <?php echo uiPageHeader('fa-ethernet', t('netcfg.cards_title')); ?>
    <div class="alert alert-info u-fs-12 u-m-8-20"><i class="fas fa-circle-info"></i> <?php echo sprintf(t('netset.addresses_elsewhere'),
        '<a href="/asterisk-settings">' . $h(t('sidebar.item_asterisk_settings')) . '</a>', '<a href="/phone-regions">' . $h(t('sidebar.item_phone_regions')) . '</a>'); ?></div>
    <?php $waiting = NetworkSettingsService::networksWaitingForRestart(); if ($waiting !== []): ?>
        <div class="alert alert-warning u-m-8-20">
            <?php echo sprintf(t('netset.restart_needed'), '<code>' . $h(implode(', ', $waiting)) . '</code>'); ?>
            <form method="POST" action="/network-settings#netcfg" class="u-mt-8">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <button type="submit" name="restart_for_networks" value="1" class="btn btn-secondary btn-sm"><i class="fas fa-rotate"></i> <?php echo t('netset.restart_button'); ?></button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($pending): ?>
        <div class="alert alert-warning u-m-8-20">
            <strong><i class="fas fa-hourglass-half"></i> <?php echo sprintf(t('netcfg.pending'), (int) ($change['seconds_left'] ?? 0)); ?></strong>
            <form method="POST" action="/network-settings#netcfg" class="u-flex-gap u-mt-8">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <button type="submit" name="netcfg_confirm" value="1" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> <?php echo t('netcfg.confirm'); ?></button>
                <button type="submit" name="netcfg_revert" value="1" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> <?php echo t('netcfg.revert'); ?></button>
            </form>
        </div>
    <?php elseif (($change['state'] ?? '') === 'reverted'): ?>
        <div class="alert alert-info u-m-8-20"><?php echo t('netcfg.was_reverted'); ?></div>
    <?php elseif (($change['state'] ?? '') === 'failed'): ?>
        <div class="alert alert-danger u-m-8-20"><?php echo $h($change['error'] ?? ''); ?></div>
    <?php endif; ?>
    <?php echo uiTableOpen([
        t('netset.interface'),
        t('netcfg.ip'),
        t('netcfg.netmask'),
        t('netset.gateway'),
        t('netset.dns'),
        '',
    ], ['search' => false, 'frame' => 'u-p-4-20-16']); ?>
            <?php foreach ($rows as $i => $if):
                $cfg = $cfgByName[$if['name']] ?? null;
                $v4 = array_values(array_filter($if['addresses'], fn($a) => strpos($a, ':') === false));
                $first = explode('/', (string) ($cfg['addresses'][0] ?? $v4[0] ?? ''), 2);
                $editable = $cfg !== null && !$pending;
            ?>
                <tr>
                    <td><code><?php echo $h($if['name']); ?></code>
                        <?php if ($cfg !== null): ?><span class="badge <?php echo $cfg['method'] === 'static' ? 'badge-primary' : 'badge-info'; ?> u-ml-4"><?php echo $cfg['method'] === 'static' ? t('netcfg.static') : 'DHCP'; ?></span><?php endif; ?>
                        <?php if (!$if['up']): ?><span class="badge badge-secondary"><?php echo t('netset.down'); ?></span><?php endif; ?></td>
                    <td><?php echo $h($first[0] ?: '-'); ?></td>
                    <td><?php echo $h(isset($first[1]) ? NetworkConfigService::maskOf((int) $first[1]) : '-'); ?></td>
                    <td><?php echo $h(($cfg['gateway'] ?? '') ?: '-'); ?>
                        <?php if (!empty($cfg['is_default'])): ?><span class="badge badge-success u-ml-4"><?php echo t('netcfg.default_badge'); ?></span><?php endif; ?>
                        <?php if (!empty($cfg['nets'])): ?><div class="u-muted u-fs-11"><?php echo $h(implode(', ', $cfg['nets'])); ?></div><?php endif; ?></td>
                    <td><?php echo $h(implode(', ', $cfg['dns'] ?? []) ?: '-'); ?></td>
                    <td class="u-text-right">
                        <?php if ($editable): ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('netcfgModal<?php echo $i; ?>')"><i class="fas fa-pen"></i> <?php echo t('common.edit'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
</div>

<?php foreach ($rows as $i => $if):
    $cfg = $cfgByName[$if['name']] ?? null;
    if ($cfg === null || $pending) { continue; }
    $static = $cfg['method'] === 'static';
    $firstParts = explode('/', (string) ($cfg['addresses'][0] ?? ''), 2);
?>
<div class="modal-overlay" id="netcfgModal<?php echo $i; ?>">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-ethernet u-primary"></i> <code><?php echo $h($if['name']); ?></code></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('netcfgModal<?php echo $i; ?>')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/network-settings#netcfg" autocomplete="off" onsubmit="return confirm(<?php echo jsArg(t('netcfg.confirm_apply')); ?>);">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="netcfg_apply" value="1">
                <input type="hidden" name="iface" value="<?php echo $h($if['name']); ?>">
                <?php if (($cfg['backend'] ?? '') === 'live'): ?><div class="alert alert-warning u-fs-12"><?php echo t('netcfg.live_note'); ?></div><?php endif; ?>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netcfg.mode'); ?></label>
                    <select name="mode" class="form-control" onchange="this.form.querySelector('.netcfg-static').style.display = this.value === 'static' ? 'block' : 'none';">
                        <option value="static" <?php echo $static ? 'selected' : ''; ?>><?php echo t('netcfg.static'); ?></option>
                        <option value="dhcp" <?php echo $static ? '' : 'selected'; ?>>DHCP</option>
                    </select>
                </div>
                <div class="netcfg-static" style="display: <?php echo $static ? 'block' : 'none'; ?>;">
                    <div class="u-grid-2">
                        <div class="form-group"><label class="form-label"><?php echo t('netcfg.ip'); ?></label><input type="text" name="address" class="form-control" maxlength="15" placeholder="192.168.1.10" value="<?php echo $h($firstParts[0]); ?>"></div>
                        <div class="form-group"><label class="form-label"><?php echo t('netcfg.netmask'); ?></label><input type="text" name="netmask" class="form-control" maxlength="15" placeholder="255.255.255.0" value="<?php echo $h(isset($firstParts[1]) ? NetworkConfigService::maskOf((int) $firstParts[1]) : '255.255.255.0'); ?>"></div>
                    </div>
                    <div class="form-group"><label class="form-label"><?php echo t('netset.gateway'); ?></label><input type="text" name="gateway" class="form-control" maxlength="15" value="<?php echo $h($cfg['gateway'] ?? ''); ?>"><small class="u-hint"><?php echo t('netcfg.gateway_help'); ?></small></div>
                    <div class="form-group"><label class="form-label"><?php echo t('netcfg.nets'); ?></label><input type="text" name="nets" class="form-control" maxlength="400" placeholder="10.12.0.0/16,192.168.5.0/24" value="<?php echo $h(implode(',', $cfg['nets'] ?? [])); ?>"><small class="u-hint"><?php echo t('netcfg.nets_help'); ?></small></div>
                    <div class="form-group"><label class="form-label"><?php echo t('netset.dns'); ?></label><input type="text" name="dns" class="form-control" maxlength="50" placeholder="1.1.1.1,8.8.8.8" value="<?php echo $h(implode(',', array_slice($cfg['dns'] ?? [], 0, 3))); ?>"></div>
                </div>
                <?php $defaultElsewhere = (bool) array_filter($netcfg['interfaces'], fn($c) => !empty($c['is_default']) && $c['name'] !== $if['name']); ?>
                <label class="u-check-label u-fs-12 u-m-8-0" style="display: block;"><input type="checkbox" name="is_default" value="1" class="u-accent" <?php echo !empty($cfg['is_default']) || !$defaultElsewhere ? 'checked' : ''; ?>> <?php echo t('netcfg.is_default'); ?></label>
                <label class="u-check-label u-fs-12 u-m-8-0" style="display: block;"><input type="checkbox" name="end_calls" value="1" class="u-accent"> <?php echo t('netcfg.end_calls'); ?></label>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('netcfgModal{$i}')", t('netcfg.apply'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="card" id="natcheck">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-stethoscope u-primary"></i> <?php echo t('nat_check.title'); ?></div>
        <?php echo uiHelpButton('natCheckHelpBox'); ?>
    </div>
    <?php echo uiHelpBoxOpen('natCheckHelpBox', t('nat_check.title')); ?>
        <p><?php echo t('nat_check.help'); ?></p>
        <div class="u-mt-10"><strong><?php echo t('common.help_limits'); ?></strong><ul class="u-m-4-0-0-20"><li><?php echo t('nat_check.limit_router'); ?></li></ul></div>
<?php echo uiHelpBoxClose(); ?>
    <div class="u-card-pad">
        <p class="u-muted u-fs-12"><?php echo t('nat_check.help'); ?></p>
        <?php if ($natResults !== null): ?>
            <ul class="u-list-style-none u-m-0-0-16 u-p-0">
                <?php foreach ($natResults as $r):
                    [$icon, $color] = ['ok' => ['fa-check-circle', 'var(--success)'], 'warn' => ['fa-exclamation-triangle', 'var(--warning)'], 'fail' => ['fa-times-circle', 'var(--danger)']][$r['status']] ?? ['fa-info-circle', 'var(--text-muted)'];
                ?>
                    <li class="u-d-flex u-gap-8 u-p-4-0 u-fs-13"><i class="fas <?php echo $icon; ?>" style="color: <?php echo $color; ?>; margin-top: 3px;"></i><span><?php echo $h($r['text']); ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="POST" action="/network-settings#natcheck">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="action" value="nat_check">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-play"></i> <?php echo t('nat_check.run'); ?></button>
        </form>
    </div>
</div>


