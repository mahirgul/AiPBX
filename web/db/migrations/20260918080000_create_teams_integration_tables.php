<?php

use Phinx\Migration\AbstractMigration;

final class CreateTeamsIntegrationTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. Teams User Mappings tablosu
        if (!$this->hasTable('teams_user_mappings')) {
            $table = $this->table('teams_user_mappings', ['id' => true, 'primary_key' => ['id'], 'collation' => 'utf8mb4_general_ci']);
            $table->addColumn('extension', 'string', ['limit' => 20, 'null' => false])
                  ->addColumn('teams_upn', 'string', ['limit' => 150, 'null' => false])
                  ->addColumn('phone_number', 'string', ['limit' => 50, 'null' => true])
                  ->addColumn('direct_routing_enabled', 'boolean', ['default' => 1, 'null' => false])
                  ->addColumn('notes', 'string', ['limit' => 255, 'null' => true])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['extension'], ['unique' => true])
                  ->addIndex(['teams_upn'], ['unique' => true])
                  ->create();
        }

        // 2. sys_settings için varsayılan Teams anahtarları
        if ($this->hasTable('sys_settings')) {
            $defaultSettings = [
                'teams_enabled'              => '0',
                'teams_domain'               => '',
                'teams_sip_port'             => '5061',
                'teams_tls_cert_path'        => '/etc/asterisk/keys/teams_cert.pem',
                'teams_tls_key_path'         => '/etc/asterisk/keys/teams_key.pem',
                'teams_sbc_name'             => '',
                'teams_webhook_enabled'      => '0',
                'teams_webhook_url'          => '',
                'teams_notify_missed_calls'  => '1',
                'teams_notify_voicemail'     => '1',
                'teams_notify_queue_alerts'  => '1',
                'teams_notify_cdr_summary'   => '0',
                'teams_notify_fax'           => '1',
            ];

            foreach ($defaultSettings as $key => $val) {
                $this->execute("
                    INSERT INTO sys_settings (setting_key, setting_value)
                    VALUES ('{$key}', '{$val}')
                    ON DUPLICATE KEY UPDATE setting_value = setting_value
                ");
            }
        }

        // 3. sys_role_permissions için ms_teams modülü yetkilendirmesi
        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("
                INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                VALUES 
                    ('admin', 'ms_teams', 1, 1, 1, 1),
                    ('read_only_admin', 'ms_teams', 1, 1, 0, 0)
                ON DUPLICATE KEY UPDATE 
                    can_view = VALUES(can_view), 
                    can_access = VALUES(can_access), 
                    can_edit = VALUES(can_edit), 
                    can_delete = VALUES(can_delete)
            ");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('teams_user_mappings')) {
            $this->table('teams_user_mappings')->drop()->save();
        }

        if ($this->hasTable('sys_settings')) {
            $this->execute("DELETE FROM sys_settings WHERE setting_key LIKE 'teams_%'");
        }

        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'ms_teams'");
        }
    }
}
