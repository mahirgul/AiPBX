<?php
/**
 * Network → Network settings: the server's own network (read only), its
 * addresses as AiPBX uses them, the addresses desk phones are told, and moving
 * them all after the server's IP changed.
 *
 * @var string $csrf
 * @var array $system   NetworkSettingsService::system()
 * @var array<string, string> $settings
 * @var list<string> $localIps
 * @var list<array{where: string, value: string}> $stale
 * @var array<string, string> $files  aipbx-priv net status
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

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-server u-primary"></i> <?php echo t('netset.system_title'); ?></div>
    </div>
    <div style="padding: 0 20px 6px;" class="u-muted u-fs-12"><?php echo t('netset.system_help'); ?></div>
    <div class="u-grid-2" style="padding: 0 20px;">
        <div><span class="u-muted u-fs-12"><?php echo t('netset.hostname'); ?></span><br><strong><?php echo $h($system['hostname']); ?></strong>
            <?php if ($system['fqdn'] !== '' && $system['fqdn'] !== $system['hostname']): ?><span class="u-muted"> · <?php echo $h($system['fqdn']); ?></span><?php endif; ?></div>
        <div><span class="u-muted u-fs-12"><?php echo t('netset.gateway'); ?></span><br><strong><?php echo $h($system['gateway'] ?: '-'); ?></strong>
            <?php if ($system['gateway_dev'] !== ''): ?><span class="u-muted"> · <?php echo $h($system['gateway_dev']); ?></span><?php endif; ?></div>
        <div class="u-mt-12"><span class="u-muted u-fs-12"><?php echo t('netset.dns'); ?></span><br><strong><?php echo $h(implode(', ', $system['dns']) ?: '-'); ?></strong></div>
        <div class="u-mt-12"><span class="u-muted u-fs-12"><?php echo t('netset.primary'); ?></span><br><strong><?php echo $h($system['primary'] ?: '-'); ?></strong></div>
    </div>
    <div class="table-responsive" style="padding: 12px 20px 20px;">
        <table class="data-table no-dt">
            <thead><tr><th><?php echo t('netset.interface'); ?></th><th><?php echo t('netset.addresses'); ?></th><th>MAC</th><th><?php echo t('netset.state'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($system['interfaces'] as $if): ?>
                <tr>
                    <td><code><?php echo $h($if['name']); ?></code></td>
                    <td><?php echo $h(implode(', ', $if['addresses']) ?: '-'); ?></td>
                    <td class="u-fs-11"><?php echo $h($if['mac']); ?></td>
                    <td><?php echo uiStatusBadge($if['up'] ? 'up' : 'down', ['up' => 'success', 'down' => 'secondary'], 'secondary', $if['up'] ? t('netset.up') : t('netset.down')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="u-muted u-fs-11 u-mt-4"><?php echo t('netset.os_note'); ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-network-wired u-primary"></i> <?php echo t('netset.server_title'); ?></div>
    </div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="save_server" value="1">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('netset.server_ip'); ?></label>
                <input type="text" name="server_ip" class="form-control" list="netsetLocalIps" maxlength="15" value="<?php echo $h($settings['server_ip']); ?>" placeholder="<?php echo $h($system['primary']); ?>">
                <small class="u-hint"><?php echo t('netset.server_ip_help'); ?></small>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_external_ip'); ?></label>
                <input type="text" name="pjsip_external_ip" class="form-control" maxlength="15" value="<?php echo $h($settings['pjsip_external_ip']); ?>" placeholder="203.0.113.10">
                <small class="u-hint"><?php echo t('asterisk_settings.external_ip_help'); ?></small>
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
    <div class="table-responsive" style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12 u-mb-4"><?php echo t('netset.files_title'); ?></div>
        <table class="data-table no-dt">
            <tbody>
                <tr><td><?php echo $h($whereLabel('coturn_relay')); ?></td><td><code><?php echo $h($files['coturn_relay'] ?? '-'); ?></code></td></tr>
                <tr><td><?php echo t('netset.coturn_allowed'); ?></td><td><code><?php echo $h($files['coturn_allowed'] ?? '-'); ?></code></td></tr>
                <tr><td><?php echo $h($whereLabel('mdns')); ?></td><td><code><?php echo $h($files['mdns'] ?? '-'); ?></code></td></tr>
                <tr><td><?php echo $h($whereLabel('certificate')); ?></td><td><code><?php echo $h($files['cert_ips'] ?? '-'); ?></code></td></tr>
            </tbody>
        </table>
    </div>
</div>
