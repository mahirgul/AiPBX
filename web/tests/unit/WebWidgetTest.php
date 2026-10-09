<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/WebWidgetService.php';

/**
 * Website call widget and call-back form (#14): validation, allowed
 * websites, one-time tokens, limits, the abuse log and the generated
 * PJSIP/dialplan.
 */
final class WebWidgetTest extends TestCase
{
    private const EXT = '7801';
    private const SITE = 'https://www.shop.example';

    private PDO $db;
    private int $routeId;

    protected function setUp(): void
    {
        Fixtures::load();
        $this->db = getDB();
        $this->cleanUp();
        putenv('PORTAL_DOMAIN=pbx.test.example');
        $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active)
            VALUES ('widgettest', '', 'Widget Agent', 'w@example.com', 'user', ?, 'sip', ?, 1)")
            ->execute([self::EXT, 'W' . bin2hex(random_bytes(6))]);
        $this->routeId = (int) $this->db->query("SELECT id FROM pbx_outbound_routes WHERE route_name = 'Test Outbound'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        putenv('PORTAL_DOMAIN');
    }

    private function cleanUp(): void
    {
        $this->db->exec('DELETE FROM pbx_web_widget_sessions');
        $this->db->exec('DELETE FROM pbx_web_widgets');
        $this->db->exec("DELETE FROM sys_users WHERE username = 'widgettest'");
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Sales website', 'number' => '7001', 'is_active' => 1,
            'dest_type' => 'extension', 'dest_id' => self::EXT,
            'allowed_origins' => "www.shop.example\n*.blog.example",
            'call_enabled' => 1, 'max_concurrent' => 2, 'max_call_seconds' => 600,
            'ip_hourly_limit' => 5, 'daily_limit' => 0, 'color' => '#ff0000', 'language' => 'tr',
        ];
    }

    private function widget(array $over = []): array
    {
        return WebWidgetService::getWidget(WebWidgetService::upsertWidget($this->form($over)));
    }

    private function token(array $res): string
    {
        $this->assertTrue($res['body']['success'], json_encode($res['body']));
        $this->assertMatchesRegularExpression('/^sip:w([0-9a-f]{32})@pbx\.test\.example$/', $res['body']['target']);
        return substr(explode('@', $res['body']['target'])[0], 5);
    }

    // ------------------------------------------------------------ validation

    public function testNewWidgetGetsPublicIdSecretAndPendingSync(): void
    {
        $w = $this->widget();
        $this->assertMatchesRegularExpression('/^w_[0-9a-f]{12}$/', $w['public_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $w['sip_secret']);
        $this->assertSame("www.shop.example\n*.blog.example", $w['allowed_origins']);
        $this->assertSame('#ff0000', $w['color']);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM sys_pending_sync WHERE domain = 'widgets'")->fetchColumn());
        $this->assertStringContainsString('/widget.js" data-widget="' . $w['public_id'] . '"', WebWidgetService::embedCode($w));
    }

    public function testValidationRejectsUnsafeOrIncompleteWidgets(): void
    {
        $cases = [
            'no origins' => ['allowed_origins' => ''],
            'bad origin' => ['allowed_origins' => 'exa mple.com'],
            'bad number' => ['number' => '70a1'],
            'unknown destination' => ['dest_id' => '99999'],
            'nothing on' => ['call_enabled' => 0, 'callback_enabled' => 0],
            'external without route' => ['dest_type' => 'external', 'external_number' => '05321234567', 'daily_limit' => 10],
            'external without daily limit' => ['dest_type' => 'external', 'external_number' => '05321234567', 'outbound_route_id' => 0],
            'callback without prefixes' => ['callback_enabled' => 1, 'daily_limit' => 10],
        ];
        $cases['external without daily limit']['outbound_route_id'] = $this->routeId;
        $cases['callback without prefixes']['outbound_route_id'] = $this->routeId;
        foreach ($cases as $label => $over) {
            try {
                WebWidgetService::validate($this->form($over));
                $this->fail("accepted: {$label}");
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }

        $this->widget();
        $this->expectException(\Exception::class);
        $this->widget(['name' => 'Second']);
    }

    public function testOriginParsingAndMatching(): void
    {
        $bad = [];
        $this->assertSame(
            ['www.example.com', '*.example.org', 'shop.example.net'],
            WebWidgetService::parseOrigins("https://www.example.com/contact\n*.example.org, shop.example.net:8443", $bad)
        );
        $this->assertSame([], $bad);
        WebWidgetService::parseOrigins('*  javascript:alert(1)', $bad);
        $this->assertCount(2, $bad);

        $w = $this->widget();
        $this->assertTrue(WebWidgetService::originAllowed($w, self::SITE));
        $this->assertTrue(WebWidgetService::originAllowed($w, 'https://news.blog.example'));
        $this->assertTrue(WebWidgetService::originAllowed($w, 'https://blog.example'));
        $this->assertTrue(WebWidgetService::originAllowed($w, 'https://pbx.test.example'), 'the portal (Try it)');
        $this->assertFalse(WebWidgetService::originAllowed($w, 'https://shop.example'));
        $this->assertFalse(WebWidgetService::originAllowed($w, 'https://evilblog.example'));
        $this->assertFalse(WebWidgetService::originAllowed($w, 'https://www.shop.example.evil.test'));
        $this->assertFalse(WebWidgetService::originAllowed($w, ''));
        $this->assertFalse(WebWidgetService::originAllowed($w, 'null'));
    }

    // ------------------------------------------------------------ calls

    public function testCallHandsOutOneTimeTokenThatTheDialplanSpendsOnce(): void
    {
        $w = $this->widget(['ask_name' => 1]);
        $res = WebWidgetService::requestCall($w['public_id'], self::SITE, '198.51.100.7', 'UA', "Ayşe \"<b>\nYılmaz|x");
        $token = $this->token($res);

        $body = $res['body'];
        $this->assertSame('widget-' . $w['id'], $body['user']);
        $this->assertSame($w['sip_secret'], $body['password']);
        $this->assertSame('sip:widget-' . $w['id'] . '@pbx.test.example', $body['uri']);
        $this->assertStringStartsWith('wss://pbx.test.example/', $body['ws_url']);
        $this->assertSame(600, $body['max_seconds']);

        $other = $this->widget(['name' => 'Other', 'number' => '7002']);
        $this->assertSame('DENY|bad_token', WebWidgetService::claim((int) $other['id'], $token), 'another widget');
        $this->assertSame('DENY|disabled', WebWidgetService::claim(999999, $token), 'no such widget');
        $this->assertSame('OK|Ayse b Yilmazx', WebWidgetService::claim((int) $w['id'], $token));
        $this->assertSame('DENY|bad_token', WebWidgetService::claim((int) $w['id'], $token), 'spent');
        $this->assertSame('DENY|bad_token', WebWidgetService::claim((int) $w['id'], 'not-a-token'));

        $row = $this->db->query('SELECT * FROM pbx_web_widget_sessions')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('claimed', $row['result']);
        $this->assertNull($row['token']);
        $this->assertSame(self::SITE, $row['origin']);
        $this->assertSame(1, WebWidgetService::countedToday((int) $w['id']));
    }

    public function testExpiredTokenIsRefused(): void
    {
        $w = $this->widget();
        $token = $this->token(WebWidgetService::requestCall($w['public_id'], self::SITE, '198.51.100.7', 'UA'));
        $this->db->exec('UPDATE pbx_web_widget_sessions SET created_at = NOW() - INTERVAL ' . (WebWidgetService::TOKEN_TTL + 5) . ' SECOND');
        $this->assertSame('DENY|expired', WebWidgetService::claim((int) $w['id'], $token));
    }

    public function testRefusalsAreLoggedAndLimitsHold(): void
    {
        $w = $this->widget(['daily_limit' => 1]);
        $pid = $w['public_id'];

        $this->assertSame(404, WebWidgetService::requestCall('w_doesnotexist1', self::SITE, '198.51.100.7', 'UA')['status']);
        $this->assertSame('denied_origin', WebWidgetService::requestCall($pid, 'https://evil.example', '198.51.100.7', 'UA')['body']['error']);

        // Daily limit: one call claimed, the next request is refused.
        $token = $this->token(WebWidgetService::requestCall($pid, self::SITE, '198.51.100.7', 'UA'));
        $this->assertStringStartsWith('OK|', WebWidgetService::claim((int) $w['id'], $token));
        $res = WebWidgetService::requestCall($pid, self::SITE, '198.51.100.8', 'UA');
        $this->assertSame(['429', 'limit_daily'], [(string) $res['status'], $res['body']['error']]);

        // A token handed out before the limit was reached cannot pass it either.
        $this->db->exec("UPDATE pbx_web_widgets SET daily_limit = 2 WHERE id = " . (int) $w['id']);
        $t2 = $this->token(WebWidgetService::requestCall($pid, self::SITE, '198.51.100.8', 'UA'));
        $t3 = $this->token(WebWidgetService::requestCall($pid, self::SITE, '198.51.100.8', 'UA'));
        $this->assertStringStartsWith('OK|', WebWidgetService::claim((int) $w['id'], $t2));
        $this->assertSame('DENY|limit_daily', WebWidgetService::claim((int) $w['id'], $t3));

        // Switched off: refused at once, also for a token already handed out.
        $this->db->exec("UPDATE pbx_web_widgets SET daily_limit = 0 WHERE id = " . (int) $w['id']);
        $t4 = $this->token(WebWidgetService::requestCall($pid, self::SITE, '198.51.100.9', 'UA'));
        $this->db->exec("UPDATE pbx_web_widgets SET is_active = 0 WHERE id = " . (int) $w['id']);
        $this->assertSame('DENY|disabled', WebWidgetService::claim((int) $w['id'], $t4));
        $this->assertSame('disabled', WebWidgetService::requestCall($pid, self::SITE, '198.51.100.9', 'UA')['body']['error']);

        $results = $this->db->query('SELECT result, COUNT(*) FROM pbx_web_widget_sessions GROUP BY result')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(1, (int) $results['denied_origin']);
        $this->assertSame(2, (int) $results['limit_daily'], 'one refused request, one refused token');
        $this->assertSame(1, (int) $results['disabled']);
    }

    public function testPerIpHourlyLimit(): void
    {
        $w = $this->widget(['ip_hourly_limit' => 2]);
        $this->token(WebWidgetService::requestCall($w['public_id'], self::SITE, '203.0.113.5', 'UA'));
        $this->token(WebWidgetService::requestCall($w['public_id'], self::SITE, '203.0.113.5', 'UA'));
        $res = WebWidgetService::requestCall($w['public_id'], self::SITE, '203.0.113.5', 'UA');
        $this->assertSame(['429', 'rate_ip'], [(string) $res['status'], $res['body']['error']]);
        $this->token(WebWidgetService::requestCall($w['public_id'], self::SITE, '203.0.113.6', 'UA'));
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM pbx_web_widget_sessions')->fetchColumn(), 'over-limit requests are not logged');
    }

    // ------------------------------------------------------------ call-back

    public function testCallbackOnlyCallsAllowedNumbers(): void
    {
        $w = $this->widget([
            'call_enabled' => 0, 'callback_enabled' => 1, 'callback_prefixes' => '05, 0212',
            'outbound_route_id' => $this->routeId, 'daily_limit' => 5,
        ]);
        $pid = $w['public_id'];

        $this->assertSame('disabled', WebWidgetService::requestCall($pid, self::SITE, '198.51.100.7', 'UA')['body']['error']);
        $this->assertSame('bad_number', WebWidgetService::requestCallback($pid, self::SITE, '198.51.100.7', 'UA', '0900 123 45 67')['body']['error']);
        $this->assertSame('bad_number', WebWidgetService::requestCallback($pid, self::SITE, '198.51.100.7', 'UA', '05')['body']['error']);
        $this->assertTrue(WebWidgetService::requestCallback($pid, self::SITE, '198.51.100.7', 'UA', '+0 532 123 45 67', 'Ali')['body']['success']);

        $row = $this->db->query("SELECT * FROM pbx_web_widget_sessions WHERE result = 'callback'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('05321234567', $row['caller_number']);
        $this->assertSame('callback', $row['kind']);
        $this->assertSame(1, WebWidgetService::countedToday((int) $w['id']));
    }

    // ------------------------------------------------------------ PBX config

    public function testDialplanGuardsTheRoutingBehindTheToken(): void
    {
        $w = $this->widget(['name' => "Sales, \"web\"\n", 'max_concurrent' => 3]);
        $id = (int) $w['id'];
        $conf = buildWidgetDialplan([$w]);

        $this->assertStringContainsString("[widget-{$id}]\nexten => _w.,1,", $conf);
        $this->assertStringContainsString("SHELL(/usr/local/bin/widget_claim.php {$id} \${FILTER(0-9a-f,\${EXTEN:1})})", $conf);
        $this->assertStringContainsString("Goto(widget-{$id}-route,s,1)", $conf);
        $this->assertStringContainsString("GROUP_COUNT(w{$id}@aipbx_widget)} > 3]?widget_busy", $conf);
        $this->assertStringContainsString('Set(TIMEOUT(absolute)=600)', $conf);
        $this->assertStringContainsString('Set(CDR(did)=7001)', $conf);
        $this->assertStringContainsString("Set(CDR(inbound_trunk)=widget-{$id})", $conf);
        $this->assertStringContainsString('PJSIP_DIAL_CONTACTS(' . self::EXT, $conf);
        $this->assertStringNotContainsString("Sales, \"web\"", $conf, 'name is cleaned for the dialplan');

        // The endpoint's context holds nothing but the token check.
        preg_match("/\[widget-{$id}\]\n(.*?)\n\n/s", $conf, $m);
        $this->assertSame(1, substr_count($m[1], 'exten =>'));
        $this->assertStringNotContainsString("[widget-{$id}-out]", $conf, 'no call-back contexts');

        $pjsip = buildWidgetPjsipConf([$w]);
        $this->assertStringContainsString("[widget-{$id}]\ntype=endpoint\ncontext=widget-{$id}\n", $pjsip);
        $this->assertStringContainsString("username=widget-{$id}\npassword={$w['sip_secret']}\n", $pjsip);
        $this->assertStringContainsString('webrtc=yes', $pjsip);
    }

    public function testExternalDestinationAndCallbackDialThroughTheRoute(): void
    {
        $w = $this->widget([
            'dest_type' => 'external', 'external_number' => '0532 111 22 33', 'outbound_route_id' => $this->routeId,
            'daily_limit' => 20, 'callback_enabled' => 1, 'callback_prefixes' => '05', 'callback_cid' => '02125550000',
        ]);
        $id = (int) $w['id'];
        $conf = buildWidgetDialplan([$w]);

        $this->assertStringContainsString("Set(CALLERID(num)=02125550000)\n same => n,Set(CDR(direction)=outbound)\n same => n,Goto(outbound-route-{$this->routeId},05321112233,1)", $conf);
        $this->assertStringContainsString("[widget-{$id}-out]", $conf);
        $this->assertStringContainsString("Set(__CID_EXTERNAL=02125550000)", $conf);
        $this->assertStringContainsString("Goto(outbound-route-{$this->routeId},\${EXTEN},1)", $conf);
        $this->assertStringContainsString("[widget-{$id}-callback]", $conf);
    }

    public function testSyncWritesOnlyActiveWidgets(): void
    {
        $on = $this->widget();
        $off = $this->widget(['number' => '7002', 'is_active' => 0]);
        syncWidgets();

        $pjsip = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_widgets.conf');
        $dialplan = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_widgets.conf');
        $this->assertStringContainsString('[widget-' . $on['id'] . ']', $pjsip);
        $this->assertStringNotContainsString('[widget-' . $off['id'] . ']', $pjsip);
        $this->assertStringNotContainsString('[widget-' . $off['id'] . ']', $dialplan);
    }

    public function testPublicConfigHasNoSecrets(): void
    {
        $cfg = WebWidgetService::publicConfig($this->widget());
        $this->assertSame(['call', 'callback', 'button_text', 'color', 'position', 'language', 'ask_name', 'max_seconds', 'jssip'], array_keys($cfg));
        $this->assertSame('https://pbx.test.example/assets/js/jssip.min.js', $cfg['jssip']);
    }
}
