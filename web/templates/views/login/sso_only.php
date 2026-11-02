<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="auth-cards" style="justify-content: center;">
    <div class="auth-card login-card">
        <h2 style="font-size: 20px; font-weight: 800; margin: 0 0 8px;"><?php echo $h($brand_title); ?></h2>
        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>
        <p class="u-muted u-fs-12"><?php echo t('sso_only.login_help'); ?></p>
        <a href="<?php echo $h($sso_url); ?>" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
            <i class="fas fa-shield-alt"></i> <span><?php echo t('sso_only.login_button'); ?></span>
        </a>
        <?php require dirname(__DIR__, 2) . '/auth_support_contact.php'; ?>
    </div>
</div>
