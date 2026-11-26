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

    public function testBindingResolveReturnsTheChosenModelOrNullWhenOff(): void
    {
        getDB()->exec("INSERT INTO ai_bindings (feature, scope_ref, task, language, mode, model_ref_id, updated_at)
            VALUES ('test_p3', 'global', 'chat', 'default', 'explicit', 'cloud:openai', NOW())
            ON DUPLICATE KEY UPDATE mode = 'explicit', model_ref_id = 'cloud:openai'");
        $this->assertSame('cloud:openai', AiBindingService::resolve('test_p3', 'chat'));

        AiBindingService::save('test_p3', 'chat', '');
        $this->assertNull(AiBindingService::resolve('test_p3', 'chat'));
    }

    public function testAssistantRulesOnlyAndPermissionSeparation(): void
    {
        $this->assertTrue(AssistantChatService::canAsk('admin'));
        $this->assertTrue(AssistantChatService::canChange('admin'));
        $this->assertFalse(AssistantChatService::canChange('read_only_admin'));

        AiBindingService::save('assistant', 'chat', '');
        $this->assertSame('rules_only', AssistantModel::resolvedMode());

        getDB()->exec("DELETE FROM ai_bindings WHERE feature = 'assistant'");
    }

    public function testTtsResolveProviderEnforcesLocalAndCloudPluginState(): void
    {
        $db = getDB();
        $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug IN ('ailocal', 'aicloud')");
        PluginManager::resetCache();
        $this->assertSame('local', AiTtsService::resolveProvider('local:piper-tr', 'tr'));
        $this->assertSame('openai', AiTtsService::resolveProvider('cloud:openai', 'tr'));

        $db->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug = 'aicloud'");
        PluginManager::resetCache();
        $this->expectException(TtsException::class);
        AiTtsService::resolveProvider('cloud:openai', 'tr');
    }
}
