<?php
/** @var array $st  CertificateService::status() */
$a = $st['active'];
$leaf = $a['leaf'] ?? null;
$domain = $st['domain'];
$level_color = ['ok' => 'var(--success)', 'warning' => 'var(--warning)', 'danger' => 'var(--danger)'][$a['level']];
$level_icon = ['ok' => 'fa-circle-check', 'warning' => 'fa-triangle-exclamation', 'danger' => 'fa-circle-xmark'][$a['level']];
$mode_labels = [
    'letsencrypt' => t('certificates.mode_letsencrypt'),
    'custom' => t('certificates.mode_custom'),
    'selfsigned' => t('certificates.mode_selfsigned'),
    '' => t('certificates.mode_unknown'),
];
$fmt = fn(?int $ts) => $ts ? date('d.m.Y H:i', $ts) : '—';
$is_le = $st['mode'] === 'letsencrypt' && $st['le_lineage'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-certificate u-primary"></i> <?php echo t('certificates.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('certHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>
    <div class="module-help-box" id="certHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('certificates.help_title'); ?></h4>
        <?php echo t('certificates.help_body'); ?>
    </div>

    <div style="padding: 16px 20px;">
        <div class="u-flex-gap" style="align-items: center; margin-bottom: 14px;">
            <i class="fas <?php echo $level_icon; ?>" style="font-size: 28px; color: <?php echo $level_color; ?>;"></i>
            <div>
                <div style="font-size: 18px; font-weight: 800; color: <?php echo $level_color; ?>;"><?php echo t('certificates.level_' . $a['level']); ?></div>
                <div class="u-muted u-fs-12"><?php echo $h($domain); ?> · <?php echo $mode_labels[$st['mode']]; ?></div>
            </div>
        </div>

        <?php if (!empty($a['issues'])): ?>
            <ul style="margin: 0 0 16px 18px; padding: 0;">
                <?php foreach ($a['issues'] as $issue): ?>
                    <li class="u-fs-12 u-mb-4"><?php echo sprintf(t('certificates.issue_' . $issue), $h($domain)); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($leaf): ?>
        <div class="table-responsive">
            <table class="data-table">
                <tbody>
                    <tr><td class="u-muted" style="width: 220px;"><?php echo t('certificates.field_subject'); ?></td><td><?php echo $h($leaf['subject_cn']); ?></td></tr>
                    <tr><td class="u-muted"><?php echo t('certificates.field_names'); ?></td><td style="font-family: monospace; font-size: 12px;"><?php echo $h(implode(', ', array_column($leaf['san'], 'value')) ?: '—'); ?></td></tr>
                    <tr><td class="u-muted"><?php echo t('certificates.field_issuer'); ?></td><td><?php echo $h(trim($leaf['issuer_o'] . ' ' . $leaf['issuer_cn'])); ?></td></tr>
                    <tr><td class="u-muted"><?php echo t('certificates.field_valid'); ?></td><td><?php echo $fmt($leaf['not_before']); ?> → <strong><?php echo $fmt($leaf['not_after']); ?></strong>
                        <span class="badge <?php echo $a['days_left'] < 0 ? 'badge-danger' : ($a['days_left'] < CertificateService::WARN_DAYS ? 'badge-warning' : 'badge-success'); ?> u-fs-11" style="margin-left: 6px;"><?php echo sprintf(t('certificates.days_left'), $a['days_left']); ?></span></td></tr>
                    <tr><td class="u-muted"><?php echo t('certificates.field_chain'); ?></td><td><?php echo (int) $a['chain_length']; ?></td></tr>
                    <tr><td class="u-muted"><?php echo t('certificates.field_fingerprint'); ?></td><td style="font-family: monospace; font-size: 11px; word-break: break-all;"><?php echo $h(strtoupper(implode(':', str_split($leaf['fingerprint'], 2)))); ?></td></tr>
                    <tr><td class="u-muted"><?php echo t('certificates.field_services'); ?></td><td>
                        <span class="badge badge-success u-fs-11"><i class="fas fa-check"></i> <?php echo t('certificates.svc_portal'); ?></span>
                        <?php foreach (['coturn' => t('certificates.svc_turn'), 'asterisk' => t('certificates.svc_sip')] as $svc => $label): ?>
                            <?php $same = $st['copies'][$svc]['same']; ?>
                            <span class="badge <?php echo $same ? 'badge-success' : 'badge-danger'; ?> u-fs-11" title="<?php echo $same ? '' : $h(t('certificates.copy_differs')); ?>">
                                <i class="fas <?php echo $same ? 'fa-check' : 'fa-xmark'; ?>"></i> <?php echo $label; ?>
                            </span>
                        <?php endforeach; ?>
                    </td></tr>
                    <?php if ($is_le): ?>
                    <tr><td class="u-muted"><?php echo t('certificates.field_next_check'); ?></td><td><?php echo $fmt($st['next_renewal_check']); ?> <span class="u-muted u-fs-11"><?php echo t('certificates.next_check_hint'); ?></span></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($st['last'])): ?>
                    <tr><td class="u-muted"><?php echo t('certificates.field_last'); ?></td><td>
                        <i class="fas <?php echo !empty($st['last']['ok']) ? 'fa-check u-success' : 'fa-xmark u-danger'; ?>"></i>
                        <?php echo $fmt(strtotime((string) ($st['last']['at'] ?? '')) ?: null); ?> — <span class="u-fs-12"><?php echo $h($st['last']['message'] ?? ''); ?></span>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($output !== ''): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-terminal u-primary"></i> <?php echo t('certificates.output'); ?></div></div>
    <pre style="margin: 0; white-space: pre-wrap; background: #0f172a; color: #e2e8f0; padding: 12px 16px; font-size: 11px; max-height: 320px; overflow: auto;"><?php echo $h($output); ?></pre>
</div>
<?php endif; ?>

<!-- Let's Encrypt -->
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-lock u-primary"></i> <?php echo t('certificates.le_title'); ?></div></div>
    <div style="padding: 16px 20px;">
        <?php if (!$st['le_possible']): ?>
            <div class="u-muted"><?php echo sprintf(t('certificates.le_not_possible_long'), $h($domain)); ?></div>
        <?php elseif (!$st['certbot']): ?>
            <div class="u-danger"><?php echo t('certificates.le_no_certbot'); ?></div>
        <?php elseif ($is_le): ?>
            <p class="u-fs-12 u-mt-0"><?php echo t('certificates.le_active_text'); ?></p>
            <div class="u-flex-gap" style="flex-wrap: wrap;">
                <form method="POST" class="u-inline cert-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="renew_test">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-vial"></i> <?php echo t('certificates.btn_renew_test'); ?></button>
                </form>
                <form method="POST" class="u-inline cert-form" data-confirm="<?php echo $h(t('certificates.confirm_renew')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="renew">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-rotate"></i> <?php echo t('certificates.btn_renew'); ?></button>
                </form>
            </div>
        <?php else: ?>
            <p class="u-fs-12 u-mt-0"><?php echo sprintf(t('certificates.le_requirements'), $h($domain)); ?></p>
            <form method="POST" class="cert-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <div class="form-group" style="max-width: 420px;">
                    <label class="form-label"><?php echo t('certificates.field_email'); ?></label>
                    <input type="email" name="email" class="form-control" placeholder="admin@<?php echo $h($domain); ?>">
                    <div class="u-hint"><?php echo t('certificates.email_hint'); ?></div>
                </div>
                <div class="u-flex-gap" style="flex-wrap: wrap;">
                    <button type="submit" name="action" value="le_test" class="btn btn-secondary"><i class="fas fa-vial"></i> <?php echo t('certificates.btn_le_test'); ?></button>
                    <button type="submit" name="action" value="le_issue" class="btn btn-primary" data-confirm="<?php echo $h(t('certificates.confirm_switch')); ?>"><i class="fas fa-certificate"></i> <?php echo t('certificates.btn_le_issue'); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Uploaded certificate -->
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-file-arrow-up u-primary"></i> <?php echo t('certificates.upload_title'); ?></div></div>
    <form method="POST" enctype="multipart/form-data" class="cert-form" autocomplete="off" style="padding: 16px 20px;" data-confirm="<?php echo $h(t('certificates.confirm_switch')); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="action" value="upload">
        <p class="u-fs-12 u-mt-0"><?php echo t('certificates.upload_text'); ?></p>
        <div class="u-grid-2">
            <div>
                <div class="u-strong u-mb-10"><?php echo t('certificates.upload_pem'); ?></div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('certificates.field_cert_file'); ?></label>
                    <input type="file" name="cert" class="form-control" accept=".pem,.crt,.cer">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('certificates.field_chain_file'); ?></label>
                    <input type="file" name="chain" class="form-control" accept=".pem,.crt,.cer">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('certificates.field_key_file'); ?></label>
                    <input type="file" name="key" class="form-control" accept=".pem,.key">
                </div>
            </div>
            <div>
                <div class="u-strong u-mb-10"><?php echo t('certificates.upload_pfx'); ?></div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('certificates.field_pfx_file'); ?></label>
                    <input type="file" name="pfx" class="form-control" accept=".pfx,.p12">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('certificates.field_password'); ?></label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password">
                    <div class="u-hint"><?php echo t('certificates.password_hint'); ?></div>
                </div>
            </div>
        </div>
        <label class="u-check-label u-fs-12 u-mb-10" style="display: block;">
            <input type="checkbox" name="allow_mismatch" value="1" class="u-accent"> <?php echo sprintf(t('certificates.allow_mismatch'), $h($domain)); ?>
        </label>
        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> <?php echo t('certificates.btn_upload'); ?></button>
    </form>
</div>

<!-- Self-signed -->
<?php if ($st['mode'] !== 'selfsigned'): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-rotate-left u-primary"></i> <?php echo t('certificates.selfsigned_title'); ?></div></div>
    <form method="POST" class="cert-form" style="padding: 16px 20px;" data-confirm="<?php echo $h(t('certificates.confirm_selfsigned')); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="action" value="selfsigned">
        <p class="u-fs-12 u-mt-0"><?php echo t('certificates.selfsigned_text'); ?></p>
        <button type="submit" class="btn btn-warning"><i class="fas fa-rotate-left"></i> <?php echo t('certificates.btn_selfsigned'); ?></button>
    </form>
</div>
<?php endif; ?>

<script>window.CERTIFICATES_WORKING = <?php echo json_encode(t('certificates.working')); ?>;</script>
<script src="<?php echo asset('/assets/js/certificates.js'); ?>"></script>
