<?php
/**
 * Duman testi alt-süreci — TEK bir rotayı render edip sonucu JSON olarak yazar.
 * bin/smoke.php tarafından her rota/dil kombinasyonu için ayrı süreçte çağrılır.
 *
 * Neden ayrı süreç: yönlendiren controller'lar header()+exit çağırıyor; exit
 * tek süreçte koşan bir runner'ı öldürürdü. Fatal error'lar da böylece izole
 * olur. Sonuç register_shutdown_function ile yazıldığı için exit ve fatal
 * durumunda da rapor üretilir.
 *
 * GÜVENLİK: sadece render eder. POST, servis yazımı, config üretimi veya
 * Asterisk reload'u tetiklemez.
 *
 * Kullanım: php bin/_smoke_render.php <path> <lang> <role> <outfile>
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

chdir('/var/www/html');
$ROUTES = require '/var/www/html/src/routes.php';
if (!isset($ROUTES[$path]) || !is_array($ROUTES[$path])) {
    file_put_contents($outfile, json_encode([
        'path' => $path, 'status' => 'skip', 'error' => 'array-olmayan rota',
    ]));
    exit(0);
}
$route = $ROUTES[$path];

// index.php'nin dispatch ortamını birebir taklit et.
$_SERVER['REQUEST_URI']    = $path;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/index.php';
// RBAC ve aktif-sekme mantığı basename($_SERVER['PHP_SELF']) üzerinden çalışıyor.
$_SERVER['PHP_SELF']       = '/' . $route['module'];

/**
 * PHP uyarılarını ÇIKTIDAN DEĞİL, programatik olarak yakala.
 *
 * Neden: bu uygulama bilinçli olarak display_errors=Off ile çalışıyor
 * (bkz. config.php'deki fatal sayfa mekanizması), dolayısıyla Warning/Notice
 * asla HTML'e basılmıyor — üretilen çıktıyı grep'lemek prensip olarak
 * çalışmaz (2026-09-01'de kasıtlı kusur enjeksiyonu yakalanmayınca bulundu).
 * Handler false döndürüyor ki PHP'nin normal akışı bozulmasın.
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
        . str_replace('/var/www/html/', '', $file) . ':' . $line;
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
        // mb_strcut, substr'ın AKSİNE çok baytlı bir UTF-8 karakteri ortadan
        // bölmez. Düz substr kullanılırken sayfa 200 KB'ı aşınca kesme noktası
        // bir Türkçe karakterin ortasına denk geliyor, json_encode geçersiz
        // UTF-8 yüzünden false dönüyor ve dosyaya BOŞ string yazılıyordu —
        // orkestratör de bunu "alt-surec sonuc uretmedi" diye raporluyordu.
        // /system-users 208 KB'a ulaşınca gerçekten yaşandı (2026-09-01).
        'output'     => mb_strcut($html, 0, 200000, 'UTF-8'),
    ];

    $json = json_encode($sonuc);
    if ($json === false) {
        // SESSİZ BAŞARISIZLIK OLMASIN: kodlama hâlâ bozuksa çıktıyı at ama
        // sonucun kendisini mutlaka yaz, yoksa hata "sonuc uretmedi" gibi
        // görünür ve asıl sebep gizlenir.
        $sonuc['output'] = '';
        $sonuc['error']  = trim($sonuc['error'] . ' [cikti JSON\'a kodlanamadi: ' . json_last_error_msg() . ']');
        $json = json_encode($sonuc);
    }
    file_put_contents($outfile, $json);
});

require '/var/www/html/auth.php';
if (is_file('/var/www/html/vendor/autoload.php')) {
    require_once '/var/www/html/vendor/autoload.php';
}
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Anonim rotalar (giriş / şifre sıfırlama) oturumsuz render edilir.
$ANON = ['/login', '/force-reset', '/reset-password'];
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
