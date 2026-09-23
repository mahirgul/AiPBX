<div class="content-wrapper">
    <!-- Başlık & Bilgi Kartı -->
    <div class="card page-header-card">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div>
                <h2 style="font-size: 20px; font-weight: 800; margin: 0 0 6px 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-shield-alt" style="color: var(--primary);"></i>
                    <?php echo t('security.page_title', 'Hesap Güvenliği'); ?>
                </h2>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0;">
                    <?php echo t('security.page_subtitle', 'İki faktörlü kimlik doğrulama (2FA), biyometrik Passkey anahtarları ve şifre yönetimi.'); ?>
                </p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <span class="badge" style="padding: 8px 14px; font-size: 12.5px; border-radius: 20px; font-weight: 700; background: <?php echo $twoFactorEnabled ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>; color: <?php echo $twoFactorEnabled ? 'var(--success)' : 'var(--danger)'; ?>; border: 1px solid <?php echo $twoFactorEnabled ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <i class="fas <?php echo $twoFactorEnabled ? 'fa-lock' : 'fa-unlock-alt'; ?>"></i>
                    2FA: <?php echo $twoFactorEnabled ? t('security.status_enabled', 'Aktif') : t('security.status_disabled', 'Devre Dışı'); ?>
                </span>
                <span class="badge" style="padding: 8px 14px; font-size: 12.5px; border-radius: 20px; font-weight: 700; background: <?php echo !empty($passkeys) ? 'rgba(2, 132, 199, 0.15)' : 'rgba(148, 163, 184, 0.15)'; ?>; color: <?php echo !empty($passkeys) ? 'var(--primary)' : 'var(--text-muted)'; ?>; border: 1px solid <?php echo !empty($passkeys) ? 'var(--primary)' : 'var(--border-color)'; ?>;">
                    <i class="fas fa-fingerprint"></i>
                    Passkey: <?php echo count($passkeys); ?> <?php echo t('security.unit_registered', 'kayıtlı'); ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($newRecoveryCodes)): ?>
        <!-- Kurtarma Kodları Gösterim Alanı (Yalnızca yeni üretildiğinde basılır) -->
        <div class="card mb-4" style="background: rgba(34, 197, 94, 0.08); border: 2px solid var(--success); border-radius: 12px; padding: 22px;">
            <div style="display: flex; align-items: flex-start; gap: 14px;">
                <div style="font-size: 28px; color: var(--success);"><i class="fas fa-key"></i></div>
                <div style="flex: 1;">
                    <h3 style="font-size: 16px; font-weight: 800; color: var(--success); margin: 0 0 6px 0;">
                        <?php echo t('security.recovery_codes_title', 'Yedek Kurtarma Kodlarınız'); ?>
                    </h3>
                    <p style="font-size: 13px; color: var(--text-color); margin: 0 0 14px 0; line-height: 1.5;">
                        <?php echo t('security.recovery_codes_desc', 'Telefonunuzu kaybetmeniz veya Authenticator uygulamanıza erişememeniz durumunda bu kodları kullanarak sisteme giriş yapabilirsiniz. <strong>Her kod yalnızca bir kez kullanılabilir</strong>. Lütfen bu kodları hemen kopyalayıp güvenli bir yerde saklayın!'); ?>
                    </p>

                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin-bottom: 16px;">
                        <?php foreach ($newRecoveryCodes as $idx => $code): ?>
                            <div style="background: var(--bg-card); border: 1px dashed var(--success); padding: 8px 12px; border-radius: 8px; text-align: center; font-family: monospace; font-size: 15px; font-weight: 700; letter-spacing: 1px;">
                                <?php echo htmlspecialchars($code); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyRecoveryCodes()">
                            <i class="fas fa-copy"></i> <?php echo t('security.btn_copy_codes', 'Kodları Panoya Kopyala'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="downloadRecoveryCodes()">
                            <i class="fas fa-download"></i> <?php echo t('security.btn_download_codes', 'Dosya Olarak İndir (.txt)'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- SOL SÜTUN: 2FA TOTP AUTHENTICATOR -->
        <div class="card" style="padding: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                <h3 style="font-size: 16px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-mobile-alt" style="color: var(--primary);"></i>
                    <?php echo t('security.2fa_card_title', 'İki Faktörlü Doğrulama (TOTP)'); ?>
                </h3>
                <?php if ($twoFactorEnabled): ?>
                    <span class="badge" style="background: rgba(34, 197, 94, 0.15); color: var(--success); font-weight: 700;">
                        <i class="fas fa-check-circle"></i> <?php echo t('security.status_active', 'Bağlı'); ?>
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted); font-weight: 700;">
                        <?php echo t('security.status_inactive', 'Bağlı Değil'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($twoFactorEnabled): ?>
                <!-- 2FA Aktif Durumu -->
                <div style="text-align: center; padding: 20px 0;">
                    <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(34, 197, 94, 0.15); color: var(--success); display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 14px;">
                        <i class="fas fa-shield-check"></i>
                    </div>
                    <h4 style="font-size: 15px; font-weight: 700; margin: 0 0 6px 0;"><?php echo t('security.2fa_active_heading', 'Hesabınız 2FA ile Güvende'); ?></h4>
                    <p style="color: var(--text-muted); font-size: 13px; max-width: 380px; margin: 0 auto 20px auto; line-height: 1.5;">
                        <?php echo t('security.2fa_active_info', 'Giriş yaparken Google Authenticator veya Microsoft Authenticator tarafından üretilen 6 haneli kod istenmektedir.'); ?>
                    </p>

                    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openRegenCodesModal()">
                            <i class="fas fa-sync-alt"></i> <?php echo t('security.btn_regen_codes', 'Yeni Kurtarma Kodları Üret'); ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="openDisable2faModal()">
                            <i class="fas fa-trash-alt"></i> <?php echo t('security.btn_disable_2fa', '2FA\'yı Devre Dışı Bırak'); ?>
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- 2FA Kurulum Alanı -->
                <div>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 18px; line-height: 1.5;">
                        <?php echo t('security.2fa_setup_intro', 'Google Authenticator, Microsoft Authenticator veya 1Password gibi bir uygulama kullanarak QR kodu tarayın.'); ?>
                    </p>

                    <div style="text-align: center; margin-bottom: 18px; background: #ffffff; padding: 16px; border-radius: 12px; border: 1px solid var(--border-color); display: inline-block; width: 100%;">
                        <img src="<?php echo $qrCodeDataUri; ?>" alt="2FA QR Code" style="width: 180px; height: 180px; display: block; margin: 0 auto;">
                        <div style="margin-top: 10px;">
                            <span style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 4px;"><?php echo t('security.manual_key_label', 'QR kodu tarayamıyorsanız gizli anahtar:'); ?></span>
                            <code id="manual_secret_key" style="background: var(--bg-card); padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 700; letter-spacing: 1px; color: var(--primary); word-break: break-all;"><?php echo htmlspecialchars($setupSecret); ?></code>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="copySecretKey()" style="margin-left: 6px;" title="Kopyala">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <form method="POST" action="/security" style="margin-top: 14px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="enable_2fa">
                        <input type="hidden" name="secret" value="<?php echo htmlspecialchars($setupSecret); ?>">

                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 13px;">
                                <?php echo t('security.verify_code_label', 'Uygulamadaki 6 Haneli Doğrulama Kodunu Girin:'); ?>
                            </label>
                            <input type="text" name="verify_code" class="form-control" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="Örn: 123456" required style="font-size: 18px; font-weight: 800; letter-spacing: 6px; text-align: center;">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-weight: 700;">
                            <i class="fas fa-check-circle"></i> <?php echo t('security.btn_activate_2fa', '2FA\'yı Doğrula ve Etkinleştir'); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- SAĞ SÜTUN: PASSKEY (FIDO2 / WEBAUTHN) -->
        <div class="card" style="padding: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                <h3 style="font-size: 16px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-fingerprint" style="color: var(--primary);"></i>
                    <?php echo t('security.passkey_card_title', 'Passkey (Biyometrik / Güvenlik Anahtarı)'); ?>
                </h3>
                <button type="button" class="btn btn-primary btn-sm" onclick="registerNewPasskey()">
                    <i class="fas fa-plus"></i> <?php echo t('security.btn_add_passkey', 'Yeni Passkey Ekle'); ?>
                </button>
            </div>

            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 18px; line-height: 1.5;">
                <?php echo t('security.passkey_intro', 'Cihazınızdaki parmak izi (Touch ID), yüz tanıma (Face ID / Windows Hello) veya harici bir donanım anahtarı (YubiKey) ile şifresiz oturum açabilirsiniz.'); ?>
            </p>

            <?php if (empty($passkeys)): ?>
                <div style="text-align: center; padding: 30px 20px; background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: 12px;">
                    <div style="font-size: 32px; color: var(--text-muted); margin-bottom: 10px;">
                        <i class="fas fa-key"></i>
                    </div>
                    <div style="font-size: 14px; font-weight: 700; color: var(--text-color); margin-bottom: 4px;">
                        <?php echo t('security.no_passkeys_title', 'Henüz Kayıtlı Passkey Yok'); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">
                        <?php echo t('security.no_passkeys_desc', 'Girişlerinizi hızlandırmak için bu cihazı veya güvenlik anahtarınızı kaydedin.'); ?>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="registerNewPasskey()">
                        <i class="fas fa-fingerprint"></i> <?php echo t('security.btn_add_first_passkey', 'Bu Cihazı Passkey Olarak Ekle'); ?>
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" style="margin: 0;">
                        <thead>
                            <tr>
                                <th><?php echo t('security.col_device', 'Cihaz Adı'); ?></th>
                                <th><?php echo t('security.col_created', 'Kayıt Tarihi'); ?></th>
                                <th><?php echo t('security.col_last_used', 'Son Kullanım'); ?></th>
                                <th style="text-align: right;"><?php echo t('common.action', 'İşlem'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($passkeys as $pk): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-fingerprint" style="color: var(--primary);"></i>
                                            <strong><?php echo htmlspecialchars($pk['device_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td style="font-size: 12.5px; color: var(--text-muted);">
                                        <?php echo date('d.m.Y H:i', strtotime($pk['created_at'])); ?>
                                    </td>
                                    <td style="font-size: 12.5px; color: var(--text-muted);">
                                        <?php echo !empty($pk['last_used_at']) ? date('d.m.Y H:i', strtotime($pk['last_used_at'])) : '—'; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn btn-outline-danger btn-xs" onclick="deletePasskey(<?php echo (int)$pk['id']; ?>, '<?php echo htmlspecialchars(addslashes($pk['device_name'])); ?>')" title="<?php echo t('common.delete', 'Sil'); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Şifre Değiştirme Kartı (Sağ alt alan) -->
            <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-color);">
                <h4 style="font-size: 15px; font-weight: 800; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-lock" style="color: var(--text-muted);"></i>
                    <?php echo t('security.change_password_title', 'Giriş Şifresini Değiştir'); ?>
                </h4>

                <form method="POST" action="/security">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label" style="font-size: 12px;"><?php echo t('security.field_current_password', 'Mevcut Şifre'); ?></label>
                        <input type="password" name="current_password" class="form-control" required style="height: 38px;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-size: 12px;"><?php echo t('security.field_new_password', 'Yeni Şifre (min 8)'); ?></label>
                            <input type="password" name="new_password" class="form-control" minlength="8" required style="height: 38px;">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-size: 12px;"><?php echo t('security.field_confirm_password', 'Yeni Şifre Tekrar'); ?></label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" required style="height: 38px;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm" style="font-weight: 700;">
                        <i class="fas fa-save"></i> <?php echo t('security.btn_save_password', 'Şifreyi Güncelle'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 2FA Devre Dışı Bırakma Modalı -->
<div class="modal-backdrop" id="modalDisable2fa" style="display: none;">
    <div class="modal-card" style="max-width: 400px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> <?php echo t('security.modal_disable_2fa_title', '2FA Devre Dışı Bırak'); ?></h3>
            <button type="button" class="btn-close" onclick="closeDisable2faModal()">&times;</button>
        </div>
        <form method="POST" action="/security">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="disable_2fa">
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                    <?php echo t('security.disable_2fa_confirm_desc', 'İki faktörlü doğrulamayı kapatmak hesabınızın güvenliğini azaltacaktır. Onaylamak için mevcut şifrenizi girin:'); ?>
                </p>
                <div class="form-group">
                    <label class="form-label"><?php echo t('security.field_current_password', 'Mevcut Şifre'); ?></label>
                    <input type="password" name="current_password" class="form-control" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDisable2faModal()"><?php echo t('common.cancel', 'İptal'); ?></button>
                <button type="submit" class="btn btn-danger"><?php echo t('security.btn_confirm_disable', 'Evet, Devre Dışı Bırak'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Kurtarma Kodlarını Yenileme Modalı -->
<div class="modal-backdrop" id="modalRegenCodes" style="display: none;">
    <div class="modal-card" style="max-width: 400px;">
        <div class="modal-header">
            <h3><i class="fas fa-sync-alt" style="color: var(--primary);"></i> <?php echo t('security.modal_regen_codes_title', 'Yeni Kurtarma Kodları'); ?></h3>
            <button type="button" class="btn-close" onclick="closeRegenCodesModal()">&times;</button>
        </div>
        <form method="POST" action="/security">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="regen_recovery_codes">
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                    <?php echo t('security.regen_codes_desc', 'Yeni kurtarma kodları üretildiğinde mevcut eski kodlarınız geçersiz kalacaktır. Onaylamak için şifrenizi girin:'); ?>
                </p>
                <div class="form-group">
                    <label class="form-label"><?php echo t('security.field_current_password', 'Mevcut Şifre'); ?></label>
                    <input type="password" name="current_password" class="form-control" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRegenCodesModal()"><?php echo t('common.cancel', 'İptal'); ?></button>
                <button type="submit" class="btn btn-primary"><?php echo t('security.btn_confirm_regen', 'Yeni Kodları Üret'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    const CSRF_TOKEN = "<?php echo htmlspecialchars($csrf_token); ?>";
    const RECOVERY_CODES = <?php echo json_encode($newRecoveryCodes ?? []); ?>;

    function copySecretKey() {
        const text = document.getElementById('manual_secret_key').innerText.trim();
        navigator.clipboard.writeText(text).then(() => {
            if (window.notify) window.notify.success("<?php echo addslashes(t('security.secret_copied', 'Gizli anahtar panoya kopyalandı.')); ?>");
        });
    }

    function copyRecoveryCodes() {
        if (!RECOVERY_CODES || !RECOVERY_CODES.length) return;
        const text = "AiPBX Yedek Kurtarma Kodları:\n" + RECOVERY_CODES.join("\n");
        navigator.clipboard.writeText(text).then(() => {
            if (window.notify) window.notify.success("<?php echo addslashes(t('security.codes_copied', 'Kurtarma kodları panoya kopyalandı.')); ?>");
        });
    }

    function downloadRecoveryCodes() {
        if (!RECOVERY_CODES || !RECOVERY_CODES.length) return;
        const content = "AiPBX Yedek Kurtarma Kodları (" + new Date().toLocaleString() + ")\n"
                      + "====================================================\n"
                      + "Her kod yalnızca BİR KEZ kullanılabilir:\n\n"
                      + RECOVERY_CODES.map((c, i) => (i + 1) + ". " + c).join("\n") + "\n";
        const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = "aipbx-recovery-codes.txt";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function openDisable2faModal() {
        document.getElementById('modalDisable2fa').style.display = 'flex';
    }
    function closeDisable2faModal() {
        document.getElementById('modalDisable2fa').style.display = 'none';
    }

    function openRegenCodesModal() {
        document.getElementById('modalRegenCodes').style.display = 'flex';
    }
    function closeRegenCodesModal() {
        document.getElementById('modalRegenCodes').style.display = 'none';
    }

    // --- WEBAUTHN PASSKEY KAYDI ---
    function base64urlToUint8Array(base64url) {
        if (!base64url) return new Uint8Array(0);
        if (base64url instanceof Uint8Array) return base64url;
        if (base64url instanceof ArrayBuffer) return new Uint8Array(base64url);
        let str = String(base64url).trim();
        if (str.startsWith('=?BINARY?B?') && str.endsWith('?=')) {
            str = str.substring(11, str.length - 2);
        }
        let base64 = str.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) {
            base64 += '=';
        }
        const raw = window.atob(base64);
        const bytes = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) {
            bytes[i] = raw.charCodeAt(i);
        }
        return bytes;
    }

    function arrayBufferToBase64(buffer) {
        if (!buffer) return '';
        if (typeof buffer === 'string') return buffer;
        let binary = '';
        const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    async function registerNewPasskey() {
        if (!window.PublicKeyCredential) {
            alert("<?php echo addslashes(t('security.webauthn_not_supported', 'Tarayıcınız veya cihazınız WebAuthn (Passkey) standardını desteklemiyor.')); ?>");
            return;
        }

        const deviceName = prompt(
            "<?php echo addslashes(t('security.prompt_device_name', 'Bu Passkey için bir cihaz adı girin:')); ?>",
            "Passkey (" + (navigator.platform || 'Cihaz') + ")"
        );
        if (deviceName === null) return; // İptal edildi

        try {
            // 1. Sunucudan create seçeneklerini al
            const optRes = await fetch('/api/passkey.php?action=register-options');
            const optData = await optRes.json();
            if (!optData.success) {
                alert("Hata: " + (optData.error || "Seçenekler alınamadı"));
                return;
            }

            const makeArgs = optData.options;
            makeArgs.challenge = base64urlToUint8Array(makeArgs.challenge);
            makeArgs.user.id = base64urlToUint8Array(makeArgs.user.id);

            if (makeArgs.excludeCredentials && Array.isArray(makeArgs.excludeCredentials)) {
                makeArgs.excludeCredentials.forEach(c => {
                    c.id = base64urlToUint8Array(c.id);
                });
            }

            // IP adresi durumunda rp.id W3C standardı gereği alan adı sayılmaz;
            // tarayıcının hata vermemesi için rp.id silinerek origin varsayılan alınır.
            if (makeArgs.rp && makeArgs.rp.id && /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/.test(makeArgs.rp.id)) {
                delete makeArgs.rp.id;
            }

            // 2. Tarayıcıda biyometrik / güvenlik anahtarı oluştur
            const credential = await navigator.credentials.create({ publicKey: makeArgs });
            if (!credential) {
                alert("Passkey oluşturulamadı.");
                return;
            }

            // 3. Sunucuya doğrulamaya gönder
            const payload = {
                action: 'register-verify',
                deviceName: deviceName.trim() || 'Passkey',
                id: credential.id || (credential.rawId ? arrayBufferToBase64(credential.rawId) : ''),
                clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                attestationObject: arrayBufferToBase64(credential.response.attestationObject),
            };

            const verifyRes = await fetch('/api/passkey.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const verifyData = await verifyRes.json();

            if (verifyData.success) {
                if (window.notify) window.notify.success("<?php echo addslashes(t('security.passkey_added_success', 'Passkey başarıyla kaydedildi!')); ?>");
                setTimeout(() => window.location.reload(), 800);
            } else {
                alert("Hata: " + (verifyData.error || "Passkey doğrulanamadı."));
            }
        } catch (err) {
            console.error(err);
            alert("Passkey işlemi iptal edildi veya bir hata oluştu: " + err.message);
        }
    }

    async function deletePasskey(passkeyId, deviceName) {
        if (!confirm("'" + deviceName + "' <?php echo addslashes(t('security.confirm_delete_passkey', 'adlı Passkey silinecektir. Emin misiniz?')); ?>")) {
            return;
        }

        try {
            const res = await fetch('/api/passkey.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    passkey_id: passkeyId,
                    csrf_token: CSRF_TOKEN
                })
            });
            const data = await res.json();
            if (data.success) {
                if (window.notify) window.notify.success("<?php echo addslashes(t('security.passkey_deleted_success', 'Passkey silindi.')); ?>");
                setTimeout(() => window.location.reload(), 800);
            } else {
                alert("Hata: " + (data.error || "Passkey silinemedi."));
            }
        } catch (err) {
            console.error(err);
            alert("Bir hata oluştu: " + err.message);
        }
    }
</script>
