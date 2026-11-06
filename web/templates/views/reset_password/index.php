<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card u-maxw-420">
        <div class="u-text-center u-mb-24">
            <div class="brand-icon u-w-56 u-h-56 u-m-0-auto-16-auto u-fs-24">
                <i class="fas fa-key u-primary"></i>
            </div>
            <h2 class="u-fs-22 u-fw-800"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <?php if ($success): ?>
            <div class="u-bg-rgba-34-197-94-0-15 u-bd-1-solid-success u-success u-p-16-16 u-r-12 u-fs-13 u-mb-20 u-ta-left">
                <i class="fas fa-check-circle"></i> <?php echo t('reset_password.success_message'); ?>
            </div>
            <a href="/login" class="btn btn-primary u-w-100 u-jc-center u-p-14">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('reset_password.login_button'); ?>
            </a>
        <?php elseif (!$user): ?>
            <p class="u-muted u-fs-14 u-ta-center u-m-16-0-24">
                <?php echo t('reset_password.invalid_link'); ?>
            </p>
            <a href="/login" class="btn btn-secondary u-w-100 u-jc-center u-p-12">
                <i class="fas fa-arrow-left"></i> <?php echo t('reset_password.back_to_login'); ?>
            </a>
        <?php else: ?>
            <form method="POST" autocomplete="off" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label class="form-label"><?php echo t('reset_password.field_new_password'); ?></label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('reset_password.field_confirm_password'); ?></label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary u-w-100 u-jc-center u-p-14 u-mt-10 u-fs-15">
                    <i class="fas fa-check"></i> <?php echo t('reset_password.update_button'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>
