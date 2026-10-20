<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/core/ApiResponse.php';
require_once dirname(__DIR__, 2) . '/src/core/ApiRequestContext.php';
require_once dirname(__DIR__, 2) . '/src/core/ResourceNormalizer.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';
require_once dirname(__DIR__, 2) . '/src/services/ApiTokenService.php';
require_once dirname(__DIR__, 2) . '/src/services/WebhookService.php';

final class PluginAndApiV1Test extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['role'] = 'admin';
        $_SESSION['csrf_token'] = 'test-csrf-token';
    }

    public function testBoolFlagHandlesFormAndJsonPayloads(): void
    {
        // HTML checkbox 'on' / missing
        $this->assertSame(1, PBXHelper::boolFlag(['is_active' => 'on'], 'is_active', 0));
        $this->assertSame(0, PBXHelper::boolFlag([], 'is_active', 0));
        $this->assertSame(1, PBXHelper::boolFlag([], 'is_active', 1));

        // JSON boolean & numeric values
        $this->assertSame(0, PBXHelper::boolFlag(['is_active' => false], 'is_active', 1));
        $this->assertSame(0, PBXHelper::boolFlag(['is_active' => 0], 'is_active', 1));
        $this->assertSame(0, PBXHelper::boolFlag(['is_active' => '0'], 'is_active', 1));
        $this->assertSame(0, PBXHelper::boolFlag(['is_active' => 'false'], 'is_active', 1));
        $this->assertSame(1, PBXHelper::boolFlag(['is_active' => true], 'is_active', 0));
        $this->assertSame(1, PBXHelper::boolFlag(['is_active' => 1], 'is_active', 0));
        $this->assertSame(1, PBXHelper::boolFlag(['is_active' => 'yes'], 'is_active', 0));
    }

    public function testResourceNormalizerStripsSecretsAndMergesPartialPatch(): void
    {
        $row = [
            'id' => 42,
            'username' => 'alice',
            'password_hash' => '$2y$10$secret',
            'sip_password' => 'plain-secret',
            'auth_password' => 'trunk-secret',
            'voicemail_pin' => '1234',
            'two_factor_secret' => 'TOTPSECRET',
            'trunks_json' => '[1,2]',
        ];
        $clean = ResourceNormalizer::sanitizeRow($row);
        $this->assertArrayNotHasKey('password_hash', $clean);
        $this->assertArrayNotHasKey('sip_password', $clean);
        $this->assertArrayNotHasKey('auth_password', $clean);
        $this->assertArrayNotHasKey('voicemail_pin', $clean);
        $this->assertArrayNotHasKey('two_factor_secret', $clean);
        $this->assertSame([1, 2], $clean['trunks']);

        $existingExt = [
            'id' => 10,
            'extension' => '101',
            'voicemail_enabled' => 1,
            'voicemail_pin' => '1234',
            'is_active' => 1,
        ];
        $merged = ResourceNormalizer::mergeForService('extension', $existingExt, [
            'cid_internal' => 'Alice <101>',
            'voicemail_enabled' => false,
        ]);
        $this->assertSame(10, $merged['user_id']);
        $this->assertSame('Alice <101>', $merged['cid_internal']);
        $this->assertSame(0, PBXHelper::boolFlag($merged, 'voicemail_enabled'));
        $this->assertSame(1, PBXHelper::boolFlag($merged, 'is_active'));
    }

    public function testApiTokenLifecycleAndIpMatch(): void
    {
        $res = ApiTokenService::create([
            'csrf_token' => 'test-csrf-token',
            'name' => 'Unit Test CRM Key',
            'scopes' => ['pbx:read', 'calls:write'],
            'allowed_ips' => '127.0.0.1, 10.20.0.0/16',
            'rate_limit_per_min' => 120,
        ]);

        $this->assertTrue($res['success']);
        $this->assertStringStartsWith('apbx_', (string)$res['raw_token']);

        $hash = hash('sha256', (string)$res['raw_token']);
        $row = DBHelper::fetchOne('SELECT * FROM sys_api_tokens WHERE token_hash = ?', [$hash]);
        $this->assertNotNull($row);
        $this->assertSame('Unit Test CRM Key', $row['name']);

        $this->assertTrue(ApiRequestContext::isIpAllowed('127.0.0.1', '127.0.0.1, 10.20.0.0/16'));
        $this->assertTrue(ApiRequestContext::isIpAllowed('10.20.99.5', '127.0.0.1, 10.20.0.0/16'));
        $this->assertFalse(ApiRequestContext::isIpAllowed('192.168.1.50', '127.0.0.1, 10.20.0.0/16'));

        $del = ApiTokenService::delete((int)$res['id'], 'test-csrf-token');
        $this->assertTrue($del['success']);
    }

    public function testWebhookSsrfGuardAndEventQueueing(): void
    {
        putenv('ALLOW_PRIVATE_WEBHOOKS=0');
        $threw = false;
        try {
            WebhookService::validateTargetUrl('http://127.0.0.1:8080/hook');
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw);

        putenv('ALLOW_PRIVATE_WEBHOOKS=1');
        $validUrl = WebhookService::validateTargetUrl('http://127.0.0.1:8080/hook');
        $this->assertSame('http://127.0.0.1:8080/hook', $validUrl);

        $save = WebhookService::save([
            'csrf_token' => 'test-csrf-token',
            'name' => 'Test Local Hook',
            'target_url' => 'http://127.0.0.1:9999/webhook',
            'events' => ['config.sync_applied', 'call.ended'],
            'is_active' => 1,
        ]);
        $this->assertTrue($save['success']);
        $whId = DBHelper::lastSavedId();
        $this->assertGreaterThan(0, $whId);

        WebhookService::emit('config.sync_applied', ['domains' => ['extensions']]);

        $item = DBHelper::fetchOne('SELECT * FROM sys_webhook_queue WHERE webhook_id = ? ORDER BY id DESC LIMIT 1', [$whId]);
        $this->assertNotNull($item);
        $this->assertSame('config.sync_applied', $item['event_name']);

        DBHelper::delete('sys_webhook_queue', 'webhook_id', $whId);
        WebhookService::delete($whId, 'test-csrf-token');
        putenv('ALLOW_PRIVATE_WEBHOOKS=0');
    }

    public function testPluginCatalogSettingsKvAndSignalingCaptureSync(): void
    {
        PluginManager::resetCache();
        $manifests = PluginManager::discoverManifests();
        $this->assertArrayHasKey('google-auth', $manifests);
        $this->assertArrayHasKey('ms-teams', $manifests);
        $this->assertArrayHasKey('web-widgets', $manifests);
        $this->assertArrayHasKey('ai-cloud', $manifests);
        $this->assertArrayHasKey('ai-tts', $manifests);
        $this->assertArrayHasKey('ai-apps', $manifests);
        $this->assertArrayHasKey('ai-models', $manifests);
        $this->assertArrayHasKey('ai-voicemail', $manifests);
        $this->assertArrayHasKey('ai-assistant', $manifests);
        $this->assertArrayHasKey('signaling-capture', $manifests);

        // Enable signaling-capture and configure settings
        $en = PluginManager::enable('signaling-capture', 'test-csrf-token');
        $this->assertTrue($en['success']);
        $this->assertTrue(PluginManager::isEnabled('signaling-capture'));

        $save = PluginManager::savePluginSettings('signaling-capture', [
            'hep_enabled' => 1,
            'capture_address' => '10.0.0.50:9060',
            'capture_password' => 'my-hep-secret',
            'capture_id' => 2001,
            'uuid_type' => 'call-id',
        ], 'test-csrf-token');
        $this->assertTrue($save['success']);

        // Secret setting is decrypted when read via getSetting() and masked in getAllSettings(..., true)
        $this->assertSame('my-hep-secret', PluginManager::getSetting('signaling-capture', 'capture_password'));
        $masked = PluginManager::getAllSettings('signaling-capture', true);
        $this->assertNotSame('my-hep-secret', $masked['capture_password']);

        // Sync hook writes hep_plugin_signaling-capture.conf to ASTERISK_PBX_DIR
        PluginManager::syncAllEnabledPlugins();

        $hepPath = rtrim((string)getenv('ASTERISK_PBX_DIR'), '/') . '/hep_plugin_signaling-capture.conf';
        $this->assertFileExists($hepPath);
        $hepContent = (string)file_get_contents($hepPath);
        $this->assertStringContainsString('enabled = yes', $hepContent);
        $this->assertStringContainsString('capture_address = 10.0.0.50:9060', $hepContent);
        $this->assertStringContainsString('capture_password = my-hep-secret', $hepContent);

        // Delivering an event triggers signaling-capture's event handler -> writes to KV
        PluginManager::deliverEventToPlugin('signaling-capture', 'call.started', [
            'event' => 'call.started',
            'timestamp' => date(DATE_ATOM),
            'data' => ['from_extension' => '101', 'to_number' => '102'],
        ]);
        $kvItems = PluginManager::kvList('signaling-capture', 'recent_events');
        $this->assertNotEmpty($kvItems);

        // Enable ai-voicemail and test voicemail.received event hook + AI sidebar group
        $enVm = PluginManager::enable('ai-voicemail', 'test-csrf-token');
        $this->assertTrue($enVm['success']);
        $aiSidebar = PluginManager::dynamicSidebarItems(true, 'ai');
        $this->assertNotEmpty($aiSidebar);

        PluginManager::deliverEventToPlugin('ai-voicemail', 'voicemail.received', [
            'event' => 'voicemail.received',
            'occurred_at' => date(DATE_ATOM),
            'data' => [
                'mailbox' => '101',
                'caller' => '05551234567',
                'duration' => 18,
                'msg_num' => '1',
                'transcript' => 'Merhaba, teklif dosyasını yarın sabah gönderebilir misiniz?',
            ],
        ]);
        $vmSummaries = PluginManager::kvList('ai-voicemail', 'summaries');
        $this->assertNotEmpty($vmSummaries);

        // Disable plugins
        PluginManager::disable('ai-voicemail', 'test-csrf-token');
        PluginManager::disable('signaling-capture', 'test-csrf-token');
        $this->assertFalse(PluginManager::isEnabled('signaling-capture'));
    }

    public function testPluginSchemaSandboxAndAutoDialerLifecycle(): void
    {
        // 1. Security check: non-plg_ table names and destructive DDL are rejected
        $threwNonPlg = false;
        try {
            PluginManager::parseAndValidatePluginSchemaSql('CREATE TABLE sys_evil (id INT PRIMARY KEY);');
        } catch (\Exception $e) {
            $threwNonPlg = true;
        }
        $this->assertTrue($threwNonPlg);

        $threwDrop = false;
        try {
            PluginManager::parseAndValidatePluginSchemaSql('DROP TABLE plg_autodial_campaigns;');
        } catch (\Exception $e) {
            $threwDrop = true;
        }
        $this->assertTrue($threwDrop);

        // 2. Enable auto-dialer -> creates plg_autodial_campaigns and plg_autodial_numbers
        PluginManager::resetCache();
        $manifests = PluginManager::discoverManifests();
        $this->assertArrayHasKey('auto-dialer', $manifests);

        $en = PluginManager::enable('auto-dialer', 'test-csrf-token');
        $this->assertTrue($en['success']);
        $this->assertTrue(PluginManager::isEnabled('auto-dialer'));

        require_once dirname(__DIR__, 3) . '/plugins/auto-dialer/AutoDialerService.php';

        $campId = AutoDialerService::createCampaign([
            'name' => 'Unit Test AMD Campaign',
            'dest_type' => 'extension',
            'dest_id' => '101',
            'caller_id' => '08505550000',
            'max_concurrent' => 2,
            'queue_free_agent_pacing' => 1,
            'max_retries' => 1,
            'retry_interval_sec' => 60,
            'amd_enabled' => 1,
            'amd_machine_action' => 'play_sound',
            'amd_machine_sound' => 'welcome_msg',
            'numbers_bulk' => "05321112233, Ali Veli, VIP\n05442223344, Ayse Kaya, Test",
        ]);
        $this->assertGreaterThan(0, $campId);

        // Verify dialplan file generated with AMD(...) parameters and voicemail drop
        $dpPath = rtrim((string)getenv('ASTERISK_PBX_DIR'), '/') . '/extensions_plugin_auto-dialer.conf';
        $this->assertFileExists($dpPath);
        $dpContent = (string)file_get_contents($dpPath);
        $this->assertStringContainsString('[aipbx-autodial-out]', $dpContent);
        $this->assertStringContainsString("[aipbx-autodial-camp-{$campId}]", $dpContent);
        $this->assertStringContainsString('AMD(2500,1500,800,5000,100,50,2,256)', $dpContent);
        $this->assertStringContainsString('WaitForSilence(1500,1,15)', $dpContent);
        $this->assertStringContainsString('Playback(custom/welcome_msg)', $dpContent);

        // Start campaign and run a worker tick
        AutoDialerService::setCampaignStatus($campId, 'running');
        $originated = AutoDialerService::tickCampaigns();
        $this->assertSame(2, $originated);

        $nums = AutoDialerService::listNumbers($campId);
        $this->assertCount(2, $nums);
        $num1 = (int)$nums[0]['id'];
        $num2 = (int)$nums[1]['id'];

        // Simulate Asterisk callback: num1 -> HUMAN, num2 -> MACHINE
        $cb1 = AutoDialerService::handleCallback([
            'num_id' => $num1,
            'event' => 'answered',
            'amd_status' => 'HUMAN',
            'amd_cause' => 'HUMAN-800-800',
            'uniqueid' => '1700000.1',
        ]);
        $this->assertTrue($cb1['success']);

        $cb2 = AutoDialerService::handleCallback([
            'num_id' => $num2,
            'event' => 'answered',
            'amd_status' => 'MACHINE',
            'amd_cause' => 'TOOLONG-1500',
            'uniqueid' => '1700000.2',
        ]);
        $this->assertTrue($cb2['success']);

        $camps = AutoDialerService::listCampaigns();
        $this->assertNotEmpty($camps);
        $this->assertSame(1, (int)$camps[0]['human_count']);
        $this->assertSame(1, (int)$camps[0]['machine_count']);

        // Clean up and uninstall plugin -> drops plg_autodial_* tables
        $un = PluginManager::uninstall('auto-dialer', 'test-csrf-token');
        $this->assertTrue($un['success']);
    }

    public function testPluginManagerTypeAndAuthorUrlSanitization(): void
    {
        $plugins = PluginManager::allPlugins();
        $this->assertNotEmpty($plugins);

        $bySlug = [];
        foreach ($plugins as $p) {
            $bySlug[$p['slug']] = $p;
            $this->assertContains($p['type'], ['official', 'community']);
            if (!empty($p['author_url'])) {
                $this->assertMatchesRegularExpression('#^https?://#i', $p['author_url']);
            }
        }

        // Official plugins
        $this->assertArrayHasKey('auto-dialer', $bySlug);
        $this->assertSame('official', $bySlug['auto-dialer']['type']);
        $this->assertArrayHasKey('google-auth', $bySlug);
        $this->assertSame('official', $bySlug['google-auth']['type']);

        // Community plugins
        if (isset($bySlug['ai-assistant'])) {
            $this->assertSame('community', $bySlug['ai-assistant']['type']);
        }
        if (isset($bySlug['signaling-capture'])) {
            $this->assertSame('community', $bySlug['signaling-capture']['type']);
        }
    }
}
