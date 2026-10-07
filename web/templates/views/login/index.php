<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/login.css'); ?>">
<?php
// Phones get two cards (logo + Google Play, sign-in form), side by side in landscape — pages/login.css.
$is_mobile_device = isMobileUserAgent();
ob_start(); ?>
        <div class="u-text-center u-mb-24">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <?php if ($site_logo_type === 'image' && !empty($site_logo_image)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo_image); ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                <?php else: ?>
                    <i class="fas <?php echo htmlspecialchars($site_logo_icon); ?> u-primary"></i>
                <?php endif; ?>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

<?php $brand_html = ob_get_clean(); ?>
    <div class="auth-cards<?php echo $is_mobile_device ? ' is-mobile' : ''; ?>">
    <?php if ($is_mobile_device): ?>
    <!-- Phones: logo + Google Play in their own card (side by side with the form in landscape) -->
    <div class="auth-card brand-card">
        <?php $lang_switch_class = 'inline'; require dirname(__DIR__, 2) . '/auth_lang_switch.php'; ?>
<?php echo $brand_html; ?>
        <a href="<?php echo htmlspecialchars(ANDROID_PLAY_URL); ?>" target="_blank" rel="noopener" class="btn btn-play" title="<?php echo htmlspecialchars(t('login.mobile_app_desc')); ?>">
            <i class="fab fa-google-play"></i> <?php echo t('common.get_on_google_play'); ?>
        </a>
        <button type="button" class="btn btn-outline-primary" id="btnPasskeyLogin" onclick="loginWithPasskey()" style="width: 100%; justify-content: center; padding: 11px 14px; font-size: 13.5px; font-weight: 700; gap: 8px; border-radius: 10px;">
            <i class="fas fa-fingerprint u-fs-16"></i> <?php echo t('login.btn_passkey', 'Passkey ile Giriş Yap'); ?>
        </button>
    </div>
    <?php endif; ?>
    <div class="auth-card login-card">
        <?php if (!$is_mobile_device) echo $brand_html; ?>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_username'); ?></label>
                <div class="u-relative">
                    <i class="fas fa-user" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="text" name="username" class="form-control" style="padding-left: 44px;" required autofocus autocomplete="off">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_password'); ?></label>
                <div class="u-relative">
                    <i class="fas fa-lock" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" name="password" class="form-control" style="padding-left: 44px;" required>
                </div>
            </div>

            <!-- Dynamic Math Security Challenge -->
            <div class="form-group" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 14px; border-radius: 12px;">
                <label class="form-label" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span><?php echo t('login.security_check'); ?></span>
                    <strong class="u-primary u-fs-16"><?php echo $num1; ?> + <?php echo $num2; ?> = ?</strong>
                </label>
                <input type="number" name="captcha_answer" class="form-control" placeholder="<?php echo t('login.captcha_placeholder'); ?>" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 10px; font-size: 15px;" title="<?php echo t('login.submit_tooltip'); ?>">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('login.submit_tooltip'); ?>
            </button>
        </form>

<?php if (!$is_mobile_device): ?>
        <div style="display: flex; align-items: center; margin: 16px 0 12px 0; gap: 10px;">
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
            <span class="u-fs-11 u-muted u-uppercase u-fw-700"><?php echo t('login.or_divider', 'VEYA'); ?></span>
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
        </div>

        <button type="button" class="btn btn-outline-primary" id="btnPasskeyLogin" onclick="loginWithPasskey()" style="width: 100%; justify-content: center; padding: 11px 14px; font-size: 13.5px; font-weight: 700; gap: 8px; border-radius: 10px;">
            <i class="fas fa-fingerprint u-fs-16"></i> <?php echo t('login.btn_passkey', 'Passkey ile Giriş Yap'); ?>
        </button>
<?php elseif (!empty($googleLoginEnabled) || (class_exists('GoogleAuthService') && GoogleAuthService::isEnabled())): ?>
        <div style="display: flex; align-items: center; margin: 16px 0 12px 0; gap: 10px;">
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
            <span class="u-fs-11 u-muted u-uppercase u-fw-700"><?php echo t('login.or_divider', 'VEYA'); ?></span>
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
        </div>
<?php endif; ?>

        <?php if (!empty($googleLoginEnabled) || (class_exists('GoogleAuthService') && GoogleAuthService::isEnabled())): ?>
        <a href="/auth/google" class="btn" style="width: 100%; justify-content: center; padding: 11px 14px; font-size: 13.5px; font-weight: 700; gap: 10px; border-radius: 10px; margin-top: 10px; background: #ffffff; color: #3c4043; border: 1px solid #dadce0; box-shadow: 0 1px 2px rgba(60,64,67,0.1); text-decoration: none; display: inline-flex; align-items: center; transition: all 0.2s;">
            <svg width="18" height="18" viewBox="0 0 18 18">
                <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z"/>
                <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
                <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z"/>
                <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
            </svg>
            <span><?php echo t('login.btn_google', 'Google ile Giriş Yap'); ?></span>
        </a>
        <?php endif; ?>

    </div>

    <?php if (!$is_mobile_device): ?>
    <div class="auth-card mobile-app-download">
        <a href="<?php echo htmlspecialchars(ANDROID_PLAY_URL); ?>" target="_blank" rel="noopener" class="btn btn-play" title="<?php echo htmlspecialchars(t('login.mobile_app_desc')); ?>">
            <i class="fab fa-google-play"></i> <?php echo t('common.get_on_google_play'); ?>
        </a>
    </div>
    <?php endif; ?>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>

<script src="<?php echo asset('/assets/js/login.js'); ?>"></script>

    <?php if ($error): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.notify) {
                window.notify.error(<?php echo json_encode($error); ?>);
            }
        });
    </script>
    <?php endif; ?>
