<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/core/ApiResponse.php';
require_once dirname(__DIR__, 2) . '/src/core/ApiRequestContext.php';
require_once dirname(__DIR__, 2) . '/src/services/ApiTokenService.php';

final class ApiV1AuthCoverageTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $createdTokenIds = [];
    /** @var list<int> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->createdTokenIds = [];
        $this->createdUserIds = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdTokenIds)) {
            $ids = implode(',', array_map('intval', $this->createdTokenIds));
            $this->db->exec("DELETE FROM sys_api_tokens WHERE id IN ({$ids})");
        }
        if (!empty($this->createdUserIds)) {
            $uids = implode(',', array_map('intval', $this->createdUserIds));
            $this->db->exec("DELETE FROM sys_users WHERE id IN ({$uids})");
        }
        $this->db->exec("DELETE FROM sys_api_tokens WHERE name LIKE 'zz_cov_%'");
        $this->db->exec("DELETE FROM sys_users WHERE username LIKE 'zz_cov_%'");
        $_SESSION = [];
    }

    /**
     * Helper to run an isolated API request through ApiV1Controller::serve() in a sub-process.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @return array{status: int, json: array<string, mixed>|null, raw: string}
     */
    private function apiRequest(string $method, string $path, ?string $token = null, array $body = [], array $server = []): array
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

        $sessionSetup = '';
        if (isset($server['SESSION']) && is_array($server['SESSION'])) {
            $sessionSetup = '$_SESSION = ' . var_export($server['SESSION'], true) . ';';
        }

        $remoteAddr = $server['REMOTE_ADDR'] ?? '127.0.0.1';
        $authHeader = $server['HTTP_AUTHORIZATION'] ?? ($token !== null ? ('Bearer ' . $token) : '');
        $csrfHeader = $server['HTTP_X_CSRF_TOKEN'] ?? '';

        $code = "<?php
{$streamClass}
register_shutdown_function(function() {
    echo \"\\n__STATUS__:\" . http_response_code();
});
require " . var_export($bootstrapPath, true) . ";
require_once " . var_export($controllerPath, true) . ";

\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";
\$_SERVER['REMOTE_ADDR'] = " . var_export((string)$remoteAddr, true) . ";
" . ($authHeader !== '' ? "\$_SERVER['HTTP_AUTHORIZATION'] = " . var_export((string)$authHeader, true) . ";" : "unset(\$_SERVER['HTTP_AUTHORIZATION']);") . "
" . ($csrfHeader !== '' ? "\$_SERVER['HTTP_X_CSRF_TOKEN'] = " . var_export((string)$csrfHeader, true) . ";" : "unset(\$_SERVER['HTTP_X_CSRF_TOKEN']);") . "
{$sessionSetup}

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

    /**
     * @param list<string> $scopes
     */
    private function createToken(string $name, array $scopes, string $allowedIps = '*', int $rateLimit = 120, ?string $expiresAt = null, int $isActive = 1): string
    {
        $rawToken = ApiTokenService::generateRawToken();
        $hash = hash('sha256', $rawToken);
        $prefix = substr($rawToken, 0, 13) . '…';

        $stmt = $this->db->prepare(
            'INSERT INTO sys_api_tokens (name, token_prefix, token_hash, scopes_json, allowed_ips, rate_limit_per_min, expires_at, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $name,
            $prefix,
            $hash,
            json_encode($scopes),
            $allowedIps !== '*' ? $allowedIps : null,
            $rateLimit,
            $expiresAt,
            $isActive,
        ]);

        $this->createdTokenIds[] = (int)$this->db->lastInsertId();
        return $rawToken;
    }

    // -------------------------------------------------------------------------
    // Token Authentication & Expiry Tests
    // -------------------------------------------------------------------------

    public function testMissingBearerKeyIsRefused(): void
    {
        $res = $this->apiRequest('GET', '/api/v1/system/info', null);
        $this->assertSame(401, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('UNAUTHORIZED', $res['json']['error']['code']);
    }

    public function testMalformedBearerKeyIsRefused(): void
    {
        $res1 = $this->apiRequest('GET', '/api/v1/system/info', null, [], [
            'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
        ]);
        $this->assertSame(401, $res1['status']);
        $this->assertSame('UNAUTHORIZED', $res1['json']['error']['code']);

        $res2 = $this->apiRequest('GET', '/api/v1/system/info', null, [], [
            'HTTP_AUTHORIZATION' => 'Bearer short_token',
        ]);
        $this->assertSame(401, $res2['status']);
        $this->assertSame('UNAUTHORIZED', $res2['json']['error']['code']);

        $res3 = $this->apiRequest('GET', '/api/v1/system/info', null, [], [
            'HTTP_AUTHORIZATION' => 'Bearer notapbx_123456789012345678901234567890',
        ]);
        $this->assertSame(401, $res3['status']);
        $this->assertSame('UNAUTHORIZED', $res3['json']['error']['code']);
    }

    public function testInvalidBearerKeyIsRefused(): void
    {
        $fakeToken = 'apbx_nonexistent_token_string_that_is_long_enough_12345';
        $res = $this->apiRequest('GET', '/api/v1/system/info', $fakeToken);
        $this->assertSame(401, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('INVALID_TOKEN', $res['json']['error']['code']);
    }

    public function testRevokedBearerKeyIsRefused(): void
    {
        $token = $this->createToken('zz_cov_revoked_key', ['system:read'], '*', 120, null, 0);
        $res = $this->apiRequest('GET', '/api/v1/system/info', $token);
        $this->assertSame(401, $res['status']);
        $this->assertSame('INVALID_TOKEN', $res['json']['error']['code']);
    }

    public function testExpiredBearerKeyIsRefused(): void
    {
        $yesterday = date('Y-m-d H:i:s', time() - 86400);
        $token = $this->createToken('zz_cov_expired_key', ['system:read'], '*', 120, $yesterday, 1);
        $res = $this->apiRequest('GET', '/api/v1/system/info', $token);
        $this->assertSame(401, $res['status']);
        $this->assertSame('TOKEN_EXPIRED', $res['json']['error']['code']);
    }

    // -------------------------------------------------------------------------
    // IP Allowlist & CIDR Tests
    // -------------------------------------------------------------------------

    public function testIpAllowlistExactMatchAllowedAndDisallowed(): void
    {
        $token = $this->createToken('zz_cov_ip_exact', ['system:read'], '198.51.100.5');

        // Disallowed client IP
        $resBad = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '198.51.100.6']);
        $this->assertSame(403, $resBad['status']);
        $this->assertSame('IP_NOT_ALLOWED', $resBad['json']['error']['code']);

        // Allowed client IP
        $resGood = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '198.51.100.5']);
        $this->assertSame(200, $resGood['status']);
        $this->assertTrue($resGood['json']['success']);
    }

    public function testIpAllowlistCidrSubnetAllowedAndDisallowed(): void
    {
        $token = $this->createToken('zz_cov_ip_cidr', ['system:read'], '10.20.0.0/16, 192.168.1.0/24');

        // Subnet 1 match
        $res1 = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '10.20.99.14']);
        $this->assertSame(200, $res1['status']);

        // Subnet 2 match
        $res2 = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '192.168.1.254']);
        $this->assertSame(200, $res2['status']);

        // Outside Subnet 1
        $resBad1 = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '10.21.0.1']);
        $this->assertSame(403, $resBad1['status']);
        $this->assertSame('IP_NOT_ALLOWED', $resBad1['json']['error']['code']);

        // Outside Subnet 2
        $resBad2 = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '192.168.2.1']);
        $this->assertSame(403, $resBad2['status']);
    }

    public function testIpAllowlistWildcardPermitsAnyIp(): void
    {
        $token = $this->createToken('zz_cov_ip_star', ['system:read'], '*');
        $res = $this->apiRequest('GET', '/api/v1/system/info', $token, [], ['REMOTE_ADDR' => '203.0.113.195']);
        $this->assertSame(200, $res['status']);
    }

    public function testDirectIsIpAllowedUnitLogic(): void
    {
        // Null / empty / wildcard
        $this->assertTrue(ApiRequestContext::isIpAllowed('1.2.3.4', ''));
        $this->assertTrue(ApiRequestContext::isIpAllowed('1.2.3.4', '*'));

        // Single IPs
        $this->assertTrue(ApiRequestContext::isIpAllowed('192.168.1.10', '192.168.1.10'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('192.168.1.11', '192.168.1.10'));

        // CIDR bit boundaries: /8, /16, /24, /28, /32
        $this->assertTrue(ApiRequestContext::isIpAllowed('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('11.1.2.3', '10.0.0.0/8'));

        $this->assertTrue(ApiRequestContext::isIpAllowed('172.16.50.1', '172.16.0.0/16'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('172.17.50.1', '172.16.0.0/16'));

        $this->assertTrue(ApiRequestContext::isIpAllowed('192.168.10.15', '192.168.10.0/28')); // .0 to .15
        $this->assertFalse(ApiRequestContext::isIpAllowed('192.168.10.16', '192.168.10.0/28'));

        $this->assertTrue(ApiRequestContext::isIpAllowed('8.8.8.8', '8.8.8.8/32'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('8.8.8.9', '8.8.8.8/32'));

        // Invalid CIDR or client IP
        $this->assertFalse(ApiRequestContext::isIpAllowed('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', 'not-a-cidr/24'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', '10.0.0.0/999'));
    }

    // -------------------------------------------------------------------------
    // Scope Enforcement Tests
    // -------------------------------------------------------------------------

    public function testTokenWithoutCallsWriteCannotOriginate(): void
    {
        // Key has pbx:read and pbx:write, but lacks calls:write
        $token = $this->createToken('zz_cov_no_calls_key', ['pbx:read', 'pbx:write']);

        $res = $this->apiRequest('POST', '/api/v1/calls/originate', $token, [
            'from_extension' => '7001',
            'to_number'      => '7002',
        ]);

        $this->assertSame(403, $res['status']);
        $this->assertNotNull($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('FORBIDDEN_SCOPE', $res['json']['error']['code']);
        $this->assertSame('calls:write', $res['json']['error']['details']['required_scope']);
    }

    public function testReadOnlyScopesCannotPerformMutations(): void
    {
        $token = $this->createToken('zz_cov_readonly_key', ['pbx:read']);

        // Can read
        $resGet = $this->apiRequest('GET', '/api/v1/extensions', $token);
        $this->assertSame(200, $resGet['status']);

        // Cannot create
        $resPost = $this->apiRequest('POST', '/api/v1/extensions', $token, [
            'extension' => '7991',
            'full_name' => 'Should Fail',
        ]);
        $this->assertSame(403, $resPost['status']);
        $this->assertSame('FORBIDDEN_SCOPE', $resPost['json']['error']['code']);

        // Cannot update
        $resPut = $this->apiRequest('PUT', '/api/v1/extensions/7991', $token, [
            'full_name' => 'Should Fail',
        ]);
        $this->assertSame(403, $resPut['status']);
        $this->assertSame('FORBIDDEN_SCOPE', $resPut['json']['error']['code']);

        // Cannot delete
        $resDel = $this->apiRequest('DELETE', '/api/v1/extensions/7991', $token);
        $this->assertSame(403, $resDel['status']);
        $this->assertSame('FORBIDDEN_SCOPE', $resDel['json']['error']['code']);
    }

    public function testWildcardScopeGrantsAllOperations(): void
    {
        $token = $this->createToken('zz_cov_wildcard_key', ['*']);

        $resInfo = $this->apiRequest('GET', '/api/v1/system/info', $token);
        $this->assertSame(200, $resInfo['status']);

        $resExt = $this->apiRequest('GET', '/api/v1/extensions', $token);
        $this->assertSame(200, $resExt['status']);

        // Calling originate with missing fields reaches 422 validation, meaning auth & scope passed
        $resOrig = $this->apiRequest('POST', '/api/v1/calls/originate', $token, []);
        $this->assertSame(422, $resOrig['status']);
        $this->assertSame('VALIDATION_ERROR', $resOrig['json']['error']['code']);
    }

    public function testDirectHasScopeLogic(): void
    {
        // Reflection on ApiRequestContext::$scopes
        $ref = new ReflectionClass(ApiRequestContext::class);
        $prop = $ref->getProperty('scopes');

        // Case 1: Wildcard '*'
        $prop->setValue(null, ['*']);
        $this->assertTrue(ApiRequestContext::hasScope('pbx:read'));
        $this->assertTrue(ApiRequestContext::hasScope('calls:write'));
        $this->assertTrue(ApiRequestContext::hasScope('custom:scope'));

        // Case 2: Exact scope
        $prop->setValue(null, ['pbx:read', 'calls:write']);
        $this->assertTrue(ApiRequestContext::hasScope('pbx:read'));
        $this->assertTrue(ApiRequestContext::hasScope('calls:write'));
        $this->assertFalse(ApiRequestContext::hasScope('pbx:write'));
        $this->assertFalse(ApiRequestContext::hasScope('system:read'));

        // Case 3: <domain>:write automatically grants <domain>:read
        $prop->setValue(null, ['pbx:write']);
        $this->assertTrue(ApiRequestContext::hasScope('pbx:read'));
        $this->assertTrue(ApiRequestContext::hasScope('pbx:write'));
        $this->assertFalse(ApiRequestContext::hasScope('calls:read'));

        // Case 4: <domain>:* grants both read and write
        $prop->setValue(null, ['system:*']);
        $this->assertTrue(ApiRequestContext::hasScope('system:read'));
        $this->assertTrue(ApiRequestContext::hasScope('system:write'));
        $this->assertFalse(ApiRequestContext::hasScope('pbx:read'));
    }

    // -------------------------------------------------------------------------
    // Session Authentication & CSRF Fallback
    // -------------------------------------------------------------------------

    public function testSessionAuthenticationPermitsGetWithoutToken(): void
    {
        // Admin session activity fresh
        $now = time();
        $session = [
            'user_id'       => 1,
            'username'      => 'admin',
            'user_role'     => 'admin',
            'role'          => 'admin',
            'last_activity' => $now,
            'csrf_token'    => 'test-valid-csrf-token',
        ];

        $res = $this->apiRequest('GET', '/api/v1/system/info', null, [], ['SESSION' => $session]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['success']);
    }

    public function testSessionAuthenticationRequiresCsrfOnMutation(): void
    {
        $now = time();
        $session = [
            'user_id'       => 1,
            'username'      => 'admin',
            'user_role'     => 'admin',
            'role'          => 'admin',
            'last_activity' => $now,
            'csrf_token'    => 'test-session-csrf-secret',
        ];

        // Mutation without X-CSRF-Token header -> 403 CSRF_REQUIRED
        $resMissing = $this->apiRequest('POST', '/api/v1/calls/originate', null, [
            'from_extension' => '7001',
            'to_number'      => '7002',
        ], [
            'SESSION' => $session,
        ]);
        $this->assertSame(403, $resMissing['status']);
        $this->assertSame('CSRF_REQUIRED', $resMissing['json']['error']['code']);

        // Mutation with invalid X-CSRF-Token header -> 403 CSRF_REQUIRED
        $resWrong = $this->apiRequest('POST', '/api/v1/calls/originate', null, [
            'from_extension' => '7001',
            'to_number'      => '7002',
        ], [
            'SESSION'            => $session,
            'HTTP_X_CSRF_TOKEN'  => 'wrong-csrf-token',
        ]);
        $this->assertSame(403, $resWrong['status']);
        $this->assertSame('CSRF_REQUIRED', $resWrong['json']['error']['code']);

        // Mutation with matching X-CSRF-Token header passes auth -> hits handler (200)
        $resValid = $this->apiRequest('POST', '/api/v1/calls/originate', null, [
            'from_extension' => '7001',
            'to_number'      => '7002',
        ], [
            'SESSION'            => $session,
            'HTTP_X_CSRF_TOKEN'  => 'test-session-csrf-secret',
        ]);
        $this->assertSame(200, $resValid['status']);
        $this->assertTrue($resValid['json']['success']);
    }

    // -------------------------------------------------------------------------
    // Rate Limiting (if testable)
    // -------------------------------------------------------------------------

    public function testRateLimitingEnforcementIfTestable(): void
    {
        $dir = is_dir('/var/lib/aipbx') ? '/var/lib/aipbx/api-ratelimit' : sys_get_temp_dir() . '/aipbx_api_ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (!is_writable($dir)) {
            $this->markTestSkipped('Rate limit directory (' . $dir . ') is not writable by current process.');
        }

        // ApiRequestContext enforces max(10, rate_limit), so minimum limit is 10 req/min
        $token = $this->createToken('zz_cov_rate_test', ['system:read'], '*', 10);

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->apiRequest('GET', '/api/v1/system/info', $token);
            $this->assertSame(200, $res['status'], "Request {$i} should pass rate limit");
        }

        // 11th request must exceed the 10/min rate limit
        $resExceeded = $this->apiRequest('GET', '/api/v1/system/info', $token);
        $this->assertSame(429, $resExceeded['status']);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $resExceeded['json']['error']['code']);
    }

    // -------------------------------------------------------------------------
    // Pagination Helper Tests
    // -------------------------------------------------------------------------

    public function testPaginationHelperDefaultsAndClamping(): void
    {
        $_GET = [];
        $p1 = ApiRequestContext::pagination();
        $this->assertSame(1, $p1['page']);
        $this->assertSame(50, $p1['per_page']);
        $this->assertSame(0, $p1['offset']);

        $_GET = ['page' => '3', 'per_page' => '25'];
        $p2 = ApiRequestContext::pagination();
        $this->assertSame(3, $p2['page']);
        $this->assertSame(25, $p2['per_page']);
        $this->assertSame(50, $p2['offset']);

        // Clamping invalid / excessive values
        $_GET = ['page' => '-5', 'per_page' => '9999'];
        $p3 = ApiRequestContext::pagination(50, 100);
        $this->assertSame(1, $p3['page']);
        $this->assertSame(100, $p3['per_page']);
        $this->assertSame(0, $p3['offset']);
    }
}
