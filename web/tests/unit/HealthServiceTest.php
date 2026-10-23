<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/HealthService.php';

/** Admin → Health checks: when a check is due and when an e-mail goes out. */
final class HealthServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function prev(string $status = 'ok', int $streak = 0, string $alerted = '', ?int $alertedAgo = null): array
    {
        return ['status' => $status, 'bad_streak' => $streak, 'alerted_status' => $alerted,
            'alerted_at' => $alertedAgo === null ? null : date('Y-m-d H:i:s', self::NOW - $alertedAgo)];
    }

    public function testFrequentChecksAlarmOnlyAfterTwoBadResults(): void
    {
        $this->assertNull(HealthService::decide($this->prev('ok'), 'fail', 5, self::NOW, 6), 'one hiccup stays quiet');
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 1), 'fail', 5, self::NOW, 6));
        // Daily checks report the first bad result.
        $this->assertSame('alarm', HealthService::decide($this->prev('ok'), 'warn', 1440, self::NOW, 6));
    }

    public function testRepeatAfterTheChosenHoursAndAtOnceWhenWorse(): void
    {
        $this->assertNull(HealthService::decide($this->prev('fail', 5, 'fail', 3600), 'fail', 5, self::NOW, 6));
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 5, 'fail', 6 * 3600), 'fail', 5, self::NOW, 6));
        $this->assertSame('alarm', HealthService::decide($this->prev('warn', 5, 'warn', 60), 'fail', 5, self::NOW, 6), 'warn → fail at once');
        $this->assertNull(HealthService::decide($this->prev('fail', 5, 'fail', 60), 'warn', 5, self::NOW, 6), 'better but not fixed: quiet');
    }

    public function testRecoveryOnlyAfterAnAlarm(): void
    {
        $this->assertSame('recovered', HealthService::decide($this->prev('fail', 3, 'fail', 60), 'ok', 5, self::NOW, 6));
        $this->assertNull(HealthService::decide($this->prev('fail', 1), 'ok', 5, self::NOW, 6), 'a hiccup that was never reported');
        $this->assertNull(HealthService::decide($this->prev('fail', 3, 'fail', 60), 'info', 5, self::NOW, 6));
        $this->assertNull(HealthService::decide($this->prev('ok'), 'info', 1440, self::NOW, 6), 'info is never an alarm');
    }

    public function testDueFollowsEachChecksInterval(): void
    {
        $at = fn(int $ago) => date('Y-m-d H:i:s', self::NOW - $ago);
        $checks = [
            'asterisk' => ['interval' => 5, 'checked_at' => $at(4 * 60)],
            'disk' => ['interval' => 60, 'checked_at' => $at(61 * 60)],
            'backup' => ['interval' => 1440, 'checked_at' => null],
            'memory' => ['interval' => 0, 'checked_at' => null],
            'web' => ['interval' => 5, 'checked_at' => $at(5 * 60 - 20)],
        ];
        $this->assertSame(['disk', 'backup', 'web'], HealthService::due($checks, self::NOW));
        $this->assertSame(['asterisk', 'disk', 'backup', 'web'], HealthService::due($checks, self::NOW, true), '"Check now": all but the ones switched off');
    }

    public function testEveryCheckHasTextsAndADefaultInterval(): void
    {
        foreach (HealthChecks::DEFAULTS as $key => $min) {
            $this->assertContains($min, HealthChecks::INTERVALS, $key);
            $this->assertNotSame('health.check_' . $key, t('health.check_' . $key));
            $this->assertNotSame('health.desc_' . $key, t('health.desc_' . $key));
        }
        $this->assertSame(['fail', 'unknown check'], HealthChecks::run('nope'));
    }

    public function testCallRecordsFailWhenCallsLeaveNoRecord(): void
    {
        // 2026-10-21: Asterisk started before MariaDB and wrote no CDRs for 11 hours.
        $this->assertSame('fail', HealthChecks::callRecordsStatus(3600, 40, 0)[0]);
        $this->assertSame('ok', HealthChecks::callRecordsStatus(3600, 40, 38)[0]);
        // just started, or hardly any calls yet: no alarm
        $this->assertSame('ok', HealthChecks::callRecordsStatus(300, 40, 0)[0]);
        $this->assertSame('ok', HealthChecks::callRecordsStatus(3600, 2, 0)[0]);
    }
}
