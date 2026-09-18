<?php

class RoleRepository extends BaseRepository
{
    protected static string $table = 'sys_roles';

    /**
     * Modül Tanımları & Türkçe İsimleri — izin matrisinin satırlarını
     * (ve kayıt sırasında hangi modüllerin var olduğunu) tanımlar.
     */
    public static function modulesDefinition(): array
    {
        return [
            'dashboard'          => ['title' => 'Kontrol Paneli', 'group' => 'Genel'],
            'my_phone'           => ['title' => 'Telefonum', 'group' => 'Genel'],
            'chat'               => ['title' => 'Sohbet', 'group' => 'Genel'],
            'trunks'             => ['title' => 'SIP Dış Hatlar', 'group' => 'Dış Hat Yönetimi'],
            'did_routes'         => ['title' => 'Gelen Rotalar', 'group' => 'Dış Hat Yönetimi'],
            'outbound_routes'    => ['title' => 'Giden Rotalar', 'group' => 'Dış Hat Yönetimi'],
            'time_conditions'    => ['title' => 'Zaman Koşulları', 'group' => 'PBX Yönetimi'],
            'ivrs'               => ['title' => 'IVR Menüleri', 'group' => 'PBX Yönetimi'],
            'extensions'         => ['title' => 'Dahili Aboneler', 'group' => 'PBX Yönetimi'],
            'queues'             => ['title' => 'Kuyruklar', 'group' => 'PBX Yönetimi'],
            'sounds'             => ['title' => 'Sesler & Anonslar', 'group' => 'PBX Yönetimi'],
            'end_call'           => ['title' => 'Sonlandırma Eylemleri', 'group' => 'PBX Yönetimi'],
            'cdr_reports'        => ['title' => 'Çağrı Raporları', 'group' => 'PBX Yönetimi'],
            'feature_codes'      => ['title' => 'Özellik Kodları (*)', 'group' => 'PBX Yönetimi'],
            'system_users'       => ['title' => 'Kullanıcılar', 'group' => 'Yönetim'],
            'roles'              => ['title' => 'Roller & İzinler', 'group' => 'Yönetim'],
            'asterisk_settings'  => ['title' => 'Santral Ayarları', 'group' => 'Yönetim'],
            'brand_settings'     => ['title' => 'Marka & Görünüm', 'group' => 'Yönetim'],
            'pending_sync'       => ['title' => 'Bekleyen Değişiklikler', 'group' => 'Yönetim'],
            'audit_log'          => ['title' => 'Denetim Günlüğü', 'group' => 'Yönetim'],
            'push_settings'      => ['title' => 'Mobil Bildirimler', 'group' => 'Yönetim'],
            'fax_mail_settings'  => ['title' => 'Faks Ayarları', 'group' => 'Yönetim'],
            'fax_settings'       => ['title' => 'Faks Birimleri', 'group' => 'Yönetim'],
            'mail_settings'      => ['title' => 'E-Posta & Relay', 'group' => 'Yönetim'],
            'firewall'           => ['title' => 'Güvenlik Duvarı', 'group' => 'Güvenlik'],
            'fail2ban'           => ['title' => 'Saldırı Engelleme', 'group' => 'Güvenlik'],
            'fax_inbox'          => ['title' => 'Gelen Fakslar', 'group' => 'Faks Sistemi'],
            'fax_send'           => ['title' => 'Faks Gönder', 'group' => 'Faks Sistemi'],
            'fax_sent'           => ['title' => 'Giden Fakslar', 'group' => 'Faks Sistemi'],
            'cc_agent'           => ['title' => 'Temsilci Ekranı', 'group' => 'Çağrı Merkezi'],
            'queue_monitor'      => ['title' => 'Kuyruk İzleme', 'group' => 'Çağrı Merkezi'],
            'cc_board'           => ['title' => 'Canlı Pano', 'group' => 'Çağrı Merkezi'],
            'pause_reports'      => ['title' => 'Mola Raporları', 'group' => 'Çağrı Merkezi'],
            'queue_logs'         => ['title' => 'Kuyruk Logları', 'group' => 'Çağrı Merkezi'],
            'ms_teams'           => ['title' => 'Microsoft Teams', 'group' => 'Entegrasyonlar'],
        ];
    }

    /**
     * modulesDefinition()'daki Türkçe grup adlarını çeviri anahtarı için bir
     * slug'a eşler (View'de t('roles.group_' . slug, $groupTr) çağrısında kullanılır).
     */
    public static function groupSlugs(): array
    {
        return [
            'Genel' => 'general',
            'Dış Hat Yönetimi' => 'trunk_mgmt',
            'PBX Yönetimi' => 'pbx_mgmt',
            'Yönetim' => 'admin_mgmt',
            'Güvenlik' => 'security',
            'Faks Sistemi' => 'fax_system',
            'Çağrı Merkezi' => 'call_center',
            'Entegrasyonlar' => 'integrations',
        ];
    }

    public static function allWithUserCount(): array
    {
        return static::db()->query(
            "SELECT r.*, COUNT(u.id) as user_count
             FROM sys_roles r
             LEFT JOIN sys_users u ON r.role_key = u.role
             GROUP BY r.id
             ORDER BY r.is_system DESC, r.id ASC"
        )->fetchAll();
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
