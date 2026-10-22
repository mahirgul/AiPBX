<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/PluginCronSchedule.php';

/**
 * Cron is a small language with sharp edges, and a plugin job that runs on the
 * wrong days is worse than one that does not run at all. These tests pin the
 * grammar, the boundaries and the day-of-month/day-of-week rule that surprises
 * people.
 */
final class PluginCronScheduleTest extends TestCase
{
    private function at(string $local): DateTimeImmutable
    {
        // Local time on purpose: the dispatcher matches the server's cron.
        return new DateTimeImmutable($local);
    }

    public function testEveryMinuteMatchesWheneverItIsCalled(): void
    {
        $schedule = new PluginCronSchedule('* * * * *');
        self::assertTrue($schedule->matches($this->at('2026-10-21 00:00:00')));
        self::assertTrue($schedule->matches($this->at('2026-10-21 13:37:00')));
        self::assertTrue($schedule->matches($this->at('2026-12-31 23:59:00')));
    }

    public function testAWorkingHoursExpressionMatchesOnlyThoseMinutes(): void
    {
        $schedule = new PluginCronSchedule('0 9 * * 1-5');
        self::assertTrue($schedule->matches($this->at('2026-10-21 09:00:00')), 'Wednesday 09:00');
        self::assertFalse($schedule->matches($this->at('2026-10-21 09:01:00')));
        self::assertFalse($schedule->matches($this->at('2026-10-21 10:00:00')));
        self::assertFalse($schedule->matches($this->at('2026-10-25 09:00:00')), 'Sunday');
    }

    public function testStepsAndLists(): void
    {
        $quarter = new PluginCronSchedule('*/15 * * * *');
        foreach ([0, 15, 30, 45] as $minute) {
            self::assertTrue($quarter->matches($this->at(sprintf('2026-10-21 10:%02d:00', $minute))));
        }
        self::assertFalse($quarter->matches($this->at('2026-10-21 10:05:00')));

        $list = new PluginCronSchedule('5,10,20-22 * * * *');
        foreach ([5, 10, 20, 21, 22] as $minute) {
            self::assertTrue($list->matches($this->at(sprintf('2026-10-21 10:%02d:00', $minute))));
        }
        self::assertFalse($list->matches($this->at('2026-10-21 10:23:00')));
    }

    public function testSundayIsBothZeroAndSeven(): void
    {
        $sunday = $this->at('2026-10-25 03:00:00');
        self::assertTrue((new PluginCronSchedule('0 3 * * 0'))->matches($sunday));
        self::assertTrue((new PluginCronSchedule('0 3 * * 7'))->matches($sunday));
        self::assertFalse((new PluginCronSchedule('0 3 * * 6'))->matches($sunday));
    }

    public function testDayOfMonthAndDayOfWeekUseTheOrRule(): void
    {
        // "the 13th or any Friday", not "Friday the 13th".
        $schedule = new PluginCronSchedule('0 0 13 * 5');
        self::assertTrue($schedule->matches($this->at('2026-12-13 00:00:00')), 'the 13th, a Sunday');
        self::assertTrue($schedule->matches($this->at('2026-11-13 00:00:00')), 'Friday the 13th');
        self::assertTrue($schedule->matches($this->at('2026-10-23 00:00:00')), 'a Friday, not the 13th');
        self::assertFalse($schedule->matches($this->at('2026-10-22 00:00:00')), 'a Thursday');

        // With only one of the two restricted, the other must still match.
        $firstOfMonth = new PluginCronSchedule('0 0 1 * *');
        self::assertTrue($firstOfMonth->matches($this->at('2026-11-01 00:00:00')));
        self::assertFalse($firstOfMonth->matches($this->at('2026-11-02 00:00:00')));
    }

    public function testMonthAndLeapDay(): void
    {
        $schedule = new PluginCronSchedule('30 2 29 2 *');
        self::assertTrue($schedule->matches($this->at('2028-02-29 02:30:00')));
        self::assertFalse($schedule->matches($this->at('2026-02-28 02:30:00')));
    }

    public function testInvalidExpressionsAreRejectedWithAMessage(): void
    {
        $cases = [
            '' => 'five fields',
            '* * * *' => 'five fields',
            '* * * * * *' => 'five fields',
            '60 * * * *' => 'outside',
            '* 24 * * *' => 'outside',
            '* * 0 * *' => 'outside',
            '* * * 13 *' => 'outside',
            '* * * * 8' => 'outside',
            '*/0 * * * *' => 'positive',
            '*/ * * * *' => 'step',
            '5-1 * * * *' => 'ends before',
            'a * * * *' => 'not a number',
            '@daily' => 'five fields',
            '1,,2 * * * *' => 'empty list',
        ];
        foreach ($cases as $expression => $expected) {
            $problems = PluginCronSchedule::validate($expression);
            self::assertNotSame([], $problems, 'should reject ' . var_export($expression, true));
            self::assertStringContainsString($expected, implode(' | ', $problems),
                'message for ' . var_export($expression, true));
        }
    }

    public function testAValidExpressionReportsNoProblemsAndKeepsItsText(): void
    {
        self::assertSame([], PluginCronSchedule::validate('*/5 0-6 1,15 * 1-5'));
        self::assertSame('*/5 0-6 1,15 * 1-5',
            (new PluginCronSchedule('*/5 0-6 1,15 * 1-5'))->expression());
    }

    public function testTheConstructorRefusesWhatTheValidatorRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PluginCronSchedule('every five minutes');
    }
}
