<?php
/**
 * Top half of the shared layout of pages without a session (login, 2FA,
 * password reset, mobile sign-in…). Included by BaseController::renderAuthPage().
 *
 * Expected variables: $auth_title (page title), $auth_head (extra <head> content, raw HTML)
 */
$auth_site_title = getSystemSetting('site_title', 'AI PBX Portal');
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
<?php echo jsI18nScript(); ?>
</head>
<body class="auth-body">
<?php // Language switch for visitors (login, 2FA, password reset): before signing in there is no user menu.
// A page can print it itself (the phone sign-in puts it in its brand card).
if (empty($auth_lang_switch_inline)) require dirname(__DIR__) . '/auth_lang_switch.php'; ?>
