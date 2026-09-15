<?php
$has_service_account = !empty($settings['push_fcm_service_account']);
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
            <i class="fas fa-bell" style="color: var(--primary);"></i> Mobil Bildirim Ayarları (Push Notifications)
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('pushHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="pushHelpBox" style="margin: 16px 20px 0 20px; display: none;">
        <h4><i class="fas fa-info-circle"></i> Mobil Bildirim Mimarisi Hakkında</h4>
        <p>AI PBX mobil softphone uygulaması <strong>üç katmanlı bildirim mimarisine</strong> sahiptir:</p>
        <ul style="margin: 8px 0 12px 20px; line-height: 1.6;">
            <li><strong>Katman 0 (Sıfır Bağımlılık - Varsayılan):</strong> Kalıcı WSS bağlantısı, Android 15 uyumlu <code>specialUse</code> ön plan servisi, Doze dirençli <code>AlarmManager</code> watchdog ve diriltme mekanizması. Dış servis gerektirmez; cihaz boşta veya ekran kapalıyken çağrılar sorunsuz alınır.</li>
            <li><strong>Katman 1 (İsteğe Bağlı - Google FCM):</strong> Agresif pil kısıtlaması uygulayan OEM cihazlarda (Xiaomi, Huawei vb.) veya kullanıcının uygulamayı görev yöneticisinden kaydırarak kapattığı durumlarda ekstra uyandırma garantisi sağlar. <em>Yalnızca abonenin aktif SIP kaydı yokken arka planda tetiklenir; bağlı abonelerde gecikme oluşturmaz.</em></li>
        </ul>
        <p style="margin-bottom: 0;">FCM kullanmak istemiyorsanız Sağlayıcıyı <strong>"Yerel Altyapı (Sıfır Bağımlılık)"</strong> olarak bırakabilirsiniz. Bu durumda mobil uygulama Google ile hiçbir ağ teması kurmaz.</p>
    </div>

    <form method="POST" autocomplete="off" style="padding: 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="save_push_settings" value="1">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="form-group">
                <label class="form-label" style="font-weight: 600;">Bildirim Katmanı</label>
                <select name="push_enabled" class="form-control" id="pushEnabledSelect" onchange="togglePushFields()">
                    <option value="1" <?php echo ($settings['push_enabled'] === '1') ? 'selected' : ''; ?>>Aktif (Push Bildirimleri Açık)</option>
                    <option value="0" <?php echo ($settings['push_enabled'] === '0') ? 'selected' : ''; ?>>Devre Dışı (Yalnızca Katman 0 Kalıcı WSS)</option>
                </select>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">Push bildirimleri kapalıyken Asterisk dialplan'ına hiçbir ek bekleme veya kanca eklenmez.</small>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-weight: 600;">Bildirim Sağlayıcısı</label>
                <select name="push_provider" class="form-control" id="pushProviderSelect" onchange="togglePushFields()">
                    <option value="none" <?php echo ($settings['push_provider'] === 'none') ? 'selected' : ''; ?>>Yok / Yerel Altyapı (Sıfır Bağımlılık)</option>
                    <option value="fcm" <?php echo ($settings['push_provider'] === 'fcm') ? 'selected' : ''; ?>>Google Firebase Cloud Messaging (FCM HTTP v1)</option>
                </select>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">Google servisleri olmadan çalışmak için 'Yok' seçeneğini belirleyin.</small>
            </div>
        </div>

        <div id="fcmConfigSection" style="<?php echo ($settings['push_provider'] === 'fcm') ? '' : 'display: none;'; ?> background: var(--bg-surface-secondary, rgba(0,0,0,0.02)); padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border-color, #e0e0e0);">
            <h4 style="margin-top: 0; margin-bottom: 16px; color: var(--primary); display: flex; align-items: center; gap: 8px;">
                <i class="fab fa-google"></i> Firebase Cloud Messaging (FCM v1) Yapılandırması
            </h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Firebase Proje Kimliği (Project ID) <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="push_fcm_project_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_project_id'] ?? ''); ?>" placeholder="ör. aipbx-phone-12345">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Firebase Konsolu → Proje Ayarları → Proje Kimliği.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Push Uyanma Bekleme Süresi (Saniye)</label>
                    <input type="number" name="push_wait_seconds" class="form-control" min="3" max="30" value="<?php echo htmlspecialchars($settings['push_wait_seconds'] ?? '8'); ?>">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Mobil uç bağlı değilken push uyanması için beklenecek maksimum süre (Varsayılan: 8 sn).</small>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Google Servis Hesabı JSON İçeriği (Service Account) <span style="color: var(--danger);">*</span></span>
                    <?php if ($has_service_account): ?>
                        <span class="badge" style="background: #28a745; color: #fff; font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                            <i class="fas fa-check-circle"></i> Servis Hesabı Tanımlı (Özel Anahtar Korunuyor)
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background: #ffc107; color: #212529; font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                            <i class="fas fa-exclamation-circle"></i> Henüz Tanımlanmadı
                        </span>
                    <?php endif; ?>
                </label>
                <textarea name="push_fcm_service_account" class="form-control" rows="5" style="font-family: monospace; font-size: 12px;" placeholder="<?php echo $has_service_account ? 'Mevcut servis hesabı kayıtlıdır. Değiştirmek istemiyorsanız bu alanı boş bırakın.' : 'Firebase Konsolu → Proje Ayarları → Hizmet Hesapları → \'Yeni özel anahtar oluştur\' ile indirilen JSON içeriğini buraya yapıştırın...'; ?>"></textarea>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">Güvenlik gereği kayıtlı servis hesabı anahtarı arayüzde geri gösterilmez.</small>
            </div>

            <div style="margin-top: 20px; border-top: 1px dashed var(--border-color, #ccc); padding-top: 16px;">
                <h5 style="margin-top: 0; margin-bottom: 12px; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-mobile-alt"></i> Mobil İstemci Açık Parametreleri (Dinamik Başlatma)
                </h5>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                    Mobil uygulama derlenirken içine <code>google-services.json</code> gömülmez. Bu bilgiler giriş yapan istemciye dinamik iletilerek FCM çalışma zamanında başlatılır:
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">Uygulama Kimliği (App ID)</label>
                        <input type="text" name="push_fcm_app_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_app_id'] ?? ''); ?>" placeholder="1:123456789:android:abcdef">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">API Anahtarı (API Key)</label>
                        <input type="text" name="push_fcm_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_api_key'] ?? ''); ?>" placeholder="AIzaSy...">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">GCM Gönderen Kimliği (Sender ID)</label>
                        <input type="text" name="push_fcm_sender_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_sender_id'] ?? ''); ?>" placeholder="ör. 123456789012">
                    </div>
                </div>
            </div>
        </div>

        <?php if (hasModulePermission('push_settings', 'edit')): ?>
        <div style="display: flex; justify-content: flex-end; gap: 12px;">
            <button type="submit" name="save_push_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> Ayarları Kaydet
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if (hasModulePermission('push_settings', 'edit')): ?>
<!-- Push Test Card -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane" style="color: var(--success, #28a745);"></i> Test Bildirimi Gönder
        </div>
    </div>
    <div style="padding: 20px;">
        <p style="margin-top: 0; color: var(--text-muted); font-size: 13px;">
            Kayıtlı bir mobil cihaza doğrudan FCM uyanma sinyali göndererek yapılandırmanın sağlıklı çalıştığını test edebilirsiniz.
        </p>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="font-weight: 600;">Hedef Dahili veya Kayıtlı Cihaz</label>
                <select id="testTargetSelect" class="form-control">
                    <option value="">-- Cihaz Seçin --</option>
                    <?php if (empty($devices)): ?>
                        <option value="" disabled>Henüz kayıtlı mobil cihaz bulunmuyor (Önce uygulamadan giriş yapınız)</option>
                    <?php else: ?>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['extension']); ?>">
                                Dahili: <?php echo htmlspecialchars($d['extension']); ?>
                                <?php if (!empty($d['full_name'])) echo ' (' . htmlspecialchars($d['full_name']) . ')'; ?>
                                - <?php echo htmlspecialchars($d['device_name'] ?: 'Android Cihaz'); ?>
                                [v<?php echo htmlspecialchars($d['app_version'] ?: '1.0'); ?>]
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <button type="button" id="btnTestPush" class="btn btn-success" onclick="sendTestPush()" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> Test Bildirimi Gönder
                </button>
            </div>
        </div>

        <div id="testResultBox" style="display: none; margin-top: 16px;"></div>
    </div>
</div>
<?php endif; ?>

<script>
function toggleModuleHelp(boxId) {
    var box = document.getElementById(boxId);
    if (box) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    }
}

function togglePushFields() {
    var provider = document.getElementById('pushProviderSelect').value;
    var fcmSection = document.getElementById('fcmConfigSection');
    if (fcmSection) {
        fcmSection.style.display = (provider === 'fcm') ? 'block' : 'none';
    }
}

function sendTestPush() {
    var select = document.getElementById('testTargetSelect');
    var target = select.value;
    var resultBox = document.getElementById('testResultBox');
    var btn = document.getElementById('btnTestPush');

    if (!target) {
        alert('Lütfen test bildirimi gönderilecek bir dahili/cihaz seçin.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
    resultBox.style.display = 'none';

    var formData = new FormData();
    formData.append('csrf_token', '<?php echo getCSRFToken(); ?>');
    formData.append('target', target);
    formData.append('type', 'extension');

    fetch('/push-settings?action=test_push', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Test Bildirimi Gönder';
        resultBox.style.display = 'block';
        if (data.success) {
            resultBox.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + (data.message || 'Başarılı') + '</div>';
        } else {
            resultBox.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> ' + (data.message || 'Gönderim başarısız') + '</div>';
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Test Bildirimi Gönder';
        resultBox.style.display = 'block';
        resultBox.innerHTML = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> İstek başarısız: ' + err + '</div>';
    });
}
</script>
