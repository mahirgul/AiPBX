<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CronScheduleHelperTest extends TestCase
{
    public function testValidation(): void
    {
        $this->assertTrue(CronScheduleHelper::isValid('* * * * *'));
        $this->assertTrue(CronScheduleHelper::isValid('*/5 * * * *'));
        $this->assertTrue(CronScheduleHelper::isValid('0 3 * * *'));
        $this->assertTrue(CronScheduleHelper::isValid('15 2 1 * 5'));
        $this->assertTrue(CronScheduleHelper::isValid('0-30/5 8-18 * * 1-5'));

        $this->assertFalse(CronScheduleHelper::isValid('invalid'));
        $this->assertFalse(CronScheduleHelper::isValid('* * * *')); // 4 parts
        $this->assertFalse(CronScheduleHelper::isValid('60 * * * *')); // minute out of range
        $this->assertFalse(CronScheduleHelper::isValid('* 24 * * *')); // hour out of range
        $this->assertFalse(CronScheduleHelper::isValid('* * 32 * *')); // day out of range
        $this->assertFalse(CronScheduleHelper::isValid('* * * 13 *')); // month out of range
        $this->assertFalse(CronScheduleHelper::isValid('* * * * 8')); // dow out of range
        $this->assertFalse(CronScheduleHelper::isValid('*/0 * * * *')); // step 0 invalid
    }

    public function testIsDue(): void
    {
        // 2026-10-24 03:00:00 (Saturday, DOW = 6)
        $time = new DateTimeImmutable('2026-10-24 03:00:00');

        $this->assertTrue(CronScheduleHelper::isDue('0 3 * * *', $time));
        $this->assertTrue(CronScheduleHelper::isDue('*/5 * * * *', $time));
        $this->assertTrue(CronScheduleHelper::isDue('0 3 * * 6', $time));
        $this->assertFalse(CronScheduleHelper::isDue('0 3 * * 0', $time)); // Sunday only
        $this->assertFalse(CronScheduleHelper::isDue('15 3 * * *', $time));
        $this->assertFalse(CronScheduleHelper::isDue('0 4 * * *', $time));
    }

    public function testGetNextRuns(): void
    {
        // From 2026-10-24 10:00:00
        $from = new DateTimeImmutable('2026-10-24 10:00:00');

        // Every 5 minutes
        $runs = CronScheduleHelper::getNextRuns('*/5 * * * *', 3, $from);
        $this->assertCount(3, $runs);
        $this->assertSame('2026-10-24 10:05:00', $runs[0]);
        $this->assertSame('2026-10-24 10:10:00', $runs[1]);
        $this->assertSame('2026-10-24 10:15:00', $runs[2]);

        // Nightly 03:00
        $nightly = CronScheduleHelper::getNextRuns('0 3 * * *', 2, $from);
        $this->assertCount(2, $nightly);
        $this->assertSame('2026-10-25 03:00:00', $nightly[0]);
        $this->assertSame('2026-10-26 03:00:00', $nightly[1]);
    }
}
