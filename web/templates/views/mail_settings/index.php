<?php
$host = $settings['mail_relay_host'] ?? '';
$port = $settings['mail_smtp_port'] ?? '25';
$security = $settings['mail_smtp_security'] ?? 'none';
$auth = $settings['mail_smtp_auth'] ?? 'no';
$user = $settings['mail_smtp_user'] ?? '';
$from_addr = $settings['mail_from_address'] ?? ($settings['portal_email_from_address'] ?? 'no-reply@example.com');
$from_name = $settings['mail_from_name'] ?? ($settings['portal_email_from_name'] ?? 'AI PBX');
$fax_from_addr = $settings['fax_email_from_address'] ?? 'fax@example.com';
$fax_from_name = $settings['fax_email_from_name'] ?? 'AI PBX Faks Sistemi';
$sync_postfix = ($settings['mail_sync_postfix'] ?? 'yes') === 'yes';
?>

<!-- Mail Settings Single-Line Fixed Tabs -->
<div class="card tabbed-page">
    <?php echo uiPageHeader('fa-envelope', t('mail_settings.title')); ?>
<div class="settings-tabs">
    <button type="button" class="settings-tab-btn active" data-tab="smtp" onclick="switchSettingsTab('smtp', this)">
        <i class="fas fa-server"></i> <?php echo t('mail_settings.tab_smtp'); ?>
    </button>
    <button type="button" class="settings-tab-btn" data-tab="sender" onclick="switchSettingsTab('sender', this)">
        <i class="fas fa-at"></i> <?php echo t('mail_settings.tab_sender'); ?>
    </button>
    <button type="button" class="settings-tab-btn" data-tab="test" onclick="switchSettingsTab('test', this)">
        <i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.tab_test'); ?>
    </button>
    <a class="settings-tab-btn u-text-decoration-none" href="/mail-templates">
        <i class="fas fa-envelope-open-text"></i> <?php echo t('mail_templates.tab'); ?>
    </a>
</div>

<!-- Main settings form -->
<form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_mail_settings" value="1">

    <!-- SECTION 1: mail relay & SMTP server -->
    <div id="tab_smtp" class="settings-tab-pane active">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-server u-primary"></i> <?php echo t('mail_settings.section_smtp'); ?>
            </div>
            <?php echo uiHelpButton('mailHelpBox'); ?>
        </div>

                <?php echo uiHelpBoxOpen('mailHelpBox', t('mail_settings.help_title')); ?>
            <p><?php echo t('mail_settings.help_body'); ?></p>
<?php echo uiHelpBoxClose(); ?>

        <div class="u-d-grid u-gtc-2fr-1fr u-gap-16">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-network-wired"></i> <?php echo t('mail_settings.field_host'); ?></label>
                <input type="text" name="mail_relay_host" class="form-control" value="<?php echo htmlspecialchars($host); ?>" placeholder="192.168.1.25 / mail.example.com">
                <small class="u-hint"><?php echo t('mail_settings.host_help'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.field_port'); ?></label>
                <input type="number" name="mail_smtp_port" class="form-control" value="<?php echo htmlspecialchars($port); ?>" min="1" max="65535" placeholder="25">
                <small class="u-hint"><?php echo t('mail_settings.port_help'); ?></small>
            </div>
        </div>

        <div class="u-grid-2 u-mt-12">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-shield-alt"></i> <?php echo t('mail_settings.field_security'); ?></label>
                <select name="mail_smtp_security" class="form-control">
                    <option value="none" <?php echo $security === 'none' ? 'selected' : ''; ?>><?php echo t('mail_settings.sec_none'); ?></option>
                    <option value="tls" <?php echo $security === 'tls' ? 'selected' : ''; ?>><?php echo t('mail_settings.starttls'); ?></option>
                    <option value="ssl" <?php echo $security === 'ssl' ? 'selected' : ''; ?>>SSL / TLS (Port 465)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="fas fa-key"></i> <?php echo t('mail_settings.field_auth'); ?></label>
                <select name="mail_smtp_auth" id="mail_smtp_auth" class="form-control" onchange="toggleAuthFields(this.value)">
                    <option value="no" <?php echo $auth === 'no' ? 'selected' : ''; ?>><?php echo t('mail_settings.auth_no'); ?></option>
                    <option value="yes" <?php echo $auth === 'yes' ? 'selected' : ''; ?>><?php echo t('mail_settings.auth_yes'); ?></option>
                </select>
            </div>
        </div>

        <div id="auth_fields" style="display: <?php echo $auth === 'yes' ? 'grid' : 'none'; ?>; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.field_user'); ?></label>
                <input type="text" name="mail_smtp_user" class="form-control" value="<?php echo htmlspecialchars($user); ?>" placeholder="user@example.com">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.field_pass'); ?></label>
                <input type="password" name="mail_smtp_pass" class="form-control" placeholder="<?php echo t('mail_settings.keep_pw'); ?>">
            </div>
        </div>

        <div class="form-group u-mt-16">
            <label class="u-d-flex u-ai-center u-gap-8 u-pointer">
                <input type="checkbox" name="mail_sync_postfix" value="1" <?php echo $sync_postfix ? 'checked' : ''; ?>>
                <span class="u-fw-600 u-text-main"><?php echo t('mail_settings.sync_postfix_label'); ?></span>
            </label>
            <small class="u-muted u-ml-24 u-mt-2" style="display: block;">
                <?php echo t('mail_settings.sync_postfix_help'); ?>
            </small>
        </div>

        <div class="u-mt-20 u-ta-right">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </div>
    </div>
    </div>

    <!-- SECTION 2: sender identity (From) -->
    <div id="tab_sender" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-at u-primary"></i> <?php echo t('mail_settings.section_sender'); ?>
            </div>
        </div>

        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.portal_from_addr'); ?></label>
                <input type="email" name="mail_from_address" class="form-control" value="<?php echo htmlspecialchars($from_addr); ?>" required>
                <small class="u-hint"><?php echo t('mail_settings.portal_from_addr_help'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.portal_from_name'); ?></label>
                <input type="text" name="mail_from_name" class="form-control" value="<?php echo htmlspecialchars($from_name); ?>" required>
            </div>
        </div>

        <div class="u-d-grid u-gtc-1fr-1fr u-gap-16 u-mt-16">
            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.fax_from_addr'); ?></label>
                <input type="email" name="fax_email_from_address" class="form-control" value="<?php echo htmlspecialchars($fax_from_addr); ?>" required>
                <small class="u-hint"><?php echo t('mail_settings.fax_from_addr_help'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.fax_from_name'); ?></label>
                <input type="text" name="fax_email_from_name" class="form-control" value="<?php echo htmlspecialchars($fax_from_name); ?>" required>
            </div>
        </div>

        <div class="u-mt-20 u-ta-right">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </div>
    </div>
    </div>
</form>

<!-- SECTION 3: live status and test email -->
<div id="tab_test" class="settings-tab-pane" style="display: none;">
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane u-primary"></i> <?php echo t('mail_settings.section_test_status'); ?>
        </div>
    </div>

    <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-280-1fr u-gap-20">
        <!-- Status summary -->
        <div class="u-bg-bg-input u-bd-1-solid-border-color u-r-12 u-p-16">
            <div class="u-fs-13 u-fw-700 u-text-main u-mb-12 u-d-flex u-ai-center u-jc-space-between">
                <span><i class="fas fa-info-circle"></i> <?php echo t('mail_settings.mta_status'); ?></span>
                <?php if ($postfix['is_running']): ?>
                    <span class="badge badge-success u-fs-11"><i class="fas fa-check-circle"></i> <?php echo t('dashboard.status_active'); ?></span>
                <?php else: ?>
                    <span class="badge badge-danger u-fs-11"><i class="fas fa-times-circle"></i> <?php echo t('dashboard.status_down'); ?></span>
                <?php endif; ?>
            </div>

            <div class="u-fs-12 u-lh-1-8 u-muted">
                <div><?php echo t('mail_settings.active_relay'); ?> <strong class="u-primary"><?php echo htmlspecialchars($postfix['relayhost'] ?: t('dashboard.not_configured')); ?></strong></div>
                <div><?php echo t('mail_settings.queue_status'); ?> <strong style="color: <?php echo $postfix['queue_count'] > 0 ? 'var(--warning)' : 'var(--success)'; ?>;"><?php echo htmlspecialchars($postfix['queue_summary']); ?></strong></div>
            </div>
        </div>

        <!-- Test email form -->
        <div class="u-bg-bg-input u-bd-1-solid-border-color u-r-12 u-p-16">
            <div class="u-fs-13 u-fw-700 u-text-main u-mb-8">
                <i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.test_title'); ?>
            </div>
            <p class="u-fs-12 u-muted u-m-0-0-12-0">
                <?php echo t('mail_settings.test_desc'); ?>
            </p>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="send_test_email" value="1">
                <div class="u-flex-gap">
                    <input type="email" name="test_recipient" class="form-control u-flex-1" placeholder="name@example.com" required>
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.btn_send_test'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
</div>

<script>
// /mail-settings#sender or #test (links from the Templates page) opens that tab.
(function () {
    var key = (location.hash || '').replace('#', '');
    var btn = key && document.querySelector('.settings-tab-btn[data-tab="' + key + '"]');
    if (btn) switchSettingsTab(key, btn);
})();
function toggleAuthFields(val) {
    var el = document.getElementById('auth_fields');
    if (el) {
        el.style.display = (val === 'yes') ? 'grid' : 'none';
    }
}
</script>
