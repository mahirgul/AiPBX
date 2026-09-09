<?php
/**
 * Front Controller (Router) — AI PBX Portalı
 * Temiz URL'leri (whitelist) hedef dosyalara eşler; eski URL'leri kalıcı yönlendirir.
 * httpd mod_rewrite, dosya/dizin olmayan tüm istekleri buraya düşürür.
 *
 * 2026-08-22: $ROUTES değerleri artık iki biçimi de destekliyor —
 *  - string  (eski/mevcut sayfalar): doğrudan dosya yolu, require edilir.
 *  - array   (MVC'ye taşınmış sayfalar): ['controller' => X::class, 'action' => 'y', 'module' => 'eski_dosya_adi.php']
 *    'module' anahtarı, auth.php'deki getModuleKeyForPage() eşlemesinin
 *    (basename($_SERVER['PHP_SELF']) üzerinden çalışır) MVC göçünden sonra
 *    da DEĞİŞMEDEN çalışmasını sağlıyor — RBAC eşlemesine hiç dokunulmuyor.
 */
require_once __DIR__ . '/auth.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($path === '') {
    $path = '/';
}

// 0. Dil değiştirme — tüm sayfalarda ortak, tek bir modüle bağlı olmayan
//    çapraz-kesit bir işlem olduğu için ayrı bir mini-route (header.php'deki
//    dil seçiciden çağrılır). CSRF gerektirmez (düşük riskli bir tercih
//    değişikliği), ama 'lang' whitelist'e karşı, 'redirect' ise açık
//    yönlendirme (open redirect) riskine karşı sıkı doğrulanıyor.
if ($path === '/set-language') {
    $lang = $_GET['lang'] ?? '';
    if (!isset(UI_LANGUAGES[$lang])) {
        $lang = 'tr';
    }
    $_SESSION['ui_language'] = $lang;
    if (isset($_SESSION['user_id'])) {
        $stmt = getDB()->prepare('UPDATE sys_users SET language_preference = ? WHERE id = ?');
        $stmt->execute([$lang, $_SESSION['user_id']]);
    }
    $redirect = $_GET['redirect'] ?? '/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/' || (isset($redirect[1]) && $redirect[1] === '/')) {
        $redirect = '/';
    }
    header('Location: ' . $redirect);
    exit;
}

// 1. Eski URL'ler → kalıcı yönlendirme (exact-match whitelist)
$LEGACY = [
    '/admin'                   => '/',
    '/admin/index.php'         => '/',
    '/admin/dashboard.php'     => '/dashboard',
    '/admin/trunks.php'        => '/trunks',
    '/admin/did_routes.php'    => '/did-routes',
    '/admin/outbound_routes.php' => '/outbound-routes',
    '/admin/time_conditions.php' => '/time-conditions',
    '/admin/ivrs.php'          => '/ivrs',
    '/admin/extensions.php'    => '/extensions',
    '/admin/queues.php'        => '/queues',
    '/admin/sounds.php'        => '/sounds',
    '/admin/end_call.php'      => '/end-call',
    '/admin/system_users.php'  => '/system-users',
    '/admin/roles.php'         => '/roles',
    '/admin/asterisk_settings.php' => '/asterisk-settings',
    '/admin/push_settings.php' => '/push-settings',
    '/admin/fax_settings.php'  => '/fax-settings',
    '/admin/fax_mail_settings.php' => '/fax-mail-settings',
    '/admin/cdr_reports.php'   => '/cdr-reports',
    '/admin/pause_reports.php' => '/pause-reports',
    '/admin/queue_logs.php'    => '/queue-logs',
    '/admin/cc_agent.php'      => '/cc-agent',
    '/admin/cc_supervisor.php' => '/cc-supervisor',
    '/admin/fax_inbox.php'     => '/fax-inbox',
    '/admin/fax_send.php'      => '/fax-send',
    '/admin/fax_sent.php'      => '/fax-sent',
    '/fax'                     => '/fax-inbox',
    '/fax/inbox.php'           => '/fax-inbox',
    '/fax/send.php'            => '/fax-send',
    '/fax/sent.php'            => '/fax-sent',
    '/cc'                      => '/cc-agent',
    '/cc/agent.php'            => '/cc-agent',
    '/cc/supervisor.php'       => '/cc-supervisor',
];
if (isset($LEGACY[$path])) {
    header('Location: ' . $LEGACY[$path], true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 301 : 308);
    exit;
}

// 2. Temiz URL'ler (exact-match whitelist) — tablo src/routes.php'de,
//    çünkü bin/smoke.php de aynı tabloyu okuyor (tek doğruluk kaynağı).
$ROUTES = require __DIR__ . '/src/routes.php';

if (!isset($ROUTES[$path])) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>404 - Sayfa Bulunamadı</title>'
       . '<link rel="stylesheet" href="/assets/css/variables.css">'
       . '<link rel="stylesheet" href="/assets/css/layout.css">'
       . '<link rel="stylesheet" href="/assets/css/components.css">'
       . '<link rel="stylesheet" href="/assets/css/fontawesome.min.css">'
       . '<link rel="stylesheet" href="/assets/css/style.css"></head>'
       . '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">'
       . '<h2 style="color:var(--warning);"><i class="fas fa-map-signs"></i> 404 - Sayfa Bulunamadı</h2>'
       . '<p style="margin:20px 0; color:var(--text-muted);">Aradığınız sayfa taşınmış veya mevcut değil.</p>'
       . '<a href="/login" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Giriş Yap</a>'
       . '</div></body></html>';
    exit;
}

// 3. Rol yönlendirmesi (ana sayfa)
if ($ROUTES[$path] === 'role') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'admin') {
        header('Location: /dashboard');
    } elseif ($role === 'cc_agent') {
        header('Location: /cc-agent');
    } elseif ($role === 'cc_manager') {
        header('Location: /cc-supervisor');
    } else {
        header('Location: /fax-inbox');
    }
    exit;
}

// 4. Hedef (RBAC + aktif sekme kontrolleri basename üzerinden çalışsın diye
//    PHP_SELF, hedef dosya yoluna/modül adına eşitlenir — tüketiciler:
//    auth.php, header.php)
$target = $ROUTES[$path];

if (is_array($target)) {
    // MVC'ye taşınmış sayfa: Controller::action() static çağrısı
    $_SERVER['PHP_SELF'] = '/' . $target['module'];
    [$controllerClass, $action] = [$target['controller'], $target['action']];
    $controllerClass::$action();
} else {
    // Eski/henüz taşınmamış sayfa: doğrudan dosya require
    $_SERVER['PHP_SELF'] = '/' . $target;
    require __DIR__ . '/' . $target;
}
