<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ConnectionService.php';

/**
 * Connections own one API token and one webhook each: the pack carries what the other side
 * needs once, rotating stops the old key, removing touches nothing that is not its own.
 */
final class ConnectionServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->cleanUp();
        $_SESSION['csrf_token'] = 'conn-csrf';
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        // Also remove fixtures left by older runs of the lookup test.
        $legacyIds = $this->db->query("SELECT id FROM sys_connections WHERE name = 'Odoo Live Lookup'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($legacyIds as $id) {
            ConnectionService::delete((int) $id);
        }
        $this->db->exec("DELETE FROM sys_connections WHERE name LIKE 'zz conn%'");
        $this->db->exec("DELETE FROM sys_api_tokens WHERE name LIKE 'Connection: zz conn%'");
        $this->db->exec("DELETE FROM sys_webhook_queue WHERE webhook_id IN (SELECT id FROM sys_webhooks WHERE name LIKE '%zz conn%')");
        $this->db->exec("DELETE FROM sys_webhooks WHERE name LIKE '%zz conn%'");
    }

    private function create(string $name, string $kind = 'odoo', string $url = 'https://odoo.example.com/'): array
    {
        $res = ConnectionService::create(['name' => $name, 'kind' => $kind, 'base_url' => $url, 'allowed_ips' => '203.0.113.10, 10.0.0.0/24'], 'conn-csrf');
        $this->assertTrue($res['success'], (string) $res['message']);
        return $res;
    }

    public function testAnOdooConnectionGetsItsOwnKeyAndWebhook(): void
    {
        $res = $this->create('zz conn Odoo');
        $pack = json_decode($res['pack'], true);
        $c = ConnectionService::find($res['id']);

        $this->assertSame(1, $pack['aipbx_connection']);
        $this->assertSame($c['uuid'], $pack['connection_id']);
        $this->assertStringStartsWith('whsec_', $pack['webhook_secret']);
        $this->assertNotEmpty($pack['instance_id']);

        $tok = DBHelper::fetchOne('SELECT * FROM sys_api_tokens WHERE id = ?', [(int) $c['api_token_id']]);
        $this->assertSame(hash('sha256', $pack['api_token']), $tok['token_hash'], 'the pack holds the real key; only its hash is stored');
        $this->assertSame(ConnectionService::SCOPES, json_decode($tok['scopes_json'], true));
        $this->assertSame('203.0.113.10, 10.0.0.0/24', $tok['allowed_ips'], 'valid allowlist entries are preserved');

        $wh = DBHelper::fetchOne('SELECT * FROM sys_webhooks WHERE id = ?', [(int) $c['webhook_id']]);
        $this->assertSame('https://odoo.example.com/aipbx/webhook/' . $c['uuid'], $wh['target_url']);
        $this->assertSame(ConnectionService::EVENTS, json_decode($wh['events_json'], true));
        $this->assertSame($pack['webhook_secret'], SecretBox::decrypt((string) $wh['secret_encrypted']));
    }

    public function testRotatingStopsTheOldKeyAndSecret(): void
    {
        $res = $this->create('zz conn Rotate', 'generic', 'https://crm.example.com/hooks/aipbx');
        $old = json_decode($res['pack'], true);
        $new = json_decode(ConnectionService::rotate($res['id'], 'conn-csrf')['pack'], true);
        $this->assertNotSame($old['api_token'], $new['api_token']);
        $this->assertNotSame($old['webhook_secret'], $new['webhook_secret']);
        $this->assertSame($old['connection_id'], $new['connection_id'], 'the other side keeps its id');
        $this->assertSame(0, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens WHERE token_hash = ?', [hash('sha256', $old['api_token'])]));
        $this->assertSame(1, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens WHERE token_hash = ?', [hash('sha256', $new['api_token'])]));
        $c = ConnectionService::find($res['id']);
        $wh = DBHelper::fetchOne('SELECT target_url, secret_encrypted, events_json FROM sys_webhooks WHERE id = ?', [(int) $c['webhook_id']]);
        $this->assertSame('https://crm.example.com/hooks/aipbx', $wh['target_url'], 'generic: the address as entered');
        $this->assertSame($new['webhook_secret'], SecretBox::decrypt((string) $wh['secret_encrypted']));
        $this->assertSame(ConnectionService::GENERIC_EVENTS, json_decode((string) $wh['events_json'], true));
    }

    public function testRemovingTakesOnlyItsOwnKeyWebhookAndQueue(): void
    {
        $a = ConnectionService::find($this->create('zz conn A')['id']);
        $b = ConnectionService::find($this->create('zz conn B')['id']);
        $this->db->prepare("INSERT INTO sys_webhook_queue (webhook_id, event_id, event_name, payload_json, attempts, max_attempts, next_attempt_at, status, created_at)
            VALUES (?, 'e1', 'call.ringing', '{}', 0, 3, NOW(), 'pending', NOW())")->execute([(int) $a['webhook_id']]);
        $this->db->prepare("INSERT INTO sys_webhook_queue (webhook_id, event_id, event_name, payload_json, attempts, max_attempts, next_attempt_at, status, created_at)
            VALUES (?, 'e2', 'call.ringing', '{}', 0, 3, NOW(), 'pending', NOW())")->execute([(int) $b['webhook_id']]);

        $this->assertTrue(ConnectionService::delete((int) $a['id']));
        $this->assertNull(ConnectionService::find((int) $a['id']));
        foreach (['sys_api_tokens' => $a['api_token_id'], 'sys_webhooks' => $a['webhook_id']] as $table => $id) {
            $this->assertSame(0, (int) DBHelper::fetchColumn("SELECT COUNT(*) FROM {$table} WHERE id = ?", [(int) $id]), $table);
        }
        $this->assertSame(0, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_webhook_queue WHERE webhook_id = ?', [(int) $a['webhook_id']]));
        $this->assertSame(1, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_webhook_queue WHERE webhook_id = ?', [(int) $b['webhook_id']]), 'B keeps its events');
        $this->assertSame(1, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens WHERE id = ?', [(int) $b['api_token_id']]));
        $this->assertFalse(ConnectionService::delete((int) $a['id']));
    }

    public function testAnAddressThePbxMustNotCallIsRefusedAndLeavesNothing(): void
    {
        $before = (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens');
        $res = ConnectionService::create(['name' => 'zz conn Bad', 'kind' => 'odoo', 'base_url' => 'http://169.254.169.254'], 'conn-csrf');
        $this->assertFalse($res['success']);
        $this->assertSame($before, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens'));
        $this->assertFalse(ConnectionService::create(['name' => '', 'base_url' => 'https://x.example.com'], 'conn-csrf')['success']);
        $this->assertFalse(ConnectionService::create(['name' => 'zz conn csrf', 'base_url' => 'https://x.example.com'], 'wrong')['success']);
        $this->assertSame(0, (int) DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_connections WHERE name LIKE 'zz conn%'"));
    }

    public function testInvalidIpAllowlistsAndCgnatAreRejected(): void
    {
        $before = (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens');
        foreach (['203.0.113.10 203.0.113.11', '203.0.113.10, nonsense', '10.0.0.1/', '10.0.0.1/abc', '10.0.0.1/0', '::1/0', ',,,'] as $badIps) {
            $r = ConnectionService::create(['name' => 'zz conn BadIp', 'kind' => 'generic', 'base_url' => 'https://crm.example.com/hook', 'allowed_ips' => $badIps], 'conn-csrf');
            $this->assertFalse($r['success'], "Expected failure for allowed_ips='{$badIps}'");
            $tokRes = ApiTokenService::create(['csrf_token' => 'conn-csrf', 'name' => 'zz tok bad ip', 'scopes' => ['pbx:read'], 'allowed_ips' => $badIps]);
            $this->assertFalse($tokRes['success'], "ApiTokenService::create should reject allowed_ips='{$badIps}'");
        }
        $this->assertSame($before, (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_api_tokens'));

        putenv('ALLOW_PRIVATE_WEBHOOKS=0');
        $cgnat = ConnectionService::create(['name' => 'zz conn CGNAT', 'kind' => 'generic', 'base_url' => 'http://100.64.0.1/hook'], 'conn-csrf');
        $this->assertFalse($cgnat['success'], 'RFC 6598 CGNAT 100.64.0.0/10 must be blocked when ALLOW_PRIVATE_WEBHOOKS=0');
    }

    public function testRotateFailsIfOwnedTokenOrWebhookIsMissingAndPackPreservesHostPort(): void
    {
        $res = $this->create('zz conn Orphan', 'generic', 'https://crm.example.com/hook');
        $c = ConnectionService::find($res['id']);
        $this->db->prepare('DELETE FROM sys_api_tokens WHERE id = ?')->execute([(int) $c['api_token_id']]);
        $rot = ConnectionService::rotate($res['id'], 'conn-csrf');
        $this->assertFalse($rot['success'], 'rotate must fail when owned token is missing');

        $res2 = $this->create('zz conn DisabledWh', 'generic', 'https://crm.example.com/hook2');
        $c2 = ConnectionService::find($res2['id']);
        $this->db->prepare('UPDATE sys_webhooks SET is_active = 0 WHERE id = ?')->execute([(int) $c2['webhook_id']]);
        $rot2 = ConnectionService::rotate($res2['id'], 'conn-csrf');
        $this->assertFalse($rot2['success'], 'rotate must fail when owned webhook is disabled/deleted');

        $prevDomain = getenv('PORTAL_DOMAIN');
        $prevHost = $_SERVER['HTTP_HOST'] ?? null;
        putenv('PORTAL_DOMAIN= ');
        try {
            $_SERVER['HTTP_HOST'] = '[2001:db8::1]:8443';
            $p1 = json_decode(ConnectionService::pack($c2, 'apbx_test', 'whsec_test'), true);
            $this->assertSame('https://[2001:db8::1]:8443', $p1['pbx_url']);

            $_SERVER['HTTP_HOST'] = 'pbx.example.com:8443';
            $p2 = json_decode(ConnectionService::pack($c2, 'apbx_test', 'whsec_test'), true);
            $this->assertSame('https://pbx.example.com:8443', $p2['pbx_url']);

            $_SERVER['HTTP_HOST'] = 'evil.com/path@attacker';
            $p3 = json_decode(ConnectionService::pack($c2, 'apbx_test', 'whsec_test'), true);
            $this->assertSame('https://localhost', $p3['pbx_url']);
        } finally {
            putenv($prevDomain === false ? 'PORTAL_DOMAIN' : 'PORTAL_DOMAIN=' . $prevDomain);
            if ($prevHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $prevHost;
            }
        }
    }

    public function testOnDemandOdooCallerLookup(): void
    {
        $res = ConnectionService::create([
            'name' => 'zz conn Odoo Live Lookup',
            'kind' => 'odoo',
            'base_url' => 'https://8.8.8.8',
        ], $_SESSION['csrf_token']);
        $this->assertTrue($res['success']);

        $seen = [];
        ConnectionService::$lookupTransport = function (string $url, array $headers, string $body) use (&$seen): ?string {
            $seen[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            return json_encode([
                'found' => true,
                'partner_id' => 42,
                'name' => 'Ahmet Yılmaz',
                'company' => 'ACME A.Ş.',
                'email' => 'ahmet@acme.example',
                'url' => '/web#id=42&model=res.partner&view_type=form',
            ]);
        };

        try {
            $number = '+90 532 111 22 33';
            $found = ConnectionService::lookupCaller($number);
            $this->assertNotNull($found);
            $this->assertSame('Ahmet Yılmaz', $found['display_name']);
            $this->assertSame('ACME A.Ş.', $found['company']);
            $this->assertSame('42', $found['customer_id']);
            $this->assertSame('https://8.8.8.8/web#id=42&model=res.partner&view_type=form', $found['external_url']);
            $this->assertCount(1, $seen);
            $this->assertStringContainsString('/aipbx/lookup/', $seen[0]['url']);
            $this->assertSame($found, ConnectionService::lookupCaller($number));
            $this->assertCount(1, $seen, 'The second lookup uses the cache.');

            $this->assertTrue(ConnectionService::setActive($res['id'], false, 'conn-csrf')['success']);
            $this->assertNull(ConnectionService::lookupCaller($number));
            $this->assertCount(1, $seen, 'The disabled connection sends no lookup.');
        } finally {
            ConnectionService::$lookupTransport = null;
        }
    }
}
