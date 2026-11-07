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
    <?php echo uiPageHeaderOpen('fa-shield-alt', t('reverse_proxy.header_title'), 'proxyHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('proxyHelpBox', t('reverse_proxy.help_title')); ?>
        <p><?php echo t('reverse_proxy.help_body'); ?></p>
        <div class="u-mt-10"><strong><?php echo t('common.help_limits'); ?></strong><ul class="u-m-4-0-0-20"><li><?php echo t('reverse_proxy.limit_reencrypt'); ?></li></ul></div>
<?php echo uiHelpBoxClose(); ?>

    <div class="u-p-16-20">
        <!-- VoIP Bypass Notice -->
        <div style="background: rgba(234, 179, 8, 0.1); border-left: 4px solid var(--warning, #eab308); padding: 14px 18px; border-radius: 4px; margin-bottom: 20px;">
            <div class="u-fw-700 u-text-main u-d-flex u-ai-center u-gap-8 u-mb-8">
                <i class="fas fa-triangle-exclamation" style="color: var(--warning, #eab308); font-size: 18px;"></i>
                <?php echo t('reverse_proxy.voip_boundary_title'); ?>
            </div>
            <div class="u-fs-13 u-muted u-lh-1-6">
                <?php echo t('reverse_proxy.voip_boundary_desc'); ?>
            </div>
        </div>

        <!-- Real-Time Request Diagnostics -->
        <div style="background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 6px; padding: 14px 18px; margin-bottom: 24px;">
            <div class="u-fw-700 u-fs-13 u-mb-10 u-d-flex u-ai-center u-gap-8">
                <i class="fas fa-stethoscope u-primary"></i> <?php echo t('reverse_proxy.diag_title'); ?>
            </div>
            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-12 u-fs-12">
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_remote_addr'); ?>:</span><br>
                    <code class="u-fs-12"><?php echo $h($diagnostics['remote_addr']); ?></code>
                    <?php if ($diagnostics['is_trusted']): ?>
                        <span class="badge badge-success u-fs-11 u-ml-4"><?php echo t('reverse_proxy.diag_trusted'); ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary u-fs-11 u-ml-4"><?php echo t('reverse_proxy.diag_untrusted'); ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_forwarded_for'); ?>:</span><br>
                    <code class="u-fs-12"><?php echo $h($diagnostics['forwarded_for'] ?: '—'); ?></code>
                </div>
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_forwarded_proto'); ?>:</span><br>
                    <code class="u-fs-12"><?php echo $h($diagnostics['forwarded_proto'] ?: '—'); ?></code>
                </div>
                <div>
                    <span class="u-muted"><?php echo t('reverse_proxy.diag_resolved_ip'); ?>:</span><br>
                    <strong style="color: var(--primary, #0284c7); font-size: 13px;"><?php echo $h($diagnostics['resolved_client_ip']); ?></strong>
                </div>
            </div>
        </div>

        <!-- Reverse Proxy Settings Form -->
        <form method="POST" autocomplete="off" class="u-mb-30">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-group u-mb-16">
                <label class="u-check-label u-fs-14 u-pointer u-d-flex u-ai-center u-gap-8">
                    <input type="checkbox" name="proxy_enabled" value="1" class="u-accent u-w-18 u-h-18" <?php echo $settings['enabled'] ? 'checked' : ''; ?>>
                    <strong><?php echo t('reverse_proxy.enable_label'); ?></strong>
                </label>
                <div class="u-hint u-ml-26"><?php echo t('reverse_proxy.enable_hint'); ?></div>
            </div>

            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-280-1fr u-gap-16 u-mb-16">
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

            <div class="form-group u-mb-20">
                <label class="form-label"><?php echo t('reverse_proxy.field_trusted_proxies'); ?></label>
                <textarea name="proxy_trusted_proxies" class="form-control u-mono u-fs-13" rows="3" placeholder="127.0.0.1, ::1"><?php echo $h($settings['trusted_proxies']); ?></textarea>
                <div class="u-hint"><?php echo t('reverse_proxy.trusted_proxies_hint'); ?></div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </form>
    </div>
</div>

<!-- Live Reverse Proxy Configuration Generator -->
<div class="card u-mt-24">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-file-code u-primary"></i> <?php echo t('reverse_proxy.gen_title'); ?>
        </div>
    </div>
    <div class="u-p-16-20">
        <p class="u-fs-12 u-mt-0 u-muted"><?php echo t('reverse_proxy.gen_desc'); ?></p>

        <!-- Proxy Type Tabs -->
        <div class="tabs-nav">
            <?php foreach ($proxyTypes as $pKey => $pLabel): ?>
                <button type="button" class="tab-btn <?php echo $settings['proxy_type'] === $pKey ? 'active' : ''; ?>" onclick="selectProxyTab('<?php echo $pKey; ?>', this)">
                    <?php echo $pLabel; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($proxyTypes as $pKey => $pLabel): ?>
            <div id="proxyTab_<?php echo $pKey; ?>" class="proxy-tab-content" style="<?php echo $settings['proxy_type'] === $pKey ? '' : 'display: none;'; ?>">
                <div class="u-d-flex u-jc-space-between u-ai-center u-mb-8">
                    <span class="u-fs-12 u-strong"><?php echo sprintf(t('reverse_proxy.sample_config_for'), $pLabel); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyProxySnippet('proxySnippet_<?php echo $pKey; ?>')">
                        <i class="fas fa-copy"></i> <?php echo t('common.copy'); ?>
                    </button>
                </div>
                <pre class="u-bg-0f172a u-c-e2e8f0 u-p-16-20 u-r-8 u-fs-12 u-lh-1-5 u-maxh-460 u-of-auto u-white-space-pre u-m-0" id="proxySnippet_<?php echo $pKey; ?>"><?php
                    echo $h(ReverseProxyService::generateProxyConfig($pKey, $fqdn, $backendIp, $backendPort, $settings['tls_termination']));
                ?></pre>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Network Ports & Firewall Rules -->
<div class="card u-mt-24">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-network-wired u-primary"></i> <?php echo t('reverse_proxy.ports_title'); ?>
        </div>
    </div>
    <div class="u-p-16-20">
        <p class="u-fs-12 u-mt-0 u-muted"><?php echo t('reverse_proxy.ports_desc'); ?></p>

        <?php echo uiTableOpen([
            t('reverse_proxy.th_port'),
            t('reverse_proxy.th_proto'),
            t('reverse_proxy.th_service'),
            t('reverse_proxy.th_scope'),
            t('reverse_proxy.th_bypass'),
            t('reverse_proxy.th_desc'),
        ], ['frame' => 'u-mb-24']); ?>
                    <?php foreach ($ports as $port): ?>
                    <tr>
                        <td class="u-mono u-fw-700"><?php echo $h($port['port']); ?></td>
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
                <?php echo uiTableClose(); ?>

        <!-- Firewall Commands Tabs -->
        <div class="u-fw-700 u-fs-14 u-mb-12 u-d-flex u-ai-center u-gap-8">
            <i class="fas fa-fire u-danger"></i> <?php echo t('reverse_proxy.fw_scripts_title'); ?>
        </div>

        <div class="tabs-nav">
            <button type="button" class="tab-btn active" onclick="selectFwTab('ufw', this)">UFW (Ubuntu/Debian)</button>
            <button type="button" class="tab-btn" onclick="selectFwTab('firewalld', this)">firewalld (RHEL/Rocky)</button>
            <button type="button" class="tab-btn" onclick="selectFwTab('iptables', this)">iptables</button>
        </div>

        <?php foreach (['ufw' => 'UFW', 'firewalld' => 'firewalld', 'iptables' => 'iptables'] as $fwKey => $fwName): ?>
            <div id="fwTab_<?php echo $fwKey; ?>" class="fw-tab-content" style="<?php echo $fwKey === 'ufw' ? '' : 'display: none;'; ?>">
                <div class="u-d-flex u-jc-space-between u-ai-center u-mb-8">
                    <span class="u-fs-12 u-strong"><?php echo sprintf(t('reverse_proxy.fw_rules_for'), $fwName); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyProxySnippet('fwSnippet_<?php echo $fwKey; ?>')">
                        <i class="fas fa-copy"></i> <?php echo t('common.copy'); ?>
                    </button>
                </div>
                <pre class="u-bg-0f172a u-c-e2e8f0 u-p-16-20 u-r-8 u-fs-12 u-lh-1-5 u-maxh-380 u-of-auto u-white-space-pre u-m-0" id="fwSnippet_<?php echo $fwKey; ?>"><?php
                    echo $h(ReverseProxyService::generateFirewallCommands($fwKey));
                ?></pre>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function selectProxyTab(key, btn) {
    document.querySelectorAll('.proxy-tab-content').forEach(function(el) { el.style.display = 'none'; });
    btn.closest('.tabs-nav').querySelectorAll('.tab-btn').forEach(function(el) {
        el.classList.remove('active');
    });
    var target = document.getElementById('proxyTab_' + key);
    if (target) { target.style.display = 'block'; }
    btn.classList.add('active');
}

function selectFwTab(key, btn) {
    document.querySelectorAll('.fw-tab-content').forEach(function(el) { el.style.display = 'none'; });
    btn.closest('.tabs-nav').querySelectorAll('.tab-btn').forEach(function(el) {
        el.classList.remove('active');
    });
    var target = document.getElementById('fwTab_' + key);
    if (target) { target.style.display = 'block'; }
    btn.classList.add('active');
}

function copyProxySnippet(id) {
    var el = document.getElementById(id);
    if (!el) return;
    UIHelper.copyToClipboard(el.innerText, <?php echo json_encode(t('common.copied_to_clipboard')); ?>);
}
</script>
