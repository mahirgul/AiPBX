<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates the database schema for:
 * 1. REST API (/api/v1/*) Bearer tokens & scopes (`sys_api_tokens`)
 * 2. Outbound Webhooks & async delivery queue (`sys_webhooks`, `sys_webhook_queue`)
 * 3. First-Class Plugin System (`sys_plugins`, `sys_plugin_settings`, `sys_plugin_kv`)
 *
 * Also auto-enables existing modular integrations (`google-auth`, `ms-teams`,
 * `web-widgets`, `ai-cloud`, `ai-tts`, `ai-apps`, `ai-models`) on upgrading
 * installations where those features are already configured, while leaving
 * fresh installations lean by default.
 */
final class CreateApiWebhookAndPluginTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_api_tokens')) {
            $this->table('sys_api_tokens')
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('token_prefix', 'string', ['limit' => 16])
                ->addColumn('token_hash', 'char', ['limit' => 64])
                ->addColumn('scopes_json', 'text', ['null' => false])
                ->addColumn('allowed_ips', 'text', ['null' => true])
                ->addColumn('rate_limit_per_min', 'integer', ['default' => 120])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('last_used_at', 'datetime', ['null' => true])
                ->addColumn('last_used_ip', 'string', ['limit' => 45, 'null' => true])
                ->addColumn('created_by', 'integer', ['null' => true])
                ->addColumn('plugin_slug', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('is_active', 'boolean', ['default' => 1])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['token_hash'], ['unique' => true])
                ->addIndex(['plugin_slug'])
                ->create();
        }

        if (!$this->hasTable('sys_webhooks')) {
            $this->table('sys_webhooks')
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('target_url', 'string', ['limit' => 500])
                ->addColumn('secret_encrypted', 'text', ['null' => true])
                ->addColumn('events_json', 'text', ['null' => false])
                ->addColumn('headers_json', 'text', ['null' => true])
                ->addColumn('timeout_sec', 'integer', ['default' => 5])
                ->addColumn('max_retries', 'integer', ['default' => 5])
                ->addColumn('is_active', 'boolean', ['default' => 1])
                ->addColumn('last_status_code', 'integer', ['null' => true])
                ->addColumn('last_triggered_at', 'datetime', ['null' => true])
                ->addColumn('last_error', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('failure_streak', 'integer', ['default' => 0])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        if (!$this->hasTable('sys_webhook_queue')) {
            $this->table('sys_webhook_queue')
                ->addColumn('webhook_id', 'integer', ['null' => true])
                ->addColumn('plugin_slug', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('event_id', 'char', ['limit' => 36])
                ->addColumn('event_name', 'string', ['limit' => 64])
                ->addColumn('payload_json', 'text', ['null' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
                ->addColumn('attempts', 'integer', ['default' => 0])
                ->addColumn('max_attempts', 'integer', ['default' => 5])
                ->addColumn('next_attempt_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending'])
                ->addColumn('last_http_code', 'integer', ['null' => true])
                ->addColumn('last_error', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('delivered_at', 'datetime', ['null' => true])
                ->addIndex(['status', 'next_attempt_at'])
                ->addIndex(['event_name'])
                ->addIndex(['created_at'])
                ->create();
        }

        if (!$this->hasTable('sys_plugins')) {
            $this->table('sys_plugins', ['id' => false, 'primary_key' => ['slug']])
                ->addColumn('slug', 'string', ['limit' => 64])
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('version', 'string', ['limit' => 32])
                ->addColumn('source', 'string', ['limit' => 24, 'default' => 'official'])
                ->addColumn('enabled', 'boolean', ['default' => 0])
                ->addColumn('worker_enabled', 'boolean', ['default' => 0])
                ->addColumn('config_version', 'integer', ['default' => 1])
                ->addColumn('installed_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        if (!$this->hasTable('sys_plugin_settings')) {
            $this->table('sys_plugin_settings', ['id' => false, 'primary_key' => ['plugin_slug', 'setting_key']])
                ->addColumn('plugin_slug', 'string', ['limit' => 64])
                ->addColumn('setting_key', 'string', ['limit' => 120])
                ->addColumn('setting_value', 'text', ['null' => true])
                ->addColumn('is_secret', 'boolean', ['default' => 0])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        if (!$this->hasTable('sys_plugin_kv')) {
            $this->table('sys_plugin_kv')
                ->addColumn('plugin_slug', 'string', ['limit' => 64])
                ->addColumn('collection', 'string', ['limit' => 64])
                ->addColumn('item_key', 'string', ['limit' => 128])
                ->addColumn('payload_json', 'text', ['null' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['plugin_slug', 'collection', 'item_key'], ['unique' => true])
                ->create();
        }

        // Auto-detect configured integrations on upgrading installations so
        // existing users experience zero disruption while fresh installations
        // stay lean until plugins are explicitly enabled in Admin -> Plugins.
        $this->autoEnableConfiguredPlugins();
    }

    private function autoEnableConfiguredPlugins(): void
    {
        $checks = [
            'google-auth' => [
                'name' => 'Google Sign-In (OAuth 2.0)',
                'sql'  => "SELECT COUNT(*) FROM sys_settings WHERE setting_key = 'google_client_id' AND setting_value != ''",
            ],
            'ms-teams' => [
                'name' => 'Microsoft Teams Direct Routing & Webhooks',
                'sql'  => "SELECT COUNT(*) FROM sys_settings WHERE (setting_key IN ('teams_enabled', 'teams_webhook_enabled') AND setting_value = '1') OR (setting_key = 'teams_domain' AND setting_value != '')",
            ],
            'web-widgets' => [
                'name' => 'Website Call Widgets & WordPress Plugin',
                'sql'  => $this->hasTable('pbx_web_widgets') ? "SELECT COUNT(*) FROM pbx_web_widgets" : null,
            ],
            'ai-cloud' => [
                'name' => 'Cloud & OpenAI-Compatible AI Integration',
                'sql'  => "SELECT COUNT(*) FROM sys_settings WHERE (setting_key LIKE 'ai_tts.%' AND setting_value != '') OR (setting_key = 'ai_vm_transcribe' AND setting_value = '1')",
            ],
            'ai-tts' => [
                'name' => 'AI Text-to-Speech Studio',
                'sql'  => $this->hasTable('ai_tts_history') ? "SELECT (SELECT COUNT(*) FROM ai_tts_history) + (SELECT COUNT(*) FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' AND setting_value != '')" : null,
            ],
            'ai-apps' => [
                'name' => 'AI Voice Applications (IVR Bots)',
                'sql'  => $this->hasTable('pbx_ai_apps') ? "SELECT COUNT(*) FROM pbx_ai_apps" : null,
            ],
            'ai-models' => [
                'name' => 'Local TTS & Speech Runtime',
                'sql'  => "SELECT COUNT(*) FROM sys_settings WHERE setting_key LIKE 'ai_engine.%' AND setting_value LIKE 'local:%'",
            ],
        ];

        foreach ($checks as $slug => $meta) {
            if (empty($meta['sql'])) {
                continue;
            }
            try {
                $row = $this->fetchRow("SELECT ({$meta['sql']}) AS cnt");
                $inUse = (int)($row['cnt'] ?? 0) > 0;
                if ($inUse) {
                    $safeSlug = addslashes($slug);
                    $safeName = addslashes($meta['name']);
                    $this->execute(
                        "INSERT IGNORE INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                         VALUES ('{$safeSlug}', '{$safeName}', '1.0.0', 'official', 1, 0, 1, NOW(), NOW())"
                    );
                }
            } catch (\Throwable $e) {
                // Best-effort detection during migration
            }
        }
    }

    public function down(): void
    {
        foreach (['sys_plugin_kv', 'sys_plugin_settings', 'sys_plugins', 'sys_webhook_queue', 'sys_webhooks', 'sys_api_tokens'] as $t) {
            if ($this->hasTable($t)) {
                $this->table($t)->drop()->save();
            }
        }
    }
}
