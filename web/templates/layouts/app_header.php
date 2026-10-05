<?php
/**
 * Top half of the shared layout of the application pages (head + sidebar + topbar).
 * Included ONLY by BaseController::renderPage(); the session and module
 * permission checks happen there — this template makes no security decision.
 *
 * Expected variables (set by renderPage): $page_title
 */
$db = getDB();

$user = getCurrentUser();
$role = $_SESSION['user_role'] ?? 'user';
$theme = $_SESSION['theme'] ?? 'light';
$active_page = basename($_SERVER['PHP_SELF']);
$request_uri = $_SERVER['REQUEST_URI'] ?? '';

// Sidebar groups, their visibility and open state: templates/sidebar_menu.php.

// Fetch Dynamic Branding Settings
$site_title = getSystemSetting('site_title', 'AiPBX');
$brand_title = getSystemSetting('brand_title', 'AiPBX');
$brand_sub = getSystemSetting('brand_sub', 'Santral & Çağrı Merkezi');
$site_logo_type = getSystemSetting('site_logo_type', 'image');
$site_logo_icon = getSystemSetting('site_logo_icon', 'fa-network-wired');
$site_logo_image = getSystemSetting('site_logo_image', BRAND_DEFAULT_LOGO_URL);
$site_favicon_url = getSystemSetting('site_favicon_url', '');

// SPA Single-Page App AJAX Buffer Interceptor
$is_spa_request = !empty($_SERVER['HTTP_X_SPA_REQUEST']) || (isset($_GET['spa']) && $_GET['spa'] === '1');
if ($is_spa_request) {
    // The SPA response never prints the sidebar (only .content-area) — so the
    // "Apply" badge in the sidebar was never refreshed on normal SPA
    // navigations/form submissions (2026-08-31, user finding: after pressing
    // Apply the badge kept the old count until the page was reloaded). The
    // current pending-change count travels here as a data attribute;
    // spa_router.js reads it after every SPA navigation/form submission and
    // updates the sidebar badge with JS — sidebar.php itself does not run on
    // this request, so the needed function is required here separately (the
    // same defensive pattern as in sidebar.php).
    require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
    $pending_sync_count_for_spa = hasModulePermission('pending_sync', 'view') ? getPendingSyncCount() : 0;
    ob_start();
    echo '<div id="spa-page-data" data-title="' . htmlspecialchars(($page_title ?? '') . ' - ' . $site_title) . '" data-page="' . htmlspecialchars($active_page) . '" data-pending-sync-count="' . (int)$pending_sync_count_for_spa . '"></div>';
    return;
}
$is_cc_agent = ($user['role'] === 'cc_agent');
if (!$is_cc_agent && !empty($user['extension'])) {
    $stmt_top = $db->query("SELECT members_json FROM pbx_queues WHERE is_active = 1");
    $q_mems = $stmt_top ? $stmt_top->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($q_mems as $mj) {
        $m_arr = json_decode($mj ?? '[]', true) ?: [];
        if (in_array((string)$user['extension'], array_map('strval', $m_arr))) {
            $is_cc_agent = true;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getUserLanguage()); ?>" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?><?php echo htmlspecialchars($site_title); ?></title>
    <link rel="shortcut icon" href="<?php echo !empty($site_favicon_url) ? htmlspecialchars($site_favicon_url) : '/favicon.ico'; ?>">

    <!-- PWA: installable app support -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="<?php echo htmlspecialchars(getSystemSetting('brand_color_primary', '#0284c7')); ?>">
    <link rel="apple-touch-icon" href="/assets/images/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="AI PBX">

    <!-- 100% Offline Local Assets & WebRTC Engine -->
    <script>
        (function() {
            var isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
            if (isCollapsed) {
                document.cookie = "sidebar_collapsed=true; path=/; max-age=31536000";
            } else if (localStorage.getItem('sidebar_collapsed') === 'false') {
                document.cookie = "sidebar_collapsed=false; path=/; max-age=31536000";
            }
            var savedFont = localStorage.getItem('font_size_pref') || 'normal';
            document.documentElement.setAttribute('data-font-size', savedFont);
        })();
        window.CSRF_TOKEN = "<?php echo getCSRFToken(); ?>";
        window.CURRENT_USER_EXT = "<?php echo htmlspecialchars($user['extension'] ?? ''); ?>";
        window.IS_CC_AGENT = <?php echo $is_cc_agent ? 'true' : 'false'; ?>;
        // The WebSocket path comes from sys_settings (no static value in code)
        window.PORTAL_WS_PATH = "<?php echo htmlspecialchars(getSystemSetting('pjsip_ws_path', '/ws')); ?>";
        // The SIP password is not embedded in the page source; it is fetched from /api/sip_credentials.php when needed
        window.ALLOWED_PHONE_MODE = "<?php echo htmlspecialchars($user['allowed_phone_mode'] ?? 'both'); ?>";
        window.USER_PHONE_MODES = <?php echo json_encode(parsePhoneModes($user['allowed_phone_mode'] ?? 'both')); ?>;
        window.SYSTEM_EXTENSIONS = <?php echo json_encode($db->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC)); ?>;
        // Softphone ring/ringback tone — if none is chosen in the admin panel, header_phone.js uses its own default
        <?php $ring_in = getSystemSetting('webrtc_ring_incoming', ''); $ring_out = getSystemSetting('webrtc_ring_outgoing', ''); ?>
        window.WEBRTC_RING_INCOMING_URL = "<?php echo $ring_in !== '' ? '/api/sound_play.php?file=' . urlencode($ring_in) : ''; ?>";
        window.WEBRTC_RING_OUTGOING_URL = "<?php echo $ring_out !== '' ? '/api/sound_play.php?file=' . urlencode($ring_out) : ''; ?>";
        window.LANG_APPLYING = <?php echo json_encode(t('topbar.applying'), JSON_UNESCAPED_UNICODE); ?>;
        if ("serviceWorker" in navigator && window.isSecureContext) {
            var isSelfSignedHost = location.protocol === 'https:' && (location.hostname === 'localhost' || location.hostname === '127.0.0.1' || /^\d+\.\d+\.\d+\.\d+$/.test(location.hostname));
            if (!isSelfSignedHost) {
                window.addEventListener("load", function () {
                    navigator.serviceWorker.register("/sw.js").catch(function () {});
                });
            }
        }
    </script>
    <link rel="stylesheet" href="<?php echo asset('/assets/css/variables.css'); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="<?php echo asset('/assets/css/layout.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/assets/css/components.css'); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="<?php echo asset('/assets/css/style.css'); ?>">
    <audio id="globalRemoteAudio" autoplay></audio>
    <script src="<?php echo asset('/assets/js/jssip.min.js'); ?>"></script>
    <script src="<?php echo asset('/assets/js/ui_helper.js'); ?>"></script>
    <script src="<?php echo asset('/assets/js/header_phone.js'); ?>"></script>
    <script src="<?php echo asset('/assets/js/spa_router.js'); ?>"></script>
</head>
<body>
<div class="app-container">
    <?php require_once dirname(__DIR__) . '/sidebar.php'; ?>

    <!-- Mobile Overlay Backdrop -->
    <div class="mobile-sidebar-overlay" id="mobile-sidebar-overlay" onclick="closeMobileSidebar()"></div>

    <!-- Main Content Area -->
    <main class="main-wrapper">
        <?php require_once dirname(__DIR__) . '/topbar.php'; ?>
        <?php require_once dirname(__DIR__) . '/softphone_drawer.php'; ?>
        <?php require_once dirname(__DIR__) . '/phone_settings_modal.php'; ?>

        <section class="content-area">
