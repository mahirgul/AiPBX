<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../src/services/ai/AiBindingService.php';
require_once __DIR__ . '/../../src/services/ai/AssistantSettingsService.php';
require_once __DIR__ . '/../../src/services/ai/AssistantModel.php';
require_once __DIR__ . '/../../src/controllers/AssistantController.php';

final class AssistantSettingsTest extends TestCase
{
    private const KEYS = [
        'assistant.model',
        'assistant.roles',
        'assistant.home_roles',
        'assistant.role_kb',
        'assistant.history_days',
        'assistant.suggestions',
        'privacy_block_cloud_ai',
    ];

    /** @var array<string, string|null> */
    private array $prevSettings = [];
    private ?string $prevRole = null;
    private $prevPluginRow = false;

    protected function setUp(): void
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        foreach (self::KEYS as $k) {
            $stmt->execute([$k]);
            $v = $stmt->fetchColumn();
            $this->prevSettings[$k] = $v === false ? null : (string) $v;
        }
        $db->prepare('DELETE FROM sys_settings WHERE setting_key IN (' . implode(',', array_fill(0, count(self::KEYS), '?')) . ')')
           ->execute(self::KEYS);
        $this->prevRole = $_SESSION['user_role'] ?? null;

        $pStmt = $db->prepare("SELECT enabled FROM sys_plugins WHERE slug = 'pbx-assistant'");
        $pStmt->execute();
        $this->prevPluginRow = $pStmt->fetchColumn();
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
             VALUES ('pbx-assistant', 'PBX Asistan', '1.0.0', 'official', 1, 0, 1, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = 1, updated_at = ?"
        )->execute([$now, $now, $now]);
        PluginManager::resetCache();
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM ai_bindings WHERE feature = 'assistant'");
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        $ins = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($this->prevSettings as $k => $v) {
            if ($v === null) {
                $del->execute([$k]);
            } else {
                $ins->execute([$k, $v]);
            }
        }
        if ($this->prevPluginRow === false) {
            $db->exec("DELETE FROM sys_plugins WHERE slug = 'pbx-assistant'");
        } else {
            $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = 'pbx-assistant'")->execute([(int) $this->prevPluginRow]);
        }
        PluginManager::resetCache();
        if ($this->prevRole === null) {
            unset($_SESSION['user_role']);
        } else {
            $_SESSION['user_role'] = $this->prevRole;
        }
    }

    public function testDefaults(): void
    {
        $this->assertNull(AiBindingService::resolve('assistant', 'chat'));
        $this->assertTrue(AssistantSettingsService::canAsk('admin'));
        $this->assertTrue(AssistantSettingsService::canChange('admin'));
        $this->assertFalse(AssistantSettingsService::canAsk('user'));
        $this->assertFalse(AssistantSettingsService::canChange('user'));
        $this->assertSame('/assistant', roleHomePath('admin'));
        $this->assertSame('/my-phone', roleHomePath('user'));
        $this->assertSame('/cc-agent', roleHomePath('cc_agent'));
        $this->assertSame(10, AssistantSettingsService::historyDays());
        $this->assertSame(AssistantSettingsService::defaultSuggestions(), AssistantSettingsService::suggestions());

        getDB()->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug = 'pbx-assistant'");
        PluginManager::resetCache();
        $this->assertSame('/dashboard', roleHomePath('admin'));
    }

    public function testSaveAndHomeFallbackWhenAskDisabled(): void
    {
        AssistantSettingsService::save([
            'assistant_model_ref' => '',
            'role_ask' => ['user' => '1'],
            'role_change' => ['cc_agent' => '1'],
            'home_role' => [
                'admin' => 'default',
                'user' => 'assistant',
                'fax_user' => 'assistant', // ask is false for fax_user -> must fall back
            ],
            'role_kb' => [
                'user' => '5',
                'cc_agent' => '-1',
            ],
            'history_days' => '0',
            'suggestions' => "Line one\n\nLine two\nLine three",
        ], ['admin', 'user', 'cc_agent', 'fax_user']);

        $this->assertNull(AiBindingService::resolve('assistant', 'chat'));
        $this->assertSame(5, AssistantSettingsService::roleCollection('user'));
        $this->assertSame(-1, AssistantSettingsService::roleCollection('cc_agent'));
        $this->assertSame(0, AssistantSettingsService::roleCollection('admin'));
        $this->assertTrue(AssistantSettingsService::canAsk('user'));
        $this->assertFalse(AssistantSettingsService::canChange('user'));
        $this->assertTrue(AssistantSettingsService::canAsk('cc_agent'));
        $this->assertTrue(AssistantSettingsService::canChange('cc_agent'));
        $this->assertFalse(AssistantSettingsService::canAsk('fax_user'));

        $this->assertSame('/dashboard', roleHomePath('admin'));
        $this->assertSame('/assistant', roleHomePath('user'));
        $this->assertSame('/fax-inbox', roleHomePath('fax_user'));

        $this->assertSame(0, AssistantSettingsService::historyDays());
        $this->assertSame(['Line one', 'Line two', 'Line three'], AssistantSettingsService::suggestions());
    }

    public function testHasModulePermissionForAssistant(): void
    {
        AssistantSettingsService::save([
            'role_ask' => ['user' => '1'],
            'role_change' => [],
        ], ['admin', 'user', 'cc_agent']);

        $_SESSION['user_role'] = 'admin';
        $this->assertTrue(hasModulePermission('assistant', 'access'));
        $this->assertTrue(hasModulePermission('assistant', 'edit'));

        $_SESSION['user_role'] = 'user';
        $this->assertTrue(hasModulePermission('assistant', 'access'));
        $this->assertFalse(hasModulePermission('assistant', 'edit'));

        $_SESSION['user_role'] = 'cc_agent';
        $this->assertFalse(hasModulePermission('assistant', 'access'));
        $this->assertFalse(hasModulePermission('assistant', 'edit'));
    }

    public function testInvalidModelRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssistantSettingsService::save(['assistant_model_ref' => 'invalid:model!']);
    }

    public function testAssistantModelHonorsRulesOnlyAndPrivacyBlock(): void
    {
        AiBindingService::save('assistant', 'chat', '');
        $this->assertSame('rules_only', AssistantModel::resolvedMode());
        $this->assertNull(AssistantModel::ask('hello'));

        getDB()->exec("INSERT INTO ai_bindings (feature, scope_ref, task, language, mode, model_ref_id, updated_at)
            VALUES ('assistant', 'global', 'chat', 'default', 'explicit', 'cloud:gemini', NOW())
            ON DUPLICATE KEY UPDATE mode = 'explicit', model_ref_id = 'cloud:gemini'");
        setSystemSetting('privacy_block_cloud_ai', '1');
        $this->assertSame('rules_only', AssistantModel::resolvedMode());
        $this->assertNull(AssistantModel::ask('hello'));
    }

    public function testAssistantControllerHasSettingsTab(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/controllers/AssistantController.php');
        $this->assertIsString($src);
        $this->assertStringContainsString('settings', $src);
        $this->assertFileDoesNotExist(__DIR__ . '/../../src/controllers/AiSettingsController.php');
    }

    public function testReadOnlyRoleCannotGainChangePermissionThroughAssistant(): void
    {
        setSystemSetting('assistant.roles', '{"read_only_admin":{"ask":true,"change":true}}');
        $this->assertTrue(AssistantSettingsService::canAsk('read_only_admin'));
        $this->assertFalse(AssistantSettingsService::canChange('read_only_admin'));
    }
}
