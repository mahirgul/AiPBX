<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card" style="max-width: 460px; text-align: center;">
        <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
            <i class="fas fa-shield-halved u-primary"></i>
        </div>
        <h2 class="u-fs-20 u-fw-800"><?php echo t('force_reset.heading'); ?></h2>
        <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_title); ?> — <?php echo htmlspecialchars($brand_sub); ?></p>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <?php if ($sent): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 16px; border-radius: 10px; font-size: 13px; margin: 20px 0; text-align: left;">
                <i class="fas fa-check-circle"></i> <?php echo t('force_reset.sent_prefix'); ?> <strong><?php echo htmlspecialchars($maskedEmail); ?></strong> <?php echo t('force_reset.sent_suffix'); ?>
            </div>
            <a href="/login" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 12px;">
                <i class="fas fa-arrow-left"></i> <?php echo t('force_reset.back_to_login'); ?>
            </a>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 16px 0 24px;">
                <?php echo t('force_reset.intro_prefix'); ?> (<?php echo $maskedEmail ? '<strong>' . htmlspecialchars($maskedEmail) . '</strong>' : t('force_reset.intro_no_email'); ?>) <?php echo t('force_reset.intro_suffix'); ?>
            </p>
            <form method="POST" autocomplete="off" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; font-size: 15px;" <?php echo empty($user['email']) ? 'disabled' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> <?php echo t('force_reset.send_button'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>
