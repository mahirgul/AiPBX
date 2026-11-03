<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/login.css'); ?>">
<div class="auth-cards">
    <div class="auth-card login-card">
        <?php require __DIR__ . '/_brand.php'; ?>
        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>
        <p class="u-muted u-fs-13 u-text-center" style="margin: 0 0 16px;"><?php echo t('sso_only.login_help'); ?></p>
        <a href="<?php echo $h($sso_url); ?>" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
            <i class="fas fa-shield-alt"></i> <span><?php echo t('sso_only.login_button'); ?></span>
        </a>
        <?php require dirname(__DIR__, 2) . '/auth_support_contact.php'; ?>
    </div>
</div>
