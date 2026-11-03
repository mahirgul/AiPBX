<?php
/** @var string $csrf @var array $status @var ?array $qr @var string $suggestedEndpoint @var ?string $log */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$server = $status['server'];
$caps = $status['caps'];
$type = $server['type'];
$size = function (int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
};
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-halved u-primary"></i> <?php echo t('vpn.server_title'); ?>
            <span class="badge <?php echo $server['active'] ? 'badge-success' : 'badge-warning'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $type === 'off' ? t('vpn.off') : ($server['active'] ? t('vpn.running') : t('vpn.not_running')); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('vpnServerHelp')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="vpnServerHelp">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('vpn.server_title'); ?></h4>
        <?php echo t('vpn.server_help'); ?>
    </div>

    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;" id="vpnServerForm">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">

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
            <a href="/vpn-server?log=1#vpnLog" class="btn btn-secondary"><i class="fas fa-scroll"></i> <?php echo t('vpn.show_log'); ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if ($type !== 'off'): ?>
<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-laptop u-primary"></i> <?php echo t('vpn.devices'); ?></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <p class="u-muted u-fs-12">
            <?php echo sprintf(t('vpn.server_address'), '<strong>' . $h($server['address']) . '</strong>'); ?>
            <?php if ($server['public_key'] !== ''): ?>
                · <?php echo t('vpn.public_key'); ?>: <code><?php echo $h($server['public_key']); ?></code>
            <?php endif; ?>
        </p>

        <form method="POST" autocomplete="off" style="display: flex; gap: 8px; margin-bottom: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="text" name="peer_name" class="form-control" maxlength="40" required placeholder="<?php echo t('vpn.device_name'); ?>" aria-label="<?php echo t('vpn.device_name'); ?>">
            <button type="submit" name="peer_add" value="1" class="btn btn-primary" style="white-space: nowrap;"><i class="fas fa-plus"></i> <?php echo t('vpn.add_device'); ?></button>
        </form>

        <?php if ($server['peers'] === []): ?>
            <p class="u-muted"><?php echo t('vpn.no_devices'); ?></p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th><?php echo t('vpn.device_name'); ?></th><th><?php echo t('vpn.address'); ?></th><th><?php echo t('vpn.state'); ?></th><th><?php echo t('vpn.traffic'); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($server['peers'] as $peer): $live = $peer['live'] ?? null; $online = is_array($live) && !empty($live['online']); ?>
                    <tr>
                        <td><?php echo $h($peer['name']); ?></td>
                        <td><code><?php echo $h($peer['ip']); ?></code></td>
                        <td><span class="badge <?php echo $online ? 'badge-success' : 'badge-warning'; ?>"><?php echo $online ? t('vpn.connected') : t('vpn.not_connected'); ?></span></td>
                        <td class="u-fs-12"><?php echo is_array($live) ? '↓ ' . $size((int) $live['rx']) . ' · ↑ ' . $size((int) $live['tx']) : '–'; ?></td>
                        <td style="white-space: nowrap; text-align: right;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                                <input type="hidden" name="peer_id" value="<?php echo (int) $peer['id']; ?>">
                                <button type="submit" name="peer_download" value="1" class="btn btn-xs btn-secondary" title="<?php echo t('vpn.download'); ?>"><i class="fas fa-download"></i> <?php echo t('vpn.download'); ?></button>
                                <?php if ($type === 'wg'): ?>
                                    <button type="submit" name="peer_qr" value="1" class="btn btn-xs btn-secondary" title="<?php echo t('vpn.qr'); ?>"><i class="fas fa-qrcode"></i> <?php echo t('vpn.qr'); ?></button>
                                <?php endif; ?>
                                <button type="submit" name="peer_delete" value="1" class="btn btn-xs btn-danger" title="<?php echo t('common.delete'); ?>" onclick="return confirm('<?php echo $h(t('vpn.confirm_delete_device')); ?>');"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($qr !== null): ?>
            <div style="margin-top: 16px; text-align: center;">
                <p><strong><?php echo $h($qr['name']); ?></strong> · <?php echo t('vpn.qr_hint'); ?></p>
                <img src="<?php echo $h($qr['image']); ?>" alt="<?php echo t('vpn.qr'); ?>" style="width: 320px; max-width: 100%; background: #fff;">
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($log !== null): ?>
<div class="card" style="margin-top: 16px;" id="vpnLog">
    <div class="card-header"><div class="card-title"><i class="fas fa-scroll u-primary"></i> <?php echo t('vpn.show_log'); ?></div></div>
    <pre style="padding: 0 20px 20px; margin: 0; overflow-x: auto; font-size: 12px;"><?php echo $h($log !== '' ? $log : t('vpn.log_empty')); ?></pre>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/vpn_server.js'); ?>"></script>
