<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/HolidayCalendarService.php';

final class HolidayCalendarServiceTest extends TestCase
{
    public function testTypedDaysAndRanges(): void
    {
        $d = HolidayCalendarService::parseText("2026-01-01 New Year\n\n# note\n2026-12-24..2026-12-26 Christmas\n2026-05-01");
        $this->assertSame([
            '2026-01-01' => 'New Year', '2026-05-01' => '',
            '2026-12-24' => 'Christmas', '2026-12-25' => 'Christmas', '2026-12-26' => 'Christmas',
        ], $d);
        $this->assertSame("2026-01-01 New Year\n2026-05-01\n2026-12-24 Christmas\n2026-12-25 Christmas\n2026-12-26 Christmas", HolidayCalendarService::formatText($d));

        $this->expectException(InvalidArgumentException::class);
        HolidayCalendarService::parseText("2026-02-30 Nope");
    }

    public function testIcsAllDayMultiDayAndYearlyEvents(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260423\r\nDTEND;VALUE=DATE:20260424\r\nSUMMARY:National Sovereignty\\, Children's Day\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260320\r\nDTEND;VALUE=DATE:20260323\r\nSUMMARY:Ramadan\r\n Feast\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20200101\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:New Year\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART:20261029T090000Z\r\nSUMMARY:Republic Day\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
        $d = HolidayCalendarService::parseIcs($ics, 2026);
        $this->assertSame("National Sovereignty, Children's Day", $d['2026-04-23']);
        $this->assertArrayNotHasKey('2026-04-24', $d); // DTEND is the day after
        $this->assertSame('RamadanFeast', $d['2026-03-20']); // a folded line joins without the space
        $this->assertArrayHasKey('2026-03-22', $d);
        $this->assertArrayNotHasKey('2026-03-23', $d);
        $this->assertSame('New Year', $d['2026-01-01']);
        $this->assertArrayHasKey('2028-01-01', $d);
        $this->assertArrayNotHasKey('2025-01-01', $d); // past years are not written out
        $this->assertArrayNotHasKey('2029-01-01', $d);
        $this->assertSame('Republic Day', $d['2026-10-29']);

        $rruleIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20250101\r\nRRULE:FREQ=YEARLY;COUNT=2\r\nSUMMARY:Two Years\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20250202\r\nRRULE:FREQ=YEARLY;INTERVAL=2\r\nSUMMARY:Biennial\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20250303\r\nRRULE:FREQ=YEARLY;UNTIL=20261231\r\nSUMMARY:Until 2026\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
        $r = HolidayCalendarService::parseIcs($rruleIcs, 2025);
        $this->assertArrayHasKey('2025-01-01', $r);
        $this->assertArrayHasKey('2026-01-01', $r);
        $this->assertArrayNotHasKey('2027-01-01', $r);
        $this->assertArrayHasKey('2025-02-02', $r);
        $this->assertArrayNotHasKey('2026-02-02', $r);
        $this->assertArrayHasKey('2027-02-02', $r);
        $this->assertArrayHasKey('2025-03-03', $r);
        $this->assertArrayHasKey('2026-03-03', $r);
        $this->assertArrayNotHasKey('2027-03-03', $r);

        $this->expectException(InvalidArgumentException::class);
        HolidayCalendarService::parseIcs('<html>not a calendar</html>');
    }

    public function testYearlyWeekdayRulesAndEventScopedExclusions(): void
    {
        $ics = "BEGIN:VCALENDAR\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20240115\nDTEND;VALUE=DATE:20240117\n"
            . "RRULE:FREQ=YEARLY;BYMONTH=1;BYDAY=3MO\nEXDATE;VALUE=DATE:20270118\n"
            . "EXDATE;VALUE=DATE:20280117\nSUMMARY:Winter holiday\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20270118\nSUMMARY:Other holiday\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20250526\nRRULE:FREQ=YEARLY;BYMONTH=5;BYDAY=MO;BYSETPOS=-1;COUNT=2\n"
            . "SUMMARY:Last Monday\nEND:VEVENT\nEND:VCALENDAR\n";

        $this->assertSame([
            '2026-01-19' => 'Winter holiday',
            '2026-01-20' => 'Winter holiday',
            '2026-05-25' => 'Last Monday',
            '2027-01-18' => 'Other holiday',
        ], HolidayCalendarService::parseIcs($ics, 2026));
    }

    public function testAddressesMustBeWebAddresses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HolidayCalendarService::fetchIcs('file:///etc/passwd');
    }

    public function testTimeGroupClosesOnItsCalendarDays(): void
    {
        $db = getDB();
        $next = date('Y-m-d', strtotime('+10 days'));
        $past = date('Y-m-d', strtotime('-10 days'));
        $db->prepare('INSERT INTO pbx_holiday_calendars (name, dates_json) VALUES (?, ?)')
            ->execute(['zz_hc_test', json_encode([$past => 'Old', $next => 'Coming'])]);
        $calId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, holiday_calendar_ids, is_active)
                   VALUES (7931, 'zz_hc_tg', '09:00:00', '18:00:00', '1,2,3,4,5', '{$calId}', 1)");
        $db->exec("INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
                   VALUES (7931, 'zz_hc_tc', 7931, 'hangup', '', 'hangup', '', 1)");
        try {
            $this->assertTrue(syncTimeCondition(7931));
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_timecondition_7931.conf');
            $this->assertStringContainsString("\"\${BUGUN}\" = \"{$next}\"]?tc_nomatch", $conf);
            $this->assertStringNotContainsString($past, $conf);

            $this->assertSame([$next], HolidayCalendarService::datesFor("{$calId},0,abc", date('Y-m-d')));
            $this->assertSame((string) $calId, HolidayCalendarService::idCsv([(string) $calId, '0', 'x', (string) $calId]));
        } finally {
            $db->exec('DELETE FROM pbx_time_conditions WHERE id = 7931');
            $db->exec('DELETE FROM pbx_time_groups WHERE id = 7931');
            $db->prepare('DELETE FROM pbx_holiday_calendars WHERE id = ?')->execute([$calId]);
            @unlink(ASTERISK_PBX_DIR . '/extensions_timecondition_7931.conf');
        }
    }
}
