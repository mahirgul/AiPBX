<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getUserLanguage()); ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?php echo t('mobile_login.page_title'); ?> - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .ml-section { border: 1px solid var(--border-color); border-radius: 12px; padding: 18px; margin-bottom: 16px; }
        .ml-section h3 { font-size: 15px; font-weight: 700; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
        .ml-steps { margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-muted); line-height: 1.7; }
        .ml-qr { background: #fff; border-radius: 10px; padding: 10px; width: 100%; max-width: 240px; margin: 0 auto 14px auto; display: block; }
        .ml-note { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 8px; }
        .ml-open { width: 100%; justify-content: center; padding: 16px; font-size: 16px; }
    </style>
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 460px;">
        <div style="text-align: center; margin-bottom: 22px;">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <i class="fas fa-mobile-alt" style="color: var(--primary);"></i>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 6px;"><?php echo t('mobile_login.page_title'); ?></p>
        </div>

        <?php if (!$info['valid']): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; text-align: center;">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo t('mobile_login.error_' . ($info['reason'] ?? 'invalid')); ?>
            </div>
            <p style="font-size: 13px; color: var(--text-muted); text-align: center; margin-bottom: 18px;"><?php echo t('mobile_login.error_hint'); ?></p>
            <a href="/login" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                <i class="fas fa-sign-in-alt"></i> <?php echo t('mobile_login.web_login'); ?>
            </a>
        <?php else: ?>
            <p style="font-size: 14px; text-align: center; margin-bottom: 18px;">
                <?php echo t('mobile_login.greeting'); ?>
                <strong><?php echo htmlspecialchars($info['user']['full_name'] ?: $info['user']['username']); ?></strong>
                (<?php echo t('mobile_login.extension'); ?> <?php echo htmlspecialchars($info['user']['extension']); ?>)
            </p>

            <?php
            $openSection = function () use ($platform, $android_link, $app_link, $play_url) { ?>
                <div class="ml-section">
                    <h3><i class="fas fa-mobile-alt" style="color: var(--primary);"></i> <?php echo t('mobile_login.phone_title'); ?></h3>
                    <a href="<?php echo htmlspecialchars($platform === 'ios' ? $app_link : $android_link); ?>" class="btn btn-primary ml-open">
                        <i class="fas fa-external-link-alt"></i> <?php echo t('mobile_login.open_app'); ?>
                    </a>
                    <p class="ml-note">
                        <?php if ($platform === 'ios'): ?>
                            <?php echo t('mobile_login.install_ios'); ?>
                        <?php else: ?>
                            <?php echo t('mobile_login.install_android'); ?>
                            <a href="<?php echo htmlspecialchars($play_url); ?>" rel="noreferrer" target="_blank">Google Play</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php };

            $qrSection = function () use ($qr, $play_url) { ?>
                <div class="ml-section">
                    <h3><i class="fas fa-qrcode" style="color: var(--primary);"></i> <?php echo t('mobile_login.qr_title'); ?></h3>
                    <?php if ($qr !== ''): ?>
                        <img class="ml-qr" src="<?php echo htmlspecialchars($qr); ?>" alt="QR">
                    <?php endif; ?>
                    <ol class="ml-steps">
                        <li><?php echo t('mobile_login.qr_step1'); ?> (<a href="<?php echo htmlspecialchars($play_url); ?>" rel="noreferrer" target="_blank">Google Play</a>)</li>
                        <li><?php echo t('mobile_login.qr_step2'); ?></li>
                        <li><?php echo t('mobile_login.qr_step3'); ?></li>
                    </ol>
                </div>
            <?php };

            // Telefonda önce "uygulamada aç", bilgisayarda önce QR.
            if ($platform === 'desktop') {
                $qrSection();
                $openSection();
            } else {
                $openSection();
                $qrSection();
            }
            ?>

            <div style="font-size: 12px; color: var(--text-muted); text-align: center; line-height: 1.6;">
                <i class="fas fa-lock"></i>
                <?php echo t('mobile_login.security_note'); ?>
                <?php echo htmlspecialchars(date('d.m.Y H:i', $expires_at)); ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
