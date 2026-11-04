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
 * @var array|null $preview  ['old', 'new', 'changes' => list<array{where, from, to}>]
 * @var string $previewError
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$whereLabel = fn(string $w) => t('netset.where_' . $w, $w);
$staleOld = $stale[0]['value'] ?? '';
$suggestNew = $system['primary'] !== '' ? $system['primary'] : ($localIps[0] ?? '');
?>
<datalist id="netsetLocalIps">
    <?php foreach ($localIps as $ip): ?><option value="<?php echo $h($ip); ?>"><?php endforeach; ?>
</datalist>

<?php if ($stale !== []): ?>
<div class="card"><div class="alert alert-warning" style="margin: 16px 20px;">
    <strong><i class="fas fa-triangle-exclamation"></i> <?php echo t('netset.stale_title'); ?></strong>
    <ul style="margin: 6px 0 8px 18px;">
        <?php foreach ($stale as $s): ?>
            <li><?php echo $h($whereLabel($s['where'])); ?>: <code><?php echo $h($s['value']); ?></code></li>
        <?php endforeach; ?>
    </ul>
    <?php echo sprintf(t('netset.stale_help'), '<code>' . $h(implode(', ', $localIps)) . '</code>'); ?>
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
    <div class="card-header">
        <div class="card-title"><i class="fas fa-ethernet u-primary"></i> <?php echo t('netcfg.cards_title'); ?></div>
    </div>
    <?php $waiting = NetworkSettingsService::networksWaitingForRestart(); if ($waiting !== []): ?>
        <div class="alert alert-warning" style="margin: 8px 20px;">
            <?php echo sprintf(t('netset.restart_needed'), '<code>' . $h(implode(', ', $waiting)) . '</code>'); ?>
            <form method="POST" action="/network-settings#netcfg" style="margin-top: 8px;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <button type="submit" name="restart_for_networks" value="1" class="btn btn-secondary btn-sm"><i class="fas fa-rotate"></i> <?php echo t('netset.restart_button'); ?></button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($pending): ?>
        <div class="alert alert-warning" style="margin: 8px 20px;">
            <strong><i class="fas fa-hourglass-half"></i> <?php echo sprintf(t('netcfg.pending'), (int) ($change['seconds_left'] ?? 0)); ?></strong>
            <form method="POST" action="/network-settings#netcfg" class="u-flex-gap" style="margin-top: 8px;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <button type="submit" name="netcfg_confirm" value="1" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> <?php echo t('netcfg.confirm'); ?></button>
                <button type="submit" name="netcfg_revert" value="1" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> <?php echo t('netcfg.revert'); ?></button>
            </form>
        </div>
    <?php elseif (($change['state'] ?? '') === 'reverted'): ?>
        <div class="alert alert-info" style="margin: 8px 20px;"><?php echo t('netcfg.was_reverted'); ?></div>
    <?php elseif (($change['state'] ?? '') === 'failed'): ?>
        <div class="alert alert-danger" style="margin: 8px 20px;"><?php echo $h($change['error'] ?? ''); ?></div>
    <?php endif; ?>
    <div class="table-responsive" style="padding: 4px 20px 16px;">
        <table class="data-table no-dt">
            <thead><tr><th><?php echo t('netset.interface'); ?></th><th><?php echo t('netcfg.ip'); ?></th><th><?php echo t('netcfg.netmask'); ?></th><th><?php echo t('netset.gateway'); ?></th><th><?php echo t('netset.dns'); ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $if):
                $cfg = $cfgByName[$if['name']] ?? null;
                $v4 = array_values(array_filter($if['addresses'], fn($a) => strpos($a, ':') === false));
                $first = explode('/', (string) ($cfg['addresses'][0] ?? $v4[0] ?? ''), 2);
                $editable = $cfg !== null && !$pending;
            ?>
                <tr>
                    <td><code><?php echo $h($if['name']); ?></code>
                        <?php if ($cfg !== null): ?><span class="badge <?php echo $cfg['method'] === 'static' ? 'badge-primary' : 'badge-info'; ?>" style="margin-left: 4px;"><?php echo $cfg['method'] === 'static' ? t('netcfg.static') : 'DHCP'; ?></span><?php endif; ?>
                        <?php if (!$if['up']): ?><span class="badge badge-secondary"><?php echo t('netset.down'); ?></span><?php endif; ?></td>
                    <td><?php echo $h($first[0] ?: '-'); ?></td>
                    <td><?php echo $h(isset($first[1]) ? NetworkConfigService::maskOf((int) $first[1]) : '-'); ?></td>
                    <td><?php echo $h(($cfg['gateway'] ?? '') ?: '-'); ?>
                        <?php if (!empty($cfg['is_default'])): ?><span class="badge badge-success" style="margin-left: 4px;"><?php echo t('netcfg.default_badge'); ?></span><?php endif; ?>
                        <?php if (!empty($cfg['nets'])): ?><div class="u-muted u-fs-11"><?php echo $h(implode(', ', $cfg['nets'])); ?></div><?php endif; ?></td>
                    <td><?php echo $h(implode(', ', $cfg['dns'] ?? []) ?: '-'); ?></td>
                    <td class="u-text-right">
                        <?php if ($editable): ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('netcfgModal<?php echo $i; ?>')"><i class="fas fa-pen"></i> <?php echo t('common.edit'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($rows as $i => $if):
    $cfg = $cfgByName[$if['name']] ?? null;
    if ($cfg === null || $pending) { continue; }
    $static = $cfg['method'] === 'static';
    $firstParts = explode('/', (string) ($cfg['addresses'][0] ?? ''), 2);
?>
<div class="modal-overlay" id="netcfgModal<?php echo $i; ?>">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-ethernet u-primary"></i> <code><?php echo $h($if['name']); ?></code></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('netcfgModal<?php echo $i; ?>')"><i class="fas fa-times"></i></button>
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
                <label class="u-check-label u-fs-12" style="display: block; margin: 8px 0;"><input type="checkbox" name="is_default" value="1" class="u-accent" <?php echo !empty($cfg['is_default']) || !$defaultElsewhere ? 'checked' : ''; ?>> <?php echo t('netcfg.is_default'); ?></label>
                <label class="u-check-label u-fs-12" style="display: block; margin: 8px 0;"><input type="checkbox" name="end_calls" value="1" class="u-accent"> <?php echo t('netcfg.end_calls'); ?></label>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('netcfgModal{$i}')", t('netcfg.apply'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-network-wired u-primary"></i> <?php echo t('netcfg.pbx_addresses_title'); ?></div>
    </div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="save_server" value="1">
        <div class="u-grid-2">
            <?php if ($settings['server_ip'] !== ''): ?>
            <div class="form-group">
                <label class="form-label"><?php echo t('netset.server_ip'); ?></label>
                <input type="text" name="server_ip" class="form-control" list="netsetLocalIps" maxlength="15" value="<?php echo $h($settings['server_ip']); ?>" placeholder="<?php echo $h($system['primary']); ?>">
                <small class="u-hint"><?php echo t('netset.server_ip_help'); ?></small>
            </div>
            <?php else: ?><input type="hidden" name="server_ip" value=""><?php endif; ?>
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_external_ip'); ?></label>
                <input type="text" name="pjsip_external_ip" class="form-control" maxlength="253" value="<?php echo $h($settings['pjsip_external_ip']); ?>" placeholder="203.0.113.10">
                <small class="u-hint"><?php echo t('netset.public_help'); ?></small>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('netset.field_media_ip'); ?></label>
                <input type="text" name="pjsip_external_media_ip" class="form-control" maxlength="253" value="<?php echo $h($settings['pjsip_external_media_ip']); ?>">
                <small class="u-hint"><?php echo t('netset.media_ip_help'); ?></small>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('asterisk_settings.field_local_net'); ?></label>
            <input type="text" name="pjsip_local_net" class="form-control" maxlength="600" value="<?php echo $h($settings['pjsip_local_net']); ?>" placeholder="192.168.1.0/24,10.0.0.0/24">
            <small class="u-hint"><?php echo t('asterisk_settings.local_net_help'); ?></small>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
        <span class="u-muted u-fs-12"> · <?php echo t('netset.apply_note'); ?></span>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-phone u-primary"></i> <?php echo t('netset.phones_title'); ?></div>
    </div>
    <div style="padding: 0 20px 6px;" class="u-muted u-fs-12"><?php echo t('phones.addresses_help'); ?></div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="save_phones" value="1">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('phones.sip_server'); ?></label>
                <input type="text" name="provision_sip_server" class="form-control" maxlength="255" list="netsetLocalIps" value="<?php echo $h($settings['provision_sip_server']); ?>" placeholder="<?php echo $h(PhoneProvisionService::sipServer() === $settings['provision_sip_server'] ? '' : PhoneProvisionService::sipServer()); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('phones.sip_proxy'); ?></label>
                <input type="text" name="provision_sip_proxy" class="form-control" maxlength="255" value="<?php echo $h($settings['provision_sip_proxy']); ?>" placeholder="<?php echo $h(t('phones.sip_proxy_none')); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('phones.base_url'); ?></label>
                <input type="text" name="provision_base_url" class="form-control" maxlength="300" value="<?php echo $h($settings['provision_base_url']); ?>" placeholder="<?php echo $h(ForgotPasswordService::portalUrl()); ?>">
                <div class="text-muted u-fs-11"><?php echo t('phones.base_url_help'); ?></div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('phones.syslog'); ?></label>
                <input type="text" name="provision_syslog" class="form-control" maxlength="15" list="netsetLocalIps" value="<?php echo $h($settings['provision_syslog']); ?>" placeholder="<?php echo $h(t('phones.sip_proxy_none')); ?>">
                <div class="text-muted u-fs-11"><?php echo t('phones.syslog_help'); ?></div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('phones.ntp'); ?></label>
                <input type="text" name="provision_ntp" class="form-control" value="<?php echo $h($settings['provision_ntp']); ?>" required>
                <div class="text-muted u-fs-11"><?php echo t('phones.ntp_help'); ?></div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('phones.allowed_networks'); ?></label>
                <textarea name="provision_allowed_networks" class="form-control" rows="3" placeholder="192.168.1.0/24&#10;10.0.0.0/8"><?php echo $h($settings['provision_allowed_networks']); ?></textarea>
                <div class="text-muted u-fs-11"><?php echo t('phones.allowed_networks_help'); ?></div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
    </form>
</div>

<div class="card" id="natcheck">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-stethoscope u-primary"></i> <?php echo t('nat_check.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('natCheckHelpBox')"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="natCheckHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('nat_check.title'); ?></h4>
        <p><?php echo t('nat_check.help'); ?></p>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('nat_check.limit_router'); ?></li></ul></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <p class="u-muted u-fs-12"><?php echo t('nat_check.help'); ?></p>
        <?php if ($natResults !== null): ?>
            <ul style="list-style: none; margin: 0 0 14px; padding: 0;">
                <?php foreach ($natResults as $r):
                    [$icon, $color] = ['ok' => ['fa-check-circle', 'var(--success)'], 'warn' => ['fa-exclamation-triangle', 'var(--warning)'], 'fail' => ['fa-times-circle', 'var(--danger)']][$r['status']] ?? ['fa-info-circle', 'var(--text-muted)'];
                ?>
                    <li style="display: flex; gap: 8px; padding: 5px 0; font-size: 13px;"><i class="fas <?php echo $icon; ?>" style="color: <?php echo $color; ?>; margin-top: 3px;"></i><span><?php echo $h($r['text']); ?></span></li>
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

<?php // Only when the address changed outside this page: an Edit here moves everything by itself. ?>
<?php if ($stale !== [] || $preview !== null || $previewError !== ''): ?>
<div class="card" id="readdress">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-right-left u-primary"></i> <?php echo t('netset.readdress_title'); ?></div>
    </div>
    <div style="padding: 0 20px 6px;" class="u-muted u-fs-12"><?php echo t('netset.readdress_help'); ?></div>
    <form method="POST" action="/network-settings#readdress" autocomplete="off" class="u-flex-gap" style="padding: 0 20px 12px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="readdress_preview">
        <div class="form-group" style="margin: 0;">
            <label class="form-label"><?php echo t('netset.old_ip'); ?></label>
            <input type="text" name="old_ip" class="form-control" maxlength="15" required value="<?php echo $h($preview['old'] ?? ($staleOld ?: $settings['server_ip'])); ?>">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label"><?php echo t('netset.new_ip'); ?></label>
            <input type="text" name="new_ip" class="form-control" maxlength="15" required list="netsetLocalIps" value="<?php echo $h($preview['new'] ?? $suggestNew); ?>">
        </div>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-eye"></i> <?php echo t('netset.preview'); ?></button>
    </form>
    <?php if ($previewError !== ''): ?>
        <div class="alert alert-danger" style="margin: 0 20px 12px;"><?php echo $h($previewError); ?></div>
    <?php endif; ?>
    <?php if ($preview !== null): ?>
        <div style="padding: 0 20px 20px;">
            <?php if ($preview['changes'] === []): ?>
                <p class="u-muted"><?php echo sprintf(t('netset.preview_none'), $h($preview['old'])); ?></p>
            <?php else: ?>
                <table class="data-table no-dt">
                    <thead><tr><th><?php echo t('netset.col_where'); ?></th><th><?php echo t('netset.col_now'); ?></th><th><?php echo t('netset.col_after'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['changes'] as $c): ?>
                        <tr><td><?php echo $h($whereLabel($c['where'])); ?></td><td><code><?php echo $h($c['from'] ?: '-'); ?></code></td><td><code><?php echo $h($c['to']); ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="POST" action="/network-settings" class="u-mt-12" onsubmit="return confirm(<?php echo jsArg(t('netset.apply_confirm')); ?>);">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                    <input type="hidden" name="readdress_apply" value="1">
                    <input type="hidden" name="old_ip" value="<?php echo $h($preview['old']); ?>">
                    <input type="hidden" name="new_ip" value="<?php echo $h($preview['new']); ?>">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('netset.apply'); ?></button>
                </form>
            <?php endif; ?>
            <div class="u-muted u-fs-11 u-mt-12"><?php echo t('netset.not_changed'); ?></div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
