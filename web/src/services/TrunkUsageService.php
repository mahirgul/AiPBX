<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../asterisk_helper.php';
require_once __DIR__ . '/NoticeService.php';

/**
 * Trunk monthly minutes / cost limit. The month so far is counted from the call records: answered
 * calls that went out through the trunk, each rounded up to whole minutes (as carriers bill).
 * Deleted records contribute only the monthly aggregate retained by the database trigger.
 * Once a minute (task_dispatcher.php) a trunk at its limit with "refuse" on gets astdb
 * trunkmonth/<trunk> = over, which sub-trunk-limit checks before an outbound call (emergency routes
 * never ask); the bell (and its e-mail) says when a trunk passes its warning level or its limit.
 * The count follows the calendar month of the server, so it starts again on the 1st by itself.
 */
final class TrunkUsageService
{
    /** @return bool true when the trunk has a minutes or a cost limit */
    public static function limited(array $trunk): bool
    {
        return (int) ($trunk['monthly_minutes_limit'] ?? 0) > 0
            || ((float) ($trunk['monthly_cost_limit'] ?? 0) > 0 && (float) ($trunk['monthly_cost_per_minute'] ?? 0) > 0);
    }

    /** Minutes the trunk carried in the month beginning at $monthStart. */
    public static function monthlyMinutes(string $trunkName, string $monthStart): int
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $trunkName);
        if ($name === '') {
            return 0;
        }
        $st = getDB()->prepare("SELECT
            (SELECT COALESCE(SUM(CEIL(billsec / 60)), 0) FROM asteriskcdr
             WHERE calldate >= ? AND calldate < DATE_ADD(?, INTERVAL 1 MONTH)
               AND disposition = 'ANSWERED' AND billsec > 0 AND dstchannel REGEXP ?)
            + COALESCE((SELECT minutes FROM pbx_deleted_trunk_usage WHERE trunk_name = ? AND month_start = ?), 0)");
        $st->execute([$monthStart, $monthStart, '^PJSIP/' . $name . '-[0-9a-f]+$', $name, $monthStart]);
        return (int) $st->fetchColumn();
    }

    /**
     * Pure: where a trunk stands with $minutes used.
     *
     * @return array{minutes: int, cost: float, percent: int, state: string}
     *         state: none (no limit) | ok | warn | over
     */
    public static function evaluate(array $trunk, int $minutes): array
    {
        $price = max(0.0, (float) ($trunk['monthly_cost_per_minute'] ?? 0));
        $cost = round($minutes * $price, 2);
        $out = ['minutes' => $minutes, 'cost' => $cost, 'percent' => 0, 'state' => 'none'];
        if (!self::limited($trunk)) {
            return $out;
        }
        $pct = 0.0;
        $minLimit = (int) ($trunk['monthly_minutes_limit'] ?? 0);
        if ($minLimit > 0) {
            $pct = max($pct, $minutes * 100 / $minLimit);
        }
        $costLimit = (float) ($trunk['monthly_cost_limit'] ?? 0);
        if ($costLimit > 0 && $price > 0) {
            $pct = max($pct, $cost * 100 / $costLimit);
        }
        $warn = max(1, min(100, (int) ($trunk['monthly_warn_percent'] ?? 80)));
        $out['percent'] = (int) floor($pct);
        $out['state'] = $pct >= 100 ? 'over' : ($pct >= $warn ? 'warn' : 'ok');
        return $out;
    }

    /** @return array<string, array{minutes: int, cost: float, percent: int, state: string}> by trunk_name */
    public static function overview(): array
    {
        $monthStart = date('Y-m-01');
        $out = [];
        try {
            $trunks = getDB()->query('SELECT * FROM pbx_trunks')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($trunks as $t) {
            if (self::limited($t) || (float) ($t['monthly_cost_per_minute'] ?? 0) > 0) {
                $out[(string) $t['trunk_name']] = self::evaluate($t, self::monthlyMinutes((string) $t['trunk_name'], $monthStart));
            }
        }
        return $out;
    }

    /** Once a minute: the astdb flag the dialplan reads, and the bell. */
    public static function refresh(): void
    {
        try {
            $trunks = getDB()->query('SELECT * FROM pbx_trunks')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return; // before the migration
        }
        $flagged = [];
        $show = AsteriskHelper::execCLI('database show trunkmonth');
        if (preg_match_all('#^/trunkmonth/(\S+)\s*:#m', (string) ($show['output'] ?? ''), $m)) {
            $flagged = array_flip($m[1]);
        }
        $monthStart = date('Y-m-01');
        foreach (array_diff(array_keys($flagged), array_column($trunks, 'trunk_name')) as $gone) {
            AsteriskHelper::execCLI('database del trunkmonth ' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $gone));
        }
        foreach ($trunks as $t) {
            $name = (string) $t['trunk_name'];
            $key = 'trunk_month_' . $name;
            $limited = self::limited($t) && !empty($t['is_active']);
            $usage = $limited ? self::evaluate($t, self::monthlyMinutes($name, $monthStart)) : ['state' => 'none'];
            $block = $limited && !empty($t['monthly_block']) && $usage['state'] === 'over';
            if ($block && !isset($flagged[$name])) {
                AsteriskHelper::execCLI('database put trunkmonth ' . $name . ' over');
            } elseif (!$block && isset($flagged[$name])) {
                AsteriskHelper::execCLI('database del trunkmonth ' . $name);
            }
            if ($usage['state'] === 'warn' || $usage['state'] === 'over') {
                $title = sprintf(t($usage['state'] === 'over' ? 'trunks.month_notice_over' : 'trunks.month_notice_warn'), $name, $usage['percent']);
                $msg = sprintf(t('trunks.month_notice_msg'), $usage['minutes'], number_format($usage['cost'], 2))
                    . ($usage['state'] === 'over' ? ' ' . t(!empty($t['monthly_block']) ? 'trunks.month_notice_blocked' : 'trunks.month_notice_not_blocked') : '');
                NoticeService::raise($key, $usage['state'] === 'over' ? 'fail' : 'warn', $title, $msg, '/trunks');
            } else {
                NoticeService::resolve($key);
            }
        }
    }
}
