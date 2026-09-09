<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('force_reset.page_title'); ?> - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 460px; text-align: center;">
        <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
            <i class="fas fa-shield-halved" style="color: var(--primary);"></i>
        </div>
        <h2 style="font-size: 20px; font-weight: 800;"><?php echo t('force_reset.heading'); ?></h2>
        <p style="color: var(--text-muted); font-size: 13px; margin-top: 6px;"><?php echo htmlspecialchars($brand_title); ?> — <?php echo htmlspecialchars($brand_sub); ?></p>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; font-size: 13px; margin: 20px 0; text-align: center;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

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
</body>
</html>
