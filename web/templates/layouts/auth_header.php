<?php
/**
 * Oturum gerektirmeyen sayfaların (giriş, 2FA, şifre sıfırlama, mobil giriş…)
 * ortak düzeninin üst yarısı. BaseController::renderAuthPage() dahil eder.
 *
 * Beklenen değişkenler: $auth_title (sayfa başlığı), $auth_head (ek <head> içeriği, ham HTML)
 */
$auth_site_title = getSystemSetting('site_title', 'AI PBX Portalı');
$auth_favicon = getSystemSetting('site_favicon_url', '');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getUserLanguage()); ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $auth_title !== '' ? htmlspecialchars($auth_title) . ' - ' : ''; ?><?php echo htmlspecialchars($auth_site_title); ?></title>
    <link rel="shortcut icon" href="<?php echo $auth_favicon !== '' ? htmlspecialchars($auth_favicon) : '/favicon.ico'; ?>">
    <link rel="stylesheet" href="<?php echo asset('/assets/css/variables.css'); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="<?php echo asset('/assets/css/layout.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/assets/css/components.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/assets/css/fontawesome.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/assets/css/style.css'); ?>">
    <?php echo $auth_head; ?>
</head>
<body class="auth-body">
