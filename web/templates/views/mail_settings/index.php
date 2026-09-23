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
<div class="settings-tabs">
    <button type="button" class="settings-tab-btn active" data-tab="smtp" onclick="switchSettingsTab('smtp', this)">
        <i class="fas fa-server"></i> <?php echo t('mail_settings.tab_smtp', 'SMTP & Relay'); ?>
    </button>
    <button type="button" class="settings-tab-btn" data-tab="sender" onclick="switchSettingsTab('sender', this)">
        <i class="fas fa-at"></i> <?php echo t('mail_settings.tab_sender', 'Gönderici'); ?>
    </button>
    <button type="button" class="settings-tab-btn" data-tab="test" onclick="switchSettingsTab('test', this)">
        <i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.tab_test', 'Test & Durum'); ?>
    </button>
</div>

<!-- Ana Ayarlar Formu -->
<form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_mail_settings" value="1">

    <!-- BÖLÜM 1: Mail Relay & SMTP Sunucusu -->
    <div id="tab_smtp" class="settings-tab-pane active">
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-server" style="color: var(--primary);"></i> <?php echo t('mail_settings.section_smtp', 'Mail Relay & SMTP Sunucusu'); ?>
            </div>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('mailHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>

        <!-- Collapsible Help Box -->
        <div class="module-help-box" id="mailHelpBox" style="margin: 0 0 16px 0;">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('mail_settings.help_title', 'Mail Relay & E-Posta Rehberi'); ?></h4>
            <p><?php echo t('mail_settings.help_body', 'Faks bildirimleri, şifre sıfırlama mailleri ve sistem uyarıları bu SMTP/Relay sunucusu üzerinden iletilir. Postfix MTA entegrasyonu seçilirse sunucu arka planda postconf ve SASL ayarlarını otomatik olarak günceller.'); ?></p>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-network-wired"></i> <?php echo t('mail_settings.field_host', 'SMTP / Relay Sunucu Adresi'); ?></label>
                <input type="text" name="mail_relay_host" class="form-control" value="<?php echo htmlspecialchars($host); ?>" placeholder="10.8.0.1 veya mail.kurum.edu.tr">
                <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('mail_settings.host_help', 'Kurumsal mail sunucunuzun veya relay makinesinin IP / FQDN adresi.'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.field_port', 'Port'); ?></label>
                <input type="number" name="mail_smtp_port" class="form-control" value="<?php echo htmlspecialchars($port); ?>" min="1" max="65535" placeholder="25">
                <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('mail_settings.port_help', 'Standart relay: 25, STARTTLS: 587, SSL: 465'); ?></small>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-shield-alt"></i> <?php echo t('mail_settings.field_security', 'Güvenlik / Şifreleme'); ?></label>
                <select name="mail_smtp_security" class="form-control">
                    <option value="none" <?php echo $security === 'none' ? 'selected' : ''; ?>><?php echo t('mail_settings.sec_none', 'Yok (Düz Metin / İç Ağ Relay)'); ?></option>
                    <option value="tls" <?php echo $security === 'tls' ? 'selected' : ''; ?>>STARTTLS (Önerilen - Port 587)</option>
                    <option value="ssl" <?php echo $security === 'ssl' ? 'selected' : ''; ?>>SSL / TLS (Port 465)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="fas fa-key"></i> <?php echo t('mail_settings.field_auth', 'Kimlik Doğrulama (SMTP Auth)'); ?></label>
                <select name="mail_smtp_auth" id="mail_smtp_auth" class="form-control" onchange="toggleAuthFields(this.value)">
                    <option value="no" <?php echo $auth === 'no' ? 'selected' : ''; ?>><?php echo t('mail_settings.auth_no', 'Hayır (IP Bazlı İzinli / Anonim Relay)'); ?></option>
                    <option value="yes" <?php echo $auth === 'yes' ? 'selected' : ''; ?>><?php echo t('mail_settings.auth_yes', 'Evet (Kullanıcı Adı & Parola Gerekli)'); ?></option>
                </select>
            </div>
        </div>

        <div id="auth_fields" style="display: <?php echo $auth === 'yes' ? 'grid' : 'none'; ?>; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.field_user', 'SMTP Kullanıcı Adı'); ?></label>
                <input type="text" name="mail_smtp_user" class="form-control" value="<?php echo htmlspecialchars($user); ?>" placeholder="kullanici@kurum.edu.tr">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.field_pass', 'SMTP Parola'); ?></label>
                <input type="password" name="mail_smtp_pass" class="form-control" placeholder="Değiştirmek istemiyorsanız boş bırakın">
            </div>
        </div>

        <div class="form-group" style="margin-top: 16px;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="checkbox" name="mail_sync_postfix" value="1" <?php echo $sync_postfix ? 'checked' : ''; ?>>
                <span style="font-weight: 600; color: var(--text-main);"><?php echo t('mail_settings.sync_postfix_label', 'Sistem Postfix MTA relayhost ayarlarını otomatik senkronize et'); ?></span>
            </label>
            <small style="color: var(--text-muted); display: block; margin-left: 24px; margin-top: 2px;">
                <?php echo t('mail_settings.sync_postfix_help', 'İşaretlendiğinde arka planda çalışan Postfix MTA servisine relayhost yazılır ve servis otomatik reload edilir.'); ?>
            </small>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </div>
    </div>
    </div>

    <!-- BÖLÜM 2: Gönderici Kimlik Bilgileri (From) -->
    <div id="tab_sender" class="settings-tab-pane" style="display: none;">
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-at" style="color: var(--primary);"></i> <?php echo t('mail_settings.section_sender', 'Gönderici Kimlik Bilgileri'); ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.portal_from_addr', 'Sistem & Portal Gönderici E-Postası'); ?></label>
                <input type="email" name="mail_from_address" class="form-control" value="<?php echo htmlspecialchars($from_addr); ?>" required>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('mail_settings.portal_from_addr_help', 'Şifre sıfırlama ve genel bildirim maillerinde From başlığı olarak kullanılır.'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.portal_from_name', 'Sistem & Portal Gönderici Adı'); ?></label>
                <input type="text" name="mail_from_name" class="form-control" value="<?php echo htmlspecialchars($from_name); ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.fax_from_addr', 'Faks Bildirim Gönderici E-Postası'); ?></label>
                <input type="email" name="fax_email_from_address" class="form-control" value="<?php echo htmlspecialchars($fax_from_addr); ?>" required>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('mail_settings.fax_from_addr_help', 'Gelen ve giden faks raporlarının iletildiği e-postalarda From başlığı.'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_settings.fax_from_name', 'Faks Gönderici Adı'); ?></label>
                <input type="text" name="fax_email_from_name" class="form-control" value="<?php echo htmlspecialchars($fax_from_name); ?>" required>
            </div>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </div>
    </div>
    </div>
</form>

<!-- BÖLÜM 3: Canlı Durum ve Test E-Postası Gönderimi -->
<div id="tab_test" class="settings-tab-pane" style="display: none;">
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane" style="color: var(--primary);"></i> <?php echo t('mail_settings.section_test_status', 'Canlı Posta Durumu ve Test Gönderimi'); ?>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
        <!-- Durum Özeti -->
        <div style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
            <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                <span><i class="fas fa-info-circle"></i> <?php echo t('mail_settings.mta_status', 'Postfix MTA Durumu'); ?></span>
                <?php if ($postfix['is_running']): ?>
                    <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check-circle"></i> <?php echo t('dashboard.status_active'); ?></span>
                <?php else: ?>
                    <span class="badge badge-danger" style="font-size: 11px;"><i class="fas fa-times-circle"></i> <?php echo t('dashboard.status_down'); ?></span>
                <?php endif; ?>
            </div>

            <div style="font-size: 12px; line-height: 1.8; color: var(--text-muted);">
                <div><?php echo t('mail_settings.active_relay', 'Aktif Postfix Relay:'); ?> <strong style="color: var(--primary);"><?php echo htmlspecialchars($postfix['relayhost'] ?: t('dashboard.not_configured')); ?></strong></div>
                <div><?php echo t('mail_settings.queue_status', 'Posta Kuyruğu:'); ?> <strong style="color: <?php echo $postfix['queue_count'] > 0 ? 'var(--warning)' : 'var(--success)'; ?>;"><?php echo htmlspecialchars($postfix['queue_summary']); ?></strong></div>
            </div>
        </div>

        <!-- Test E-Postası Gönderim Formu -->
        <div style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
            <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
                <i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.test_title', 'Test E-Postası Gönder'); ?>
            </div>
            <p style="font-size: 12px; color: var(--text-muted); margin: 0 0 12px 0;">
                <?php echo t('mail_settings.test_desc', 'Mevcut ayarlar üzerinden test mesajı göndererek relay bağlantısını test edin.'); ?>
            </p>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="send_test_email" value="1">
                <div style="display: flex; gap: 8px;">
                    <input type="email" name="test_recipient" class="form-control" placeholder="ornek@kurum.edu.tr" required style="flex: 1;">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.btn_send_test', 'Test Gönder'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<script>
function toggleAuthFields(val) {
    var el = document.getElementById('auth_fields');
    if (el) {
        el.style.display = (val === 'yes') ? 'grid' : 'none';
    }
}
</script>
