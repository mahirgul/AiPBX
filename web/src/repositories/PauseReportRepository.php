<?php

class PauseReportRepository extends BaseRepository
{
    protected static string $table = 'cc_pause_logs';

    public static function search(string $startDate, string $endDate, bool $canViewAll, string $userExt, string $agentFilter, string $reasonFilter): array
    {
        $sql = "SELECT p.*, TIMESTAMPDIFF(SECOND, p.start_time, IFNULL(p.end_time, NOW())) AS duration_sec
                FROM cc_pause_logs p WHERE DATE(p.start_time) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];

        if (!$canViewAll) {
            $sql .= " AND p.agent_extension = ?";
            $params[] = $userExt;
        } elseif (!empty($agentFilter)) {
            $sql .= " AND (p.agent_extension = ? OR p.agent_name LIKE ?)";
            $params[] = $agentFilter;
            $params[] = "%$agentFilter%";
        }

        if (!empty($reasonFilter)) {
            $sql .= " AND p.pause_reason = ?";
            $params[] = $reasonFilter;
        }

        $sql .= " ORDER BY p.start_time DESC LIMIT 500";
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function distinctAgents(): array
    {
        return static::db()->query("SELECT DISTINCT agent_extension, agent_name FROM cc_pause_logs WHERE agent_extension != '' ORDER BY agent_extension ASC")->fetchAll();
    }

    public static function distinctReasons(): array
    {
        return static::db()->query("SELECT DISTINCT pause_reason FROM cc_pause_logs WHERE pause_reason != ''")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Saniyeyi "1s 20dk 5sn" / "20dk 5sn" biçiminde okunabilir metne çevirir.
     * Önceden pause_reports.php'nin içinde bağımsız bir fonksiyondu.
     */
    public static function formatSeconds(int $sec): string
    {
        $m = floor($sec / 60);
        $s = $sec % 60;
        if ($m > 60) {
            $h = floor($m / 60);
            $m = $m % 60;
            return "{$h}s {$m}dk {$s}sn";
        }
        return "{$m}dk {$s}sn";
    }
}
