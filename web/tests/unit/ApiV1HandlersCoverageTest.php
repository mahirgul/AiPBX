<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/core/ApiResponse.php';
require_once dirname(__DIR__, 2) . '/src/core/ApiRequestContext.php';
require_once dirname(__DIR__, 2) . '/src/core/ResourceNormalizer.php';
require_once dirname(__DIR__, 2) . '/src/services/ApiTokenService.php';

final class ApiV1HandlersCoverageTest extends TestCase
{
    private PDO $db;
    private string $authToken = '';
    private int $tokenId = 0;
    /** @var list<int> */
    private array $createdUserIds = [];
    /** @var list<int> */
    private array $createdTrunkIds = [];
    /** @var list<int> */
    private array $createdConfIds = [];
    /** @var list<int> */
    private array $createdTcIds = [];
    /** @var list<int> */
    private array $createdTgIds = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->createdUserIds = [];
        $this->createdTrunkIds = [];
        $this->createdConfIds = [];
        $this->createdTcIds = [];
        $this->createdTgIds = [];

        // Create a root token with all scopes
        $rawToken = ApiTokenService::generateRawToken();
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db->prepare(
            "INSERT INTO sys_api_tokens (name, token_prefix, token_hash, scopes_json, allowed_ips, rate_limit_per_min, is_active, created_at)
             VALUES ('zz_cov_handlers_token', ?, ?, ?, '*', 1000, 1, NOW())"
        );
        $stmt->execute([substr($rawToken, 0, 13) . '…', $hash, json_encode(['*'])]);
        $this->tokenId = (int)$this->db->lastInsertId();
        $this->authToken = $rawToken;
    }

    protected function tearDown(): void
    {
        if ($this->tokenId > 0) {
            $this->db->exec("DELETE FROM sys_api_tokens WHERE id = {$this->tokenId}");
        }
        if (!empty($this->createdUserIds)) {
            $ids = implode(',', array_map('intval', $this->createdUserIds));
            $this->db->exec("DELETE FROM sys_users WHERE id IN ({$ids})");
        }
        if (!empty($this->createdTrunkIds)) {
            $ids = implode(',', array_map('intval', $this->createdTrunkIds));
            $this->db->exec("DELETE FROM pbx_trunks WHERE id IN ({$ids})");
        }
        if (!empty($this->createdConfIds)) {
            $ids = implode(',', array_map('intval', $this->createdConfIds));
            $this->db->exec("DELETE FROM pbx_conferences WHERE id IN ({$ids})");
        }
        if (!empty($this->createdTcIds)) {
            $ids = implode(',', array_map('intval', $this->createdTcIds));
            $this->db->exec("DELETE FROM pbx_time_conditions WHERE id IN ({$ids})");
        }
        if (!empty($this->createdTgIds)) {
            $ids = implode(',', array_map('intval', $this->createdTgIds));
            $this->db->exec("DELETE FROM pbx_time_groups WHERE id IN ({$ids})");
        }

        $this->db->exec("DELETE FROM sys_users WHERE username LIKE 'zz_cov_%' OR extension LIKE '79%'");
        $this->db->exec("DELETE FROM pbx_trunks WHERE trunk_name LIKE 'zz_cov_%'");
        $this->db->exec("DELETE FROM pbx_conferences WHERE room_number LIKE '79%' OR title LIKE 'zz_cov_%'");
        $this->db->exec("DELETE FROM pbx_time_conditions WHERE title LIKE 'zz_cov_%'");
        $this->db->exec("DELETE FROM pbx_time_groups WHERE title LIKE 'zz_cov_%'");
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @return array{status: int, json: array<string, mixed>|null, raw: string}
     */
    private function apiRequest(string $method, string $path, array $body = [], array $server = []): array
    {
        $bootstrapPath = dirname(__DIR__) . '/bootstrap.php';
        $controllerPath = dirname(__DIR__, 2) . '/src/controllers/ApiV1Controller.php';

        $hasBody = !empty($body) || in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $jsonPayload = $hasBody ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        $streamClass = '';
        if ($hasBody) {
            $streamClass = '
class MockPhpInputStream {
    public $context;
    private static string $data = ' . var_export((string)$jsonPayload, true) . ';
    private int $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        $this->pos = 0;
        return true;
    }
    public function stream_read($count) {
        $ret = substr(self::$data, $this->pos, $count);
        $this->pos += strlen($ret);
        return $ret;
    }
    public function stream_eof() { return $this->pos >= strlen(self::$data); }
    public function stream_stat() { return []; }
}
stream_wrapper_unregister("php");
stream_wrapper_register("php", MockPhpInputStream::class);
';
        }

        $remoteAddr = $server['REMOTE_ADDR'] ?? '127.0.0.1';
        $authHeader = $server['HTTP_AUTHORIZATION'] ?? ('Bearer ' . $this->authToken);

        $code = "<?php
{$streamClass}
register_shutdown_function(function() {
    \$code = http_response_code();
    echo \"\\n__STATUS__:\" . (\$code !== false && \$code > 0 ? \$code : 200);
});
require " . var_export($bootstrapPath, true) . ";
require_once " . var_export($controllerPath, true) . ";

\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";
\$_SERVER['REMOTE_ADDR'] = " . var_export((string)$remoteAddr, true) . ";
\$_SERVER['HTTP_AUTHORIZATION'] = " . var_export((string)$authHeader, true) . ";

ApiV1Controller::serve(" . var_export($path, true) . ");
";

        $pipes = [];
        $proc = proc_open('php', [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            $this->fail('Failed to spawn PHP sub-process for API test.');
        }

        fwrite($pipes[0], $code);
        fclose($pipes[0]);

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $status = 0;
        $bodyRaw = $stdout;
        if (preg_match("/\n__STATUS__:(\d+)$/", $stdout, $m)) {
            $status = (int)$m[1];
            $bodyRaw = substr($stdout, 0, -strlen($m[0]));
        }

        $decoded = json_decode(trim($bodyRaw), true);
        return [
            'status' => $status,
            'json'   => is_array($decoded) ? $decoded : null,
            'raw'    => $bodyRaw,
        ];
    }

    // -------------------------------------------------------------------------
    // OpenAPI Specification and CORS Tests
    // -------------------------------------------------------------------------

    public function testOpenApiSpecificationServed(): void
    {
        $res = $this->apiRequest('GET', '/api/v1/openapi.json');
        $this->assertSame(200, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertStringStartsWith('3.', (string)($res['json']['openapi'] ?? ''));
        $this->assertArrayHasKey('info', $res['json']);
        $this->assertArrayHasKey('paths', $res['json']);
        $this->assertArrayHasKey('/extensions', $res['json']['paths']);
    }

    public function testCorsPreflightReturns204NoContent(): void
    {
        $res = $this->apiRequest('OPTIONS', '/api/v1/extensions');
        $this->assertSame(204, $res['status']);
    }

    // -------------------------------------------------------------------------
    // System Domain Tests
    // -------------------------------------------------------------------------

    public function testSystemInfoAndHealthEndpoints(): void
    {
        $resInfo = $this->apiRequest('GET', '/api/v1/system/info');
        $this->assertSame(200, $resInfo['status']);
        $this->assertTrue($resInfo['json']['success']);
        $this->assertArrayHasKey('version', $resInfo['json']['data']);
        $this->assertArrayHasKey('php_version', $resInfo['json']['data']);
        $this->assertArrayHasKey('server_time', $resInfo['json']['data']);

        $resHealth = $this->apiRequest('GET', '/api/v1/system/health');
        $this->assertSame(200, $resHealth['status']);
        $this->assertTrue($resHealth['json']['success']);
        $this->assertArrayHasKey('summary', $resHealth['json']['data']);
        $this->assertArrayHasKey('checks', $resHealth['json']['data']);

        // The test database of CI starts empty: set the value the answer must contain.
        $oldIp = getSystemSetting('pjsip_external_ip', null);
        setSystemSetting('pjsip_external_ip', '203.0.113.5');
        try {
            $resSettings = $this->apiRequest('GET', '/api/v1/system/settings');
        } finally {
            if ($oldIp === null) {
                getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'pjsip_external_ip'");
            } else {
                setSystemSetting('pjsip_external_ip', (string) $oldIp);
            }
        }
        $this->assertSame(200, $resSettings['status']);
        $this->assertTrue($resSettings['json']['success']);
        $this->assertIsArray($resSettings['json']['data']);
        $this->assertSame('203.0.113.5', $resSettings['json']['data']['pjsip_external_ip'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Extensions Domain: CRUD, 422 Validation & Secrets Normalization
    // -------------------------------------------------------------------------

    public function testExtensionsPostValidationFailsWith422ForBadInput(): void
    {
        // Missing required extension / username
        $res = $this->apiRequest('POST', '/api/v1/extensions', [
            'full_name' => 'Incomplete Extension',
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('VALIDATION_ERROR', $res['json']['error']['code']);
    }

    public function testExtensionsPostCreatesResourceAndNeverReturnsSecrets(): void
    {
        $payload = [
            'extension'              => '7971',
            'username'               => 'zz_cov_7971',
            'full_name'              => 'Coverage User 7971',
            'email'                  => 'zz_cov_7971@example.com',
            'password'               => 'StrongPass7971!',
            'sip_password'           => 'SecretSipPass7971!',
            'voicemail_pin'          => '9876',
            'voicemail_enabled'      => true,
            'voicemail_email_notify' => true,
        ];

        $res = $this->apiRequest('POST', '/api/v1/extensions', $payload);
        $this->assertSame(201, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertTrue($res['json']['success']);

        $data = $res['json']['data'];
        $this->assertIsArray($data);
        $this->assertSame('7971', $data['extension']);
        $this->assertSame('Coverage User 7971', $data['full_name']);
        $this->assertGreaterThan(0, (int)$data['id']);
        $this->createdUserIds[] = (int)$data['id'];

        // Secrets must NEVER be leaked in API response
        $this->assertArrayNotHasKey('sip_password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertArrayNotHasKey('voicemail_pin', $data);
        $this->assertArrayNotHasKey('secret_encrypted', $data);
        $this->assertArrayNotHasKey('auth_password', $data);
        $this->assertArrayNotHasKey('two_factor_secret', $data);
    }

    public function testExtensionsGetListAndGetSingleStripSecrets(): void
    {
        // Insert directly with secrets in DB
        $stmt = $this->db->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type,
                                    sip_password, voicemail_pin, two_factor_secret, is_active)
             VALUES (?, ?, ?, ?, 'user', '7972', 'sip', ?, ?, ?, 1)"
        );
        $stmt->execute([
            'zz_cov_7972',
            password_hash('Hash7972!', PASSWORD_DEFAULT),
            'Coverage User 7972',
            'zz_cov_7972@example.com',
            'PlainSipPassword123!',
            '5678',
            'TOTPSECRETKEY12345',
        ]);
        $insertedId = (int)$this->db->lastInsertId();
        $this->createdUserIds[] = $insertedId;

        // 1. GET list
        $resList = $this->apiRequest('GET', '/api/v1/extensions');
        $this->assertSame(200, $resList['status']);
        $this->assertNotNull($resList['json']);
        $this->assertTrue($resList['json']['success']);
        $this->assertIsArray($resList['json']['data']);

        $found = null;
        foreach ($resList['json']['data'] as $item) {
            if ($item['extension'] === '7972') {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'Extension 7972 must be in list');
        $this->assertSame('Coverage User 7972', $found['full_name']);
        $this->assertArrayNotHasKey('sip_password', $found);
        $this->assertArrayNotHasKey('password_hash', $found);
        $this->assertArrayNotHasKey('voicemail_pin', $found);
        $this->assertArrayNotHasKey('two_factor_secret', $found);

        // 2. GET single
        $resSingle = $this->apiRequest('GET', '/api/v1/extensions/7972');
        $this->assertSame(200, $resSingle['status']);
        $singleData = $resSingle['json']['data'];
        $this->assertSame('7972', $singleData['extension']);
        $this->assertArrayNotHasKey('sip_password', $singleData);
        $this->assertArrayNotHasKey('password_hash', $singleData);
        $this->assertArrayNotHasKey('voicemail_pin', $singleData);
        $this->assertArrayNotHasKey('two_factor_secret', $singleData);
    }

    public function testExtensionsPutUpdatesResourceAndReturnsSanitizedData(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active)
             VALUES ('zz_cov_7973', 'hash', 'Original Name', 'orig@example.com', 'user', '7973', 'sip', 'SecretPass7973!', 1)"
        );
        $stmt->execute();
        $id = (int)$this->db->lastInsertId();
        $this->createdUserIds[] = $id;

        $resPut = $this->apiRequest('PUT', '/api/v1/extensions/7973', [
            'full_name' => 'Updated Full Name 7973',
        ]);

        $this->assertSame(200, $resPut['status']);
        $this->assertTrue($resPut['json']['success']);
        $this->assertSame('Updated Full Name 7973', $resPut['json']['data']['full_name']);
        $this->assertArrayNotHasKey('password_hash', $resPut['json']['data']);
        $this->assertArrayNotHasKey('sip_password', $resPut['json']['data']);

        // Check DB updated
        $fresh = $this->db->query("SELECT full_name FROM sys_users WHERE id = {$id}")->fetchColumn();
        $this->assertSame('Updated Full Name 7973', $fresh);
    }

    public function testExtensionsDeleteRemovesResource(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active)
             VALUES ('zz_cov_7974', 'hash', 'To Delete', 'user', '7974', 'sip', 1)"
        );
        $stmt->execute();
        $id = (int)$this->db->lastInsertId();
        $this->createdUserIds[] = $id;

        $resDel = $this->apiRequest('DELETE', '/api/v1/extensions/7974');
        $this->assertSame(200, $resDel['status']);
        $this->assertTrue($resDel['json']['success']);

        // Subsequent GET returns 404
        $resGet = $this->apiRequest('GET', '/api/v1/extensions/7974');
        $this->assertSame(404, $resGet['status']);
        $this->assertSame('NOT_FOUND', $resGet['json']['error']['code']);
    }

    // -------------------------------------------------------------------------
    // Trunks Domain: Validation, Secrets Normalization & Toggle
    // -------------------------------------------------------------------------

    public function testTrunksPostValidationFailsWith422ForBadInput(): void
    {
        $res = $this->apiRequest('POST', '/api/v1/trunks', [
            'trunk_name' => '',
        ]);
        $this->assertSame(422, $res['status']);
        $this->assertSame('VALIDATION_ERROR', $res['json']['error']['code']);
    }

    public function testTrunksListAndGetNormalizeAndStripSecrets(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO pbx_trunks (trunk_name, title, ip_address, port, auth_username, auth_password, codecs, is_active, sort_order)
             VALUES ('zz_cov_trunk_7975', 'Coverage Trunk', '198.51.100.75', 5060, 'trunk_user', 'AUTH_SECRET', 'alaw,ulaw', 1, 99)"
        );
        $stmt->execute();
        $trunkId = (int)$this->db->lastInsertId();
        $this->createdTrunkIds[] = $trunkId;

        // 1. GET list
        $resList = $this->apiRequest('GET', '/api/v1/trunks');
        $this->assertSame(200, $resList['status']);
        $this->assertTrue($resList['json']['success']);

        $found = null;
        foreach ($resList['json']['data'] as $t) {
            if ($t['trunk_name'] === 'zz_cov_trunk_7975') {
                $found = $t;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertArrayNotHasKey('secret_encrypted', $found);
        $this->assertArrayNotHasKey('auth_password', $found);
        $this->assertArrayNotHasKey('secret', $found);

        // 2. GET single
        $resSingle = $this->apiRequest('GET', "/api/v1/trunks/{$trunkId}");
        $this->assertSame(200, $resSingle['status']);
        $singleData = $resSingle['json']['data'];
        $this->assertSame('zz_cov_trunk_7975', $singleData['trunk_name']);
        $this->assertArrayNotHasKey('secret_encrypted', $singleData);
        $this->assertArrayNotHasKey('auth_password', $singleData);
        $this->assertArrayNotHasKey('secret', $singleData);

        // 3. Toggle status
        $resToggle = $this->apiRequest('POST', "/api/v1/trunks/{$trunkId}/toggle");
        $this->assertSame(200, $resToggle['status']);
        $this->assertSame(0, (int)$resToggle['json']['data']['is_active']);

        // 4. Delete trunk
        $resDel = $this->apiRequest('DELETE', "/api/v1/trunks/{$trunkId}");
        $this->assertSame(200, $resDel['status']);

        // 5. Subsequent GET returns 404
        $res404 = $this->apiRequest('GET', "/api/v1/trunks/{$trunkId}");
        $this->assertSame(404, $res404['status']);
    }

    // -------------------------------------------------------------------------
    // Conferences Domain Tests
    // -------------------------------------------------------------------------

    public function testConferencesValidationAndList(): void
    {
        // 422 for bad input
        $resBad = $this->apiRequest('POST', '/api/v1/conferences', [
            'room_number' => '',
        ]);
        $this->assertSame(422, $resBad['status']);
        $this->assertSame('VALIDATION_ERROR', $resBad['json']['error']['code']);

        // Insert valid conference
        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (room_number, title, is_active)
             VALUES ('7976', 'zz_cov_conf_7976', 1)"
        );
        $stmt->execute();
        $confId = (int)$this->db->lastInsertId();
        $this->createdConfIds[] = $confId;

        $resGet = $this->apiRequest('GET', "/api/v1/conferences/{$confId}");
        $this->assertSame(200, $resGet['status']);
        $this->assertSame('7976', $resGet['json']['data']['room_number']);

        $resDel = $this->apiRequest('DELETE', "/api/v1/conferences/{$confId}");
        $this->assertSame(200, $resDel['status']);
    }

    // -------------------------------------------------------------------------
    // Time Conditions & Time Groups Domain Tests
    // -------------------------------------------------------------------------

    public function testTimeConditionsAndTimeGroupsListEndpoints(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO pbx_time_groups (title, time_start, time_end, days_of_week, is_active)
             VALUES ('zz_cov_tg_7977', '09:00:00', '18:00:00', '1,2,3,4,5', 1)"
        );
        $stmt->execute();
        $tgId = (int)$this->db->lastInsertId();
        $this->createdTgIds[] = $tgId;

        $stmtTc = $this->db->prepare(
            "INSERT INTO pbx_time_conditions (title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES ('zz_cov_tc_7977', ?, 'extension', '7001', 'hangup', '', 1)"
        );
        $stmtTc->execute([$tgId]);
        $tcId = (int)$this->db->lastInsertId();
        $this->createdTcIds[] = $tcId;

        // GET time-groups
        $resTg = $this->apiRequest('GET', '/api/v1/time-groups');
        $this->assertSame(200, $resTg['status']);
        $this->assertIsArray($resTg['json']['data']);

        // GET time-conditions
        $resTc = $this->apiRequest('GET', '/api/v1/time-conditions');
        $this->assertSame(200, $resTc['status']);
        $this->assertIsArray($resTc['json']['data']);
    }

    // -------------------------------------------------------------------------
    // Calls Domain Tests (Originate, Hangup, Active)
    // -------------------------------------------------------------------------

    public function testCallsOriginateValidationAndExecution(): void
    {
        // 1. Missing fields -> 422
        $resBad = $this->apiRequest('POST', '/api/v1/calls/originate', [
            'from_extension' => '7001',
            // 'to_number' is missing
        ]);
        $this->assertSame(422, $resBad['status']);
        $this->assertSame('VALIDATION_ERROR', $resBad['json']['error']['code']);

        // 2. Valid fields -> 200 (AIPBX_NO_ASTERISK mock returns dummy output)
        $resGood = $this->apiRequest('POST', '/api/v1/calls/originate', [
            'from_extension' => '7001',
            'to_number'      => '7002',
        ]);
        $this->assertSame(200, $resGood['status']);
        $this->assertTrue($resGood['json']['success']);
        $this->assertSame('7001', $resGood['json']['data']['from_extension']);
        $this->assertSame('7002', $resGood['json']['data']['to_number']);
        $this->assertStringContainsString('Local/7001@cc-internal extension 7002@from-internal-web', $resGood['json']['data']['asterisk_reply']);
    }

    public function testCallsHangupValidationAndExecution(): void
    {
        // 1. Missing channel -> 422
        $resBad = $this->apiRequest('POST', '/api/v1/calls/hangup', [
            'channel' => '',
        ]);
        $this->assertSame(422, $resBad['status']);
        $this->assertSame('VALIDATION_ERROR', $resBad['json']['error']['code']);

        // 2. Valid PJSIP channel -> 200
        $resGood = $this->apiRequest('POST', '/api/v1/calls/hangup', [
            'channel' => 'PJSIP/7001-00000001',
        ]);
        $this->assertSame(200, $resGood['status']);
        $this->assertTrue($resGood['json']['success']);
        $this->assertSame('PJSIP/7001-00000001', $resGood['json']['data']['channel']);

        // 3. Valid Local channel with '@' and ';' -> 200 (preserved intact)
        $resLocal = $this->apiRequest('POST', '/api/v1/calls/hangup', [
            'channel' => 'Local/7001@from-internal-pbx-00000001;1',
        ]);
        $this->assertSame(200, $resLocal['status']);
        $this->assertTrue($resLocal['json']['success']);
        $this->assertSame('Local/7001@from-internal-pbx-00000001;1', $resLocal['json']['data']['channel']);
    }

    public function testCallsActiveListEndpoint(): void
    {
        $res = $this->apiRequest('GET', '/api/v1/calls/active');
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['success']);
        $this->assertIsArray($res['json']['data']);
    }

    // -------------------------------------------------------------------------
    // Error Handling: Unknown Endpoint (404) & Method Not Allowed (405)
    // -------------------------------------------------------------------------

    public function testUnknownEndpointReturns404(): void
    {
        $res = $this->apiRequest('GET', '/api/v1/unknown-domain-test');
        $this->assertSame(404, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertSame('NOT_FOUND', $res['json']['error']['code']);
    }

    public function testMethodNotAllowedReturns405(): void
    {
        $res = $this->apiRequest('PATCH', '/api/v1/cdr');
        $this->assertSame(405, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertSame('METHOD_NOT_ALLOWED', $res['json']['error']['code']);
    }
}
