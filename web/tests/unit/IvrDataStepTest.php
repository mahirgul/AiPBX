<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IvrDataStepTest extends TestCase
{
    private ?int $stepId = null;
    private ?int $dbId = null;

    protected function setUp(): void
    {
        parent::setUp();
        getDB()->exec('DELETE FROM pbx_ivr_data_steps');
        getDB()->exec('DELETE FROM pbx_external_dbs');
    }

    protected function tearDown(): void
    {
        if ($this->stepId !== null) {
            IvrDataStepService::deleteStep($this->stepId);
        }
        if ($this->dbId !== null) {
            ExternalDbService::deleteConnection($this->dbId);
        }
        parent::tearDown();
    }

    public function testValidationFailsOnEmptyNameOrInvalidSources(): void
    {
        $res = IvrDataStepService::saveStep([
            'name' => '',
            'input_source' => 'invalid',
            'data_source' => 'invalid',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('name', $res['errors']);
        $this->assertArrayHasKey('input_source', $res['errors']);
        $this->assertArrayHasKey('data_source', $res['errors']);
    }

    public function testDatabaseSourceRequiresExternalDbAndQuery(): void
    {
        $res = IvrDataStepService::saveStep([
            'name' => 'VIP Customer Check',
            'input_source' => 'caller_id',
            'data_source' => 'database',
            'external_db_id' => '',
            'query_sql' => '',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('external_db_id', $res['errors']);
        $this->assertArrayHasKey('query_sql', $res['errors']);
    }

    public function testHttpSourceRequiresValidUrl(): void
    {
        $res = IvrDataStepService::saveStep([
            'name' => 'CRM HTTP Lookup',
            'input_source' => 'dtmf',
            'data_source' => 'http',
            'http_url' => 'not-a-valid-url',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('http_url', $res['errors']);
    }

    public function testHttpSourceBlocksPrivateIpSsrfAtValidation(): void
    {
        $res = IvrDataStepService::saveStep([
            'name' => 'Internal Cloud Metadata Lookup',
            'input_source' => 'dtmf',
            'data_source' => 'http',
            'http_url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('http_url', $res['errors']);
    }

    public function testSaveAndRetrieveStep(): void
    {
        $save = IvrDataStepService::saveStep([
            'name' => 'Customer Balance',
            'channel_var_name' => 'CUST_BALANCE',
            'input_source' => 'dtmf',
            'data_source' => 'http',
            'prompt_file' => 'custom/enter_id',
            'max_digits' => 8,
            'input_timeout_sec' => 6,
            'http_url' => 'https://example.com/api/balance?id={input}',
            'http_method' => 'GET',
            'found_dest_type' => 'queue',
            'found_dest_id' => 'support',
            'not_found_dest_type' => 'ivr',
            'not_found_dest_id' => 'main_menu',
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => '',
            'is_active' => 1,
        ]);

        $this->assertTrue($save['ok']);
        $this->stepId = (int)$save['id'];
        $this->assertGreaterThan(0, $this->stepId);

        $row = IvrDataStepRepository::findById($this->stepId);
        $this->assertNotNull($row);
        $this->assertSame('Customer Balance', $row['name']);
        $this->assertSame('CUST_BALANCE', $row['channel_var_name']);
        $this->assertSame('dtmf', $row['input_source']);
        $this->assertSame('http', $row['data_source']);
        $this->assertSame('custom/enter_id', $row['prompt_file']);
        $this->assertSame(8, (int)$row['max_digits']);
        $this->assertSame(6, (int)$row['input_timeout_sec']);

        // Update with self-loop destination -> should sanitize to hangup
        $update = IvrDataStepService::saveStep([
            'id' => $this->stepId,
            'name' => 'Customer Balance',
            'channel_var_name' => 'CUST_BALANCE',
            'input_source' => 'dtmf',
            'data_source' => 'http',
            'http_url' => 'https://example.com/api/balance?id={input}',
            'http_method' => 'GET',
            'found_dest_type' => 'ivr_data',
            'found_dest_id' => (string)$this->stepId,
            'not_found_dest_type' => 'invalid_type',
            'not_found_dest_id' => 'xyz',
        ]);
        $this->assertTrue($update['ok']);
        $rowAfter = IvrDataStepRepository::findById($this->stepId);
        $this->assertSame('hangup', $rowAfter['found_dest_type']);
        $this->assertSame('', $rowAfter['found_dest_id']);
        $this->assertSame('hangup', $rowAfter['not_found_dest_type']);
    }

    public function testPerformLookupWithDatabase(): void
    {
        $testHost = (string)(getenv('DB_HOST') ?: '127.0.0.1');
        $testPort = (int)((string)(getenv('DB_PORT') ?: '3306'));
        $testDb = (string)(getenv('DB_NAME') ?: 'asterisk_test');
        $testUser = (string)(getenv('DB_USER') ?: 'root');
        $testPass = (string)(getenv('DB_PASS') ?: '');

        $dbSave = ExternalDbService::saveConnection([
            'name' => 'Testing DB',
            'driver' => 'mysql',
            'host' => $testHost,
            'port' => $testPort,
            'database_name' => $testDb,
            'username' => $testUser,
            'password' => $testPass,
            'timeout_sec' => 3,
            'is_active' => 1,
        ]);
        $this->assertTrue($dbSave['ok']);
        $this->dbId = (int)$dbSave['id'];

        // Step looking for caller_id
        $stepSave = IvrDataStepService::saveStep([
            'name' => 'Extension Query',
            'channel_var_name' => 'EXT_FOUND',
            'input_source' => 'caller_id',
            'data_source' => 'database',
            'external_db_id' => $this->dbId,
            'query_sql' => "SELECT 1 AS is_found WHERE :caller_id = '1001'",
            'is_active' => 1,
        ]);
        $this->assertTrue($stepSave['ok']);
        $this->stepId = (int)$stepSave['id'];

        // Test matching input
        $resFound = IvrDataStepService::performLookup($this->stepId, '', '1001');
        $this->assertSame('found', $resFound['status']);
        $this->assertSame('1', (string)$resFound['val']);

        // Test non-matching input
        $resNotFound = IvrDataStepService::performLookup($this->stepId, '', '9999');
        $this->assertSame('not_found', $resNotFound['status']);
    }

    public function testInactiveStepReturnsNotFound(): void
    {
        $stepSave = IvrDataStepService::saveStep([
            'name' => 'Inactive Step',
            'channel_var_name' => 'TEST_VAR',
            'input_source' => 'caller_id',
            'data_source' => 'http',
            'http_url' => 'https://example.com/check',
            'is_active' => 0,
        ]);
        $this->assertTrue($stepSave['ok']);
        $stepId = (int)$stepSave['id'];

        $res = IvrDataStepService::performLookup($stepId, '', '1001');
        $this->assertSame('not_found', $res['status']);

        IvrDataStepService::deleteStep($stepId);
    }

    public function testRepeatedLookupParametersWorkWithNativePrepares(): void
    {
        [$sql, $params] = IvrDataStepService::bindLookupParams(
            'SELECT :caller_id AS a, :caller_id AS b, :input AS c', '42', '05321234567');
        $this->assertSame('SELECT :p1_caller_id AS a, :p2_caller_id AS b, :p3_input AS c', $sql);

        // The same as an external connection: no emulation, so a repeated name would be HY093.
        $pdo = getDB();
        $old = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $this->assertSame(['a' => '05321234567', 'b' => '05321234567', 'c' => '42'], $st->fetch(PDO::FETCH_ASSOC));
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $old);
        }
    }
}
