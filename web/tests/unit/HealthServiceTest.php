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

    public function testAFlappingProblemMailsOncePerWindow(): void
    {
        // The alarm went out, the check recovered (status cleared, alerted_at kept)
        // and it is bad again five minutes later: the same incident, no new mail.
        $this->assertNull(HealthService::decide($this->prev('fail', 1, '', 300), 'fail', 5, self::NOW, 6));
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 1, '', 6 * 3600), 'fail', 5, self::NOW, 6));
        // A check that has not mailed inside the window still alarms at once.
        $this->assertSame('alarm', HealthService::decide($this->prev('ok'), 'warn', 1440, self::NOW, 6));
    }

    public function testTheWindowIsMeasuredFromTheLastMail(): void
    {
        $this->assertNull(HealthService::decide($this->prev('fail', 1, '', 3599), 'fail', 5, self::NOW, 1), 'inside a one-hour window');
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 1, '', 3600), 'fail', 5, self::NOW, 1), 'and through it at the hour');
    }

    public function testAQuietWindowHoldsAWarning(): void
    {
        $this->assertNull(HealthService::decide($this->prev('ok'), 'warn', 1440, self::NOW, 6, true));
        $this->assertSame('alarm', HealthService::decide($this->prev('ok'), 'warn', 1440, self::NOW, 6, false), 'mailed once the window is over');
    }

    public function testAFailureIsNeverHeld(): void
    {
        $this->assertSame('alarm', HealthService::decide($this->prev('ok'), 'fail', 1440, self::NOW, 6, true));
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 1), 'fail', 5, self::NOW, 6, true), 'two bad results in a row: mailed in the window');
    }

    public function testAQuietWindowHoldsARecovery(): void
    {
        $this->assertNull(HealthService::decide($this->prev('fail', 3, 'fail', 60), 'ok', 5, self::NOW, 6, true));
        $this->assertSame('recovered', HealthService::decide($this->prev('fail', 3, 'fail', 60), 'ok', 5, self::NOW, 6, false));
        $this->assertNull(HealthService::decide($this->prev('fail', 3), 'ok', 5, self::NOW, 6, true), 'a held warning that fixed itself has nothing to say');
    }

    public function testAMalformedOrEmptyWindowHoldsNothing(): void
    {
        // Malformed: refused by the page and never a window.
        foreach (['', '22:00', '25:00-07:00', '22:00 - 07:00'] as $bad) {
            $this->assertFalse(HealthService::validQuietWindow($bad), $bad);
            $this->assertFalse(HealthService::inQuietWindow($bad, '03:00'), $bad);
        }
        // Well formed but zero-length: accepted, and means no window at all.
        foreach (['09:00-09:00', '22:00-22:00'] as $empty) {
            $this->assertTrue(HealthService::validQuietWindow($empty), $empty);
            $this->assertFalse(HealthService::inQuietWindow($empty, '03:00'), $empty);
        }
    }

    public function testAWindowCrossesMidnight(): void
    {
        $this->assertTrue(HealthService::validQuietWindow('22:00-07:00'));
        $this->assertTrue(HealthService::inQuietWindow('22:00-07:00', '23:59'));
        $this->assertTrue(HealthService::inQuietWindow('22:00-07:00', '00:01'));
        $this->assertTrue(HealthService::inQuietWindow('22:00-07:00', '22:00'), 'the start belongs to the window');
        $this->assertFalse(HealthService::inQuietWindow('22:00-07:00', '07:00'), 'the end does not');
        $this->assertFalse(HealthService::inQuietWindow('22:00-07:00', '12:00'));
        $this->assertTrue(HealthService::inQuietWindow('09:00-17:00', '10:00'), 'a window inside one day');
        $this->assertFalse(HealthService::inQuietWindow('09:00-17:00', '08:59'));
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

    public function testTrunkLimitsWarnAboutRecentRefusals(): void
    {
        $astdb = "/trunkblock/nectip                               : 1799999000:412:rate:1799999950\n"
            . "/trunkblock/VOIP                                 : 1799990000:3:channels:1799990100\n"
            . "2 results found.\n";
        [$status, $msg] = HealthChecks::trunkLimitsStatus($astdb, self::NOW);
        $this->assertSame('warn', $status);
        $this->assertStringContainsString('nectip', $msg);
        $this->assertStringContainsString('412', $msg);
        $this->assertStringNotContainsString('VOIP', $msg, 'older than 15 minutes');
        $this->assertSame('ok', HealthChecks::trunkLimitsStatus("0 results found.\n", self::NOW)[0]);
        $this->assertSame('info', HealthChecks::trunkLimitsStatus('', self::NOW)[0], 'Asterisk did not answer: not "all fine"');
    }
}
