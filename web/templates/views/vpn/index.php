<?php
/** @var string $csrf @var string $active_tab @var array $status @var ?array $qr @var string $suggestedEndpoint @var ?string $serverLog @var ?string $clientLog @var int $logId */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$server = $status['server'];
$caps = $status['caps'];
$type = $server['type'];
$tunnels = $status['tunnels'];
$size = static function (int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
};
$tabs = [
    'server' => [
        'label' => t('vpn.server_title'),
        'icon'  => 'fa-shield-halved',
        'badge' => $type === 'off' ? t('vpn.off') : ($server['active'] ? t('vpn.running') : t('vpn.not_running')),
    ],
    'client' => [
        'label' => t('vpn.client_title'),
        'icon'  => 'fa-plug',
        'badge' => (string) count($tunnels),
    ],
];
?>
<?php if (!empty($status['unavailable'])): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo t('vpn.err_unavailable'); ?></div>
<?php endif; ?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-shield-halved', 'VPN', 'vpnHelpBox'); ?>
        <span class="badge <?php echo $server['active'] ? 'badge-success' : 'badge-warning'; ?> u-fs-11">
            <?php echo $type === 'off' ? t('vpn.off') : ($server['active'] ? t('vpn.running') : t('vpn.not_running')); ?>
        </span>
    <?php echo uiPageHeaderActions(); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiTabs($tabs, $active_tab); ?>

    <?php echo uiHelpBoxOpen('vpnHelpBox', 'VPN'); ?>
        <p class="u-mt-0 u-mb-8"><strong><?php echo $h(t('vpn.server_title')); ?>:</strong> <?php echo t('vpn.server_help'); ?></p>
        <p class="u-m-0"><strong><?php echo $h(t('vpn.client_title')); ?>:</strong> <?php echo t('vpn.client_help'); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <!-- TAB: VPN SERVER -->
    <div data-tab-pane="server"<?php echo $active_tab === 'server' ? '' : ' hidden'; ?>>
        <form method="POST" autocomplete="off" id="vpnServerForm">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="_tab" value="server">

            <div class="form-group">
                <label class="form-label" for="vpn_type"><?php echo t('vpn.type'); ?></label>
                <select name="vpn_type" id="vpn_type" class="form-control">
                    <option value="off" <?php echo $type === 'off' ? 'selected' : ''; ?>><?php echo t('vpn.off'); ?></option>
                    <option value="wg" <?php echo $type === 'wg' ? 'selected' : ''; ?> <?php echo $caps['wg'] ? '' : 'disabled'; ?>>WireGuard</option>
                    <option value="ovpn" <?php echo $type === 'ovpn' ? 'selected' : ''; ?> <?php echo $caps['ovpn'] ? '' : 'disabled'; ?>>OpenVPN</option>
                </select>
            </div>

            <div id="vpnServerFields" style="<?php echo $type === 'off' ? 'display: none;' : ''; ?>">
                <div class="form-group">
                    <label class="form-label" for="vpn_endpoint"><?php echo t('vpn.endpoint'); ?></label>
                    <input type="text" name="vpn_endpoint" id="vpn_endpoint" class="form-control" value="<?php echo $h($server['endpoint'] !== '' ? $server['endpoint'] : $suggestedEndpoint); ?>">
                    <div class="u-muted u-fs-12"><?php echo t('vpn.endpoint_hint'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="vpn_port"><?php echo t('vpn.port'); ?></label>
                    <input type="number" min="1" max="65535" name="vpn_port" id="vpn_port" class="form-control" value="<?php echo (int) $server['port']; ?>">
                </div>
                <div class="form-group" id="vpnProtoGroup" style="<?php echo $type === 'ovpn' ? '' : 'display: none;'; ?>">
                    <label class="form-label" for="vpn_proto"><?php echo t('vpn.proto'); ?></label>
                    <select name="vpn_proto" id="vpn_proto" class="form-control">
                        <option value="udp" <?php echo $server['proto'] === 'udp' ? 'selected' : ''; ?>>UDP</option>
                        <option value="tcp" <?php echo $server['proto'] === 'tcp' ? 'selected' : ''; ?>>TCP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="vpn_net"><?php echo t('vpn.net'); ?></label>
                    <input type="text" name="vpn_net" id="vpn_net" class="form-control" value="<?php echo $h($server['net']); ?>">
                    <div class="u-muted u-fs-12"><?php echo t('vpn.net_hint'); ?></div>
                </div>
            </div>

            <button type="submit" name="save_server" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            <?php if ($type !== 'off'): ?>
                <a href="/vpn-server?tab=server&amp;log=1#vpnLog" class="btn btn-secondary"><i class="fas fa-scroll"></i> <?php echo t('vpn.show_log'); ?></a>
            <?php endif; ?>
        </form>

        <?php if ($type !== 'off'): ?>
        <hr class="u-mt-16 u-mb-16">
        <h3 class="card-title u-mb-8"><i class="fas fa-laptop u-primary"></i> <?php echo t('vpn.devices'); ?></h3>
        <p class="u-muted u-fs-12">
            <?php echo sprintf(t('vpn.server_address'), '<strong>' . $h($server['address']) . '</strong>'); ?>
            <?php if ($server['public_key'] !== ''): ?>
                · <?php echo t('vpn.public_key'); ?>: <code><?php echo $h($server['public_key']); ?></code>
            <?php endif; ?>
        </p>

        <form method="POST" autocomplete="off" class="u-d-flex u-gap-8 u-mb-16">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="_tab" value="server">
            <input type="text" name="peer_name" class="form-control" maxlength="40" required placeholder="<?php echo t('vpn.device_name'); ?>" aria-label="<?php echo t('vpn.device_name'); ?>">
            <button type="submit" name="peer_add" value="1" class="btn btn-primary u-nowrap"><i class="fas fa-plus"></i> <?php echo t('vpn.add_device'); ?></button>
        </form>

        <?php if ($server['peers'] === []): ?>
            <p class="u-muted"><?php echo t('vpn.no_devices'); ?></p>
        <?php else: ?>
        <?php echo uiTableOpen([
            t('vpn.device_name'),
            t('vpn.address'),
            t('vpn.state'),
            t('vpn.traffic'),
            '',
        ]); ?>
            <?php foreach ($server['peers'] as $peer): $live = $peer['live'] ?? null; $online = is_array($live) && !empty($live['online']); ?>
                <tr>
                    <td><?php echo $h($peer['name']); ?></td>
                    <td><code><?php echo $h($peer['ip']); ?></code></td>
                    <td><span class="badge <?php echo $online ? 'badge-success' : 'badge-warning'; ?>"><?php echo $online ? t('vpn.connected') : t('vpn.not_connected'); ?></span></td>
                    <td class="u-fs-12"><?php echo is_array($live) ? '↓ ' . $size((int) $live['rx']) . ' · ↑ ' . $size((int) $live['tx']) : '–'; ?></td>
                    <td class="u-nowrap u-ta-right">
                        <form method="POST" class="u-d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                            <input type="hidden" name="_tab" value="server">
                            <input type="hidden" name="peer_id" value="<?php echo (int) $peer['id']; ?>">
                            <button type="submit" name="peer_download" value="1" class="btn btn-xs btn-secondary" title="<?php echo t('vpn.download'); ?>"><i class="fas fa-download"></i> <?php echo t('vpn.download'); ?></button>
                            <?php if ($type === 'ovpn'): ?>
                                <button type="submit" name="peer_yealink" value="1" class="btn btn-xs btn-secondary" title="<?php echo t('vpn.yealink_hint'); ?>"><i class="fas fa-phone"></i> Yealink</button>
                            <?php endif; ?>
                            <?php if ($type === 'wg'): ?>
                                <button type="submit" name="peer_qr" value="1" class="btn btn-xs btn-secondary" title="<?php echo t('vpn.qr'); ?>"><i class="fas fa-qrcode"></i> <?php echo t('vpn.qr'); ?></button>
                            <?php endif; ?>
                            <button type="submit" name="peer_delete" value="1" class="btn btn-icon btn-xs btn-danger" title="<?php echo t('common.delete'); ?>" onclick="return confirm(<?php echo jsArg(t('vpn.confirm_delete_device')); ?>);"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
        <?php endif; ?>

        <?php if ($qr !== null): ?>
            <div class="u-mt-16 u-ta-center">
                <p><strong><?php echo $h($qr['name']); ?></strong> · <?php echo t('vpn.qr_hint'); ?></p>
                <img class="u-w-320 u-maxw-100pc u-bg-fff" src="<?php echo $h($qr['image']); ?>" alt="<?php echo t('vpn.qr'); ?>">
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($serverLog !== null): ?>
        <div class="u-mt-16" id="vpnLog">
            <h4 class="u-fs-13 u-fw-700 u-mb-6"><i class="fas fa-scroll u-primary"></i> <?php echo t('vpn.show_log'); ?></h4>
            <pre class="u-card-pad u-m-0 u-overflow-x-auto u-fs-12 u-bg-bg-input u-r-8"><?php echo $h($serverLog !== '' ? $serverLog : t('vpn.log_empty')); ?></pre>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: VPN CLIENT -->
    <div data-tab-pane="client"<?php echo $active_tab === 'client' ? '' : ' hidden'; ?>>
        <form method="POST" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="_tab" value="client">
            <div class="u-grid-2 u-gap-12">
                <div class="form-group">
                    <label class="form-label" for="tunnel_name"><?php echo t('vpn.connection_name'); ?></label>
                    <input type="text" name="tunnel_name" id="tunnel_name" class="form-control" maxlength="40" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="tunnel_type"><?php echo t('vpn.type'); ?></label>
                    <select name="tunnel_type" id="tunnel_type" class="form-control">
                        <option value="auto"><?php echo t('vpn.type_auto'); ?></option>
                        <option value="wg" <?php echo $caps['wg'] ? '' : 'disabled'; ?>>WireGuard</option>
                        <option value="ovpn" <?php echo $caps['ovpn'] ? '' : 'disabled'; ?>>OpenVPN</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="tunnel_file"><?php echo t('vpn.profile_file'); ?></label>
                <input type="file" name="tunnel_file" id="tunnel_file" class="form-control" accept=".conf,.ovpn,.txt">
            </div>
            <div class="form-group">
                <label class="form-label" for="tunnel_config"><?php echo t('vpn.profile_text'); ?></label>
                <textarea name="tunnel_config" id="tunnel_config" class="form-control u-mono u-fs-12" rows="6" spellcheck="false"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label" for="tunnel_routes"><?php echo t('vpn.routes'); ?></label>
                <input type="text" name="tunnel_routes" id="tunnel_routes" class="form-control" placeholder="192.168.50.0/24, 172.16.5.0/24">
                <div class="u-muted u-fs-12"><?php echo t('vpn.routes_hint'); ?></div>
            </div>
            <div id="tunnelLogin" style="display: none;">
                <div class="u-grid-2 u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="tunnel_username"><?php echo t('vpn.username'); ?></label>
                        <input type="text" name="tunnel_username" id="tunnel_username" class="form-control" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tunnel_password"><?php echo t('vpn.password'); ?></label>
                        <input type="password" name="tunnel_password" id="tunnel_password" class="form-control" autocomplete="new-password">
                        <div class="u-muted u-fs-12"><?php echo t('vpn.login_hint'); ?></div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="u-check-label-6">
                    <input type="checkbox" name="tunnel_manage" value="1" class="u-accent">
                    <?php echo t('vpn.manage'); ?>
                </label>
                <div class="u-muted u-fs-12"><?php echo t('vpn.manage_hint'); ?></div>
            </div>
            <button type="submit" name="tunnel_add" value="1" class="btn btn-primary"><i class="fas fa-plus"></i> <?php echo t('vpn.add_connection'); ?></button>
        </form>

        <?php foreach ($tunnels as $tunnel): ?>
        <hr class="u-mt-16 u-mb-16">
        <div class="u-mb-12">
            <div class="u-d-flex u-ai-center u-gap-8 u-wrap u-mb-8">
                <strong><i class="fas fa-plug u-primary"></i> <?php echo $h($tunnel['name']); ?></strong>
                <span class="u-muted u-fs-12"><?php echo $tunnel['type'] === 'wg' ? 'WireGuard' : 'OpenVPN'; ?> · <?php echo $h($tunnel['remote']); ?></span>
                <span class="badge <?php echo !empty($tunnel['online']) ? 'badge-success' : 'badge-warning'; ?> u-fs-11">
                    <?php echo !$tunnel['enabled'] ? t('vpn.off') : (!empty($tunnel['online']) ? t('vpn.connected') : t('vpn.not_connected')); ?>
                </span>
            </div>
            <?php if ($tunnel['address'] !== ''): ?>
                <p class="u-muted u-fs-12"><?php echo t('vpn.address'); ?>: <code><?php echo $h($tunnel['address']); ?></code> · <code><?php echo $h($tunnel['iface']); ?></code></p>
            <?php endif; ?>
            <?php if (!empty($tunnel['ignored'])): ?>
                <p class="u-warning u-fs-12"><i class="fas fa-triangle-exclamation"></i> <?php echo sprintf(t('vpn.ignored'), $h(implode(', ', $tunnel['ignored']))); ?></p>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="_tab" value="client">
                <input type="hidden" name="tunnel_id" value="<?php echo (int) $tunnel['id']; ?>">
                <div class="form-group">
                    <label class="form-label" for="routes<?php echo (int) $tunnel['id']; ?>"><?php echo t('vpn.routes'); ?></label>
                    <input type="text" name="tunnel_routes" id="routes<?php echo (int) $tunnel['id']; ?>" class="form-control" value="<?php echo $h(implode(', ', $tunnel['routes'])); ?>">
                </div>
                <div class="form-group">
                    <label class="u-check-label-6 u-mb-4" style="display: block;">
                        <input type="hidden" name="tunnel_enabled" value="0">
                        <input type="checkbox" name="tunnel_enabled" value="1" class="u-accent" <?php echo $tunnel['enabled'] ? 'checked' : ''; ?>>
                        <?php echo t('vpn.enabled'); ?>
                    </label>
                    <label class="u-check-label-6" style="display: block;">
                        <input type="hidden" name="tunnel_manage" value="0">
                        <input type="checkbox" name="tunnel_manage" value="1" class="u-accent" <?php echo !empty($tunnel['manage']) ? 'checked' : ''; ?>>
                        <?php echo t('vpn.manage'); ?>
                    </label>
                </div>
                <button type="submit" name="tunnel_save" value="1" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
                <a href="/vpn-client?log=<?php echo (int) $tunnel['id']; ?>#vpnLog" class="btn btn-secondary btn-sm"><i class="fas fa-scroll"></i> <?php echo t('vpn.show_log'); ?></a>
                <button type="submit" name="tunnel_delete" value="1" class="btn btn-danger btn-sm" onclick="return confirm(<?php echo jsArg(t('vpn.confirm_delete_connection')); ?>);"><i class="fas fa-trash"></i> <?php echo t('common.delete'); ?></button>
            </form>
        </div>
        <?php endforeach; ?>

        <?php if ($clientLog !== null): ?>
        <div class="u-mt-16" id="vpnLog">
            <h4 class="u-fs-13 u-fw-700 u-mb-6"><i class="fas fa-scroll u-primary"></i> <?php echo t('vpn.show_log'); ?></h4>
            <pre class="u-card-pad u-m-0 u-overflow-x-auto u-fs-12 u-bg-bg-input u-r-8"><?php echo $h($clientLog !== '' ? $clientLog : t('vpn.log_empty')); ?></pre>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?php echo asset('/assets/js/vpn_server.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/vpn_client.js'); ?>"></script>
