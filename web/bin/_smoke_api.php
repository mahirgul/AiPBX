<?php
/**
 * Smoke test subprocess — calls ONE api/*.php endpoint as a given role and
 * writes the result as JSON. Called by bin/smoke.php.
 *
 * Why a separate process: an api endpoint answers with header()+http_response_code()
 * and often exit; a fatal error must not take the runner with it. The result is
 * written by register_shutdown_function, so a report exists on exit and on
 * fatal errors as well — the same mechanism as bin/_smoke_render.php.
 *
 * SAFETY: GET only, and no request body is set, so the POST branches (the ones
 * that write) are unreachable. A GET on api/notices.php does prune resolved
 * notices older than 30 days, exactly as opening the bell does — noted here
 * because bin/smoke.php promises not to write.
 *
 * Usage: php bin/_smoke_api.php <endpoint under web/, e.g. api/notices.php> <role> <outfile> [query]
 *
 * The optional query is what the page itself sends (`action=status` and the
 * like); without it most of these endpoints answer 400/405 and there would be
 * nothing to compare the two roles on.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$script  = $argv[1] ?? '';
$role    = $argv[2] ?? 'admin';
$outfile = $argv[3] ?? '';
$query   = $argv[4] ?? '';

if ($script === '' || $outfile === '') {
    fwrite(STDERR, "kullanim: php bin/_smoke_api.php <endpoint> <role> <outfile>\n");
    exit(2);
}

$SMOKE_ROOT = dirname(__DIR__);
chdir($SMOKE_ROOT);
$GLOBALS['SMOKE_ROOT'] = $SMOKE_ROOT;

$file = $SMOKE_ROOT . '/' . ltrim($script, '/');
if (!is_file($file)) {
    file_put_contents($outfile, json_encode(['script' => $script, 'status' => 'skip', 'error' => 'no such endpoint']));
    exit(0);
}

// The endpoint is addressed directly, as the browser's fetch() addresses it.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/' . ltrim($script, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($script, '/');
$_SERVER['PHP_SELF']       = '/' . ltrim($script, '/');
$_GET = [];
$_POST = [];
if ($query !== '') { parse_str($query, $_GET); }

/** Same programmatic warning capture as bin/_smoke_render.php. */
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

register_shutdown_function(function () use ($script, $query, $role, $outfile) {
    $body = '';
    while (ob_get_level() > 0) { $body .= (string) ob_get_clean(); }

    $last = error_get_last();
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $error = '';
    if ($last !== null && in_array($last['type'], $fatal_types, true)) {
        $error = $last['message'] . ' @ ' . $last['file'] . ':' . $last['line'];
    }
    $decoded = json_decode($body, true);

    $result = [
        'script' => $script,
        'query'  => $query,
        'role'   => $role,
        'status' => $error === '' ? 'ok' : 'fatal',
        'error'  => $error,
        // http_response_code() answers 200 when the endpoint never set one.
        'code'   => (int) (http_response_code() ?: 200),
        'json'   => is_array($decoded) ? $decoded : null,
        'body'   => mb_strcut($body, 0, 4000, 'UTF-8'),
        'php_errors' => array_values(array_unique($GLOBALS['SMOKE_PHP_ERRORS'] ?? [])),
    ];
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $result['body'] = '';
        $json = json_encode($result);
    }
    file_put_contents($outfile, $json);
});

if (is_file($SMOKE_ROOT . '/vendor/autoload.php')) {
    require_once $SMOKE_ROOT . '/vendor/autoload.php';
}
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// The same forged session the route renderer uses: the role under test, on a
// signed-in user, so requireApiLogin() passes and the endpoint's own check is
// what decides.
$_SESSION['user_id']       = 1;
$_SESSION['user_role']     = $role;
$_SESSION['username']      = 'smoke';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token']    = 'smoke-token';

ob_start();
require $file;
