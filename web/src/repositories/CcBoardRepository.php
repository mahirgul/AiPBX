<?php

class CcBoardRepository extends BaseRepository
{
    protected static string $table = 'pbx_queues';

    public static function activeQueuesForSupervisorScope(): array
    {
        return static::db()->query(
            "SELECT queue_name, title, members_json, supervisors_json, supervisor_extension FROM pbx_queues WHERE is_active = 1 ORDER BY queue_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Kullanıcı bu kuyruğun (çoklu supervisors_json VEYA eski tek
     * supervisor_extension alanı üzerinden) süpervizörü mü? Hem erişim
     * kontrolü hem "Kuyruklarım" filtresi için kullanılıyor — Erişim
     * Kontrolü (queue_monitor) ile aynı kapsam mantığı.
     */
    public static function isSupervisorOf(array $queue, string $userExt): bool
    {
        if ($userExt === '') {
            return false;
        }
        $sups = json_decode($queue['supervisors_json'] ?? '[]', true) ?: [];
        if (!empty($queue['supervisor_extension']) && !in_array($queue['supervisor_extension'], $sups)) {
            $sups[] = $queue['supervisor_extension'];
        }
        return in_array($userExt, array_map('strval', $sups));
    }
}
