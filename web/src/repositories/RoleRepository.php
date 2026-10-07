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
            'dashboard'          => ['title' => 'Kontrol Paneli', 'group' => 'Genel', 'actions' => self::VAE],
            'my_phone'           => ['title' => 'Telefonum', 'group' => 'Genel', 'actions' => self::VAE],
            'chat'               => ['title' => 'Sohbet', 'group' => 'Genel', 'actions' => self::VA],
            'cdr_reports'        => ['title' => 'Çağrı Raporları', 'group' => 'Genel', 'actions' => self::VAD],
            'trunks'             => ['title' => 'SIP Dış Hatlar', 'group' => 'Dış Hat Yönetimi', 'actions' => $all],
            'did_routes'         => ['title' => 'Gelen Rotalar', 'group' => 'Dış Hat Yönetimi', 'actions' => $all],
            'outbound_routes'    => ['title' => 'Giden Rotalar', 'group' => 'Dış Hat Yönetimi', 'actions' => $all],
            'dial_permissions'   => ['title' => 'Arama Yetki Grupları', 'group' => 'Dış Hat Yönetimi', 'actions' => $all],
            'time_conditions'    => ['title' => 'Zaman Koşulları', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'ivrs'               => ['title' => 'IVR Menüleri', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'extensions'         => ['title' => 'Dahili Aboneler', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'ring_groups'        => ['title' => 'Çalma Grupları', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'boss_secretary'     => ['title' => 'Şef - Sekreter', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'conferences'        => ['title' => 'Konferans Odaları', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'queues'             => ['title' => 'Kuyruklar', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'sounds'             => ['title' => 'Sesler & Anonslar', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'end_call'           => ['title' => 'Sonlandırma Eylemleri', 'group' => 'PBX Yönetimi', 'actions' => $all],
            'feature_codes'      => ['title' => 'Özellik Kodları (*)', 'group' => 'PBX Yönetimi', 'actions' => self::VAE],
            'system_users'       => ['title' => 'Kullanıcılar', 'group' => 'Yönetim', 'actions' => $all, 'admin_only' => true],
            'roles'              => ['title' => 'Roller & İzinler', 'group' => 'Yönetim', 'actions' => $all, 'admin_only' => true],
            'asterisk_settings'  => ['title' => 'Santral Ayarları', 'group' => 'Yönetim', 'actions' => self::VAE],
            'brand_settings'     => ['title' => 'Marka & Görünüm', 'group' => 'Yönetim', 'actions' => self::VAE],
            'push_settings'      => ['title' => 'Mobil Bildirimler', 'group' => 'Yönetim', 'actions' => self::VAE, 'admin_only_edit' => true],
            'fax_mail_settings'  => ['title' => 'Faks Ayarları', 'group' => 'Yönetim', 'actions' => self::VAE],
            'fax_settings'       => ['title' => 'Faks Birimleri', 'group' => 'Yönetim', 'actions' => $all],
            'mail_settings'      => ['title' => 'E-Posta & Relay', 'group' => 'Yönetim', 'actions' => self::VAE, 'admin_only' => true],
            'pending_sync'       => ['title' => 'Bekleyen Değişiklikler', 'group' => 'Yönetim', 'actions' => self::VAE],
            'audit_log'          => ['title' => 'Denetim Günlüğü', 'group' => 'Yönetim', 'actions' => self::VA],
            'system_update'      => ['title' => 'Sistem Güncelleme', 'group' => 'Yönetim', 'actions' => self::VAE, 'admin_only' => true],
            'firewall'           => ['title' => 'Güvenlik Duvarı', 'group' => 'Güvenlik', 'actions' => $all, 'admin_only' => true],
            'fail2ban'           => ['title' => 'Saldırı Engelleme', 'group' => 'Güvenlik', 'actions' => $all, 'admin_only' => true],
            'certificates'       => ['title' => 'Sertifikalar', 'group' => 'Güvenlik', 'actions' => self::VAE, 'admin_only' => true],
            'google_integration' => ['title' => 'Google ile Giriş', 'group' => 'Entegrasyonlar', 'actions' => self::VAE, 'admin_only' => true],
            'ms_teams'           => ['title' => 'Microsoft Teams', 'group' => 'Entegrasyonlar', 'actions' => $all],
            'ai_tts'             => ['title' => 'Cloud TTS', 'group' => 'Yapay Zekâ', 'actions' => $all],
            'fax_inbox'          => ['title' => 'Gelen Fakslar', 'group' => 'Faks Sistemi', 'actions' => $all],
            'fax_send'           => ['title' => 'Faks Gönder', 'group' => 'Faks Sistemi', 'actions' => self::VAE],
            'fax_sent'           => ['title' => 'Giden Fakslar', 'group' => 'Faks Sistemi', 'actions' => $all],
            'cc_board'           => ['title' => 'Canlı Pano', 'group' => 'Çağrı Merkezi', 'actions' => self::VA],
            'queue_monitor'      => ['title' => 'Kuyruk İzleme', 'group' => 'Çağrı Merkezi', 'actions' => self::VA],
            'cc_agent'           => ['title' => 'Temsilci Ekranı', 'group' => 'Çağrı Merkezi', 'actions' => self::VA],
            'queue_reports'      => ['title' => 'Kuyruk Rapor Merkezi', 'group' => 'Çağrı Merkezi', 'actions' => self::VA],
            'pause_reports'      => ['title' => 'Mola Raporları', 'group' => 'Çağrı Merkezi', 'actions' => self::VA],
            'queue_logs'         => ['title' => 'Kuyruk Logları', 'group' => 'Çağrı Merkezi', 'actions' => self::VA],
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
            'Genel' => 'general',
            'Dış Hat Yönetimi' => 'trunk_mgmt',
            'PBX Yönetimi' => 'pbx_mgmt',
            'Yönetim' => 'admin_mgmt',
            'Güvenlik' => 'security',
            'Entegrasyonlar' => 'integrations',
            'Yapay Zekâ' => 'ai',
            'Faks Sistemi' => 'fax_system',
            'Çağrı Merkezi' => 'call_center',
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
