<?php
/**
 * Smoke test subprocess — renders ONE route and writes the result as JSON.
 * Called by bin/smoke.php in a separate process for every route/language
 * combination.
 *
 * Why a separate process: redirecting controllers call header()+exit; exit
 * would kill a runner working in a single process. Fatal errors are isolated
 * that way too. The result is written by register_shutdown_function, so a
 * report is produced on exit and on fatal errors as well.
 *
 * SAFETY: only renders. It never triggers a POST, a service write, config
 * generation or an Asterisk reload.
 *
 * Usage: php bin/_smoke_render.php <path> <lang> <role> <outfile>
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$path    = $argv[1] ?? '';
$lang    = $argv[2] ?? 'tr';
$role    = $argv[3] ?? 'admin';
$outfile = $argv[4] ?? '';

if ($path === '' || $outfile === '') {
    fwrite(STDERR, "kullanim: php bin/_smoke_render.php <path> <lang> <role> <outfile>\n");
    exit(2);
}

// Repo root (/var/www/html points here on the server; the checkout directory in CI).
$SMOKE_ROOT = dirname(__DIR__);
chdir($SMOKE_ROOT);
$ROUTES = require $SMOKE_ROOT . '/src/routes.php';
if (!isset($ROUTES[$path]) || !is_array($ROUTES[$path])) {
    file_put_contents($outfile, json_encode([
        'path' => $path, 'status' => 'skip', 'error' => 'array-olmayan rota',
    ]));
    exit(0);
}
$route = $ROUTES[$path];

// Optional query string (SMOKE_QUERY="export=pdf&date_range=week"), e.g. for exports.
$query = (string) getenv('SMOKE_QUERY');
if ($query !== '') {
    parse_str($query, $_GET);
}

// Mimic index.php's dispatch environment exactly.
$_SERVER['REQUEST_URI']    = $path . ($query !== '' ? '?' . $query : '');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/index.php';
// RBAC and the active-tab logic work from basename($_SERVER['PHP_SELF']).
$_SERVER['PHP_SELF']       = '/' . $route['module'];

/**
 * Catch PHP warnings programmatically, NOT FROM THE OUTPUT.
 *
 * Why: this app deliberately runs with display_errors=Off (see the fatal
 * page mechanism in config.php), so Warnings/Notices never reach the HTML —
 * grepping the generated output cannot work in principle (found on
 * 2026-09-01 when a deliberate fault injection was not caught).
 * The handler returns false so PHP's normal flow is not disturbed.
 */
$GLOBALS['SMOKE_PHP_ERRORS'] = [];
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    $names = [
        E_WARNING         => 'Warning',
        E_NOTICE          => 'Notice',
        E_USER_WARNING    => 'User Warning',
        E_USER_NOTICE     => 'User Notice',
        E_DEPRECATED      => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
    ];
    $label = $names[$no] ?? ('errno ' . $no);
    $GLOBALS['SMOKE_PHP_ERRORS'][] = "$label: $str @ "
        . str_replace($GLOBALS['SMOKE_ROOT'] . '/', '', $file) . ':' . $line;
    return false;
});

register_shutdown_function(function () use ($path, $lang, $role, $outfile) {
    $html = '';
    while (ob_get_level() > 0) { $html .= (string) ob_get_clean(); }

    $last = error_get_last();
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $error = '';
    if ($last !== null && in_array($last['type'], $fatal_types, true)) {
        $error = $last['message'] . ' @ ' . $last['file'] . ':' . $last['line'];
    }

    $sonuc = [
        'path'   => $path,
        'lang'   => $lang,
        'role'   => $role,
        'status' => $error === '' ? 'ok' : 'fatal',
        'error'  => $error,
        'bytes'  => strlen($html),
        'closed'     => stripos($html, '</html>') !== false,
        'php_errors' => array_values(array_unique($GLOBALS['SMOKE_PHP_ERRORS'] ?? [])),
        // Unlike substr, mb_strcut does not split a multibyte UTF-8 character
        // in the middle. With plain substr, once a page passed 200 KB the cut
        // landed in the middle of a Turkish character, json_encode returned
        // false because of invalid UTF-8 and an EMPTY string was written to
        // the file — the orchestrator reported it as "the subprocess produced
        // no result". It really happened when /system-users reached 208 KB
        // (2026-09-01).
        'output'     => mb_strcut($html, 0, 200000, 'UTF-8'),
    ];

    $json = json_encode($sonuc);
    if ($json === false) {
        // NO SILENT FAILURE: if the encoding is still broken, drop the output
        // but always write the result itself, otherwise the error looks like
        // "produced no result" and the real cause is hidden.
        $sonuc['output'] = '';
        $sonuc['error']  = trim($sonuc['error'] . ' [cikti JSON\'a kodlanamadi: ' . json_last_error_msg() . ']');
        $json = json_encode($sonuc);
    }
    file_put_contents($outfile, $json);
});

require $SMOKE_ROOT . '/auth.php';
if (is_file($SMOKE_ROOT . '/vendor/autoload.php')) {
    require_once $SMOKE_ROOT . '/vendor/autoload.php';
}
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Anonymous routes (login / password reset) are rendered without a session.
$ANON = ['/login', '/force-reset', '/reset-password', '/forgot-password'];
if (!in_array($path, $ANON, true)) {
    $_SESSION['user_id']       = 1;
    $_SESSION['user_role']     = $role;
    $_SESSION['username']      = 'smoke';
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = 'smoke-token';
}
$_SESSION['ui_language'] = $lang;

ob_start();
$cls    = $route['controller'];
$action = $route['action'];
$cls::$action();
