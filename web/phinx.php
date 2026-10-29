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
 *
 * The PORT is read, not assumed. The compose deployment publishes MariaDB on
 * 127.0.0.1:${AIPBX_DB_PORT} and hands that value to every service as DB_PORT
 * (docker/README.md), so a fixed 3306 here meant `phinx migrate` connected to
 * nothing at all on any host that had to move the port. The same precedence as
 * config.php applies — environment, then env file — because a migration must
 * run against the server the portal is going to use, not a second guess at it.
 * The env file itself follows AIPBX_ENV_FILE when set (aipbx-init.sh writes it
 * to that path), so a deployment that relocates it still migrates.
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

/**
 * One setting: the environment first, then the env file, then $default.
 * Deployment variables are visible to the process (compose sets them) and are
 * also written into the env file; the environment wins, exactly like
 * portalEnv() in config.php.
 */
function phinxSetting(array $env, string $key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = $env[$key] ?? null;
    }
    return ($v === null || $v === '') ? $default : $v;
}

$env = phinxLoadEnv(getenv('AIPBX_ENV_FILE') ?: null);

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
            'host' => phinxSetting($env, 'DB_HOST', 'localhost'),
            'name' => phinxSetting($env, 'DB_NAME', 'asterisk'),
            'user' => phinxSetting($env, 'MIGRATOR_DB_USER'),
            'pass' => phinxSetting($env, 'MIGRATOR_DB_PASS'),
            // The deployment's port (DB_PORT), not MariaDB's default.
            'port' => (int) phinxSetting($env, 'DB_PORT', 3306),
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
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
