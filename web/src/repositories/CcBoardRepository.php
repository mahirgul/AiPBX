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
     * Is the user a supervisor of this queue (through the multiple
     * supervisors_json OR the old single supervisor_extension field)? Used for
     * both the access check and the "My queues" filter — the same scope logic
     * as the access control (queue_monitor).
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

    /** Is the extension a supervisor of at least one active queue? */
    public static function supervisesAnyQueue(string $userExt): bool
    {
        if ($userExt === '') {
            return false;
        }
        foreach (static::activeQueuesForSupervisorScope() as $queue) {
            if (static::isSupervisorOf($queue, $userExt)) {
                return true;
            }
        }
        return false;
    }
}
