<?php

class QueueRepository extends BaseRepository
{
    protected static string $table = 'pbx_queues';

    public static function allOrderedById(): array
    {
        return static::findAll('id ASC');
    }

    public static function extensionAgents(): array
    {
        return static::db()->query(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY role ASC, extension ASC"
        )->fetchAll();
    }

    /**
     * Kuyruk formundaki "Temsilciler" listesinde görünen roller.
     */
    public const AGENT_ROLES = ['cc_agent', 'user'];

    /**
     * Kuyruk formundaki "Temsilciler" listesi: sadece Temsilci (cc_agent) ve Kullanıcı (user) rolleri —
     * faks hatları, admin ve izleyici hesapları kuyruğa üye yapılamaz.
     */
    public static function queueAgents(): array
    {
        $in = implode(',', array_fill(0, count(self::AGENT_ROLES), '?'));
        $stmt = static::db()->prepare(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE role IN ({$in}) AND (extension_type IS NULL OR extension_type != 'fax') AND (is_active IS NULL OR is_active = 1) AND extension IS NOT NULL AND extension != '' ORDER BY extension ASC"
        );
        $stmt->execute(self::AGENT_ROLES);
        return $stmt->fetchAll();
    }

    /**
     * Kuyruk formundaki "Kuyruk Yöneticileri" listesinde görünen roller.
     */
    public const MANAGER_ROLES = ['cc_manager', 'admin'];

    /**
     * Kuyruk formundaki "Kuyruk Yöneticileri" listesi: Kuyruk Yönetici ve Admin rolleri.
     */
    public static function queueManagers(): array
    {
        $in = implode(',', array_fill(0, count(self::MANAGER_ROLES), '?'));
        $stmt = static::db()->prepare(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE role IN ({$in}) AND (extension_type IS NULL OR extension_type != 'fax') AND (is_active IS NULL OR is_active = 1) AND extension IS NOT NULL AND extension != '' ORDER BY extension ASC"
        );
        $stmt->execute(self::MANAGER_ROLES);
        return $stmt->fetchAll();
    }

    /**
     * Rolü listeye uymadığı halde bir kuyruğa ZATEN atanmış kullanıcılar (ör. rol
     * filtresi gelmeden önce yönetici yapılmış adminler). Formda sadece atandıkları
     * kuyruk açılınca gösterilirler; gösterilmeselerdi kuyruk kaydedilince sessizce
     * atamadan düşerlerdi.
     * @param string $jsonColumn 'members_json' | 'supervisors_json'
     * @param string[] $roles Listenin normal rolleri (ör. AGENT_ROLES | MANAGER_ROLES)
     */
    public static function assignedOutsideRole(string $jsonColumn, array $roles): array
    {
        $exts = [];
        foreach (static::db()->query("SELECT {$jsonColumn} AS j, supervisor_extension FROM pbx_queues")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (json_decode($row['j'] ?? '[]', true) ?: [] as $e) $exts[] = (string)$e;
            if ($jsonColumn === 'supervisors_json' && !empty($row['supervisor_extension'])) $exts[] = (string)$row['supervisor_extension'];
        }
        $exts = array_values(array_unique(array_filter($exts, 'strlen')));
        if (empty($exts)) return [];
        $in = implode(',', array_fill(0, count($exts), '?'));
        $role_in = implode(',', array_fill(0, count($roles), '?'));
        $stmt = static::db()->prepare("SELECT id, username, full_name, extension, role FROM sys_users WHERE extension IN ({$in}) AND role NOT IN ({$role_in}) AND (extension_type IS NULL OR extension_type != 'fax') AND (is_active IS NULL OR is_active = 1) ORDER BY extension ASC");
        $stmt->execute(array_merge($exts, $roles));
        return $stmt->fetchAll();
    }

    public static function activeMohClasses(): array
    {
        $classes = static::db()->query("SELECT name FROM pbx_moh_classes WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        return !empty($classes) ? $classes : ['default', 'custom'];
    }

    public static function supervisorName(string $extension): string
    {
        return DBHelper::fetchColumn("SELECT full_name FROM sys_users WHERE extension = ?", [$extension]) ?: $extension;
    }
}
