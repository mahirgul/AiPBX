<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/login.css'); ?>">
<?php
// Phones get two cards (logo + Google Play, sign-in form), side by side in landscape — pages/login.css.
$is_mobile_device = isMobileUserAgent();
ob_start();
require __DIR__ . '/_brand.php';
$brand_html = ob_get_clean();
?>
    <div class="auth-cards<?php echo $is_mobile_device ? ' is-mobile' : ''; ?>">
    <?php if ($is_mobile_device): ?>
    <!-- Phones: logo + Google Play in their own card (side by side with the form in landscape) -->
    <div class="auth-card brand-card">
<?php echo $brand_html; ?>
        <a href="<?php echo htmlspecialchars(ANDROID_PLAY_URL); ?>" target="_blank" rel="noopener" class="btn btn-play" title="<?php echo htmlspecialchars(t('login.mobile_app_desc')); ?>">
            <i class="fab fa-google-play"></i> <?php echo t('common.get_on_google_play'); ?>
        </a>
        <button type="button" class="btn btn-outline-primary u-w-100 u-jc-center u-fw-700 u-gap-8 u-r-12" id="btnPasskeyLogin" onclick="loginWithPasskey()">
            <i class="fas fa-fingerprint u-fs-16"></i> <?php echo t('login.btn_passkey'); ?>
        </button>
        <?php $lang_switch_class = 'inline'; require dirname(__DIR__, 2) . '/auth_lang_switch.php'; ?>
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
                    <i class="fas fa-user u-pos-absolute u-left-16 u-top-15 u-muted"></i>
                    <input type="text" name="username" class="form-control u-pl-44" required autofocus autocomplete="off">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_password'); ?></label>
                <div class="u-relative">
                    <i class="fas fa-lock u-pos-absolute u-left-16 u-top-15 u-muted"></i>
                    <input type="password" name="password" class="form-control u-pl-44" required>
                </div>
                <div class="u-ta-right u-mt-6">
                    <a href="/forgot-password" class="u-fs-12 u-primary u-text-decoration-none"><?php echo t('forgot.link'); ?></a>
                </div>
            </div>

            <!-- Dynamic Math Security Challenge -->
            <?php if (LoginCaptchaService::usesTurnstile()): ?>
            <div class="form-group">
                <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(LoginCaptchaService::siteKey(), ENT_QUOTES); ?>" data-theme="auto"></div>
                <script src="<?php echo LoginCaptchaService::SCRIPT_URL; ?>" async defer></script>
            </div>
            <?php else: ?>
            <div class="form-group">
                <label class="form-label" for="captcha_answer"><?php echo t('login.security_check'); ?></label>
                <div class="captcha-row">
                    <strong class="captcha-question"><?php echo $num1; ?> + <?php echo $num2; ?> = ?</strong>
                    <input type="number" inputmode="numeric" id="captcha_answer" name="captcha_answer" class="form-control" placeholder="<?php echo t('login.captcha_placeholder'); ?>" required autocomplete="off">
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary u-w-100 u-jc-center u-p-14 u-mt-10 u-fs-15" title="<?php echo t('login.submit_tooltip'); ?>">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('login.submit_tooltip'); ?>
            </button>
        </form>

<?php if (!$is_mobile_device): ?>
        <div class="u-d-flex u-ai-center u-m-16-0-12-0 u-gap-12">
            <div class="u-flex-1 u-h-1 u-bg-border-color"></div>
            <span class="u-fs-11 u-muted u-uppercase u-fw-700"><?php echo t('login.or_divider'); ?></span>
            <div class="u-flex-1 u-h-1 u-bg-border-color"></div>
        </div>

        <button type="button" class="btn btn-outline-primary u-w-100 u-jc-center u-fw-700 u-gap-8 u-r-12" id="btnPasskeyLogin" onclick="loginWithPasskey()">
            <i class="fas fa-fingerprint u-fs-16"></i> <?php echo t('login.btn_passkey'); ?>
        </button>
<?php elseif (!empty($googleLoginEnabled) || !empty($oidcLoginEnabled) || !empty($samlLoginEnabled) || (class_exists('GoogleAuthService') && GoogleAuthService::isEnabled()) || (class_exists('OidcAuthService') && OidcAuthService::isEnabled()) || (class_exists('SamlAuthService') && SamlAuthService::isEnabled())): ?>
        <div class="u-d-flex u-ai-center u-m-16-0-12-0 u-gap-12">
            <div class="u-flex-1 u-h-1 u-bg-border-color"></div>
            <span class="u-fs-11 u-muted u-uppercase u-fw-700"><?php echo t('login.or_divider'); ?></span>
            <div class="u-flex-1 u-h-1 u-bg-border-color"></div>
        </div>
<?php endif; ?>

        <?php if (!empty($googleLoginEnabled) || (class_exists('GoogleAuthService') && GoogleAuthService::isEnabled())): ?>
        <a href="/auth/google" class="btn u-w-100 u-jc-center u-fw-700 u-gap-12 u-r-12 u-mt-12 u-bg-ffffff u-c-3c4043 u-bd-1-solid-dadce0 u-box-shadow-0-1-2-rgba-60-64-67-0-1 u-text-decoration-none u-d-inline-flex u-ai-center u-transition-all-0-2s">
            <svg width="18" height="18" viewBox="0 0 18 18">
                <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z"/>
                <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
                <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z"/>
                <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
            </svg>
            <span><?php echo t('login.btn_google'); ?></span>
        </a>
        <?php endif; ?>

        <?php if (!empty($oidcLoginEnabled) || (class_exists('OidcAuthService') && OidcAuthService::isEnabled())): ?>
            <?php
            $oidcMeta = !empty($oidcPublicSettings) ? $oidcPublicSettings : (class_exists('OidcAuthService') ? OidcAuthService::getPublicSettings() : ['provider_name' => 'Corporate SSO', 'icon' => 'fas fa-shield-alt']);
            $oidcLabel = htmlspecialchars((string)($oidcMeta['provider_name'] ?? 'Corporate SSO'));
            $oidcIcon = htmlspecialchars((string)($oidcMeta['icon'] ?? 'fas fa-shield-alt'));
            ?>
        <a href="/auth/oidc" class="btn btn-outline-primary u-w-100 u-jc-center u-fw-700 u-gap-12 u-r-12 u-mt-12 u-text-decoration-none u-d-inline-flex u-ai-center u-transition-all-0-2s">
            <i class="<?php echo $oidcIcon; ?> u-fs-16"></i>
            <span><?php echo $oidcLabel; ?></span>
        </a>
        <?php endif; ?>

        <?php if (!empty($samlLoginEnabled) || (class_exists('SamlAuthService') && SamlAuthService::isEnabled())): ?>
            <?php
            $samlMeta = !empty($samlPublicSettings) ? $samlPublicSettings : (class_exists('SamlAuthService') ? SamlAuthService::getPublicSettings() : ['provider_name' => 'Single Sign-On (SAML)', 'icon' => 'fas fa-shield-alt']);
            $samlLabel = htmlspecialchars((string)($samlMeta['provider_name'] ?? 'Single Sign-On (SAML)'));
            $samlIcon = htmlspecialchars((string)($samlMeta['icon'] ?? 'fas fa-shield-alt'));
            ?>
        <a href="/auth/saml" class="btn btn-outline-primary u-w-100 u-jc-center u-fw-700 u-gap-12 u-r-12 u-mt-12 u-text-decoration-none u-d-inline-flex u-ai-center u-transition-all-0-2s">
            <i class="<?php echo $samlIcon; ?> u-fs-16"></i>
            <span><?php echo $samlLabel; ?></span>
        </a>
        <?php endif; ?>

        <?php if (!$is_mobile_device): ?>
            <?php $lang_switch_class = 'inline'; require dirname(__DIR__, 2) . '/auth_lang_switch.php'; ?>
        <?php endif; ?>
        <?php require dirname(__DIR__, 2) . '/auth_support_contact.php'; ?>
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
