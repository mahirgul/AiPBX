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
    private function installMovedPluginFixture(string $slug): void
    {
        $dir = sys_get_temp_dir() . '/aipbx-test-community-plugins';
        if (!is_dir($dir)) { mkdir($dir, 0700, true); }
        putenv('AIPBX_CUSTOM_PLUGINS_DIR=' . $dir);
        $zip = new ZipArchive();
        $zip->open(dirname(__DIR__, 3) . '/conf/plugin-transitions/' . $slug . '-1.1.0.zip');
        $zip->extractTo($dir . '/' . $slug); $zip->close();
        PluginManager::resetCache();
    }

    protected function tearDown(): void
    {
        putenv('AIPBX_CUSTOM_PLUGINS_DIR');
        PluginManager::resetCache();
    }

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
        $this->installMovedPluginFixture('ai-voicemail');
        $this->installMovedPluginFixture('ai-assistant');
        $this->installMovedPluginFixture('auto-dialer');
        PluginManager::resetCache();
        $manifests = PluginManager::discoverManifests();
        $this->assertArrayHasKey('auto-dialer', $manifests);
        $this->assertArrayHasKey('oidc-auth', $manifests);
        $this->assertArrayHasKey('google-auth', $manifests);
        $this->assertArrayHasKey('ms-teams', $manifests);
        $this->assertArrayHasKey('web-widgets', $manifests);
        $this->assertArrayHasKey('ai-cloud', $manifests);
        $this->assertArrayHasKey('ai-tts', $manifests);
        $this->assertArrayHasKey('ai-apps', $manifests);
        $this->assertArrayHasKey('ai-models', $manifests);
        $this->assertArrayHasKey('ai-voicemail', $manifests);
        $this->assertArrayHasKey('ai-assistant', $manifests);
        // Signaling capture is part of AiPBX since 1.50.8, not a plugin any more.
        $this->assertArrayNotHasKey('signaling-capture', $manifests);

        // Secret settings: decrypted by getSetting(), masked by getAllSettings(..., true)
        $en = PluginManager::enable('ai-assistant', 'test-csrf-token');
        $this->assertTrue($en['success']);
        $save = PluginManager::savePluginSettings('ai-assistant', [
            'api_base_url' => 'https://api.example.com/v1',
            'api_key' => 'my-secret-key',
            'model' => 'test-model',
        ], 'test-csrf-token');
        $this->assertTrue($save['success']);
        $this->assertSame('my-secret-key', PluginManager::getSetting('ai-assistant', 'api_key'));
        $masked = PluginManager::getAllSettings('ai-assistant', true);
        $this->assertNotSame('my-secret-key', $masked['api_key']);

        // Enable ai-voicemail and test voicemail.transcribed event hook + AI sidebar group
        $enVm = PluginManager::enable('ai-voicemail', 'test-csrf-token');
        $this->assertTrue($enVm['success']);
        $aiSidebar = PluginManager::dynamicSidebarItems(true, 'ai');
        $this->assertNotEmpty($aiSidebar);

        PluginManager::deliverEventToPlugin('ai-voicemail', 'voicemail.transcribed', [
            'event' => 'voicemail.transcribed',
            'occurred_at' => date(DATE_ATOM),
            'data' => [
                'extension' => '101',
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
        PluginManager::disable('ai-assistant', 'test-csrf-token');
        $this->assertFalse(PluginManager::isEnabled('ai-assistant'));
    }

    public function testVoicemailPluginUsesCompletedContentAndIgnoresMetadata(): void
    {
        $this->installMovedPluginFixture('ai-voicemail');
        $this->installMovedPluginFixture('ai-assistant');
        $this->installMovedPluginFixture('auto-dialer');
        $this->assertTrue(PluginManager::enable('ai-voicemail', 'test-csrf-token')['success']);
        PluginManager::savePluginSettings('ai-voicemail', ['api_key' => ''], 'test-csrf-token');
        $this->assertContains('ai-voicemail', PluginManager::pluginsSubscribedToEvent('voicemail.transcribed'));
        $this->assertNotContains('ai-voicemail', PluginManager::pluginsSubscribedToEvent('voicemail.created'));
        $event = ['event_id' => 'vm-content-regression', 'occurred_at' => '2026-11-10T12:00:00Z',
            'data' => ['extension' => '101', 'caller' => 'test', 'duration' => 18, 'new_count' => 1]];
        $key = hash('sha256', '101:vm-content-regression');
        PluginManager::kvDelete('ai-voicemail', 'summaries', $key);
        try {
            PluginManager::deliverEventToPlugin('ai-voicemail', 'voicemail.created', $event);
            PluginManager::deliverEventToPlugin('ai-voicemail', 'voicemail.transcribed', $event);
            $this->assertNull(PluginManager::kvGet('ai-voicemail', 'summaries', $key));
            $event['data']['transcript'] = 'Please send the contract tomorrow morning.';
            $event['data']['summary'] = 'Caller requests the contract tomorrow.';
            PluginManager::deliverEventToPlugin('ai-voicemail', 'voicemail.transcribed', $event);
            $value = PluginManager::kvGet('ai-voicemail', 'summaries', $key)['value'];
            $this->assertSame($event['data']['transcript'], $value['transcript']);
            $this->assertSame($event['data']['summary'], $value['summary']);
            $event['data']['summary'] = 'A retry must not overwrite the completed result.';
            PluginManager::deliverEventToPlugin('ai-voicemail', 'voicemail.transcribed', $event);
            $this->assertSame($value, PluginManager::kvGet('ai-voicemail', 'summaries', $key)['value']);
        } finally {
            PluginManager::kvDelete('ai-voicemail', 'summaries', $key);
            PluginManager::disable('ai-voicemail', 'test-csrf-token');
        }
    }

    public function testAutoDialerRetainsAnsweredSlotAndRejectsOldAttempts(): void
    {
        $this->installMovedPluginFixture('auto-dialer');
        PluginManager::enable('auto-dialer', 'test-csrf-token');
        require_once PluginManager::customDir() . '/auto-dialer/AutoDialerService.php';
        $id = AutoDialerService::createCampaign(['name'=>'slot regression','dest_type'=>'extension','dest_id'=>'101','max_concurrent'=>1,'numbers_bulk'=>"15550001111\n15550002222"]);
        try {
            AutoDialerService::setCampaignStatus($id, 'running');
            $this->assertSame(1, AutoDialerService::tickCampaigns());
            $q=getDB()->prepare("SELECT id FROM plg_autodial_numbers WHERE campaign_id=? AND status='dialing'");
            $q->execute([$id]); $num=(int)$q->fetchColumn();
            getDB()->prepare('UPDATE plg_autodial_numbers SET attempts=2 WHERE id=?')->execute([$num]);
            AutoDialerService::handleCallback(['num_id'=>$num,'attempt'=>2,'event'=>'answered','amd_status'=>'HUMAN']);
            $this->assertSame(0, AutoDialerService::tickCampaigns());
            foreach (['answered','hangup','dial_failed'] as $event) {
                $r=AutoDialerService::handleCallback(['num_id'=>$num,'attempt'=>1,'event'=>$event]);
                $this->assertSame('stale_attempt',$r['state']);
            }
            $q=getDB()->prepare('SELECT status FROM plg_autodial_numbers WHERE id=?'); $q->execute([$num]);
            $this->assertSame('answered',$q->fetchColumn());
            AutoDialerService::handleCallback(['num_id'=>$num,'attempt'=>2,'event'=>'hangup','duration'=>30]);
            $q->execute([$num]); $this->assertSame('completed',$q->fetchColumn());
            $this->assertSame(1, AutoDialerService::tickCampaigns());
        } finally {
            AutoDialerService::deleteCampaign($id);
            PluginManager::disable('auto-dialer', 'test-csrf-token');
        }
    }

    public function testPluginSchemaSandboxAndAutoDialerLifecycle(): void
    {
        $this->installMovedPluginFixture('auto-dialer');
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

        require_once PluginManager::customDir() . '/auto-dialer/AutoDialerService.php';

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
            'num_id' => $num1, 'attempt' => 1,
            'event' => 'answered',
            'amd_status' => 'HUMAN',
            'amd_cause' => 'HUMAN-800-800',
            'uniqueid' => '1700000.1',
        ]);
        $this->assertTrue($cb1['success']);

        $cb2 = AutoDialerService::handleCallback([
            'num_id' => $num2, 'attempt' => 1,
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
        $this->installMovedPluginFixture('ai-assistant');
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
        $this->assertArrayHasKey('ms-teams', $bySlug);
        $this->assertSame('official', $bySlug['ms-teams']['type']);
        $this->assertArrayHasKey('google-auth', $bySlug);
        $this->assertSame('official', $bySlug['google-auth']['type']);
        $this->assertArrayHasKey('oidc-auth', $bySlug);
        $this->assertSame('official', $bySlug['oidc-auth']['type']);

        // Community plugins
        if (isset($bySlug['ai-assistant'])) {
            $this->assertSame('community', $bySlug['ai-assistant']['type']);
        }
    }

    public function testUploadedPluginCannotClaimOfficialType(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx_custom_plugins_' . bin2hex(random_bytes(4));
        mkdir($dir . '/fake-official', 0700, true);
        file_put_contents($dir . '/fake-official/plugin.json', json_encode([
            'slug' => 'fake-official',
            'name' => 'Fake Official',
            'version' => '1.0.0',
            'type' => 'official',
        ]));

        $prev = getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR=' . $dir);
        PluginManager::resetCache();
        try {
            $bySlug = array_column(PluginManager::allPlugins(), null, 'slug');
            $this->assertArrayHasKey('fake-official', $bySlug);
            $this->assertSame('community', $bySlug['fake-official']['type']);
            $this->assertSame('official', $bySlug['ms-teams']['type']);
        } finally {
            putenv($prev === false ? 'AIPBX_CUSTOM_PLUGINS_DIR' : 'AIPBX_CUSTOM_PLUGINS_DIR=' . $prev);
            PluginManager::resetCache();
            unlink($dir . '/fake-official/plugin.json');
            rmdir($dir . '/fake-official');
            rmdir($dir);
        }
    }

    /** 25 MB is the compressed size: a small upload must not be able to fill the disk. */
    public function testAZipThatUnpacksTooLargeIsRefused(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx_custom_plugins_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $zipFile = $dir . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $zip->addFromString('plugin.json', json_encode(['slug' => 'zip-bomb-test', 'name' => 'Bomb', 'version' => '1.0.0', 'type' => 'community']));
        $zip->addFromString('data.bin', str_repeat("\0", 210 * 1024 * 1024));
        $zip->close();
        $this->assertLessThan(25 * 1024 * 1024, (int) filesize($zipFile));

        $prev = getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR=' . $dir);
        PluginManager::resetCache();
        try {
            $res = PluginManager::installFromZip(
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $zipFile, 'size' => filesize($zipFile)],
                'test-csrf-token'
            );
            $this->assertFalse($res['success']);
            $this->assertStringContainsString('200 MB', (string) ($res['error'] ?? ''));
            $this->assertDirectoryDoesNotExist($dir . '/zip-bomb-test');
        } finally {
            putenv($prev === false ? 'AIPBX_CUSTOM_PLUGINS_DIR' : 'AIPBX_CUSTOM_PLUGINS_DIR=' . $prev);
            PluginManager::resetCache();
            @unlink($zipFile);
        }
    }

    public function testZipUploadIsStagedInsideThePluginsDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx_custom_plugins_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $zipFile = $dir . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $zip->addFromString('plugin.json', json_encode(['slug' => 'zip-upload-test', 'name' => 'Zip Upload Test']));
        $zip->addFromString('admin/page.php', "<?php echo 'ok';\n");
        $zip->close();

        $prev = getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR=' . $dir);
        PluginManager::resetCache();
        try {
            $res = PluginManager::installFromZip(
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $zipFile, 'size' => filesize($zipFile)],
                'test-csrf-token'
            );
            $this->assertTrue($res['success'], (string)($res['error'] ?? ''));
            $this->assertFileExists($dir . '/zip-upload-test/admin/page.php');
            // The archive is unpacked next to the plugins (Apache has a private
            // /tmp, and rename() cannot move a directory out of it) and nothing
            // is left behind. A test cannot reproduce that mount boundary; the
            // fix was verified by uploading through the portal on a real install.
            $this->assertSame([], glob($dir . '/.stage-*', GLOB_ONLYDIR));
        } finally {
            PluginManager::uninstall('zip-upload-test', 'test-csrf-token');
            putenv($prev === false ? 'AIPBX_CUSTOM_PLUGINS_DIR' : 'AIPBX_CUSTOM_PLUGINS_DIR=' . $prev);
            PluginManager::resetCache();
            @unlink($zipFile);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testCatalogInstallChecksTheChecksumAndThePluginAskedFor(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx_custom_plugins_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $zipOf = static function (string $slug) use ($dir): string {
            $file = $dir . '-' . $slug . '.zip';
            $zip = new ZipArchive();
            $zip->open($file, ZipArchive::CREATE);
            $zip->addFromString('plugin.json', json_encode(['slug' => $slug, 'name' => 'Catalog Test', 'version' => '1.0.0']));
            $zip->addFromString('admin/page.php', "<?php echo 'ok';\n");
            $zip->close();
            $data = (string) file_get_contents($file);
            unlink($file);
            return $data;
        };
        $good = $zipOf('catalog-test');
        $other = $zipOf('catalog-other');
        $zips = ['catalog-test' => $good, 'catalog-swap' => $other, 'catalog-bad' => $good, 'catalog-old' => $zipOf('catalog-old')];
        $entry = static fn(string $slug, string $data): array => ['slug' => $slug, 'name' => 'Catalog Test', 'version' => '1.0.0', 'sha256' => hash('sha256', $data)];
        $catalog = ['plugins' => [
            $entry('catalog-test', $good),
            $entry('catalog-swap', $other),            // the right checksum, but another plugin inside
            $entry('catalog-bad', 'something else'),   // the download does not match the catalog
            ['slug' => 'catalog-nover', 'sha256' => hash('sha256', $good)], // no version: not listed
        ]];
        $catalog['plugins'][] = ['version' => '2.0.0'] + $entry('catalog-old', $zips['catalog-old']); // the zip holds 1.0.0
        $catalog = ['plugins' => [...$catalog['plugins'],
        ]];

        $prev = getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR=' . $dir);
        $wasOn = getSystemSetting('plugins_community_catalog', '0');
        setSystemSetting('plugins_community_catalog', '1');
        file_put_contents($dir . '/.catalog.json', json_encode($catalog));
        PluginManager::$fetch = static function (string $url) use ($zips): ?string {
            return preg_match('#/([a-z-]+)\.zip$#', $url, $m) ? ($zips[$m[1]] ?? null) : null;
        };
        PluginManager::resetCache();
        try {
            $this->assertSame(['catalog-test', 'catalog-swap', 'catalog-bad', 'catalog-old'], array_keys(PluginManager::communityCatalog()));
            $this->assertFalse(PluginManager::installFromCatalog('catalog-old', 'test-csrf-token')['success'],
                'a zip of another version than the catalog lists is refused');

            $res = PluginManager::installFromCatalog('catalog-bad', 'test-csrf-token');
            $this->assertFalse($res['success'], 'a download that does not match the catalog is refused');
            $res = PluginManager::installFromCatalog('catalog-swap', 'test-csrf-token');
            $this->assertFalse($res['success'], 'a zip holding another plugin is refused');
            $this->assertDirectoryDoesNotExist($dir . '/catalog-other');

            $res = PluginManager::installFromCatalog('catalog-test', 'test-csrf-token');
            $this->assertTrue($res['success'], (string) ($res['error'] ?? ''));
            $this->assertFileExists($dir . '/catalog-test/admin/page.php');
            $this->assertSame([], glob($dir . '/.download-*'), 'no download is left behind');
        } finally {
            PluginManager::$fetch = null;
            PluginManager::uninstall('catalog-test', 'test-csrf-token');
            setSystemSetting('plugins_community_catalog', (string) $wasOn);
            putenv($prev === false ? 'AIPBX_CUSTOM_PLUGINS_DIR' : 'AIPBX_CUSTOM_PLUGINS_DIR=' . $prev);
            PluginManager::resetCache();
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
