<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card" style="max-width: 460px; text-align: center;">
        <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
            <i class="fas fa-shield-halved u-primary"></i>
        </div>
        <h2 class="u-fs-20 u-fw-800"><?php echo t('force_reset.heading'); ?></h2>
        <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_title); ?> — <?php echo htmlspecialchars($brand_sub); ?></p>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <?php if ($changed): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 16px; border-radius: 10px; font-size: 13px; margin: 20px 0; text-align: left;">
                <i class="fas fa-check-circle"></i> <?php echo t('reset_password.success_message'); ?>
            </div>
            <a href="/login" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('reset_password.login_button'); ?>
            </a>
        <?php elseif ($sent): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 16px; border-radius: 10px; font-size: 13px; margin: 20px 0; text-align: left;">
                <i class="fas fa-check-circle"></i> <?php echo t('force_reset.sent_prefix'); ?> <strong><?php echo htmlspecialchars($maskedEmail); ?></strong> <?php echo t('force_reset.sent_suffix'); ?>
            </div>
            <a href="/login" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 12px;">
                <i class="fas fa-arrow-left"></i> <?php echo t('force_reset.back_to_login'); ?>
            </a>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 16px 0 20px;">
                <?php echo t('force_reset.set_intro'); ?>
            </p>
            <form method="POST" autocomplete="off" action="" style="text-align: left;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label class="form-label"><?php echo t('reset_password.field_new_password'); ?></label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('reset_password.field_confirm_password'); ?></label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 6px; font-size: 15px;">
                    <i class="fas fa-check"></i> <?php echo t('reset_password.update_button'); ?>
                </button>
            </form>
            <?php if (!empty($user['email'])): ?>
                <p style="color: var(--text-muted); font-size: 12.5px; line-height: 1.6; margin: 20px 0 10px;">
                    <?php echo t('force_reset.or_email'); ?> <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>
                </p>
                <form method="POST" autocomplete="off" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 10px; font-size: 13px;">
                        <i class="fas fa-paper-plane"></i> <?php echo t('force_reset.send_button'); ?>
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>
