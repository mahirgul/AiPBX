<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('login.page_title'); ?> - <?php echo htmlspecialchars($site_title); ?></title>
    <?php if (!empty($site_favicon_url)): ?>
        <link rel="shortcut icon" href="<?php echo htmlspecialchars($site_favicon_url); ?>">
    <?php endif; ?>

    <!-- 100% Offline Local Assets -->
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        @media (max-width: 768px) {
            .mobile-app-download {
                display: block !important;
            }
        }
    </style>
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 420px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <?php if ($site_logo_type === 'image' && !empty($site_logo_image)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo_image); ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                <?php else: ?>
                    <i class="fas <?php echo htmlspecialchars($site_logo_icon); ?>" style="color: var(--primary);"></i>
                <?php endif; ?>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 6px;"><?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; text-align: center;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_username'); ?></label>
                <div style="position: relative;">
                    <i class="fas fa-user" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="text" name="username" class="form-control" style="padding-left: 44px;" required autofocus autocomplete="off">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_password'); ?></label>
                <div style="position: relative;">
                    <i class="fas fa-lock" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" name="password" class="form-control" style="padding-left: 44px;" required>
                </div>
            </div>

            <!-- Dynamic Math Security Challenge -->
            <div class="form-group" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 14px; border-radius: 12px;">
                <label class="form-label" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span><?php echo t('login.security_check'); ?></span>
                    <strong style="color: var(--primary); font-size: 16px;"><?php echo $num1; ?> + <?php echo $num2; ?> = ?</strong>
                </label>
                <input type="number" name="captcha_answer" class="form-control" placeholder="<?php echo t('login.captcha_placeholder'); ?>" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 10px; font-size: 15px;" title="<?php echo t('login.submit_tooltip'); ?>">
                <i class="fas fa-sign-in-alt"></i>
            </button>
        </form>

        <?php
        $login_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_mobile_device = (bool) preg_match('/(android|iphone|ipad|ipod|mobile|phone|silk|blackberry|opera mini|windows phone)/i', $login_ua);
        ?>
        <div class="mobile-app-download" style="<?php echo $is_mobile_device ? 'margin-top: 22px; padding-top: 18px; border-top: 1px dashed var(--border-color); text-align: center;' : 'display: none; margin-top: 22px; padding-top: 18px; border-top: 1px dashed var(--border-color); text-align: center;'; ?>">
            <div style="font-size: 13px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; justify-content: center; gap: 8px; color: var(--text-color);">
                <i class="fab fa-android" style="color: #3DDC84; font-size: 18px;"></i>
                <span><?php echo t('login.mobile_app_title'); ?></span>
            </div>
            <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px; line-height: 1.4;">
                <?php echo t('login.mobile_app_desc'); ?>
            </p>
            <a href="/app.apk" download="AiPBX.apk" class="btn btn-outline-primary" style="width: 100%; justify-content: center; gap: 8px; font-weight: 600; padding: 10px 14px; font-size: 13px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center;">
                <i class="fas fa-download"></i> <?php echo t('login.download_apk'); ?>
            </a>
        </div>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>

    <?php if ($error): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.notify) {
                window.notify.error(<?php echo json_encode($error); ?>);
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
