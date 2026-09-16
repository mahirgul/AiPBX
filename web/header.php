<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$module_key = getModuleKeyForPage();
requireModulePermission($module_key, 'view');
$db = getDB();

$user = getCurrentUser();
$role = $_SESSION['user_role'] ?? 'fax_user';
$theme = $_SESSION['theme'] ?? 'light';
$active_page = basename($_SERVER['PHP_SELF']);
$request_uri = $_SERVER['REQUEST_URI'] ?? '';

$is_dashboard_active = in_array($active_page, ['dashboard.php', 'index.php', 'my_phone.php', 'chat.php', 'cdr_reports.php', 'reports.php']);
$is_trunk_active = in_array($active_page, ['trunks.php', 'did_routes.php', 'outbound_routes.php']);
$is_pbx_active = in_array($active_page, ['time_conditions.php', 'ivrs.php', 'extensions.php', 'queues.php', 'sounds.php', 'end_call.php']);
$is_admin_active = in_array($active_page, ['system_users.php', 'roles.php', 'asterisk_settings.php', 'brand_settings.php', 'fax_mail_settings.php', 'fax_settings.php', 'pending_sync.php', 'audit_log.php', 'push_settings.php']);
$is_fax_active = in_array($active_page, ['fax_inbox.php', 'fax_send.php', 'fax_sent.php']);
$is_cc_active = in_array($active_page, ['cc_agent.php', 'cc_supervisor.php', 'cc_board.php', 'queue_logs.php', 'pause_reports.php']);
$is_security_active = in_array($active_page, ['firewall.php', 'fail2ban.php']);

// Module Visibility Checks via RBAC
$can_view_dashboard_group = hasModulePermission('dashboard', 'view') || hasModulePermission('my_phone', 'view') || hasModulePermission('chat', 'view') || hasModulePermission('cdr_reports', 'view') || hasModulePermission('cc_reports', 'view');
$can_view_trunks_group = hasModulePermission('trunks', 'view') || hasModulePermission('did_routes', 'view') || hasModulePermission('outbound_routes', 'view');
$can_view_pbx_group = hasModulePermission('time_conditions', 'view') || hasModulePermission('ivrs', 'view') || hasModulePermission('extensions', 'view') || hasModulePermission('queues', 'view') || hasModulePermission('sounds', 'view') || hasModulePermission('end_call', 'view');
$can_view_admin_group = hasModulePermission('system_users', 'view') || hasModulePermission('roles', 'view') || hasModulePermission('asterisk_settings', 'view') || hasModulePermission('brand_settings', 'view') || hasModulePermission('pending_sync', 'view') || hasModulePermission('push_settings', 'view') || hasModulePermission('fax_mail_settings', 'view') || hasModulePermission('fax_settings', 'view') || hasModulePermission('audit_log', 'view');
$can_view_fax_group = hasModulePermission('fax_inbox', 'view') || hasModulePermission('fax_send', 'view') || hasModulePermission('fax_sent', 'view');
$can_view_cc_group = hasModulePermission('cc_agent', 'view') || hasModulePermission('cc_board', 'view') || hasModulePermission('cc_reports', 'view') || hasModulePermission('pause_reports', 'view') || hasModulePermission('queue_logs', 'view');
// Firewall/fail2ban HER ZAMAN admin-only (auth.php circuit-breaker) — grup
// görünürlüğü de doğrudan role kontrolüyle, roles.php'nin izin matrisinden
// bağımsız (roles/system_users ile aynı desen).
$can_view_security_group = ($role === 'admin');

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
    // SPA yanıtında sidebar hiç basılmıyor (sadece .content-area) — sidebar'daki
    // "Uygula" rozeti bu yüzden normal SPA navigasyonlarında/form gönderimlerinde
    // hiç yenilenmiyordu (2026-08-31, kullanıcı bulgusu: Uygula'ya basınca rozet
    // sayfa yenilenene kadar eski sayıyla kalıyordu). Güncel bekleyen-değişiklik
    // sayısı burada data attribute olarak taşınıyor, spa_router.js her SPA
    // navigasyonu/form gönderimi sonrası bunu okuyup sidebar rozetini JS ile
    // günceller — sidebar.php'nin kendisi bu istekte hiç çalışmadığı için
    // gerekli fonksiyon burada ayrıca require ediliyor (aynı sidebar.php'deki
    // savunmacı desen).
    require_once __DIR__ . '/src/asterisk_sync.php';
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
<html lang="tr" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?><?php echo htmlspecialchars($site_title); ?></title>
    <link rel="shortcut icon" href="<?php echo !empty($site_favicon_url) ? htmlspecialchars($site_favicon_url) : '/favicon.ico'; ?>">

    <!-- PWA: yüklenebilir uygulama desteği -->
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
        // WebSocket yolu sys_settings'ten gelir (kodda statik değer yok)
        window.PORTAL_WS_PATH = "<?php echo htmlspecialchars(getSystemSetting('pjsip_ws_path', '/ws')); ?>";
        // SIP parolası sayfa kaynağına gömülmez; /api/sip_credentials.php üzerinden lazım olduğunda alınır
        window.ALLOWED_PHONE_MODE = "<?php echo htmlspecialchars($user['allowed_phone_mode'] ?? 'both'); ?>";
        window.USER_PHONE_MODES = <?php echo json_encode(parsePhoneModes($user['allowed_phone_mode'] ?? 'both')); ?>;
        window.SYSTEM_EXTENSIONS = <?php echo json_encode($db->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC)); ?>;
        // Softphone zil/çevirme tonu — admin panelde seçilmemişse header_phone.js kendi varsayılanını kullanır
        <?php $ring_in = getSystemSetting('webrtc_ring_incoming', ''); $ring_out = getSystemSetting('webrtc_ring_outgoing', ''); ?>
        window.WEBRTC_RING_INCOMING_URL = "<?php echo $ring_in !== '' ? '/api/sound_play.php?file=' . urlencode($ring_in) : ''; ?>";
        window.WEBRTC_RING_OUTGOING_URL = "<?php echo $ring_out !== '' ? '/api/sound_play.php?file=' . urlencode($ring_out) : ''; ?>";
        window.LANG_APPLYING = "<?php echo t('topbar.applying'); ?>";
        if ("serviceWorker" in navigator) {
            window.addEventListener("load", function () {
                navigator.serviceWorker.register("/sw.js").catch(function () {});
            });
        }
    </script>
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
    <audio id="globalRemoteAudio" autoplay></audio>
    <script src="/assets/js/jssip.min.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/ui_helper.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/header_phone.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/spa_router.js?v=<?php echo time(); ?>"></script>
</head>
<body>
<div class="app-container">
    <?php require_once __DIR__ . '/templates/sidebar.php'; ?>

    <!-- Mobile Overlay Backdrop -->
    <div class="mobile-sidebar-overlay" id="mobile-sidebar-overlay" onclick="closeMobileSidebar()"></div>

    <!-- Main Content Area -->
    <main class="main-wrapper">
        <?php require_once __DIR__ . '/templates/topbar.php'; ?>
        <?php require_once __DIR__ . '/templates/softphone_drawer.php'; ?>
        <?php require_once __DIR__ . '/templates/phone_settings_modal.php'; ?>

        <section class="content-area">
