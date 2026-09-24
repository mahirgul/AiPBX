<?php
$is_enabled = !empty($settings['enabled']);
$raw_enabled = !empty($settings['raw_enabled']);
$client_id = $settings['client_id'] ?? '';
$client_secret = $settings['client_secret'] ?? '';
$redirect_uri = $settings['redirect_uri'] ?? '';
?>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fab fa-google" style="color: #EA4335;"></i> <?php echo t('google_integration.header_title', 'Google ile Giriş Entegrasyonu (OAuth 2.0)'); ?>
            <span class="badge <?php echo $is_enabled ? 'badge-success' : 'badge-secondary'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $is_enabled ? t('common.active', 'Aktif') : t('common.passive', 'Devre Dışı'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('googleHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Rehber Kutusu -->
    <div class="module-help-box" id="googleHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('google_integration.guide_title', 'Google OAuth 2.0 & Tek Tıkla Giriş Rehberi'); ?></h4>
        <p>
            <?php echo t('google_integration.guide_p1', 'Google ile Giriş entegrasyonu, santral kullanıcılarının e-posta adresleri üzerinden şifre girmeden tek tıkla oturum açmasını sağlar. Bu özellik Web Portalı, Android ve iOS mobil uygulamalarının tamamında ortak çalışır.'); ?>
        </p>
        <p style="margin-bottom: 0;">
            <?php echo t('google_integration.guide_p2', 'Kullanıcının Google hesabı ile AI PBX kullanıcısının e-posta adresi eşleştiğinde kimlik doğrulanır ve yetkisine uygun arayüze otomatik olarak yönlendirilir.'); ?>
        </p>
    </div>

    <form method="POST" action="/google-integration" autocomplete="off" style="padding: 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="save_settings">

        <div style="background: var(--bg-hover, rgba(0,0,0,0.02)); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <label for="googleOauthToggle" style="font-size: 14px; font-weight: 700; cursor: pointer; margin: 0; display: block;">
                    <i class="fas fa-power-off" style="color: <?php echo $raw_enabled ? '#10b981' : 'var(--text-muted)'; ?>; margin-right: 6px;"></i>
                    <?php echo t('google_integration.enable_label', 'Google ile Girişi Etkinleştir'); ?>
                </label>
                <small style="color: var(--text-muted); display: block; margin-top: 3px;">
                    <?php echo t('google_integration.enable_desc', 'Aktif edildiğinde Web giriş ekranında ve mobil uygulamalarda Google ile Giriş Yap butonu görünür.'); ?>
                </small>
            </div>
            <div>
                <input type="checkbox" name="google_oauth_enabled" value="1" id="googleOauthToggle" <?php echo $raw_enabled ? 'checked' : ''; ?> style="width: 20px; height: 20px; cursor: pointer;">
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 18px;">
            <label class="form-label" style="font-size: 13px; font-weight: 700;">
                <i class="fas fa-id-badge" style="color: var(--primary);"></i> Google Client ID
            </label>
            <input type="text" name="google_client_id" class="form-control" value="<?php echo htmlspecialchars($client_id); ?>" placeholder="Örn: 1234567890-abcdefg123456.apps.googleusercontent.com" style="height: 40px; font-family: monospace; font-size: 13px;">
            <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                <?php echo t('google_integration.client_id_desc', 'Google Cloud Console üzerinden oluşturulan OAuth 2.0 Web Client ID değeri.'); ?>
            </small>
        </div>

        <div class="form-group" style="margin-bottom: 18px;">
            <label class="form-label" style="font-size: 13px; font-weight: 700;">
                <i class="fas fa-key" style="color: var(--primary);"></i> Google Client Secret
            </label>
            <div style="position: relative;">
                <input type="password" id="googleClientSecretInput" name="google_client_secret" class="form-control" value="<?php echo htmlspecialchars($client_secret); ?>" placeholder="Örn: GOCSPX-xxxxxx..." style="height: 40px; font-family: monospace; font-size: 13px; padding-right: 40px;">
                <button type="button" onclick="toggleSecretVisibility()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer;" title="Göster/Gizle">
                    <i class="fas fa-eye" id="secretEyeIcon"></i>
                </button>
            </div>
            <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                <?php echo t('google_integration.client_secret_desc', 'Google Cloud Console OAuth istemcisine ait gizli anahtar.'); ?>
            </small>
        </div>

        <div class="form-group" style="margin-bottom: 24px;">
            <label class="form-label" style="font-size: 13px; font-weight: 700;">
                <i class="fas fa-link" style="color: var(--primary);"></i> <?php echo t('google_integration.redirect_uri_label', 'Yetkili Yönlendirme Adresi (Redirect URI)'); ?>
            </label>
            <div style="display: flex; gap: 8px;">
                <input type="text" id="redirectUriInput" readonly class="form-control" value="<?php echo htmlspecialchars($redirect_uri); ?>" style="height: 40px; font-family: monospace; font-size: 13px; background: var(--bg-hover, rgba(0,0,0,0.03)); user-select: all;">
                <button type="button" class="btn btn-secondary" onclick="copyRedirectUri()" style="white-space: nowrap;">
                    <i class="fas fa-copy"></i> <span id="copyBtnText"><?php echo t('common.copy', 'Kopyala'); ?></span>
                </button>
            </div>
            <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                <?php echo t('google_integration.redirect_uri_desc', 'Google Cloud Console > Yetkili yönlendirme URI\'leri (Authorized redirect URIs) alanına birebir bu adresi eklemelisiniz.'); ?>
            </small>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary" style="font-weight: 700; padding: 10px 24px;">
                <i class="fas fa-save"></i> <?php echo t('google_integration.btn_save', 'Google Entegrasyon Ayarlarını Kaydet'); ?>
            </button>
        </div>
    </form>
</div>

<!-- Kurulum Adımları & Bilgi Kartı -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-tasks" style="color: var(--primary);"></i> <?php echo t('google_integration.setup_guide_title', 'Google Cloud Console Kurulum Adımları'); ?>
        </div>
    </div>
    <div style="padding: 20px; line-height: 1.7; font-size: 13.5px;">
        <ol style="padding-left: 20px; margin-bottom: 0;">
            <li style="margin-bottom: 10px;">
                <strong>Google Cloud Console</strong> adresine gidin: 
                <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" style="color: var(--primary); text-decoration: underline;">
                    console.cloud.google.com/apis/credentials <i class="fas fa-external-link-alt" style="font-size: 11px;"></i>
                </a>
            </li>
            <li style="margin-bottom: 10px;">
                Üst kısımdan <strong>+ CREATE CREDENTIALS (+ KİMLİK BİLGİSİ OLUŞTUR)</strong> butonuna tıklayıp <strong>OAuth client ID</strong> seçeneğini seçin.
            </li>
            <li style="margin-bottom: 10px;">
                Uygulama türü (Application type) olarak <strong>Web application (Web uygulaması)</strong> seçin ve bir isim verin (Örn: <em>AI PBX Santral</em>).
            </li>
            <li style="margin-bottom: 10px;">
                <strong>Authorized redirect URIs (Yetkili yönlendirme URI'leri)</strong> bölümünün altındaki <strong>+ ADD URI</strong> butonuna tıklayın ve yukarıda gösterilen <code><?php echo htmlspecialchars($redirect_uri); ?></code> adresini yapıştırın.
            </li>
            <li style="margin-bottom: 10px;">
                <strong>Create (Oluştur)</strong> butonuna basarak <strong>Client ID</strong> ve <strong>Client Secret</strong> değerlerinizi alın.
            </li>
            <li style="margin-bottom: 10px;">
                Bu değerleri yukarıdaki forma yapıştırıp <strong>Google ile Girişi Etkinleştir</strong> onay kutusunu işaretleyerek kaydedin.
            </li>
            <li style="margin-bottom: 0;">
                <strong>Mobil Uygulama (Android / iOS):</strong> Android ve iOS mobil uygulamalarınız aynı Web Client ID'yi kullanacak şekilde hazırdır. Santral üzerinde ayarları kaydettikten sonra mobil uygulamalarda tek tıkla Google ile oturum açılabilir.
            </li>
        </ol>
    </div>
</div>

<script>
function toggleSecretVisibility() {
    var inp = document.getElementById('googleClientSecretInput');
    var icon = document.getElementById('secretEyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

function copyRedirectUri() {
    var copyText = document.getElementById('redirectUriInput');
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        var btnText = document.getElementById('copyBtnText');
        var orig = btnText.innerText;
        btnText.innerText = 'Kopyalandı!';
        setTimeout(function() {
            btnText.innerText = orig;
        }, 2000);
    });
}
</script>
