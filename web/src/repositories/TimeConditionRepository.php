<?php
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

class TimeConditionRepository extends BaseRepository
{
    protected static string $table = 'pbx_time_conditions';

    public static function allWithGroupInfo(): array
    {
        return static::db()->query(
            "SELECT tc.*, tg.title AS group_title, tg.time_start, tg.time_end
             FROM pbx_time_conditions tc
             LEFT JOIN pbx_time_groups tg ON tc.time_group_id = tg.id
             ORDER BY tc.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function allTimeGroups(): array
    {
        return static::db()->query('SELECT * FROM pbx_time_groups ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function timeGroupMap(array $timeGroups): array
    {
        $map = [];
        foreach ($timeGroups as $tg) {
            $map[$tg['id']] = $tg;
        }
        return $map;
    }
}
