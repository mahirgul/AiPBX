<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExternalDbServiceTest extends TestCase
{
    private ?int $connectionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        getDB()->exec('DELETE FROM pbx_external_dbs');
    }

    protected function tearDown(): void
    {
        if ($this->connectionId !== null) {
            ExternalDbService::deleteConnection($this->connectionId);
        }
        parent::tearDown();
    }

    public function testValidationFailsOnInvalidDriverOrEmptyName(): void
    {
        $res = ExternalDbService::saveConnection([
            'name' => '',
            'driver' => 'oracle_unsupported',
            'host' => '',
            'database_name' => '',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('name', $res['errors']);
        $this->assertArrayHasKey('driver', $res['errors']);
        $this->assertArrayHasKey('database_name', $res['errors']);
    }

    public function testSaveAndRetrieveConnectionWithEncryptedPassword(): void
    {
        $save = ExternalDbService::saveConnection([
            'name' => 'CRM Database',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database_name' => 'crm_db',
            'username' => 'crm_reader',
            'password' => 'SuperSecret123',
            'timeout_sec' => 3,
            'is_active' => 1,
        ], 1);

        $this->assertTrue($save['ok']);
        $this->connectionId = (int)$save['id'];
        $this->assertGreaterThan(0, $this->connectionId);

        $row = ExternalDbRepository::findById($this->connectionId);
        $this->assertNotNull($row);
        $this->assertSame('CRM Database', $row['name']);
        $this->assertNotSame('SuperSecret123', $row['password_encrypted']);
        $this->assertStringStartsWith('sb1:', (string)$row['password_encrypted']);

        // Decryption test
        $decrypted = SecretBox::decrypt((string)$row['password_encrypted']);
        $this->assertSame('SuperSecret123', $decrypted);

        // Edit without changing password
        $edit = ExternalDbService::saveConnection([
            'id' => $this->connectionId,
            'name' => 'CRM Database v2',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database_name' => 'crm_db',
            'username' => 'crm_reader',
            'password' => '', // Blank should keep existing
        ]);
        $this->assertTrue($edit['ok']);

        $row2 = ExternalDbRepository::findById($this->connectionId);
        $this->assertSame('CRM Database v2', $row2['name']);
        $this->assertSame('SuperSecret123', SecretBox::decrypt((string)$row2['password_encrypted']));
    }

    public function testExecuteQueryGuardRejectsMutationsAndMultiStatements(): void
    {
        $save = ExternalDbService::saveConnection([
            'name' => 'Test MySQL',
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => 3306,
            'database_name' => getenv('DB_NAME') ?: 'asterisk_test',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
            'is_active' => 1,
        ]);
        $this->assertTrue($save['ok']);
        $this->connectionId = (int)$save['id'];

        // Mutation queries must throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only read-only SELECT or WITH queries are permitted');
        ExternalDbService::executeQuery($this->connectionId, 'DELETE FROM pbx_external_dbs WHERE 1=1');
    }

    public function testExecuteQueryGuardRejectsMultiStatements(): void
    {
        $save = ExternalDbService::saveConnection([
            'name' => 'Test MySQL 2',
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => 3306,
            'database_name' => getenv('DB_NAME') ?: 'asterisk_test',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
            'is_active' => 1,
        ]);
        $this->assertTrue($save['ok']);
        $this->connectionId = (int)$save['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple SQL statements in a single query are not allowed');
        ExternalDbService::executeQuery($this->connectionId, 'SELECT 1; DROP TABLE pbx_external_dbs;');
    }

    public function testTestConnectionWithMysql(): void
    {
        $res = ExternalDbService::testConnection([
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => 3306,
            'database_name' => getenv('DB_NAME') ?: 'asterisk_test',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
        ]);

        $this->assertTrue($res['ok'], 'Connection failed: ' . $res['message']);
        $this->assertStringContainsString('Connection successful', $res['message']);
        $this->assertGreaterThanOrEqual(0, $res['latency_ms']);
    }

    public function testExecuteSelectQuerySuccessfully(): void
    {
        $save = ExternalDbService::saveConnection([
            'name' => 'Test MySQL Query',
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => 3306,
            'database_name' => getenv('DB_NAME') ?: 'asterisk_test',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
            'is_active' => 1,
        ]);
        $this->assertTrue($save['ok']);
        $this->connectionId = (int)$save['id'];

        $rows = ExternalDbService::executeQuery($this->connectionId, "SELECT 123 AS test_col, '#vip' AS tag_col");
        $this->assertCount(1, $rows);
        $this->assertSame(123, (int)$rows[0]['test_col']);
        $this->assertSame('#vip', (string)$rows[0]['tag_col']);
    }
}
