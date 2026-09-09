<?php
/**
 * Phinx (DB migration) yapılandırması.
 * DB kimlik bilgileri /etc/ai-pbx.env'den okunur — burada tekrar
 * yazılmaz/saklanmaz. Uygulamanın çalışan (runtime) kullanıcısından
 * (DB_USER, sadece SELECT/INSERT/UPDATE/DELETE) BİLEREK FARKLI
 * bir kullanıcı (MIGRATOR_DB_USER, CREATE/ALTER/DROP dahil tüm
 * DDL yetkisi) kullanılıyor — çalışan uygulamanın hiçbir zaman şema
 * değiştirme yetkisi olmamalı, bu ayrım kasıtlı bir güvenlik sınırı.
 * Bu dosya web sunucusundan doğrudan erişime kapalıdır (bkz.
 * /etc/httpd/conf.d/routing.conf — /var/www/html/db Require all denied).
 */

function phinxLoadEnv($path = null) {
    $env = [];
    if ($path === null) {
        $path = '/etc/ai-pbx.env';
    }
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $env;
}

$env = phinxLoadEnv();

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment' => 'production',
        'production' => [
            'adapter' => 'mysql',
            'host' => $env['DB_HOST'] ?? 'localhost',
            'name' => $env['DB_NAME'] ?? 'asterisk',
            'user' => $env['MIGRATOR_DB_USER'] ?? '',
            'pass' => $env['MIGRATOR_DB_PASS'] ?? '',
            'port' => 3306,
            'charset' => 'utf8mb4',
        ],
        // İzole test veritabanı (bin/setup-test-db.sh kullanır).
        // Bilinçli olarak ORTAM DEĞİŞKENİNDEN okur, /etc/*.env'den değil:
        // test kimlik bilgilerinin üretim env dosyasında yeri yok.
        'testing' => [
            'adapter' => 'mysql',
            'host' => getenv('DB_HOST') ?: 'localhost',
            'name' => getenv('DB_NAME') ?: 'asterisk_test',
            'user' => getenv('DB_USER') ?: '',
            'pass' => getenv('DB_PASS') ?: '',
            'port' => 3306,
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
