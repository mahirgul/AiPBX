<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('reset_password.page_title'); ?> - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 420px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <i class="fas fa-key" style="color: var(--primary);"></i>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 6px;"><?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; text-align: center;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; text-align: left;">
                <i class="fas fa-check-circle"></i> <?php echo t('reset_password.success_message'); ?>
            </div>
            <a href="/login" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('reset_password.login_button'); ?>
            </a>
        <?php elseif (!$user): ?>
            <p style="color: var(--text-muted); font-size: 14px; text-align: center; margin: 16px 0 24px;">
                <?php echo t('reset_password.invalid_link'); ?>
            </p>
            <a href="/login" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 12px;">
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

                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 10px; font-size: 15px;">
                    <i class="fas fa-check"></i> <?php echo t('reset_password.update_button'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>
</body>
</html>
