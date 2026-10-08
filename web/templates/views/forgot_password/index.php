<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/login.css'); ?>">
    <div class="auth-card" style="max-width: 420px;">
        <div class="u-text-center u-mb-24">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <i class="fas fa-key u-primary"></i>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo t('forgot.title'); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_title); ?> — <?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <?php if ($sent): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; line-height: 1.6;">
                <i class="fas fa-check-circle"></i> <?php echo t('forgot.sent'); ?>
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 13.5px; line-height: 1.6; margin: 0 0 18px;"><?php echo t('forgot.intro'); ?></p>
            <form method="POST" autocomplete="off" action="/forgot-password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label class="form-label" for="identifier"><?php echo t('forgot.field_identifier'); ?></label>
                    <input type="text" id="identifier" name="identifier" class="form-control" required maxlength="120" autofocus autocomplete="username"
                           value="<?php echo htmlspecialchars((string) ($_POST['identifier'] ?? '')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="captcha_answer"><?php echo t('login.security_check'); ?></label>
                    <div class="captcha-row">
                        <strong class="captcha-question"><?php echo (int) $captcha[0]; ?> + <?php echo (int) $captcha[1]; ?> = ?</strong>
                        <input type="number" inputmode="numeric" id="captcha_answer" name="captcha_answer" class="form-control" placeholder="<?php echo t('login.captcha_placeholder'); ?>" required autocomplete="off">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 6px; font-size: 15px;">
                    <i class="fas fa-paper-plane"></i> <?php echo t('forgot.submit'); ?>
                </button>
            </form>
            <p class="u-hint" style="margin-top: 14px; font-size: 12px; line-height: 1.5;"><?php echo t('forgot.no_email_hint'); ?></p>
        <?php endif; ?>
        <a href="/login" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 12px; margin-top: 12px;">
            <i class="fas fa-arrow-left"></i> <?php echo t('reset_password.back_to_login'); ?>
        </a>
        <?php require dirname(__DIR__, 2) . '/auth_support_contact.php'; ?>
    </div>
