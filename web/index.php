<?php
/**
 * Front controller (router) — AI PBX portal
 * Maps clean URLs (whitelist) to their targets; permanently redirects old URLs.
 * httpd mod_rewrite sends every request that is not a file/directory here.
 *
 * 2026-08-22: $ROUTES values support both forms —
 *  - string  (old/existing pages): a direct file path, required.
 *  - array   (pages moved to MVC): ['controller' => X::class, 'action' => 'y', 'module' => 'old_file_name.php']
 *    The 'module' key keeps the getModuleKeyForPage() mapping in auth.php
 *    (which works from basename($_SERVER['PHP_SELF'])) working UNCHANGED
 *    after the MVC migration — the RBAC mapping is not touched at all.
 */
require_once __DIR__ . '/auth.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($path === '') {
    $path = '/';
}

// 0. Language switch — a cross-cutting action shared by all pages and tied
//    to no single module, hence a separate mini-route (called from the
//    language picker in header.php). It needs no CSRF (a low-risk preference
//    change), but 'lang' is checked against a whitelist and 'redirect' is
//    strictly validated against open redirects.
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

// 1. Old URLs → permanent redirect (exact-match whitelist)
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

// 2. Clean URLs (exact-match whitelist) — the table lives in src/routes.php,
//    because bin/smoke.php reads the same table (single source of truth).
$ROUTES = require __DIR__ . '/src/routes.php';

if (!isset($ROUTES[$path])) {
    http_response_code(404);
    $auth_title = '404';
    $auth_head = '';
    require __DIR__ . '/templates/layouts/auth_header.php';
    View::render('errors/404');
    require __DIR__ . '/templates/layouts/auth_footer.php';
    exit;
}

// 3. Role redirect (home page)
if ($ROUTES[$path] === 'role') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    header('Location: ' . roleHomePath($_SESSION['user_role'] ?? ''));
    exit;
}

// 4. Target (PHP_SELF is set to the target file path/module name so the RBAC
//    and active-tab checks work from basename — consumers: auth.php,
//    header.php)
$target = $ROUTES[$path];

if (is_array($target)) {
    // Page moved to MVC: static Controller::action() call
    $_SERVER['PHP_SELF'] = '/' . $target['module'];
    [$controllerClass, $action] = [$target['controller'], $target['action']];
    $controllerClass::$action();
} else {
    // Old/not yet migrated page: require the file directly
    $_SERVER['PHP_SELF'] = '/' . $target;
    require __DIR__ . '/' . $target;
}
