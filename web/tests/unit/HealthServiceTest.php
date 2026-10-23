<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/HealthService.php';

/** Admin → Health checks: when a check is due and when it asks the cabinet for a notice. */
final class HealthServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function prev(string $status = 'ok', int $streak = 0): array
    {
        return ['status' => $status, 'bad_streak' => $streak];
    }

    public function testFrequentChecksAlarmOnlyAfterTwoBadResults(): void
    {
        $this->assertNull(HealthService::decide($this->prev('ok'), 'fail', 5), 'one hiccup stays quiet');
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 1), 'fail', 5));
        // Daily checks report the first bad result.
        $this->assertSame('alarm', HealthService::decide($this->prev('ok'), 'warn', 1440));
    }

    public function testALastingProblemKeepsAskingForANotice(): void
    {
        // How often it is written and mailed is the cabinet's dedupe window now.
        $this->assertSame('alarm', HealthService::decide($this->prev('fail', 5), 'fail', 5));
        $this->assertSame('alarm', HealthService::decide($this->prev('warn', 5), 'fail', 5), 'warn → fail at once');
        $this->assertNull(HealthService::decide($this->prev('fail', 5), 'warn', 5), 'better but not fixed: quiet');
    }

    public function testRecoveryOnlyAfterAProblemLongEnoughToReport(): void
    {
        $this->assertSame('recovered', HealthService::decide($this->prev('fail', 3), 'ok', 5));
        $this->assertSame('recovered', HealthService::decide($this->prev('warn', 1), 'ok', 1440), 'a daily check reports its first result');
        $this->assertNull(HealthService::decide($this->prev('fail', 1), 'ok', 5), 'a hiccup that was never reported');
        $this->assertNull(HealthService::decide($this->prev('fail', 3), 'info', 5));
        $this->assertNull(HealthService::decide($this->prev('ok'), 'info', 1440), 'info is never an alarm');
    }

    public function testTheNoticeCarriesTheCheckAndItsStatus(): void
    {
        $failed = HealthService::noticeFor('trunks', 'fail', 'provider is unreachable');
        self::assertSame('critical', $failed['severity']);
        self::assertStringContainsString(t('health.check_trunks'), $failed['title']);
        self::assertStringContainsString(t('health.status_fail'), $failed['body']);
        self::assertSame('health:trunks:fail', $failed['dedupe_key']);

        $warning = HealthService::noticeFor('trunks', 'warn', 'disk filling up');
        self::assertSame('warn', $warning['severity']);
        self::assertSame('health:trunks:warn', $warning['dedupe_key'], 'a new key, so an escalation is a new notice');

        $recovered = HealthService::noticeFor('trunks', 'ok', 'registered again');
        self::assertSame('info', $recovered['severity'], 'back to normal is never e-mailed');
        self::assertSame('health:trunks:ok', $recovered['dedupe_key']);
        self::assertStringContainsString(t('health.check_trunks'), $recovered['title']);
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
}
