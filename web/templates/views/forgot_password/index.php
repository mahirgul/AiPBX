<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/login.css'); ?>">
    <div class="auth-card u-maxw-420">
        <div class="u-text-center u-mb-24">
            <div class="brand-icon u-w-56 u-h-56 u-m-0-auto-16-auto u-fs-24">
                <i class="fas fa-key u-primary"></i>
            </div>
            <h2 class="u-fs-22 u-fw-800"><?php echo t('forgot.title'); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_title); ?> — <?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <?php if ($sent): ?>
            <div class="u-bg-rgba-34-197-94-0-15 u-bd-1-solid-success u-success u-p-16-16 u-r-12 u-fs-13 u-mb-20 u-lh-1-6">
                <i class="fas fa-check-circle"></i> <?php echo t('forgot.sent'); ?>
            </div>
        <?php else: ?>
            <p class="u-muted u-fs-14 u-lh-1-6 u-m-0-0-20"><?php echo t('forgot.intro'); ?></p>
            <form method="POST" autocomplete="off" action="/forgot-password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label class="form-label" for="identifier"><?php echo t('forgot.field_identifier'); ?></label>
                    <input type="text" id="identifier" name="identifier" class="form-control" required maxlength="120" autofocus autocomplete="username"
                           value="<?php echo htmlspecialchars((string) ($_POST['identifier'] ?? '')); ?>">
                </div>
                <?php if (LoginCaptchaService::usesTurnstile()): ?>
                <div class="form-group">
                    <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(LoginCaptchaService::siteKey(), ENT_QUOTES); ?>" data-theme="auto"></div>
                    <script src="<?php echo LoginCaptchaService::SCRIPT_URL; ?>" async defer></script>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label class="form-label" for="captcha_answer"><?php echo t('login.security_check'); ?></label>
                    <div class="captcha-row">
                        <strong class="captcha-question"><?php echo (int) $captcha[0]; ?> + <?php echo (int) $captcha[1]; ?> = ?</strong>
                        <input type="number" inputmode="numeric" id="captcha_answer" name="captcha_answer" class="form-control" placeholder="<?php echo t('login.captcha_placeholder'); ?>" required autocomplete="off">
                    </div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary u-w-100 u-jc-center u-p-14 u-mt-6 u-fs-15">
                    <i class="fas fa-paper-plane"></i> <?php echo t('forgot.submit'); ?>
                </button>
            </form>
            <p class="u-hint u-mt-16 u-fs-12 u-lh-1-5"><?php echo t('forgot.no_email_hint'); ?></p>
        <?php endif; ?>
        <a href="/login" class="btn btn-secondary u-w-100 u-jc-center u-p-12 u-mt-12">
            <i class="fas fa-arrow-left"></i> <?php echo t('reset_password.back_to_login'); ?>
        </a>
        <?php require dirname(__DIR__, 2) . '/auth_support_contact.php'; ?>
    </div>
