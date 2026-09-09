<?php
// ============================================================================
// System Configuration — KODDA STATİK AYAR YOKTUR.
// Sırlar ve altyapı ayarları: /etc/ai-pbx.env (640 root:asterisk)
// İş/branding ayarları:        DB `sys_settings` (getSystemSetting)
// ============================================================================

/**
 * /etc/ai-pbx.env dosyasını okur (anahtar=değer satırları, # yorum).
 * Apache SetEnv (getenv) değerleri önceliklidir.
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

/** Ortam değerini okur: getenv → env dosyası → (varsa) fallback */
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
define('DB_USER', portalEnv('DB_USER'));          // sır: fallback YOK
define('DB_PASS', portalEnv('DB_PASS'));          // sır: fallback YOK

define('SITE_NAME', portalEnv('SITE_NAME', 'AI PBX Portalı'));
define('FAX_STORAGE_PATH', portalEnv('FAX_STORAGE_PATH', '/var/www/faxes'));
define('MONITOR_STORAGE_PATH', portalEnv('MONITOR_STORAGE_PATH', '/var/spool/asterisk/monitor'));
define('FAX_OUTGOING_SPOOL', portalEnv('FAX_OUTGOING_SPOOL', '/var/spool/asterisk/fax/outgoing'));
define('SOUNDS_CUSTOM_DIR', portalEnv('SOUNDS_CUSTOM_DIR', '/var/lib/asterisk/sounds/custom'));
define('PJSIP_DTLS_CERT', portalEnv('PJSIP_DTLS_CERT', '/etc/asterisk/keys/asterisk.pem'));
define('ASTERISK_PBX_DIR', portalEnv('ASTERISK_PBX_DIR', '/etc/asterisk/pbx'));
define('MOH_BASE_DIR', portalEnv('MOH_BASE_DIR', '/var/lib/asterisk/moh'));
define('ASTERISK_CALL_SPOOL', portalEnv('ASTERISK_CALL_SPOOL', '/var/spool/asterisk/outgoing'));
define('SYNC_QUEUE_LOGS_SCRIPT', portalEnv('SYNC_QUEUE_LOGS_SCRIPT', '/usr/local/bin/sync_queue_logs.php'));
define('GS_BINARY', portalEnv('GS_BINARY', '/usr/bin/gs'));

// Centralized Asterisk AMI Credentials (sır: fallback YOK)
define('AMI_HOST', portalEnv('AMI_HOST', '127.0.0.1'));
define('AMI_PORT', portalEnv('AMI_PORT', '5038'));
define('AMI_USER', portalEnv('AMI_USER'));
define('AMI_PASS', portalEnv('AMI_PASS'));

// Dynamic System Timezone Configuration
date_default_timezone_set(portalEnv('TIMEZONE', 'Europe/Istanbul'));

// WebRTC TURN sunucusu (coturn) — REST API tarzı zaman-sınırlı kimlik bilgisi
// üretimi için kullanılır (sır: fallback YOK). Sadece TURNS (TLS/TCP) kullanılıyor
// — düz STUN/TURN ağ kenar cihazında protokol imzasından filtreleniyor
// (dış test ile doğrulandı, 2026-08-20).
define('TURN_SECRET', portalEnv('TURN_SECRET'));
define('CHAT_JWT_SECRET', portalEnv('CHAT_JWT_SECRET'));
define('TURN_HOST', portalEnv('TURN_HOST', 'pbx.example.com'));
define('TURNS_PORT', portalEnv('TURNS_PORT', '5349'));

// Hardened Session Configuration for Public Security
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Themed fatal-error page (aynı auth.php 403 sayfasının tasarım diline uyar). Gerçek teknik
 * detayı (ör. PDOException mesajı) her zaman error_log()'a yazar — 2026-08-19'daki DB kesintisinde
 * `die('Veritabanı bağlantı hatası!')` gerçek nedeni (env dosyası okunamıyordu) hiçbir yere
 * loglamadan sessizce yutuyordu, teşhis canlı CLI hata ayıklaması gerektirdi. Kullanıcıya asla ham
 * exception mesajı gösterilmez (sunucu yolu/kimlik bilgisi sızdırma riski). API istekleri
 * (`/api/` altı veya `Accept: application/json`) JSON döner ki fetch().then(res=>res.json())
 * sessizce patlamasın.
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
<html lang="tr">
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
        <i class="fas fa-rotate-right"></i> Tekrar Dene
    </button>
    <p style="color:var(--text-muted); font-size:11px; margin-top:20px;">Hata zamanı: <?php echo date('d.m.Y H:i:s'); ?></p>
</div>
</body>
</html>
<?php
    exit;
}

/**
 * PHP fatal hatalarını (try/catch ile yakalanmayanlar) da bu sayfaya yönlendirir — önceden
 * display_errors=Off olduğu için kullanıcı boş beyaz sayfa görüyordu, hata hiçbir yerde
 * görünmüyordu (log_errors=On ama error_log çağıran kimse yoktu).
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            renderFatalErrorPage(
                'Beklenmeyen Bir Hata Oluştu',
                'Sistem beklenmeyen bir sorunla karşılaştı. Sorun devam ederse sistem yöneticisine bildirin.',
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
                'Veritabanı Bağlantısı Kurulamadı',
                'Sistem şu anda veritabanına bağlanamıyor. Bu genellikle geçicidir — lütfen birkaç dakika içinde tekrar deneyin. Sorun devam ederse sistem yöneticisine bildirin.',
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
 * Marka & Görünüm sayfasında (src/brand_settings.php) tanımlanan özel ana renkleri
 * (--primary/--secondary) enjekte eder. Bare `:root` seçicisi kullanılıyor —
 * variables.css'te hem `:root,[data-theme="dark"]` hem `[data-theme="light"]`
 * ile AYNI özgüllükte (0,1,0) ama daha SONRA yüklendiği için, kaynak sırası
 * gereği her iki temada da bu override kazanır (ekstra [data-theme] koşuluna
 * gerek yok). Tüm HTML kabuğu döken sayfalarda (header.php, login.php,
 * force_reset.php, reset_password.php) variables.css linkinden SONRA çağrılmalı.
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

// Dil kodu -> Türkçe okunabilir isim. Kapsamadığı bir kod (yeni bir paket) varsa
// getAvailableLanguages() zaten ham kodu (ör. "de") fallback olarak gösterir.
const LANGUAGE_LABELS = [
    'tr' => 'Türkçe',
    'en' => 'İngilizce (en)',
    'en_AU' => 'İngilizce - Avustralya (en_AU)',
    'en_GB' => 'İngilizce - İngiltere (en_GB)',
    'es' => 'İspanyolca (es)',
    'fr' => 'Fransızca (fr)',
    'de' => 'Almanca (de)',
    'it' => 'İtalyanca (it)',
    'ru' => 'Rusça (ru)',
    'pr' => 'Portekizce (pr)',
    'pt_BR' => 'Portekizce - Brezilya (pt_BR)',
];

/**
 * Sunucuda gerçekten kurulu Asterisk ses dili klasörlerini tarar (/var/lib/asterisk/sounds/<kod>).
 * 'custom' (özel Türkçe anonslar) ve 'phonetic' (fonetik alfabe, gerçek bir dil değil) hariç tutulur.
 * Dil Ayarları (Santral Ayarları / Gelen Rota / IVR / Kuyruk) dropdown'larını bu liste besler —
 * yeni bir dil paketi yüklenince koda dokunmadan otomatik seçilebilir hale gelir.
 */
function getAvailableLanguages() {
    $base = dirname(SOUNDS_CUSTOM_DIR);
    $exclude = ['custom', 'phonetic'];
    $langs = [];
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $code = basename($dir);
        if (in_array($code, $exclude, true)) continue;
        $langs[] = $code;
    }
    sort($langs);
    return $langs;
}

// Rate Limiting & Brute Force Tracker
function checkBruteForceLockout($ip, $username) {
    $db = getDB();

    // IP bazlı kilitleme: aynı kaynaktan gelen klasik brute-force'u yakalar,
    // eşik düşük tutulabilir çünkü sadece o IP'yi etkiler.
    $stmt = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE ip_address = ? AND status = 'FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() >= 5) return true;

    // Kullanıcı adı bazlı kilitleme: farklı IP'lerden dağıtık saldırıyı
    // yakalamak için var, ama IP'den TAMAMEN bağımsız olduğu için eşik düşük
    // tutulursa (5 gibi) herkes, bilinen bir kullanıcı adını hiçbir kimlik
    // doğrulaması gerektirmeden dilediği zaman art arda 5 yanlış denemeyle
    // KALICI OLARAK kilitleyebilir (kampüs NAT'ı arkasındaki paylaşılan bir IP
    // için de aynı risk geçerli) — bu yüzden çok daha yüksek bir eşik kullanılır
    // (2026-08-21 denetiminde bulundu).
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
        // $username $_POST'tan geliyor ve sadece trim() ediliyor — \r\n içerirse
        // log dosyasına sahte ek satır enjekte edilebilir (fail2ban/analiz araçlarını
        // yanıltabilir), bu yüzden log satırına yazmadan önce temizleniyor.
        $safe_username = preg_replace('/[\r\n]+/', ' ', $username);
        $log_line = sprintf("%s - [%s] FAILED_LOGIN user=%s\n", $ip, date('Y-m-d H:i:s'), $safe_username);
        @file_put_contents('/var/log/httpd/web_login_failures.log', $log_line, FILE_APPEND);
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
function toCleanAscii($str) {
    if ($str === null || $str === '') return '';

    $search  = ['ç', 'Ç', 'ğ', 'Ğ', 'ı', 'İ', 'ö', 'Ö', 'ş', 'Ş', 'ü', 'Ü'];
    $replace = ['c', 'C', 'g', 'G', 'i', 'I', 'o', 'O', 's', 'S', 'u', 'U'];

    $str = str_replace($search, $replace, (string)$str);
    $str = preg_replace('/[^\x20-\x7E]/', '', $str);

    return trim($str);
}

/**
 * Whitelist a routing destination type (Gelen Rota / IVR / Zaman Koşulu hedef türü)
 * against the fixed set buildDestinationLines() understands. Anything else is
 * interpolated raw into generated Asterisk dialplan NoOp/comment lines by the
 * sync layer, so an unvalidated value here is a config-injection vector.
 */
function sanitizeDestType($type) {
    $valid = ['queue', 'ivr', 'time_condition', 'extension', 'fax', 'announcement', 'hangup'];
    $type = trim((string)$type);
    return in_array($type, $valid, true) ? $type : 'hangup';
}

/**
 * Web arayüzü çoklu dil (i18n) sistemi. Bu, Asterisk'in SESLİ anons dilinden
 * (pbx_dids/pbx_ivrs/pbx_queues.language, getAvailableLanguages()) TAMAMEN
 * AYRI bir katmandır — biri telefon görüşmesindeki sesi, bu ise web
 * panelindeki metinleri kontrol eder.
 */
const UI_LANGUAGES = ['tr' => 'Türkçe', 'en' => 'English'];

function getUserLanguage() {
    if (isset($_SESSION['ui_language']) && isset(UI_LANGUAGES[$_SESSION['ui_language']])) {
        return $_SESSION['ui_language'];
    }
    return 'tr';
}

/**
 * Çeviri anahtarını aktif dile göre döner. Çeviri bulunamazsa (eksik anahtar
 * ya da hiç çevrilmemiş bir sayfa) sessizce boş göstermek yerine anahtarın
 * kendisi (ya da verilen $default) gösterilir — eksik çeviriler görünür kalır.
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


// UI bileşen yardımcıları ayrı dosyaya taşındı (2026-08-31) — bkz. src/ui_helpers.php.
// Buradan require ediliyor ki config.php'yi yükleyen her yerde eskisi gibi kullanılabilsin.
require_once __DIR__ . '/src/ui_helpers.php';
