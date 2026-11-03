<?php
/** @var string $csrf @var array $status @var int $logId @var ?string $log */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$caps = $status['caps'];
$tunnels = $status['tunnels'];
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-plug u-primary"></i> <?php echo t('vpn.client_title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('vpnClientHelp')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="vpnClientHelp">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('vpn.client_title'); ?></h4>
        <?php echo t('vpn.client_help'); ?>
    </div>

    <form method="POST" enctype="multipart/form-data" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
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
        <div class="form-group">
            <label class="form-label" for="tunnel_file"><?php echo t('vpn.profile_file'); ?></label>
            <input type="file" name="tunnel_file" id="tunnel_file" class="form-control" accept=".conf,.ovpn,.txt">
        </div>
        <div class="form-group">
            <label class="form-label" for="tunnel_config"><?php echo t('vpn.profile_text'); ?></label>
            <textarea name="tunnel_config" id="tunnel_config" class="form-control" rows="6" spellcheck="false" style="font-family: monospace; font-size: 12px;"></textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="tunnel_routes"><?php echo t('vpn.routes'); ?></label>
            <input type="text" name="tunnel_routes" id="tunnel_routes" class="form-control" placeholder="192.168.50.0/24, 172.16.5.0/24">
            <div class="u-muted u-fs-12"><?php echo t('vpn.routes_hint'); ?></div>
        </div>
        <div id="tunnelLogin" style="display: none;">
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
        <div class="form-group">
            <label class="u-check-label-6">
                <input type="checkbox" name="tunnel_manage" value="1" class="u-accent">
                <?php echo t('vpn.manage'); ?>
            </label>
            <div class="u-muted u-fs-12"><?php echo t('vpn.manage_hint'); ?></div>
        </div>
        <button type="submit" name="tunnel_add" value="1" class="btn btn-primary"><i class="fas fa-plus"></i> <?php echo t('vpn.add_connection'); ?></button>
    </form>
</div>

<?php foreach ($tunnels as $tunnel): ?>
<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-plug u-primary"></i> <?php echo $h($tunnel['name']); ?>
            <span class="u-muted u-fs-12"><?php echo $tunnel['type'] === 'wg' ? 'WireGuard' : 'OpenVPN'; ?> · <?php echo $h($tunnel['remote']); ?></span>
            <span class="badge <?php echo !empty($tunnel['online']) ? 'badge-success' : 'badge-warning'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo !$tunnel['enabled'] ? t('vpn.off') : (!empty($tunnel['online']) ? t('vpn.connected') : t('vpn.not_connected')); ?>
            </span>
        </div>
    </div>
    <div style="padding: 0 20px 20px;">
        <?php if ($tunnel['address'] !== ''): ?>
            <p class="u-muted u-fs-12"><?php echo t('vpn.address'); ?>: <code><?php echo $h($tunnel['address']); ?></code> · <code><?php echo $h($tunnel['iface']); ?></code></p>
        <?php endif; ?>
        <?php if (!empty($tunnel['ignored'])): ?>
            <p class="u-warning u-fs-12"><i class="fas fa-triangle-exclamation"></i> <?php echo sprintf(t('vpn.ignored'), $h(implode(', ', $tunnel['ignored']))); ?></p>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="tunnel_id" value="<?php echo (int) $tunnel['id']; ?>">
            <div class="form-group">
                <label class="form-label" for="routes<?php echo (int) $tunnel['id']; ?>"><?php echo t('vpn.routes'); ?></label>
                <input type="text" name="tunnel_routes" id="routes<?php echo (int) $tunnel['id']; ?>" class="form-control" value="<?php echo $h(implode(', ', $tunnel['routes'])); ?>">
            </div>
            <div class="form-group">
                <label class="u-check-label-6">
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
            <button type="submit" name="tunnel_save" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            <a href="/vpn-client?log=<?php echo (int) $tunnel['id']; ?>#vpnLog" class="btn btn-secondary"><i class="fas fa-scroll"></i> <?php echo t('vpn.show_log'); ?></a>
            <button type="submit" name="tunnel_delete" value="1" class="btn btn-danger" onclick="return confirm('<?php echo $h(t('vpn.confirm_delete_connection')); ?>');"><i class="fas fa-trash"></i> <?php echo t('common.delete'); ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php if ($log !== null): ?>
<div class="card" style="margin-top: 16px;" id="vpnLog">
    <div class="card-header"><div class="card-title"><i class="fas fa-scroll u-primary"></i> <?php echo t('vpn.show_log'); ?></div></div>
    <pre style="padding: 0 20px 20px; margin: 0; overflow-x: auto; font-size: 12px;"><?php echo $h($log !== '' ? $log : t('vpn.log_empty')); ?></pre>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/vpn_client.js'); ?>"></script>
