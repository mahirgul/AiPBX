<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card u-maxw-460 u-ta-center">
        <div class="brand-icon u-w-56 u-h-56 u-m-0-auto-16-auto u-fs-24">
            <i class="fas fa-shield-halved u-primary"></i>
        </div>
        <h2 class="u-fs-20 u-fw-800"><?php echo t('force_reset.heading'); ?></h2>
        <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_title); ?> — <?php echo htmlspecialchars($brand_sub); ?></p>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <?php if ($changed): ?>
            <div class="u-bg-rgba-34-197-94-0-15 u-bd-1-solid-success u-success u-p-16-16 u-r-12 u-fs-13 u-m-20-0 u-ta-left">
                <i class="fas fa-check-circle"></i> <?php echo t('reset_password.success_message'); ?>
            </div>
            <a href="/login" class="btn btn-primary u-w-100 u-jc-center u-p-14">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('reset_password.login_button'); ?>
            </a>
        <?php elseif ($sent): ?>
            <div class="u-bg-rgba-34-197-94-0-15 u-bd-1-solid-success u-success u-p-16-16 u-r-12 u-fs-13 u-m-20-0 u-ta-left">
                <i class="fas fa-check-circle"></i> <?php echo t('force_reset.sent_prefix'); ?> <strong><?php echo htmlspecialchars($maskedEmail); ?></strong> <?php echo t('force_reset.sent_suffix'); ?>
            </div>
            <a href="/login" class="btn btn-secondary u-w-100 u-jc-center u-p-12">
                <i class="fas fa-arrow-left"></i> <?php echo t('force_reset.back_to_login'); ?>
            </a>
        <?php else: ?>
            <p class="u-muted u-fs-14 u-lh-1-6 u-m-16-0-20">
                <?php echo t('force_reset.set_intro'); ?>
            </p>
            <form method="POST" autocomplete="off" action="" class="u-ta-left">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label class="form-label"><?php echo t('reset_password.field_new_password'); ?></label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('reset_password.field_confirm_password'); ?></label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary u-w-100 u-jc-center u-p-14 u-mt-6 u-fs-15">
                    <i class="fas fa-check"></i> <?php echo t('reset_password.update_button'); ?>
                </button>
            </form>
            <?php if (!empty($user['email'])): ?>
                <p class="u-muted u-fs-13 u-lh-1-6 u-m-20-0-12">
                    <?php echo t('force_reset.or_email'); ?> <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>
                </p>
                <form method="POST" autocomplete="off" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-secondary u-w-100 u-jc-center u-p-10 u-fs-13">
                        <i class="fas fa-paper-plane"></i> <?php echo t('force_reset.send_button'); ?>
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>
