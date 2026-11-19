<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiBindingService.php';
require_once __DIR__ . '/../../src/services/ai/AssistantSettingsService.php';
require_once __DIR__ . '/../../src/services/ai/AssistantModel.php';
require_once __DIR__ . '/../../src/services/ai/AssistantChatService.php';
require_once __DIR__ . '/../../src/services/AiTtsService.php';

/**
 * Faz 3 tests: Assistant binding/rules_only/RBAC separation & TTS Studio binding resolution.
 */
final class AiPhase3AssistantTtsTest extends TestCase
{
    private array $prevPlugins = [];

    protected function setUp(): void
    {
        $db = getDB();
        $this->prevPlugins = $db->query("SELECT slug, enabled FROM sys_plugins WHERE slug IN ('ailocal', 'aicloud')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $db->exec("DELETE FROM ai_bindings WHERE feature IN ('test_p3', 'panel_default') AND language IN ('default', 'de')");
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM ai_bindings WHERE feature IN ('test_p3', 'panel_default') AND language IN ('default', 'de')");
        foreach ($this->prevPlugins as $slug => $en) {
            $st = $db->prepare('UPDATE sys_plugins SET enabled = ? WHERE slug = ?');
            $st->execute([(int) $en, $slug]);
        }
        PluginManager::resetCache();
    }

    public function testBindingResolveHandlesLocalCloudExplicitOffAndLanguageOverride(): void
    {
        AiBindingService::saveBinding('panel_default', 'chat', 'explicit', 'local:llama3', 'default', 'local');
        AiBindingService::saveBinding('panel_default', 'chat', 'explicit', 'local:llama3-de', 'de', 'local');
        AiBindingService::saveBinding('panel_default', 'chat', 'explicit', 'cloud:openai', 'default', 'cloud');

        AiBindingService::saveBinding('test_p3', 'chat', 'local_default', null, 'default', 'global');
        $this->assertSame('local:llama3', AiBindingService::resolve('test_p3', 'chat', 'tr', 'global'));
        $this->assertSame('local:llama3-de', AiBindingService::resolve('test_p3', 'chat', 'de', 'global'));

        // Local default never falls back to cloud when local is unset
        getDB()->exec("DELETE FROM ai_bindings WHERE feature = 'panel_default' AND scope_ref = 'local'");
        $this->assertNull(AiBindingService::resolve('test_p3', 'chat', 'tr', 'global'));

        AiBindingService::saveBinding('test_p3', 'chat', 'cloud_default', null, 'default', 'global');
        $this->assertSame('cloud:openai', AiBindingService::resolve('test_p3', 'chat', 'tr', 'global'));

        AiBindingService::saveBinding('test_p3', 'chat', 'off', null, 'default', 'global');
        $this->assertNull(AiBindingService::resolve('test_p3', 'chat', 'tr', 'global'));
    }

    public function testAssistantRulesOnlyAndPermissionSeparation(): void
    {
        $this->assertTrue(AssistantChatService::canAsk('admin'));
        $this->assertTrue(AssistantChatService::canChange('admin'));
        $this->assertFalse(AssistantChatService::canChange('read_only_admin'));

        AiBindingService::saveBinding('assistant', 'chat', 'off', null, 'default', 'global');
        $this->assertSame('rules_only', AssistantModel::resolvedMode());

        getDB()->exec("DELETE FROM ai_bindings WHERE feature = 'assistant'");
    }

    public function testTtsResolveProviderEnforcesLocalAndCloudPluginState(): void
    {
        $db = getDB();
        AiBindingService::saveBinding('panel_default', 'tts', 'explicit', 'local:piper-tr', 'default', 'local');
        AiBindingService::saveBinding('panel_default', 'tts', 'explicit', 'cloud:openai', 'default', 'cloud');

        $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug IN ('ailocal', 'aicloud')");
        PluginManager::resetCache();
        $this->assertSame('local', AiTtsService::resolveProvider('local_default', 'tr'));
        $this->assertSame('openai', AiTtsService::resolveProvider('cloud_default', 'tr'));

        $db->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug = 'aicloud'");
        PluginManager::resetCache();
        $this->expectException(TtsException::class);
        AiTtsService::resolveProvider('cloud_default', 'tr');
    }
}
