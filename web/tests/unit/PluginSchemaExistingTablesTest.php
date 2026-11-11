<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A plugin calls applyPluginSchema() on every request and worker tick. The worker runs with
 * NoNewPrivileges and cannot use sudo: when the tables already exist, nothing may be delegated
 * (1.73.1–1.73.4 turned auto-dialer off after an update this way).
 */
final class PluginSchemaExistingTablesTest extends TestCase
{
    private string $dir;
    /** @var list<list<string>> */
    private array $calls = [];
    private string|false $dbName;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';
        require_once dirname(__DIR__, 2) . '/src/priv_helper.php';
        $this->dir = sys_get_temp_dir() . '/aipbx-schema-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        getDB()->exec('CREATE TABLE IF NOT EXISTS plg_schema_probe (id INT PRIMARY KEY)');
        $this->calls = [];
        PrivHelper::$fake = function (array $args): array {
            $this->calls[] = $args;
            return ['success' => true, 'output' => ''];
        };
        // Outside the test database name the broker path is taken, as in production.
        $this->dbName = getenv('DB_NAME');
        putenv('DB_NAME=asterisk');
        putenv('AIPBX_MIGRATOR_ENV_FILE=' . $this->dir . '/missing.env');
    }

    protected function tearDown(): void
    {
        PrivHelper::$fake = null;
        putenv($this->dbName === false ? 'DB_NAME' : 'DB_NAME=' . $this->dbName);
        putenv('AIPBX_MIGRATOR_ENV_FILE');
        getDB()->exec('DROP TABLE IF EXISTS plg_schema_probe');
        @unlink($this->dir . '/schema.sql');
        @rmdir($this->dir);
    }

    public function testExistingTablesNeedNoPrivilegeBroker(): void
    {
        file_put_contents($this->dir . '/schema.sql', 'CREATE TABLE IF NOT EXISTS plg_schema_probe (id INT PRIMARY KEY);');
        $this->assertSame(['plg_schema_probe'], PluginManager::applyPluginSchema('schema-probe', $this->dir));
        $this->assertSame([], $this->calls);
    }

    public function testMissingTableStillGoesThroughTheBroker(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root applies the schema itself');
        }
        file_put_contents($this->dir . '/schema.sql', 'CREATE TABLE IF NOT EXISTS plg_schema_absent (id INT PRIMARY KEY);');
        PluginManager::applyPluginSchema('schema-probe', $this->dir);
        $this->assertSame([['plugin-schema', 'apply', 'schema-probe']], $this->calls);
    }
}
