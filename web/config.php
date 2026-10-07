<?php
// ============================================================================
// System configuration — NO STATIC SETTINGS IN CODE.
// Secrets and infrastructure: /etc/ai-pbx.env (640 root:asterisk)
// Business/branding settings: DB `sys_settings` (getSystemSetting)
// ============================================================================

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Reads /etc/ai-pbx.env (key=value lines, # comments).
 * Apache SetEnv (getenv) values take precedence.
 */
function loadPortalEnv($path = null) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if ($path === null) {
        $path = '/etc/ai-pbx.env';
    }
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $cache[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $cache;
}

/** Reads an environment value: getenv → env file → fallback (if any) */
function portalEnv($key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === '') {
        $env = loadPortalEnv();
        $v = $env[$key] ?? null;
    }
    return ($v === null || $v === '') ? $default : $v;
}

define('DB_HOST', portalEnv('DB_HOST', 'localhost'));
define('DB_NAME', portalEnv('DB_NAME', 'asterisk'));
define('DB_USER', portalEnv('DB_USER'));          // secret: NO fallback
define('DB_PASS', portalEnv('DB_PASS'));          // secret: NO fallback

define('SITE_NAME', portalEnv('SITE_NAME', 'AI PBX Portal'));
define('FAX_STORAGE_PATH', portalEnv('FAX_STORAGE_PATH', '/var/www/faxes'));
define('MONITOR_STORAGE_PATH', portalEnv('MONITOR_STORAGE_PATH', '/var/spool/asterisk/monitor'));
define('FAX_OUTGOING_SPOOL', portalEnv('FAX_OUTGOING_SPOOL', '/var/spool/asterisk/fax/outgoing'));
define('SOUNDS_CUSTOM_DIR', portalEnv('SOUNDS_CUSTOM_DIR', '/var/lib/asterisk/sounds/custom'));
define('PJSIP_DTLS_CERT', portalEnv('PJSIP_DTLS_CERT', '/etc/asterisk/keys/asterisk.pem'));
define('ASTERISK_PBX_DIR', portalEnv('ASTERISK_PBX_DIR', '/etc/asterisk/pbx'));
// Asterisk's own config files (rtp.conf, udptl.conf, voicemail.conf, asterisk.conf); tests redirect it.
define('ASTERISK_CONF_DIR', portalEnv('ASTERISK_CONF_DIR', '/etc/asterisk'));
define('MOH_BASE_DIR', portalEnv('MOH_BASE_DIR', '/var/lib/asterisk/moh'));
define('ASTERISK_CALL_SPOOL', portalEnv('ASTERISK_CALL_SPOOL', '/var/spool/asterisk/outgoing'));
define('SYNC_QUEUE_LOGS_SCRIPT', portalEnv('SYNC_QUEUE_LOGS_SCRIPT', '/usr/local/bin/sync_queue_logs.php'));
define('GS_BINARY', portalEnv('GS_BINARY', '/usr/bin/gs'));

// Centralized Asterisk AMI credentials (secret: NO fallback)
define('AMI_HOST', portalEnv('AMI_HOST', '127.0.0.1'));
define('AMI_PORT', portalEnv('AMI_PORT', '5038'));
define('AMI_USER', portalEnv('AMI_USER'));
define('AMI_PASS', portalEnv('AMI_PASS'));

// Dynamic System Timezone Configuration
date_default_timezone_set(portalEnv('TIMEZONE', 'Europe/Istanbul'));

// WebRTC TURN server (coturn) — used to issue REST-API style time-limited
// credentials (secret: NO fallback). Only TURNS (TLS/TCP) is used — plain
// STUN/TURN is filtered by protocol signature at the network edge
// (verified with an external test, 2026-08-20).
define('TURN_SECRET', portalEnv('TURN_SECRET'));
define('CHAT_JWT_SECRET', portalEnv('CHAT_JWT_SECRET'));
define('TURN_HOST', portalEnv('TURN_HOST', 'pbx.example.com'));
define('TURNS_PORT', portalEnv('TURNS_PORT', '443'));

// Hardened Session Configuration for Public Security
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Themed fatal-error page (follows the design of the auth.php 403 page). The
 * real technical detail (e.g. the PDOException message) always goes to
 * error_log() — during the DB outage of 2026-08-19,
 * `die('Veritabanı bağlantı hatası!')` silently swallowed the real cause (the
 * env file was unreadable) without logging it anywhere, and diagnosing it took
 * live CLI debugging. The raw exception message is never shown to the user
 * (risk of leaking server paths/credentials). API requests (under `/api/` or
 * with `Accept: application/json`) get JSON so fetch().then(res=>res.json())
 * does not blow up silently.
 */
function renderFatalErrorPage($title, $message, $logDetail = '', $httpCode = 503) {
    if ($logDetail !== '') {
        error_log('[AI PBX Fatal] ' . $logDetail);
    }
    http_response_code($httpCode);

    $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0)
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getUserLanguage()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($title); ?></title>
<link rel="stylesheet" href="/assets/css/variables.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/fontawesome.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="auth-body" data-theme="light">
<div class="auth-card" style="text-align:center; max-width:460px;">
    <div style="font-size:44px; color:var(--danger); margin-bottom:16px;">
        <i class="fas fa-plug-circle-xmark"></i>
    </div>
    <h2 style="margin:0 0 12px; color:var(--text-main); font-size:19px;"><?php echo htmlspecialchars($title); ?></h2>
    <p style="color:var(--text-muted); font-size:14px; line-height:1.6; margin:0 0 24px;"><?php echo htmlspecialchars($message); ?></p>
    <button onclick="location.reload()" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; margin:0 auto;">
        <i class="fas fa-rotate-right"></i> <?php echo htmlspecialchars(t('error_page.retry')); ?>
    </button>
    <p style="color:var(--text-muted); font-size:11px; margin-top:20px;"><?php echo htmlspecialchars(t('error_page.time')); ?>: <?php echo date('d.m.Y H:i:s'); ?></p>
</div>
</body>
</html>
<?php
    exit;
}

/**
 * Also sends PHP fatal errors (the ones not caught by try/catch) to this page
 * — with display_errors=Off the user used to get a blank white page and the
 * error showed up nowhere (log_errors=On, but nothing called error_log).
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            renderFatalErrorPage(
                t('error_page.unexpected_title'),
                t('error_page.unexpected_text'),
                $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']
            );
        }
    }
});

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            if (file_exists('/var/lib/mysql/mysql.sock')) {
                $dsn = 'mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=' . DB_NAME . ';charset=utf8mb4';
            }
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            renderFatalErrorPage(
                t('error_page.db_title'),
                t('error_page.db_text'),
                'DB connection failed: ' . $e->getMessage()
            );
        }
    }
    return $pdo;
}

// CSRF Protection Functions
function getCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Fetch System Setting Value with Fallback Default
// AiPBX logo used when no logo image is uploaded in Brand & Appearance / after a reset to defaults.
const BRAND_DEFAULT_LOGO_URL = '/assets/images/aipbx-logo.png';

// Installed AiPBX version: VERSION at the repo root (releases are tagged by
// bin/release.sh; updates by conf/sbin/aipbx-update). "dev" when the file is
// missing (development checkout etc.).
define('AIPBX_VERSION', (function (): string {
    $f = dirname(__DIR__) . '/VERSION';
    $v = is_readable($f) ? trim((string) file_get_contents($f)) : '';
    return preg_match('/^\d+\.\d+\.\d+/', $v) ? $v : 'dev';
})());

function getSystemSetting($key, $default = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM sys_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null && $val !== '') ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Injects the custom main colours (--primary/--secondary) set on the Brand &
 * Appearance page (src/brand_settings.php). A bare `:root` selector is used —
 * it has the SAME specificity (0,1,0) as both `:root,[data-theme="dark"]` and
 * `[data-theme="light"]` in variables.css but loads LATER, so by source order
 * this override wins in both themes (no extra [data-theme] condition needed).
 * Every page that prints the HTML shell (header.php, login.php,
 * force_reset.php, reset_password.php) must call it AFTER the variables.css link.
 */
function renderBrandColorOverrideCSS() {
    $primary = getSystemSetting('brand_color_primary', '');
    $secondary = getSystemSetting('brand_color_secondary', '');
    if ($primary === '' && $secondary === '') return;
    echo '<style>:root{';
    if ($primary !== '') echo '--primary:' . htmlspecialchars($primary) . ';';
    if ($secondary !== '') echo '--secondary:' . htmlspecialchars($secondary) . ';';
    echo '}</style>' . "\n";
}

// Prompt language code -> its name in that language (same in every UI
// language). A code not listed here (a new package) is shown as the raw code.
const LANGUAGE_LABELS = [
    'en' => 'English (en)',
    'en_US' => 'English - US (en_US)',
    'en_AU' => 'English - Australia (en_AU)',
    'en_GB' => 'English - UK (en_GB)',
    'en_NZ' => 'English - New Zealand (en_NZ)',
    'tr' => 'Türkçe (tr)',
    'es' => 'Español (es)',
    'fr' => 'Français (fr)',
    'de' => 'Deutsch (de)',
    'it' => 'Italiano (it)',
    'ja' => '日本語 (ja)',
    'ru' => 'Русский (ru)',
    'sv' => 'Svenska (sv)',
    'pr' => 'Português (pr)',
    'pt_BR' => 'Português - Brasil (pt_BR)',
];

/**
 * Scans the Asterisk sound language folders really installed on the server
 * (/var/lib/asterisk/sounds/<code>). 'custom' (custom Turkish prompts) and
 * 'phonetic' (phonetic alphabet, not a real language) are excluded.
 * This list feeds the language dropdowns (PBX Settings / Inbound Route / IVR /
 * Queue) — a newly installed language pack becomes selectable automatically,
 * without touching the code.
 */
function getAvailableLanguages() {
    // Asterisk plays prompts from /usr/share/asterisk/sounds/<lang> (Debian's
    // English set lives only there); AiPBX's own and downloaded packs are in
    // /var/lib/asterisk/sounds/<lang> and linked into it. Only language-code
    // names count (en, tr, en_GB) — not custom/recordings/en_US_f_Allison.
    $langs = [];
    foreach (['/usr/share/asterisk/sounds', dirname(SOUNDS_CUSTOM_DIR)] as $base) {
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $code = basename($dir);
            // a link left for a language whose files were never installed does not count
            if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code) && glob($dir . '/*')) {
                $langs[$code] = true;
            }
        }
    }
    $langs = array_keys($langs);
    sort($langs);
    return $langs;
}

/**
 * Looks up the account a sign-in name refers to. The name may be a username
 * or an extension; when one user's username equals another user's
 * extension, the username match wins.
 */
function findLoginUser(string $login, string $columns): array|false
{
    $stmt = getDB()->prepare("SELECT {$columns} FROM sys_users WHERE username = ? OR extension = ? ORDER BY (username = ?) DESC, id ASC LIMIT 1");
    $stmt->execute([$login, $login, $login]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Checks a sign-in password. The exact input is tried first, then the
 * trimmed form: self-service password changes store the password as typed,
 * while admin resets used to trim it, and logins used to trim always — so a
 * password with leading/trailing spaces could never sign in.
 */
function verifyLoginPassword(string $input, string $hash): bool
{
    if (password_verify($input, $hash)) {
        return true;
    }
    $trimmed = trim($input);
    return $trimmed !== $input && password_verify($trimmed, $hash);
}

// Rate Limiting & Brute Force Tracker
function checkBruteForceLockout($ip, $username) {
    $db = getDB();

    // Per-IP lockout: catches classic brute force from one source; the
    // threshold can stay low because it only affects that IP.
    $stmt = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE ip_address = ? AND status = 'FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() >= 5) return true;

    // Per-username lockout: exists to catch a distributed attack from many
    // IPs, but since it is COMPLETELY independent of the IP, a low threshold
    // (like 5) would let anyone lock a known username PERMANENTLY at will with
    // 5 wrong attempts in a row, without any authentication (the same risk
    // applies to a shared IP behind a campus NAT) — so a much higher threshold
    // is used (found in the 2026-08-21 audit).
    $stmt = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE username = ? AND status = 'FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$username]);
    return $stmt->fetchColumn() >= 20;
}

function logLoginAttempt($ip, $username, $status) {
    $db = getDB();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $db->prepare("INSERT INTO sys_login_logs (ip_address, username, status, user_agent) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ip, $username, $status, $ua]);
    
    // Log failures to Fail2ban logfile
    if ($status === 'FAILED') {
        // $username comes from $_POST and is only trim()med — if it contains
        // \r\n, fake extra lines could be injected into the log file
        // (misleading fail2ban/analysis tools), so it is cleaned before being
        // written to the log line.
        $safe_username = preg_replace('/[\r\n]+/', ' ', $username);
        // The timestamp is written with its timezone offset: the portal
        // TIMEZONE and the server's system timezone may differ, and fail2ban
        // would take an offset-less time for system time and ignore the lines
        // as being "in the future". install.sh makes the directory writable
        // for www-data (/etc/fail2ban/jail.d/aipbx-web.local watches this file).
        $log_line = sprintf("%s - [%s] FAILED_LOGIN user=%s\n", $ip, date(DATE_ATOM), $safe_username);
        @file_put_contents('/var/log/aipbx/web_login_failures.log', $log_line, FILE_APPEND);
    }
}

// Unified Notification Helper Functions for Session-based Flash Alerts
function notify($message, $type = 'info') {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (!isset($_SESSION['sys_notifications']) || !is_array($_SESSION['sys_notifications'])) {
        $_SESSION['sys_notifications'] = [];
    }
    $_SESSION['sys_notifications'][] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => time()
    ];
}

function getFlashNotifications() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $list = $_SESSION['sys_notifications'] ?? [];
    unset($_SESSION['sys_notifications']);
    return $list;
}

/**
 * Convert Turkish and UTF-8 characters to clean 7-bit ASCII representation
 * for Asterisk configuration files and CLI commands.
 */
/**
 * Cache-friendly asset (CSS/JS) URL: ?v=<mtime> changes only when the file
 * does. Templates used to use ?v=time() — the version changed on every
 * request, so the browser could NEVER cache the CSS/JS files and downloaded
 * them again on every page.
 */
function asset(string $path): string {
    static $cache = [];
    if (!isset($cache[$path])) {
        $file = __DIR__ . '/' . ltrim($path, '/');
        $cache[$path] = $path . (is_file($file) ? '?v=' . filemtime($file) : '');
    }
    return $cache[$path];
}

function toCleanAscii($str) {
    if ($str === null || $str === '') return '';

    $search  = ['ç', 'Ç', 'ğ', 'Ğ', 'ı', 'İ', 'ö', 'Ö', 'ş', 'Ş', 'ü', 'Ü'];
    $replace = ['c', 'C', 'g', 'G', 'i', 'I', 'o', 'O', 's', 'S', 'u', 'U'];

    $str = str_replace($search, $replace, (string)$str);
    $str = preg_replace('/[^\x20-\x7E]/', '', $str);

    return trim($str);
}

/**
 * Whitelist a routing destination type (inbound route / IVR / time condition
 * destination type) against the fixed set buildDestinationLines() understands.
 * Anything else is interpolated raw into generated Asterisk dialplan
 * NoOp/comment lines by the sync layer, so an unvalidated value here is a
 * config-injection vector.
 */
function sanitizeDestType($type) {
    $valid = ['queue', 'ivr', 'time_condition', 'extension', 'fax', 'announcement', 'hangup', 'ring_group', 'conference', 'voicemail', 'outbound_route'];
    $type = trim((string)$type);
    return in_array($type, $valid, true) ? $type : 'hangup';
}

/**
 * Multi-language (i18n) system of the web interface. This is a layer
 * COMPLETELY SEPARATE from Asterisk's SPOKEN prompt language
 * (pbx_dids/pbx_ivrs/pbx_queues.language, getAvailableLanguages()) — one
 * controls the audio in a phone call, this one the texts in the web panel.
 */
const UI_LANGUAGES = ['en' => 'English', 'tr' => 'Türkçe'];

// The Android app on Google Play (login page, My Phone, mobile sign-in links).
const ANDROID_PLAY_URL = 'https://play.google.com/store/apps/details?id=com.mhrgl.AiPBX';
// Language of a visitor with no preference yet (login page, new users).
const DEFAULT_UI_LANGUAGE = 'en';

function getUserLanguage() {
    // Session-less requests (the mobile API) set the language of the request.
    if (isset($GLOBALS['AIPBX_REQUEST_LANGUAGE'], UI_LANGUAGES[$GLOBALS['AIPBX_REQUEST_LANGUAGE']])) {
        return $GLOBALS['AIPBX_REQUEST_LANGUAGE'];
    }
    if (isset($_SESSION['ui_language']) && isset(UI_LANGUAGES[$_SESSION['ui_language']])) {
        return $_SESSION['ui_language'];
    }
    return DEFAULT_UI_LANGUAGE;
}

/**
 * Returns the translation key in the active language. When no translation is
 * found (a missing key or a page never translated) the key itself (or the
 * given $default) is shown instead of silently showing nothing — missing
 * translations stay visible.
 */
function t($key, $default = null) {
    static $translations = [];
    $lang = getUserLanguage();
    if (!isset($translations[$lang])) {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        $translations[$lang] = is_file($file) ? require $file : [];
    }
    return $translations[$lang][$key] ?? ($default ?? $key);
}


/**
 * Built-in roles (seed.sql, is_system = 1) are shown in the interface
 * language: their name/description come from roles.system.<key>[_desc].
 * Roles an admin creates keep the name typed for them.
 */
const SYSTEM_ROLE_KEYS = ['admin', 'read_only_admin', 'cc_agent', 'fax_user', 'cc_manager', 'user'];

function localizeRole(array $row): array {
    $key = $row['role_key'] ?? ($row['role'] ?? null);
    if ($key === null || !in_array($key, SYSTEM_ROLE_KEYS, true)) {
        return $row;
    }
    if (array_key_exists('role_name', $row)) {
        $row['role_name'] = t('roles.system.' . $key, (string) $row['role_name']);
    }
    if (array_key_exists('description', $row)) {
        $row['description'] = t('roles.system.' . $key . '_desc', (string) $row['description']);
    }
    return $row;
}

function localizeRoles(array $rows): array {
    return array_map('localizeRole', $rows);
}

/**
 * Translations for the page scripts: every "js.*" key of the active language
 * as window.I18N, and window.__(key, ...args) filling %s placeholders in order.
 * Printed in the <head> of every layout (app and auth pages), before any
 * page script runs.
 */
function jsI18nScript(): string {
    static $lang = [];
    $code = getUserLanguage();
    if (!isset($lang[$code])) {
        $all = require __DIR__ . '/lang/' . $code . '.php';
        $lang[$code] = array_filter($all, fn($k) => str_starts_with($k, 'js.'), ARRAY_FILTER_USE_KEY);
    }
    return '<script>window.I18N = ' . json_encode((object) $lang[$code], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
        . 'window.__ = function (k) { var s = (window.I18N && window.I18N[k]) || k, a = arguments, i = 1;'
        . ' return String(s).replace(/%(?:(\\d+)\\$)?s/g, function (m, n) { var j = n ? +n : i++; return j < a.length ? a[j] : ""; }); };</script>';
}

// Audit log (writeAuditLog) — needed by services called from API endpoints too.
require_once __DIR__ . '/src/audit_log.php';

// UI component helpers moved to their own file (2026-08-31) — see src/ui_helpers.php.
// Required from here so they keep working everywhere config.php is loaded.
require_once __DIR__ . '/src/ui_helpers.php';
