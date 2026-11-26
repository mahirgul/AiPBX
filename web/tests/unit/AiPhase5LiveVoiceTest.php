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

    public function testAnUnusableChoiceResolvesToNothingInsteadOfAnotherModel(): void
    {
        $db = getDB();
        $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug IN ('ailocal', 'aicloud')");
        PluginManager::resetCache();
        // An explicit local voice stays local; cloud blocked by the caller means nothing, not a local voice.
        $this->assertSame('ema-lightning', AiAppService::resolveTaskBinding(0, 'tts', 'local:ema-lightning', 'tr', true));
        $this->assertSame('', AiAppService::resolveTaskBinding(0, 'tts', 'cloud:openai', 'tr', false));
        // Local speech to text and language models no longer exist.
        $this->assertSame('', AiAppService::resolveTaskBinding(0, 'stt', 'local:whisper-small-tr', 'tr', true));
        // Defaults and per-language engines are gone: they resolve to nothing.
        $this->assertSame('', AiAppService::resolveTaskBinding(0, 'tts', 'local_default', 'tr', true));
        $this->assertSame('', AiAppService::resolveTaskBinding(0, 'tts', 'engine:tr', 'tr', true));
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
        $this->assertSame('keywords', $cfg['understand']);
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
