<?php
/**
 * @var array $settings
 * @var list<array{port: string, proto: string, service: string, scope: string, bypass: bool, description: string}> $ports
 * @var array $diagnostics
 * @var string $primary_ip
 * @var string $csrf_token
 */

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$proxyTypes = ['nginx' => 'Nginx', 'caddy' => 'Caddy', 'traefik' => 'Traefik', 'haproxy' => 'HAProxy', 'apache' => 'Apache'];
$tlsModes = [
    'edge' => t('reverse_proxy.tls_edge'),
    're_encrypt' => t('reverse_proxy.tls_re_encrypt'),
    'none' => t('reverse_proxy.tls_none'),
];

$backendIp = $primary_ip ?: '127.0.0.1';
$backendPort = 80;
$fqdn = $settings['external_fqdn'] ?: 'pbx.example.com';
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-alt u-primary"></i> <?php echo t('reverse_proxy.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('proxyHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>
    <div class="module-help-box" id="proxyHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('reverse_proxy.help_title'); ?></h4>
        <p><?php echo t('reverse_proxy.help_body'); ?></p>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('reverse_proxy.limit_reencrypt'); ?></li></ul></div>
    </div>

    <div style="padding: 16px 20px;">
        <!-- VoIP Bypass Notice -->
        <div style="background: rgba(234, 179, 8, 0.1); border-left: 4px solid var(--warning, #eab308); padding: 14px 18px; border-radius: 4px; margin-bottom: 20px;">
            <div style="font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                <i class="fas fa-triangle-exclamation" style="color: var(--warning, #eab308); font-size: 18px;"></i>
                <?php echo t('reverse_proxy.voip_boundary_title'); ?>
            </div>
            <div style="font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                <?php echo t('reverse_proxy.voip_boundary_desc'); ?>
            </div>
        </div>

        <!-- Real-Time Request Diagnostics -->
        <div style="background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 6px; padding: 14px 18px; margin-bottom: 24px;">
            <div style="font-weight: 700; font-size: 13px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-stethoscope u-primary"></i> <?php echo t('reverse_proxy.diag_title'); ?>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; font-size: 12px;">
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_remote_addr'); ?>:</span><br>
                    <code style="font-size: 12px;"><?php echo $h($diagnostics['remote_addr']); ?></code>
                    <?php if ($diagnostics['is_trusted']): ?>
                        <span class="badge badge-success u-fs-11" style="margin-left: 4px;"><?php echo t('reverse_proxy.diag_trusted'); ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary u-fs-11" style="margin-left: 4px;"><?php echo t('reverse_proxy.diag_untrusted'); ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_forwarded_for'); ?>:</span><br>
                    <code style="font-size: 12px;"><?php echo $h($diagnostics['forwarded_for'] ?: '—'); ?></code>
                </div>
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_forwarded_proto'); ?>:</span><br>
                    <code style="font-size: 12px;"><?php echo $h($diagnostics['forwarded_proto'] ?: '—'); ?></code>
                </div>
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_resolved_ip'); ?>:</span><br>
                    <strong style="color: var(--primary, #0284c7); font-size: 13px;"><?php echo $h($diagnostics['resolved_client_ip']); ?></strong>
                </div>
            </div>
        </div>

        <!-- Reverse Proxy Settings Form -->
        <form method="POST" autocomplete="off" style="margin-bottom: 30px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-group" style="margin-bottom: 16px;">
                <label class="u-check-label u-fs-14" style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="proxy_enabled" value="1" class="u-accent" <?php echo $settings['enabled'] ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                    <strong><?php echo t('reverse_proxy.enable_label'); ?></strong>
                </label>
                <div class="u-hint" style="margin-left: 26px;"><?php echo t('reverse_proxy.enable_hint'); ?></div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('reverse_proxy.field_external_fqdn'); ?></label>
                    <input type="text" name="proxy_external_fqdn" id="proxyFqdnInput" class="form-control" value="<?php echo $h($settings['external_fqdn']); ?>" placeholder="pbx.example.com">
                    <div class="u-hint"><?php echo t('reverse_proxy.fqdn_hint'); ?></div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('reverse_proxy.field_external_port'); ?></label>
                    <input type="number" name="proxy_external_port" class="form-control" value="<?php echo (int) $settings['external_port']; ?>" min="1" max="65535">
                    <div class="u-hint"><?php echo t('reverse_proxy.port_hint'); ?></div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('reverse_proxy.field_tls_mode'); ?></label>
                    <select name="proxy_tls_termination" class="form-control">
                        <?php foreach ($tlsModes as $val => $lbl): ?>
                            <option value="<?php echo $h($val); ?>" <?php echo $settings['tls_termination'] === $val ? 'selected' : ''; ?>><?php echo $h($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="u-hint"><?php echo t('reverse_proxy.tls_mode_hint'); ?></div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('reverse_proxy.field_proxy_type'); ?></label>
                    <select name="proxy_type" class="form-control">
                        <?php foreach ($proxyTypes as $pt => $ptName): ?>
                            <option value="<?php echo $h($pt); ?>" <?php echo $settings['proxy_type'] === $pt ? 'selected' : ''; ?>><?php echo $h($ptName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label"><?php echo t('reverse_proxy.field_trusted_proxies'); ?></label>
                <textarea name="proxy_trusted_proxies" class="form-control" rows="3" style="font-family: monospace; font-size: 13px;" placeholder="127.0.0.1, ::1"><?php echo $h($settings['trusted_proxies']); ?></textarea>
                <div class="u-hint"><?php echo t('reverse_proxy.trusted_proxies_hint'); ?></div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </form>
    </div>
</div>

<!-- Live Reverse Proxy Configuration Generator -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-file-code u-primary"></i> <?php echo t('reverse_proxy.gen_title'); ?>
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <p class="u-fs-12 u-mt-0 u-muted"><?php echo t('reverse_proxy.gen_desc'); ?></p>

        <!-- Proxy Type Tabs -->
        <div class="tabs-nav" style="display: flex; gap: 8px; border-bottom: 1px solid var(--border-color, #e2e8f0); margin-bottom: 16px;">
            <?php foreach ($proxyTypes as $pKey => $pLabel): ?>
                <button type="button" class="tab-btn <?php echo $settings['proxy_type'] === $pKey ? 'active' : ''; ?>" onclick="selectProxyTab('<?php echo $pKey; ?>', this)" style="padding: 8px 16px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent;">
                    <?php echo $pLabel; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($proxyTypes as $pKey => $pLabel): ?>
            <div id="proxyTab_<?php echo $pKey; ?>" class="proxy-tab-content" style="<?php echo $settings['proxy_type'] === $pKey ? '' : 'display: none;'; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span class="u-fs-12 u-strong"><?php echo sprintf(t('reverse_proxy.sample_config_for'), $pLabel); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyProxySnippet('proxySnippet_<?php echo $pKey; ?>')">
                        <i class="fas fa-copy"></i> <?php echo t('common.copy'); ?>
                    </button>
                </div>
                <pre id="proxySnippet_<?php echo $pKey; ?>" style="background: #0f172a; color: #e2e8f0; padding: 14px 18px; border-radius: 6px; font-size: 12px; line-height: 1.5; max-height: 460px; overflow: auto; white-space: pre; margin: 0;"><?php
                    echo $h(ReverseProxyService::generateProxyConfig($pKey, $fqdn, $backendIp, $backendPort, $settings['tls_termination']));
                ?></pre>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Network Ports & Firewall Rules -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-network-wired u-primary"></i> <?php echo t('reverse_proxy.ports_title'); ?>
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <p class="u-fs-12 u-mt-0 u-muted"><?php echo t('reverse_proxy.ports_desc'); ?></p>

        <div class="table-responsive" style="margin-bottom: 24px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('reverse_proxy.th_port'); ?></th>
                        <th><?php echo t('reverse_proxy.th_proto'); ?></th>
                        <th><?php echo t('reverse_proxy.th_service'); ?></th>
                        <th><?php echo t('reverse_proxy.th_scope'); ?></th>
                        <th><?php echo t('reverse_proxy.th_bypass'); ?></th>
                        <th><?php echo t('reverse_proxy.th_desc'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ports as $port): ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 700;"><?php echo $h($port['port']); ?></td>
                        <td><span class="badge badge-secondary u-fs-11"><?php echo $h($port['proto']); ?></span></td>
                        <td><strong><?php echo $h($port['service']); ?></strong></td>
                        <td><?php echo $h($port['scope']); ?></td>
                        <td>
                            <?php if ($port['bypass']): ?>
                                <span class="badge badge-warning u-fs-11" title="<?php echo $h(t('reverse_proxy.bypass_badge_hint')); ?>">
                                    <i class="fas fa-arrow-right"></i> <?php echo t('reverse_proxy.bypass_yes'); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-info u-fs-11">
                                    <i class="fas fa-shuffle"></i> <?php echo t('reverse_proxy.bypass_no'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="u-fs-12 u-muted"><?php echo $h($port['description']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Firewall Commands Tabs -->
        <div style="font-weight: 700; font-size: 14px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-fire u-danger"></i> <?php echo t('reverse_proxy.fw_scripts_title'); ?>
        </div>

        <div class="tabs-nav" style="display: flex; gap: 8px; border-bottom: 1px solid var(--border-color, #e2e8f0); margin-bottom: 16px;">
            <button type="button" class="fw-tab-btn active" onclick="selectFwTab('ufw', this)" style="padding: 6px 14px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent;">UFW (Ubuntu/Debian)</button>
            <button type="button" class="fw-tab-btn" onclick="selectFwTab('firewalld', this)" style="padding: 6px 14px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent;">firewalld (RHEL/Rocky)</button>
            <button type="button" class="fw-tab-btn" onclick="selectFwTab('iptables', this)" style="padding: 6px 14px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent;">iptables</button>
        </div>

        <?php foreach (['ufw' => 'UFW', 'firewalld' => 'firewalld', 'iptables' => 'iptables'] as $fwKey => $fwName): ?>
            <div id="fwTab_<?php echo $fwKey; ?>" class="fw-tab-content" style="<?php echo $fwKey === 'ufw' ? '' : 'display: none;'; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span class="u-fs-12 u-strong"><?php echo sprintf(t('reverse_proxy.fw_rules_for'), $fwName); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyProxySnippet('fwSnippet_<?php echo $fwKey; ?>')">
                        <i class="fas fa-copy"></i> <?php echo t('common.copy'); ?>
                    </button>
                </div>
                <pre id="fwSnippet_<?php echo $fwKey; ?>" style="background: #0f172a; color: #e2e8f0; padding: 14px 18px; border-radius: 6px; font-size: 12px; line-height: 1.5; max-height: 380px; overflow: auto; white-space: pre; margin: 0;"><?php
                    echo $h(ReverseProxyService::generateFirewallCommands($fwKey));
                ?></pre>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function selectProxyTab(key, btn) {
    document.querySelectorAll('.proxy-tab-content').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.tab-btn').forEach(function(el) {
        el.classList.remove('active');
        el.style.borderBottomColor = 'transparent';
        el.style.color = 'inherit';
    });
    var target = document.getElementById('proxyTab_' + key);
    if (target) { target.style.display = 'block'; }
    btn.classList.add('active');
    btn.style.borderBottomColor = 'var(--primary, #0284c7)';
    btn.style.color = 'var(--primary, #0284c7)';
}

function selectFwTab(key, btn) {
    document.querySelectorAll('.fw-tab-content').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.fw-tab-btn').forEach(function(el) {
        el.classList.remove('active');
        el.style.borderBottomColor = 'transparent';
        el.style.color = 'inherit';
    });
    var target = document.getElementById('fwTab_' + key);
    if (target) { target.style.display = 'block'; }
    btn.classList.add('active');
    btn.style.borderBottomColor = 'var(--primary, #0284c7)';
    btn.style.color = 'var(--primary, #0284c7)';
}

function copyProxySnippet(id) {
    var el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.innerText).then(function() {
        if (typeof showToast === 'function') {
            showToast('<?php echo t('common.copied_to_clipboard'); ?>', 'success');
        } else {
            alert('<?php echo t('common.copied_to_clipboard'); ?>');
        }
    }).catch(function() {
        alert('Could not copy to clipboard.');
    });
}

// Initialise active tab styling
document.addEventListener('DOMContentLoaded', function() {
    var pActive = document.querySelector('.tab-btn.active');
    if (pActive) {
        pActive.style.borderBottomColor = 'var(--primary, #0284c7)';
        pActive.style.color = 'var(--primary, #0284c7)';
    }
    var fwActive = document.querySelector('.fw-tab-btn.active');
    if (fwActive) {
        fwActive.style.borderBottomColor = 'var(--primary, #0284c7)';
        fwActive.style.color = 'var(--primary, #0284c7)';
    }
});
</script>
