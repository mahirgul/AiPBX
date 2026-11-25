<?php

class RoleRepository extends BaseRepository
{
    protected static string $table = 'sys_roles';

    /** Actions a page can use; most pages are read-only or have nothing to delete. */
    public const ALL_ACTIONS = ['view', 'access', 'edit', 'delete'];
    private const VA = ['view', 'access'];
    private const VAE = ['view', 'access', 'edit'];
    private const VAD = ['view', 'access', 'delete'];

    /**
     * Rows of the permission matrix, in the sidebar's groups and order.
     *
     * - actions:    the columns that mean something for the page: POSTs need
     *               "edit", deletions "delete" (auth.php checks them on every
     *               request); the other boxes are not shown.
     * - admin_only: only the admin role ever gets in (auth.php circuit
     *               breaker); shown locked, never granted to another role.
     * - admin_only_edit: other roles may look, only admin may change.
     */
    public static function modulesDefinition(): array
    {
        $all = self::ALL_ACTIONS;
        return [
            'assistant'          => ['title' => 'PBX Assistant', 'group' => 'General', 'actions' => self::VAE],
            'dashboard'          => ['title' => 'Dashboard', 'group' => 'General', 'actions' => self::VAE],
            'my_phone'           => ['title' => 'My Phone', 'group' => 'General', 'actions' => self::VAE],
            'chat'               => ['title' => 'Chat', 'group' => 'General', 'actions' => self::VA],
            'meetings'           => ['title' => 'Video Meetings', 'group' => 'General', 'actions' => $all],
            'cdr_reports'        => ['title' => 'Call Reports', 'group' => 'General', 'actions' => self::VAD],
            'phonebook'          => ['title' => 'Address Book', 'group' => 'General', 'actions' => $all],
            'system_users'       => ['title' => 'Users', 'group' => 'Administration', 'actions' => $all, 'admin_only' => true],
            'roles'              => ['title' => 'Roles & Permissions', 'group' => 'Administration', 'actions' => $all, 'admin_only' => true],
            'asterisk_settings'  => ['title' => 'PBX Settings', 'group' => 'Administration', 'actions' => self::VAE],
            'brand_settings'     => ['title' => 'Branding & Appearance', 'group' => 'Administration', 'actions' => self::VAE],
            'push_settings'      => ['title' => 'Mobile Push', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only_edit' => true],
            'mail_settings'      => ['title' => 'E-mail & Relay', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'file_storage'       => ['title' => 'File Storage', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'pending_sync'       => ['title' => 'Pending Changes', 'group' => 'Administration', 'actions' => self::VAE],
            'backup'             => ['title' => 'Backup & Restore', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'system_update'      => ['title' => 'System Update', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'scheduled_tasks'    => ['title' => 'Scheduled Tasks', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'health'             => ['title' => 'Health Checks', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'trunks'             => ['title' => 'SIP Trunks', 'group' => 'Outbound Line Management', 'actions' => $all],
            'did_routes'         => ['title' => 'Inbound Routes', 'group' => 'Outbound Line Management', 'actions' => $all],
            'outbound_routes'    => ['title' => 'Outbound Routes', 'group' => 'Outbound Line Management', 'actions' => $all],
            'dial_permissions'   => ['title' => 'Dial Permission Groups', 'group' => 'Outbound Line Management', 'actions' => $all],
            'extensions'         => ['title' => 'Extensions', 'group' => 'PBX Management', 'actions' => $all],
            'ivrs'               => ['title' => 'IVR Menus', 'group' => 'PBX Management', 'actions' => $all],
            'time_conditions'    => ['title' => 'Time Conditions', 'group' => 'PBX Management', 'actions' => $all],
            'ring_groups'        => ['title' => 'Ring Groups', 'group' => 'PBX Management', 'actions' => $all],
            'conferences'        => ['title' => 'Conference Rooms', 'group' => 'PBX Management', 'actions' => $all],
            'boss_secretary'     => ['title' => 'Boss - Secretary', 'group' => 'PBX Management', 'actions' => $all],
            'end_call'           => ['title' => 'Hangup Actions', 'group' => 'PBX Management', 'actions' => $all],
            'call_modifiers'     => ['title' => 'Call Modifiers', 'group' => 'PBX Management', 'actions' => $all, 'admin_only' => true],
            'parking'            => ['title' => 'Call Parking', 'group' => 'PBX Management', 'actions' => ['view', 'edit'], 'admin_only' => true],
            'sounds'             => ['title' => 'Sounds & Music', 'group' => 'PBX Management', 'actions' => $all],
            'feature_codes'      => ['title' => 'Feature Codes (*)', 'group' => 'PBX Management', 'actions' => self::VAE],
            'custom_dialplan'    => ['title' => 'Custom Dialplan', 'group' => 'PBX Management', 'actions' => $all, 'admin_only' => true],
            'custom_configs'     => ['title' => 'Custom Configs', 'group' => 'PBX Management', 'actions' => ['view', 'edit'], 'admin_only' => true],
            'advanced_config'    => ['title' => 'Advanced Configuration', 'group' => 'PBX Management', 'actions' => $all, 'admin_only' => true],
            'phones'             => ['title' => 'Phones', 'group' => 'Network & Devices', 'actions' => $all, 'admin_only' => true],
            'cc_board'           => ['title' => 'Wallboard', 'group' => 'Call Center', 'actions' => self::VA],
            'queue_monitor'      => ['title' => 'Queue Monitor', 'group' => 'Call Center', 'actions' => self::VA],
            'cc_agent'           => ['title' => 'Agent Screen', 'group' => 'Call Center', 'actions' => self::VA],
            'queues'             => ['title' => 'Queues', 'group' => 'Call Center', 'actions' => $all],
            'queue_reports'      => ['title' => 'Queue Report Centre', 'group' => 'Call Center', 'actions' => self::VA],
            'pause_reports'      => ['title' => 'Pause Reports', 'group' => 'Call Center', 'actions' => self::VA],
            'queue_logs'         => ['title' => 'Queue Logs', 'group' => 'Call Center', 'actions' => self::VA],
            'fax_inbox'          => ['title' => 'Incoming Faxes', 'group' => 'Fax System', 'actions' => $all],
            'fax_send'           => ['title' => 'Send Fax', 'group' => 'Fax System', 'actions' => self::VAE],
            'fax_sent'           => ['title' => 'Sent Faxes', 'group' => 'Fax System', 'actions' => $all],
            'fax_settings'       => ['title' => 'Fax Units', 'group' => 'Fax System', 'actions' => $all],
            'fax_mail_settings'  => ['title' => 'Fax Settings', 'group' => 'Fax System', 'actions' => self::VAE],
            'ai_tts'             => ['title' => 'Cloud TTS', 'group' => 'AI', 'actions' => $all, 'plugin' => 'ai-tts'],
            'builtin_ai_agent'   => ['title' => 'AI Agent', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true],
            'ai_local'           => ['title' => 'Local AI', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'ailocal'],
            'ai_cloud'           => ['title' => 'Cloud AI', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'aicloud'],
            'ai_voicemail'       => ['title' => 'AI Voicemail', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'ai-voicemail'],
            'ai_call_analysis'   => ['title' => 'Call Analysis', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'ai-call-analysis'],
            'ai_knowledge'       => ['title' => 'Knowledge Base (TXT RAG)', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'ai-knowledge'],
            'ai_assistant'       => ['title' => 'Assistant Settings', 'group' => 'AI', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'pbx-assistant'],
            'plugins'            => ['title' => 'Plugin Catalog', 'group' => 'Integrations', 'actions' => $all, 'admin_only' => true],
            'api_keys'           => ['title' => 'REST API Keys', 'group' => 'Integrations', 'actions' => $all, 'admin_only' => true],
            'webhooks'           => ['title' => 'Outbound Webhooks', 'group' => 'Integrations', 'actions' => $all, 'admin_only' => true],
            'connections'        => ['title' => 'Connections (Odoo, CRM)', 'group' => 'Integrations', 'actions' => $all, 'admin_only' => true],
            'external_dbs'       => ['title' => 'External Databases', 'group' => 'Integrations', 'actions' => $all, 'admin_only' => true],
            'ms_teams'           => ['title' => 'Microsoft Teams', 'group' => 'Integrations', 'actions' => $all, 'plugin' => 'ms-teams'],
            'web_widgets'        => ['title' => 'Web Widgets', 'group' => 'Integrations', 'actions' => $all, 'admin_only' => true, 'plugin' => 'web-widgets'],
            'troubleshooting'    => ['title' => 'Troubleshooting', 'group' => 'Troubleshooting', 'actions' => self::VAE, 'admin_only' => true],
            'call_quality'       => ['title' => 'Call Quality', 'group' => 'Troubleshooting', 'actions' => self::VAE, 'admin_only' => true],
            'firewall'           => ['title' => 'Firewall', 'group' => 'Security', 'actions' => $all, 'admin_only' => true],
            'fail2ban'           => ['title' => 'Fail2ban', 'group' => 'Security', 'actions' => $all, 'admin_only' => true],
            'google_integration' => ['title' => 'Google Sign-in', 'group' => 'Security', 'actions' => self::VAE, 'admin_only' => true, 'plugin' => 'google-auth'],
            'certificates'       => ['title' => 'Certificates', 'group' => 'Security', 'actions' => self::VAE, 'admin_only' => true],
            'privacy'            => ['title' => 'GDPR & Data Privacy', 'group' => 'Security', 'actions' => $all, 'admin_only' => true],
            'audit_log'          => ['title' => 'Audit Log', 'group' => 'Security', 'actions' => self::VA],
        ];
    }

    /**
     * Maps RBAC module keys to their corresponding plugin slug in `sys_plugins`.
     *
     * @return array<string, string>
     */
    public static function pluginModuleMap(): array
    {
        $map = [];
        foreach (self::modulesDefinition() as $key => $info) {
            if (!empty($info['plugin'])) {
                $map[$key] = (string)$info['plugin'];
            }
        }
        return $map;
    }

    /** Module keys only the admin role can use (must match auth.php's circuit breaker). */
    public static function adminOnlyModules(): array
    {
        return array_keys(array_filter(self::modulesDefinition(), fn($m) => !empty($m['admin_only'])));
    }

    /**
     * Maps the Turkish group names in modulesDefinition() to a slug for the
     * translation key (used in the view's t('roles.group_' . slug, $groupTr) call).
     */
    public static function groupSlugs(): array
    {
        return [
            'General' => 'general',
            'Outbound Line Management' => 'trunk_mgmt',
            'PBX Management' => 'pbx_mgmt',
            'Network & Devices' => 'network',
            'Administration' => 'admin_mgmt',
            'Security' => 'security',
            'Integrations' => 'integrations',
            'AI' => 'ai',
            'Fax System' => 'fax_system',
            'Call Center' => 'call_center',
            'Troubleshooting' => 'troubleshooting',
        ];
    }

    public static function allWithUserCount(): array
    {
        return localizeRoles(static::db()->query(
            "SELECT r.*,
                (SELECT COUNT(*) FROM sys_users u
                 WHERE u.role = r.role_key
                    OR EXISTS (SELECT 1 FROM sys_user_roles ur WHERE ur.user_id = u.id AND ur.role_key = r.role_key)
                ) AS user_count
             FROM sys_roles r
             ORDER BY r.is_system DESC, r.id ASC"
        )->fetchAll());
    }

    public static function allPermissionsMap(): array
    {
        $all_permissions = [];
        $perm_rows = static::db()->query("SELECT * FROM sys_role_permissions")->fetchAll();
        foreach ($perm_rows as $pr) {
            $all_permissions[$pr['role_key']][$pr['module_key']] = [
                'view' => (int)$pr['can_view'],
                'access' => (int)$pr['can_access'],
                'edit' => (int)$pr['can_edit'],
                'delete' => (int)$pr['can_delete'],
            ];
        }
        return $all_permissions;
    }
}
