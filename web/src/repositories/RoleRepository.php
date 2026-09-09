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
            'dashboard'          => ['title' => 'Kontrol Paneli (Dashboard)', 'group' => 'Genel'],
            'trunks'             => ['title' => 'SIP Dış Hat Ayarları', 'group' => 'Dış Hat Yönetimi'],
            'did_routes'         => ['title' => 'Gelen Rotalar', 'group' => 'Dış Hat Yönetimi'],
            'outbound_routes'    => ['title' => 'Giden Rotalar', 'group' => 'Dış Hat Yönetimi'],
            'time_conditions'    => ['title' => 'Zaman Koşulları & Grupları', 'group' => 'PBX Yönetimi'],
            'ivrs'               => ['title' => 'IVR Sesli Yanıt Menüleri', 'group' => 'PBX Yönetimi'],
            'extensions'         => ['title' => 'Dahili Abone Yönetimi', 'group' => 'PBX Yönetimi'],
            'queues'             => ['title' => 'Çağrı Merkezi Kuyrukları', 'group' => 'PBX Yönetimi'],
            'sounds'             => ['title' => 'Anons & Bekleme Müziği (MOH)', 'group' => 'PBX Yönetimi'],
            'end_call'           => ['title' => 'Çağrı Sonlandırma Eylemleri', 'group' => 'PBX Yönetimi'],
            'cdr_reports'        => ['title' => 'Çağrı Raporları & Ses Kayıtları', 'group' => 'PBX Yönetimi'],
            'feature_codes'      => ['title' => 'Feature Code Yönetimi (*78, *72/73, *20/21, *90)', 'group' => 'PBX Yönetimi'],
            'system_users'       => ['title' => 'Kullanıcı Ayarları', 'group' => 'Yönetim'],
            'roles'              => ['title' => 'Kullanıcı Rolleri & İzinler', 'group' => 'Yönetim'],
            'asterisk_settings'  => ['title' => 'Santral Gelişmiş Ayarları', 'group' => 'Yönetim'],
            'brand_settings'     => ['title' => 'Marka & Görünüm', 'group' => 'Yönetim'],
            'fax_mail_settings'  => ['title' => 'Faks Ayarları', 'group' => 'Yönetim'],
            'fax_settings'       => ['title' => 'Faks Birimleri', 'group' => 'Yönetim'],
            'fax_inbox'          => ['title' => 'Gelen Fakslar', 'group' => 'Faks Sistemi'],
            'fax_send'           => ['title' => 'Faks Gönderme Paneli', 'group' => 'Faks Sistemi'],
            'fax_sent'           => ['title' => 'Giden Fakslar', 'group' => 'Faks Sistemi'],
            'cc_agent'           => ['title' => 'Temsilci WebRTC Ekranı', 'group' => 'Çağrı Merkezi'],
            'queue_monitor'      => ['title' => 'Kuyruk İzleme', 'group' => 'Çağrı Merkezi'],
            'cc_board'           => ['title' => 'Çağrı Merkezi Panosu', 'group' => 'Çağrı Merkezi'],
            'pause_reports'      => ['title' => 'Mola & Ara Raporları', 'group' => 'Çağrı Merkezi'],
            'queue_logs'         => ['title' => 'Kuyruk Analiz Logları', 'group' => 'Çağrı Merkezi'],
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
            'Faks Sistemi' => 'fax_system',
            'Çağrı Merkezi' => 'call_center',
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
