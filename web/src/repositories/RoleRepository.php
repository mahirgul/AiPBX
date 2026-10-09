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
            'dashboard'          => ['title' => 'Dashboard', 'group' => 'General', 'actions' => self::VAE],
            'my_phone'           => ['title' => 'My Phone', 'group' => 'General', 'actions' => self::VAE],
            'chat'               => ['title' => 'Chat', 'group' => 'General', 'actions' => self::VA],
            'cdr_reports'        => ['title' => 'Call Reports', 'group' => 'General', 'actions' => self::VAD],
            'trunks'             => ['title' => 'SIP Trunks', 'group' => 'Outbound Line Management', 'actions' => $all],
            'did_routes'         => ['title' => 'Inbound Routes', 'group' => 'Outbound Line Management', 'actions' => $all],
            'outbound_routes'    => ['title' => 'Outbound Routes', 'group' => 'Outbound Line Management', 'actions' => $all],
            'dial_permissions'   => ['title' => 'Dial Permission Groups', 'group' => 'Outbound Line Management', 'actions' => $all],
            'time_conditions'    => ['title' => 'Time Conditions', 'group' => 'PBX Management', 'actions' => $all],
            'ivrs'               => ['title' => 'IVR Menus', 'group' => 'PBX Management', 'actions' => $all],
            'extensions'         => ['title' => 'Extensions', 'group' => 'PBX Management', 'actions' => $all],
            'phones'             => ['title' => 'Phones', 'group' => 'PBX Management', 'actions' => $all, 'admin_only' => true],
            'ring_groups'        => ['title' => 'Ring Groups', 'group' => 'PBX Management', 'actions' => $all],
            'boss_secretary'     => ['title' => 'Boss - Secretary', 'group' => 'PBX Management', 'actions' => $all],
            'conferences'        => ['title' => 'Conference Rooms', 'group' => 'PBX Management', 'actions' => $all],
            'queues'             => ['title' => 'Queues', 'group' => 'PBX Management', 'actions' => $all],
            'sounds'             => ['title' => 'Sounds & Music', 'group' => 'PBX Management', 'actions' => $all],
            'end_call'           => ['title' => 'Hangup Actions', 'group' => 'PBX Management', 'actions' => $all],
            'feature_codes'      => ['title' => 'Feature Codes (*)', 'group' => 'PBX Management', 'actions' => self::VAE],
            'system_users'       => ['title' => 'Users', 'group' => 'Administration', 'actions' => $all, 'admin_only' => true],
            'roles'              => ['title' => 'Roles & Permissions', 'group' => 'Administration', 'actions' => $all, 'admin_only' => true],
            'asterisk_settings'  => ['title' => 'PBX Settings', 'group' => 'Administration', 'actions' => self::VAE],
            'brand_settings'     => ['title' => 'Branding & Appearance', 'group' => 'Administration', 'actions' => self::VAE],
            'push_settings'      => ['title' => 'Mobile Push', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only_edit' => true],
            'fax_mail_settings'  => ['title' => 'Fax Settings', 'group' => 'Administration', 'actions' => self::VAE],
            'fax_settings'       => ['title' => 'Fax Units', 'group' => 'Administration', 'actions' => $all],
            'mail_settings'      => ['title' => 'E-mail & Relay', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'file_storage'       => ['title' => 'File Storage', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'pending_sync'       => ['title' => 'Pending Changes', 'group' => 'Administration', 'actions' => self::VAE],
            'audit_log'          => ['title' => 'Audit Log', 'group' => 'Administration', 'actions' => self::VA],
            'system_update'      => ['title' => 'System Update', 'group' => 'Administration', 'actions' => self::VAE, 'admin_only' => true],
            'firewall'           => ['title' => 'Firewall', 'group' => 'Security', 'actions' => $all, 'admin_only' => true],
            'fail2ban'           => ['title' => 'Fail2ban', 'group' => 'Security', 'actions' => $all, 'admin_only' => true],
            'certificates'       => ['title' => 'Certificates', 'group' => 'Security', 'actions' => self::VAE, 'admin_only' => true],
            'google_integration' => ['title' => 'Google Sign-in', 'group' => 'Integrations', 'actions' => self::VAE, 'admin_only' => true],
            'ms_teams'           => ['title' => 'Microsoft Teams', 'group' => 'Integrations', 'actions' => $all],
            'ai_tts'             => ['title' => 'Cloud TTS', 'group' => 'AI', 'actions' => $all],
            'fax_inbox'          => ['title' => 'Incoming Faxes', 'group' => 'Fax System', 'actions' => $all],
            'fax_send'           => ['title' => 'Send Fax', 'group' => 'Fax System', 'actions' => self::VAE],
            'fax_sent'           => ['title' => 'Sent Faxes', 'group' => 'Fax System', 'actions' => $all],
            'cc_board'           => ['title' => 'Wallboard', 'group' => 'Call Center', 'actions' => self::VA],
            'queue_monitor'      => ['title' => 'Queue Monitor', 'group' => 'Call Center', 'actions' => self::VA],
            'cc_agent'           => ['title' => 'Agent Screen', 'group' => 'Call Center', 'actions' => self::VA],
            'queue_reports'      => ['title' => 'Queue Report Centre', 'group' => 'Call Center', 'actions' => self::VA],
            'pause_reports'      => ['title' => 'Pause Reports', 'group' => 'Call Center', 'actions' => self::VA],
            'queue_logs'         => ['title' => 'Queue Logs', 'group' => 'Call Center', 'actions' => self::VA],
        ];
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
            'Administration' => 'admin_mgmt',
            'Security' => 'security',
            'Integrations' => 'integrations',
            'AI' => 'ai',
            'Fax System' => 'fax_system',
            'Call Center' => 'call_center',
        ];
    }

    public static function allWithUserCount(): array
    {
        return localizeRoles(static::db()->query(
            "SELECT r.*, COUNT(u.id) as user_count
             FROM sys_roles r
             LEFT JOIN sys_users u ON r.role_key = u.role
             GROUP BY r.id
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
