<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/services/ai/AiAppService.php';
require_once __DIR__ . '/../../src/services/PrivacyService.php';
require_once __DIR__ . '/../../src/sync/SyncAiApps.php';

final class AiPhase5LiveVoiceTest extends TestCase
{
    private ?string $origPrivacy = null;
    /** @var array<string, int|false> */
    private array $prevPlugins = [];

    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'phase5_csrf';
        $_SESSION['user_id'] = null;
        $db = getDB();
        foreach (['ailocal', 'aicloud'] as $slug) {
            $prev = $db->query("SELECT enabled FROM sys_plugins WHERE slug = '{$slug}'")->fetchColumn();
            $this->prevPlugins[$slug] = $prev === false ? false : (int) $prev;
            $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = '{$slug}'");
        }
        if (class_exists('PluginManager')) {
            PluginManager::resetCache();
        }
        $val = $db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'privacy_block_cloud_ai'")->fetchColumn();
        $this->origPrivacy = $val === false ? null : (string) $val;
        $db->exec("DELETE FROM pbx_ai_apps WHERE title LIKE 'Phase5App%'");
        $db->exec("DELETE FROM ai_bindings WHERE feature = 'ai_app' OR (feature = 'panel_default' AND scope_ref IN ('local','cloud'))");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_type IN ('ai_app','ai_agent','privacy')");
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_ai_apps WHERE title LIKE 'Phase5App%'");
        $db->exec("DELETE FROM ai_bindings WHERE feature = 'ai_app' OR (feature = 'panel_default' AND scope_ref IN ('local','cloud'))");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_type IN ('ai_app','ai_agent','privacy')");
        if ($this->origPrivacy === null) {
            $db->exec("DELETE FROM sys_settings WHERE setting_key = 'privacy_block_cloud_ai'");
        } else {
            setSystemSetting('privacy_block_cloud_ai', $this->origPrivacy);
        }
        foreach ($this->prevPlugins as $slug => $prev) {
            if ($prev !== false) {
                $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = ?")->execute([$prev, $slug]);
            }
        }
        if (class_exists('PluginManager')) {
            PluginManager::resetCache();
        }
    }

    public function testPerAppBindingsAndLocalNeverFallsBackToCloud(): void
    {
        AiBindingService::saveBinding('panel_default', 'tts', 'explicit', 'cloud:openai', 'default', 'cloud');
        AiBindingService::saveBinding('panel_default', 'stt', 'explicit', 'local:vosk-tr-small', 'default', 'local');

        $appId = AiAppService::save([
            'title' => 'Phase5App 1',
            'app_type' => 'voice_requests',
            'model_id' => 'local_default',
            'stt_model' => 'local_default',
            'understand' => 'auto',
            'greeting' => 'Merhaba',
            'intents' => [['name' => 'Test', 'keywords' => 'test']],
            'dest_type' => 'hangup',
            'dest_id' => '',
            'is_active' => '1',
        ]);

        // local_default must resolve to local STT, never cloud TTS even though cloud panel_default exists
        $this->assertSame('vosk-tr-small', AiAppService::resolveTaskBinding($appId, 'stt', 'local_default', 'tr', true));
        $ttsResolved = AiAppService::resolveTaskBinding($appId, 'tts', 'local_default', 'tr', true);
        $this->assertFalse(str_starts_with($ttsResolved, 'cloud:'));

        // Dependencies tracked in ai_bindings for local scope
        $deps = AiBindingService::dependenciesForScope('local');
        $labels = array_column($deps, 'label');
        $this->assertContains('ai_app (tts/default)', $labels);
        $this->assertContains('ai_app (stt/default)', $labels);
    }

    public function testPrivacyBlockStripsCloudCredentialsFromAppServiceConfig(): void
    {
        setSystemSetting('privacy_block_cloud_ai', '1');
        $cfg = AiAppService::serviceConfig([
            'id' => 99,
            'model_id' => 'cloud:openai',
            'speed' => 1.0,
            'config' => json_encode([
                'stt_model' => 'cloud:openai',
                'stt_lang' => 'tr',
                'understand' => 'cloud:openai',
                'conversation' => true,
                'instructions' => 'Test',
                'intents' => [],
            ]),
        ]);

        $this->assertArrayNotHasKey('tts_cloud', $cfg);
        $this->assertArrayNotHasKey('stt_cloud', $cfg);
        $this->assertArrayNotHasKey('llm_cloud', $cfg);
        $this->assertSame('auto', $cfg['understand']);
    }


    public function testPrivacyComplianceMarksAiAppsPendingSync(): void
    {
        $res = PrivacyService::saveCompliance([
            'csrf_token' => 'phase5_csrf',
            'block_cloud_ai' => '1',
        ]);
        $this->assertTrue($res['success']);
        $domains = getDB()->query("SELECT domain FROM sys_pending_sync WHERE entity_type = 'privacy'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('ai_apps', $domains);
        $this->assertNotContains('ai_agents', $domains);
    }
}
