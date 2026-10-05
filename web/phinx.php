<?php
/**
 * Phinx (DB migration) configuration.
 * DB credentials are read from /etc/ai-pbx.env — not written/stored here
 * again. A user DELIBERATELY DIFFERENT from the application's runtime user
 * (DB_USER, only SELECT/INSERT/UPDATE/DELETE) is used (MIGRATOR_DB_USER, all
 * DDL privileges including CREATE/ALTER/DROP) — the running application must
 * never be able to change the schema; this split is a deliberate security
 * boundary. This file is closed to direct access from the web server (see
 * /etc/apache2/conf-available/aipbx-routing.conf — /var/www/html/db Require all denied).
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
        // Isolated test database (used by bin/setup-test-db.sh).
        // Deliberately read from the ENVIRONMENT, not from /etc/*.env:
        // test credentials have no place in the production env file.
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
