<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card" style="max-width: 420px;">
        <div class="u-text-center u-mb-24">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px; background: rgba(2, 132, 199, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-shield-alt u-primary u-fs-26"></i>
            </div>
            <h2 class="u-fs-20 u-fw-800"><?php echo t('login_2fa.title', 'İki Faktörlü Doğrulama'); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6">
                <?php echo t('login_2fa.account_label', 'Hesap:'); ?> <strong><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></strong>
            </p>
        </div>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <form method="POST" action="/login-2fa" id="twoFactorForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="auth_mode" id="auth_mode" value="totp">

            <!-- TOTP 6-Digit Section -->
            <div id="section_totp">
                <div class="form-group u-text-center">
                    <label class="form-label" style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">
                        <?php echo t('login_2fa.code_prompt', 'Authenticator uygulamanızdaki 6 haneli kodu girin:'); ?>
                    </label>
                    <input type="text" name="totp_code" id="totp_code" class="form-control"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000"
                           style="font-size: 26px; font-weight: 800; letter-spacing: 10px; text-align: center; height: 54px; border-radius: 12px;"
                           autofocus>
                </div>
            </div>

            <!-- Recovery Code Section (Toggled) -->
            <div id="section_recovery" style="display: none;">
                <div class="form-group">
                    <label class="form-label u-fs-13 u-muted u-mb-8">
                        <?php echo t('login_2fa.recovery_prompt', '8 karakterli yedek kurtarma kodunuzu girin:'); ?>
                    </label>
                    <input type="text" name="recovery_code" id="recovery_code" class="form-control"
                           placeholder="Örn: 8F3K-9M2Q"
                           style="font-size: 18px; font-weight: 700; letter-spacing: 2px; text-align: center; text-transform: uppercase; height: 50px; border-radius: 12px;">
                    <small style="display: block; color: var(--text-muted); margin-top: 6px; font-size: 11px;">
                        <?php echo t('login_2fa.recovery_help', 'Her kurtarma kodu tek kullanımlıktır ve kullanıldıktan sonra geçersiz kalır.'); ?>
                    </small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 14px; font-size: 15px; font-weight: 700;">
                <i class="fas fa-check-circle u-mr-6"></i> <?php echo t('login_2fa.btn_verify', 'Doğrula ve Giriş Yap'); ?>
            </button>
        </form>

        <div style="margin-top: 20px; text-align: center; display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
            <button type="button" id="toggleModeBtn" onclick="toggleAuthMode()" style="background: none; border: none; color: var(--primary); cursor: pointer; text-decoration: underline; font-size: 12.5px;">
                <i class="fas fa-key"></i> <?php echo t('login_2fa.use_recovery_code', 'Cihazınıza erişemiyor musunuz? Kurtarma kodu kullanın'); ?>
            </button>

            <form method="POST" action="/login-2fa" class="u-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 12px;">
                    <i class="fas fa-arrow-left"></i> <?php echo t('login_2fa.btn_cancel', 'Farklı bir hesapla giriş yap'); ?>
                </button>
            </form>
        </div>
    </div>

    <script>
        window.LOGIN_2FA_TEXT = {
            use_totp: <?php echo json_encode(t('login_2fa.use_totp_code', 'Authenticator koduna geri dön')); ?>,
            use_recovery: <?php echo json_encode(t('login_2fa.use_recovery_code', 'Cihazınıza erişemiyor musunuz? Kurtarma kodu kullanın')); ?>
        };
    </script>
<script src="<?php echo asset('/assets/js/login_two_factor.js'); ?>"></script>
