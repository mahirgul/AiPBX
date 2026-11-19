<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../src/services/ai/AssistantSettingsService.php';
require_once __DIR__ . '/../../src/services/ai/AssistantModel.php';
require_once __DIR__ . '/../../src/controllers/AssistantController.php';

final class AssistantSettingsTest extends TestCase
{
    private const KEYS = [
        'assistant.model',
        'assistant.roles',
        'assistant.home_roles',
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

        $pStmt = $db->prepare("SELECT enabled FROM sys_plugins WHERE slug = 'ai-assistant'");
        $pStmt->execute();
        $this->prevPluginRow = $pStmt->fetchColumn();
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
             VALUES ('ai-assistant', 'PBX Asistan', '1.0.0', 'official', 1, 0, 1, ?, ?)
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
            $db->exec("DELETE FROM sys_plugins WHERE slug = 'ai-assistant'");
        } else {
            $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = 'ai-assistant'")->execute([(int) $this->prevPluginRow]);
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
        $this->assertSame('', AssistantSettingsService::model());
        $this->assertTrue(AssistantSettingsService::canAsk('admin'));
        $this->assertTrue(AssistantSettingsService::canChange('admin'));
        $this->assertFalse(AssistantSettingsService::canAsk('user'));
        $this->assertFalse(AssistantSettingsService::canChange('user'));
        $this->assertSame('/assistant', roleHomePath('admin'));
        $this->assertSame('/my-phone', roleHomePath('user'));
        $this->assertSame('/cc-agent', roleHomePath('cc_agent'));
        $this->assertSame(10, AssistantSettingsService::historyDays());
        $this->assertSame(AssistantSettingsService::defaultSuggestions(), AssistantSettingsService::suggestions());

        getDB()->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug = 'ai-assistant'");
        PluginManager::resetCache();
        $this->assertSame('/dashboard', roleHomePath('admin'));
    }

    public function testSaveAndHomeFallbackWhenAskDisabled(): void
    {
        AssistantSettingsService::save([
            'assistant_model' => 'rules_only',
            'role_ask' => ['user' => '1'],
            'role_change' => ['cc_agent' => '1'],
            'home_role' => [
                'admin' => 'default',
                'user' => 'assistant',
                'fax_user' => 'assistant', // ask is false for fax_user -> must fall back
            ],
            'history_days' => '0',
            'suggestions' => "Line one\n\nLine two\nLine three",
        ], ['admin', 'user', 'cc_agent', 'fax_user']);

        $this->assertSame('rules_only', AssistantSettingsService::model());
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
        AssistantSettingsService::save(['assistant_model' => 'invalid:model!']);
    }

    public function testAssistantModelHonorsRulesOnlyAndPrivacyBlock(): void
    {
        setSystemSetting('assistant.model', 'rules_only');
        $this->assertSame('rules_only', AssistantModel::resolvedMode());
        $this->assertNull(AssistantModel::ask('hello'));

        setSystemSetting('assistant.model', 'cloud:gemini');
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

    public function testSelectedLocalModelRunsLocallyEvenWhenCloudIsBlocked(): void
    {
        $token = tempnam(sys_get_temp_dir(), 'asst-token-');
        file_put_contents($token, str_repeat('ab', 32));
        putenv('AIPBX_AI_TOKEN_FILE=' . $token);
        setSystemSetting('assistant.model', 'local:hf-test-model');
        setSystemSetting('privacy_block_cloud_ai', '1');
        (new ReflectionProperty(LocalAiService::class, 'modelsCache'))->setValue(null, null);
        $db = getDB();
        $previousUser = $_SESSION['user_id'] ?? null;
        $previousAi = $db->query("SELECT enabled FROM sys_plugins WHERE slug='ai'")->fetchColumn();
        $previousLocal = $db->query("SELECT enabled FROM sys_plugins WHERE slug='ailocal'")->fetchColumn();
        $db->exec("INSERT INTO sys_plugins (slug,enabled) VALUES ('ai',1),('ailocal',1) ON DUPLICATE KEY UPDATE enabled=1");
        PluginManager::resetCache();
        $db->exec("INSERT INTO sys_users (username,password_hash,full_name,email,role,is_active,is_device) VALUES ('asst_gateway_local','','Test','','admin',1,0)");
        $uid = (int) $db->lastInsertId();
        $_SESSION['user_id'] = $uid;
        $urls = [];
        TtsHttp::$transport = static function ($method, $url, $headers, $body) use (&$urls): array {
            $urls[] = $url;
            if (str_ends_with($url, '/v1/models')) {
                $data = ['models' => [['id' => 'hf-test-model', 'kind' => 'llm', 'state' => 'ready']]];
            } else {
                $request = json_decode($body, true);
                AiGateway::resolve($request['request_id'], hash('sha256', $body));
                $data = ['protocol_version' => 1, 'request_id' => $request['request_id'], 'status' => 'succeeded', 'output' => 'Local answer'];
            }
            return ['status' => 200, 'body' => json_encode($data), 'type' => 'application/json'];
        };
        try {
            $this->assertSame('local:hf-test-model', AssistantModel::resolvedMode());
            $this->assertSame('Local answer', AssistantModel::ask('Question'));
            $this->assertSame(['http://127.0.0.1:8790/v1/models', 'http://127.0.0.1:8790/v1/gateway/execute'], $urls);
        } finally {
            $db->prepare('DELETE FROM sys_users WHERE id=?')->execute([$uid]);
            if ($previousUser === null) { unset($_SESSION['user_id']); } else { $_SESSION['user_id'] = $previousUser; }
            if ($previousAi === false) { $db->exec("DELETE FROM sys_plugins WHERE slug='ai'"); }
            else { $db->prepare("UPDATE sys_plugins SET enabled=? WHERE slug='ai'")->execute([$previousAi]); }
            if ($previousLocal === false) { $db->exec("DELETE FROM sys_plugins WHERE slug='ailocal'"); }
            else { $db->prepare("UPDATE sys_plugins SET enabled=? WHERE slug='ailocal'")->execute([$previousLocal]); }
            PluginManager::resetCache();
            TtsHttp::$transport = null;
            (new ReflectionProperty(LocalAiService::class, 'modelsCache'))->setValue(null, null);
            putenv('AIPBX_AI_TOKEN_FILE');
            unlink($token);
        }
    }

}
