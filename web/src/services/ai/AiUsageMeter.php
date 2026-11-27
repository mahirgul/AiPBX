<?php
/**
 * This month's use of each cloud AI account (seconds of speech, characters, tokens), its monthly limits, and what is
 * reserved for the jobs that are running now. Below the account store and the provider calls, which count and ask here.
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';

final class AiUsageMeter
{
    /** @var array<string, int> "<provider>:<column>" => units the jobs running now have reserved (a job's own use is not counted twice) */
    private static array $reserved = [];

    /** Notes that a job has reserved `$units` of a provider's quota (AiJobService::reserveQuota()). */
    public static function addReservation(string $providerId, string $col, int $units): void
    {
        $k = $providerId . ':' . $col;
        self::$reserved[$k] = (self::$reserved[$k] ?? 0) + $units;
    }

    public static function activeReservedQuota(string $providerId, string $col): int
    {
        return self::$reserved[$providerId . ':' . $col] ?? 0;
    }

    /** Takes the reservation back (the job now counts its real use) and returns how much it was. */
    public static function consumeReservedQuota(string $providerId, string $col): int
    {
        $k = $providerId . ':' . $col;
        $v = self::$reserved[$k] ?? 0;
        unset(self::$reserved[$k]);
        return $v;
    }

    /** Adds to this month's use of a provider (seconds of audio, characters, tokens). */
    public static function count(string $provider, string $unit, float $amount): void
    {
        if (!in_array($unit, ['stt_seconds', 'tts_chars', 'llm_tokens'], true) || $amount <= 0) {
            return;
        }
        $reserved = self::consumeReservedQuota($provider, $unit);
        $delta = $amount - $reserved;
        $insertVal = max(0.0, $delta);
        try {
            getDB()->prepare("INSERT INTO ai_cloud_usage (provider, month, {$unit}) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE {$unit} = GREATEST(0, {$unit} + ?)")
                ->execute([$provider, date('Y-m'), $insertVal, $delta]);
        } catch (\Throwable $e) {
            // Counting never breaks the call itself.
        }
    }

    /** @return array<string, array{stt_seconds: float, tts_chars: int, llm_tokens: int}> */
    public static function usageThisMonth(): array
    {
        try {
            $st = getDB()->prepare('SELECT provider, stt_seconds, tts_chars, llm_tokens FROM ai_cloud_usage WHERE month = ?');
            $st->execute([date('Y-m')]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['provider']] = ['stt_seconds' => (float) $r['stt_seconds'], 'tts_chars' => (int) $r['tts_chars'], 'llm_tokens' => (int) $r['llm_tokens']];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Monthly quota limits configured for a provider (0 = unlimited).
     * @return array{stt_min: int, tts_chars: int, llm_tokens: int}
     */
    public static function limits(string $id): array
    {
        return [
            'stt_min' => max(0, (int) getSystemSetting("ai_limit.{$id}.stt_min", '0')),
            'tts_chars' => max(0, (int) getSystemSetting("ai_limit.{$id}.tts_chars", '0')),
            'llm_tokens' => max(0, (int) getSystemSetting("ai_limit.{$id}.llm_tokens", '0')),
        ];
    }

    public static function saveLimits(string $id, array $post): void
    {
        $db = getDB();
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach (['stt_min', 'tts_chars', 'llm_tokens'] as $metric) {
            $key = "ai_limit.{$id}.{$metric}";
            if (!empty($post['clear'])) {
                $del->execute([$key]);
                continue;
            }
            $field = "limit_{$metric}";
            if (!array_key_exists($field, $post)) {
                continue;
            }
            $v = max(0, (int) $post[$field]);
            if ($v <= 0) {
                $del->execute([$key]);
            } else {
                $set->execute([$key, (string) $v]);
            }
        }
    }

    /**
     * Computes this month's quota percentage and warning/exceeded flags.
     * @param array{stt_seconds?: float|int, tts_chars?: int, llm_tokens?: int}|null $usageRow
     * @return array{pct: int, warn: bool, exceeded: bool, jobs: array{stt: bool, tts: bool, llm: bool}}
     */
    public static function usageStatus(string $id, ?array $usageRow = null): array
    {
        $limits = self::limits($id);
        if ($usageRow === null) {
            $usageRow = self::usageThisMonth()[$id] ?? [];
            $usageRow['stt_seconds'] = max(0.0, (float) ($usageRow['stt_seconds'] ?? 0) - self::activeReservedQuota($id, 'stt_seconds'));
            $usageRow['tts_chars'] = max(0, (int) ($usageRow['tts_chars'] ?? 0) - self::activeReservedQuota($id, 'tts_chars'));
            $usageRow['llm_tokens'] = max(0, (int) ($usageRow['llm_tokens'] ?? 0) - self::activeReservedQuota($id, 'llm_tokens'));
        }
        $caps = [
            'stt' => [$limits['stt_min'] * 60, (float) ($usageRow['stt_seconds'] ?? 0)],
            'tts' => [$limits['tts_chars'], (float) ($usageRow['tts_chars'] ?? 0)],
            'llm' => [$limits['llm_tokens'], (float) ($usageRow['llm_tokens'] ?? 0)],
        ];
        $maxPct = 0;
        $jobs = ['stt' => false, 'tts' => false, 'llm' => false];
        foreach ($caps as $job => [$lim, $used]) {
            if ($lim <= 0) {
                continue;
            }
            $pct = (int) floor(($used / $lim) * 100);
            if ($used >= $lim) {
                $jobs[$job] = true;
                $pct = max($pct, 100);
            }
            $maxPct = max($maxPct, $pct);
        }
        $exceeded = in_array(true, $jobs, true) || $maxPct >= 100;
        return [
            'pct' => $maxPct,
            'warn' => !$exceeded && $maxPct >= 80,
            'exceeded' => $exceeded,
            'jobs' => $jobs,
        ];
    }

    public static function overLimit(string $id, string $job = ''): bool
    {
        $st = self::usageStatus($id);
        if ($job !== '' && array_key_exists($job, $st['jobs'])) {
            return $st['jobs'][$job];
        }
        return $st['exceeded'];
    }
}
