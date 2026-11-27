<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/ApiTokenService.php';

/**
 * What a CRM uses: the address book, the CDR sync cursor, recordings by id and a
 * click-to-call that a retry does not place twice.
 */
final class ApiV1CrmEndpointsTest extends TestCase
{
    private PDO $db;
    /** @var array<string, array{0: int, 1: string}> scope set => token id, raw token */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->cleanUp();
        foreach (['all' => ['*'], 'calls' => ['calls:read'], 'contacts' => ['contacts:read', 'contacts:write'], 'pbxw' => ['pbx:write'], 'users' => ['users:write']] as $k => $scopes) {
            $raw = ApiTokenService::generateRawToken();
            $this->db->prepare("INSERT INTO sys_api_tokens (name, token_prefix, token_hash, scopes_json, allowed_ips, rate_limit_per_min, is_active, created_at)
                VALUES ('zz_crm_token', ?, ?, ?, '*', 1000, 1, NOW())")->execute([substr($raw, 0, 13) . '…', hash('sha256', $raw), json_encode($scopes)]);
            $this->tokens[$k] = [(int) $this->db->lastInsertId(), $raw];
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->exec("DELETE FROM sys_api_tokens WHERE name = 'zz_crm_token'");
        $this->db->exec("DELETE FROM phonebook_contacts WHERE display_name LIKE 'zz CRM%'");
        $this->db->exec("DELETE FROM asteriskcdr WHERE uniqueid LIKE 'zzcrm.%'");
        $this->db->exec('DELETE FROM api_idempotency_keys');
        $this->db->exec("DELETE FROM sys_users WHERE extension IN ('79801', '79802', '79803', '79804')");
    }

    /** @return array{status: int, json: array<string, mixed>|null, raw: string} */
    private function api(string $method, string $path, array $body = [], array $get = [], string $token = 'all'): array
    {
        $payload = $body ? json_encode($body) : '';
        $stream = $payload === '' ? '' : '
class ZzInput { public $context; private static string $d = ' . var_export($payload, true) . '; private int $p = 0;
  public function stream_open($a, $b, $c, &$d) { $this->p = 0; return true; }
  public function stream_read($n) { $r = substr(self::$d, $this->p, $n); $this->p += strlen($r); return $r; }
  public function stream_eof() { return $this->p >= strlen(self::$d); }
  public function stream_stat() { return []; } }
stream_wrapper_unregister("php"); stream_wrapper_register("php", ZzInput::class);';
        $code = "<?php {$stream}
register_shutdown_function(function () { \$c = http_response_code(); echo \"\\n__STATUS__:\" . (\$c ?: 200); });
require " . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";
require_once " . var_export(dirname(__DIR__, 2) . '/src/controllers/ApiV1Controller.php', true) . ";
\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_SERVER['HTTP_AUTHORIZATION'] = " . var_export('Bearer ' . $this->tokens[$token][1], true) . ";
\$_GET = " . var_export($get, true) . ";
ApiV1Controller::serve(" . var_export("/api/v1/" . $path, true) . ");";
        $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $status = 0;
        if (preg_match("/\n__STATUS__:(\\d+)$/", $out, $m)) {
            $status = (int) $m[1];
            $out = substr($out, 0, -strlen($m[0]));
        }
        $json = json_decode(trim($out), true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $out];
    }

    public function testContactsNeedTheirScopeAndLookUpAnySpelling(): void
    {
        $this->assertSame(403, $this->api('GET', 'contacts', [], [], 'calls')['status'], 'calls:read is not contacts:read');

        $r = $this->api('POST', 'contacts', ['display_name' => 'zz CRM Ayse', 'external_id' => 'crm-42',
            'numbers' => [['label' => 'mobile', 'number' => '+90 532 777 66 55']]], [], 'contacts');
        $this->assertSame(201, $r['status'], $r['raw']);
        $id = (int) $r['json']['data']['id'];
        $this->assertArrayNotHasKey('numbers_indexed_at', $r['json']['data']);

        $again = $this->api('POST', 'contacts', ['display_name' => 'zz CRM Ayse Demir', 'external_id' => 'crm-42',
            'numbers' => [['label' => 'mobile', 'number' => '+90 532 777 66 55']]], [], 'contacts');
        $this->assertSame($id, (int) $again['json']['data']['id'], 'the same external_id updates');

        $found = $this->api('GET', 'contacts/lookup', [], ['number' => '05327776655'], 'contacts');
        $this->assertSame('zz CRM Ayse Demir', $found['json']['data']['display_name'] ?? null);
        $this->assertNull($this->api('GET', 'contacts/lookup', [], ['number' => '05320000000'], 'contacts')['json']['data']);

        $patched = $this->api('PATCH', "contacts/{$id}", ['company' => 'ACME'], [], 'contacts');
        $this->assertSame('ACME', $patched['json']['data']['company']);
        $this->assertCount(1, $patched['json']['data']['numbers'], 'a PATCH keeps the numbers it does not send');

        $patchedPhone = $this->api('PATCH', "contacts/{$id}", ['phone_mobile' => '05329998877'], [], 'contacts');
        $this->assertSame(200, $patchedPhone['status'], $patchedPhone['raw']);
        $this->assertSame('05329998877', $patchedPhone['json']['data']['phone_mobile']);
        $this->assertSame('05329998877', $patchedPhone['json']['data']['numbers'][0]['number']);

        $this->assertSame(404, $this->api('PUT', 'contacts/9999999', ['display_name' => 'Missing', 'phone_mobile' => '05320001111'], [], 'contacts')['status']);
        $this->assertSame(422, $this->api('POST', 'contacts', ['display_name' => 'zz CRM no number'], [], 'contacts')['status']);
        $this->assertSame(200, $this->api('DELETE', "contacts/{$id}", [], [], 'contacts')['status']);
        $this->assertSame(404, $this->api('GET', "contacts/{$id}", [], [], 'contacts')['status']);
    }

    public function testCdrCursorAndRecordingsById(): void
    {
        $dir = rtrim(MONITOR_STORAGE_PATH, '/') . '/zzcrm';
        @mkdir($dir, 0755, true);
        file_put_contents("{$dir}/a.mp3", 'ID3fake-audio');
        $ins = $this->db->prepare("INSERT INTO asteriskcdr (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, duration, billsec, disposition, amaflags, uniqueid, userfield, linkedid)
            VALUES (NOW(), '', '0532', '100', 'x', 'c', 'd', 'Dial', '', 10, 5, 'ANSWERED', 3, ?, ?, ?)");
        $ins->execute(['zzcrm.1', "{$dir}/a.mp3", 'zzcrm.1']);
        $first = (int) $this->db->lastInsertId();
        $ins->execute(['zzcrm.2', '/etc/passwd', 'zzcrm.1']);
        $outside = (int) $this->db->lastInsertId();

        $page = $this->api('GET', 'cdr', [], ['after_id' => (string) ($first - 1), 'per_page' => '1'], 'calls');
        $this->assertSame([$first], array_column($page['json']['data'], 'id'), 'oldest first after the cursor');
        $this->assertSame('zzcrm.1', $page['json']['data'][0]['linkedid']);
        $this->assertSame("/api/v1/recordings/{$first}", $page['json']['data'][0]['recording_url']);
        $next = $this->api('GET', 'cdr', [], ['after_id' => (string) $page['json']['meta']['next_after_id']], 'calls');
        $this->assertSame($outside, $next['json']['data'][0]['id']);

        $descPage = $this->api('GET', 'cdr', [], ['per_page' => '10'], 'calls');
        $this->assertSame(max(array_column($descPage['json']['data'], 'id')), $descPage['json']['meta']['next_after_id']);

        $audio = $this->api('GET', "recordings/{$first}", [], [], 'calls');
        $this->assertSame(200, $audio['status']);
        $this->assertSame('ID3fake-audio', $audio['raw']);
        $this->assertSame(404, $this->api('GET', "recordings/{$first}/extra", [], [], 'calls')['status'], 'sub-action on recordings must 404');
        $this->assertSame(404, $this->api('GET', "recordings/{$outside}", [], [], 'calls')['status'], 'a path outside the recordings folder');
        $this->assertStringNotContainsString('root:', $this->api('GET', "recordings/{$outside}", [], [], 'calls')['raw']);
        $this->assertSame(404, $this->api('GET', 'recordings/../../etc/passwd', [], [], 'calls')['status']);
        $this->assertSame(403, $this->api('GET', "recordings/{$first}", [], [], 'contacts')['status']);
        @unlink("{$dir}/a.mp3");
        @rmdir($dir);

        require_once dirname(__DIR__, 2) . '/src/services/RecordingStorage.php';
        $this->assertFalse(RecordingStorage::send('s3:faxes/2026/secret.pdf'), 'S3 outside recordings/ must be refused');
        $this->assertFalse(RecordingStorage::send('s3:recordings/../faxes/secret.pdf'), 'S3 traversal must be refused');
    }

    private function zzUser(string $ext, string $role, int $twoFactor = 0, int $device = 0): int
    {
        $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active, is_device, token_epoch, extension, two_factor_enabled)
            VALUES (?, '', 'Pairing', '', ?, 1, ?, 0, ?, ?)")->execute(['zz_pair_' . $ext, $role, $device, $ext, $twoFactor]);
        return (int) $this->db->lastInsertId();
    }

    public function testAnExtensionGetsAOneTimeMobileLinkNotAPassword(): void
    {
        $id = $this->zzUser('79802', 'user');
        $this->assertSame(403, $this->api('POST', "extensions/{$id}/pairing", [], [], 'pbxw')['status'], 'pbx:write may create accounts, not sign in as them');

        $r = $this->api('POST', "extensions/{$id}/pairing", [], [], 'users');
        $this->assertSame(200, $r['status'], $r['raw']);
        $d = $r['json']['data'];
        $this->assertStringContainsString('/mobile-login?token=', $d['url']);
        $this->assertStringStartsWith('data:image/svg+xml', $d['qr_data_uri']);
        $this->assertSame('79802', $d['extension']);
        $this->assertStringNotContainsString('assword', $r['raw']);

        parse_str((string) parse_url($d['url'], PHP_URL_QUERY), $q);
        $this->assertTrue(QrLoginService::inspectToken($q['token'])['valid']);
        $spent = $this->db->prepare('UPDATE sys_user_qr_tokens SET used_at = NOW() WHERE token = ?');
        $spent->execute([$q['token']]);
        $this->assertSame('used', QrLoginService::inspectToken($q['token'])['reason'], 'a spent link is refused');
        $this->assertSame(200, $this->api('POST', 'extensions/79802/pairing', [], [], 'users')['status'], 'the extension number works as the id');
    }

    public function testNoLinkForAdministratorsManagersTwoFactorOrDevices(): void
    {
        foreach ([['79803', 'admin', 0], ['79804', 'cc_manager', 0]] as [$ext, $role, $tf]) {
            $this->assertSame(403, $this->api('POST', "extensions/{$this->zzUser($ext, $role, $tf)}/pairing", [], [], 'users')['status'], $role);
        }
        $tf = $this->zzUser('79801', 'user', 1);
        $this->assertSame(403, $this->api('POST', "extensions/{$tf}/pairing", [], [], 'users')['status'], 'two-factor needs the web sign-in');
        $this->assertSame(404, $this->api('POST', 'extensions/79899/pairing', [], [], 'users')['status']);
        $this->assertSame(404, $this->api('POST', 'extensions/nonexistent-abc/pairing', [], [], 'users')['status']);

        // When a numeric extension equals another user's row id, the extension match wins deterministically.
        $adminId = $this->zzUser('79805', 'admin', 0);
        $extUser = $this->zzUser((string) $adminId, 'user', 0);
        try {
            $match = $this->api('POST', "extensions/{$adminId}/pairing", [], [], 'users');
            $this->assertSame(200, $match['status'], $match['raw']);
            $this->assertSame((string) $adminId, $match['json']['data']['extension']);
        } finally {
            $this->db->prepare('DELETE FROM sys_users WHERE id IN (?, ?)')->execute([$adminId, $extUser]);
        }
    }

    public function testARetriedClickToCallIsNotPlacedTwice(): void
    {
        $this->db->exec("INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active, is_device, token_epoch, extension)
            VALUES ('zz_crm_ext', '', 'CRM', '', 'user', 1, 0, 0, '79801')");
        $body = ['from_extension' => '79801', 'to_number' => '05321112233', 'client_request_id' => 'click-1'];
        $one = $this->api('POST', 'calls/originate', $body);
        $this->assertSame(200, $one['status'], $one['raw']);
        $this->assertFalse($one['json']['data']['duplicate']);
        $two = $this->api('POST', 'calls/originate', $body);
        $this->assertTrue($two['json']['data']['duplicate']);
        $this->assertSame($one['json']['data']['asterisk_reply'], $two['json']['data']['asterisk_reply']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM api_idempotency_keys')->fetchColumn());

        // Reusing client_request_id with different params must return 409 IDEMPOTENCY_CONFLICT
        $conflict = $this->api('POST', 'calls/originate', ['to_number' => '05329990000'] + $body);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['json']['error']['code']);

        // An in-flight row (response_json IS NULL) must return 409 REQUEST_IN_PROGRESS
        $inFlightHash = hash('sha256', 'token:' . $this->tokens['all'][0] . "\nclick-inflight");
        $this->db->prepare('INSERT INTO api_idempotency_keys (key_hash, response_json) VALUES (?, NULL)')->execute([$inFlightHash]);
        $inProgress = $this->api('POST', 'calls/originate', ['client_request_id' => 'click-inflight'] + $body);
        $this->assertSame(409, $inProgress['status']);
        $this->assertSame('REQUEST_IN_PROGRESS', $inProgress['json']['error']['code']);

        // Non-existent / external / inactive from_extension must be rejected with 404
        $missingExt = $this->api('POST', 'calls/originate', ['from_extension' => '99999', 'to_number' => '05321112233']);
        $this->assertSame(404, $missingExt['status']);
        $extPstn = $this->api('POST', 'calls/originate', ['from_extension' => '05329998877', 'to_number' => '05321112233']);
        $this->assertSame(404, $extPstn['status']);
        $this->db->exec("UPDATE sys_users SET is_active = 0 WHERE extension = '79801'");
        $inactiveExt = $this->api('POST', 'calls/originate', ['from_extension' => '79801', 'to_number' => '05321112233']);
        $this->assertSame(404, $inactiveExt['status']);
        $this->db->exec("UPDATE sys_users SET is_active = 1 WHERE extension = '79801'");

        $other = $this->api('POST', 'calls/originate', ['client_request_id' => 'click-2'] + $body);
        $this->assertFalse($other['json']['data']['duplicate'], 'another id is another call');
    }

    public function testStrictCidrAllowlistValidation(): void
    {
        require_once dirname(__DIR__, 2) . '/src/core/ApiRequestContext.php';
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', '10.0.0.1/'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', '10.0.0.1/abc'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', '10.0.0.1/0'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', '10.0.0.1/24/8'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('::1', '::1/0'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('10.0.0.1', ',,,'));
        $this->assertTrue(ApiRequestContext::isIpAllowed('10.0.0.1', '0.0.0.0/0'));
        $this->assertTrue(ApiRequestContext::isIpAllowed('2001:db8:1::1', '2001:db8::/32'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue(ApiRequestContext::isIpAllowed('2001:db8::1', '::/0'));
    }
}
