<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/NoticeService.php';

/**
 * The two rules that make a notice cabinet readable: one problem is one notice
 * inside the window, and mail never wakes anybody up.
 *
 * Needs `pbx_notices` (db/migrations/..._create_pbx_notices.php); CI migrates
 * the testing database before PHPUnit runs.
 */
final class NoticeServiceTest extends TestCase
{
    private const SETTING_KEYS = [
        'notice_email', 'notice_email_to', 'notice_quiet_hours', 'notice_window_hours',
    ];

    protected function setUp(): void
    {
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

    private function wipe(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM pbx_notices');
        $placeholders = implode(',', array_fill(0, count(self::SETTING_KEYS), '?'));
        $db->prepare('DELETE FROM sys_settings WHERE setting_key IN (' . $placeholders . ')')
            ->execute(self::SETTING_KEYS);
    }

    private function setSetting(string $key, string $value): void
    {
        getDB()->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
    }

    private function noticeCount(): int
    {
        return (int) getDB()->query('SELECT COUNT(*) FROM pbx_notices')->fetchColumn();
    }

    public function testTheDefaultsAreQuiet(): void
    {
        $settings = NoticeService::settings();
        self::assertFalse($settings['email']);
        self::assertSame('', $settings['quiet_hours']);
        self::assertSame(NoticeService::DEFAULT_WINDOW_HOURS, $settings['window_hours']);
    }

    public function testTheSameProblemInsideTheWindowIsOneNotice(): void
    {
        $first = NoticeService::notify('warn', 'Trunk down', 'REGISTER failed', 'trunk-1-down', 'trunks');
        self::assertArrayHasKey('id', $first);

        $second = NoticeService::notify('warn', 'Trunk down', 'REGISTER failed', 'trunk-1-down', 'trunks');
        self::assertSame(['deduped' => true], $second);
        self::assertSame(1, $this->noticeCount());
    }

    public function testADifferentProblemIsItsOwnNotice(): void
    {
        NoticeService::notify('warn', 'Trunk down', 'REGISTER failed', 'trunk-1-down');
        NoticeService::notify('warn', 'Trunk down', 'REGISTER failed', 'trunk-2-down');
        self::assertSame(2, $this->noticeCount());
    }

    public function testTheWindowCanBeTurnedOff(): void
    {
        $this->setSetting('notice_window_hours', '0');
        NoticeService::notify('info', 'Sync finished', '', 'sync-done');
        NoticeService::notify('info', 'Sync finished', '', 'sync-done');
        self::assertSame(2, $this->noticeCount());
    }

    public function testTheWindowIsBoundedToAWeek(): void
    {
        $this->setSetting('notice_window_hours', '9999');
        self::assertSame(168, NoticeService::settings()['window_hours']);
        $this->setSetting('notice_window_hours', '-5');
        self::assertSame(0, NoticeService::settings()['window_hours']);
    }

    public function testASeverityOutsideTheListBecomesInfo(): void
    {
        NoticeService::notify('explode', 'Odd severity', '');
        $row = getDB()->query('SELECT severity FROM pbx_notices ORDER BY id DESC LIMIT 1')->fetchColumn();
        self::assertSame('info', $row);
    }

    public function testAnEmptyTitleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NoticeService::notify('info', '   ', 'body');
    }

    public function testReadingHidesTheCount(): void
    {
        $first = NoticeService::notify('info', 'One', '', 'one');
        NoticeService::notify('info', 'Two', '', 'two');
        self::assertSame(2, NoticeService::unreadCount());

        self::assertTrue(NoticeService::markRead((int) $first['id']));
        self::assertSame(1, NoticeService::unreadCount());
        self::assertFalse(NoticeService::markRead((int) $first['id']), 'already read stays read');
    }

    public function testMarkAllRead(): void
    {
        NoticeService::notify('info', 'One', '', 'one');
        NoticeService::notify('critical', 'Two', '', 'two');
        self::assertSame(2, NoticeService::markAllRead());
        self::assertSame(0, NoticeService::unreadCount());
    }

    public function testAQuietWindowCrossingMidnight(): void
    {
        self::assertTrue(NoticeService::inQuietWindow('22:00-07:00', '23:30'));
        self::assertTrue(NoticeService::inQuietWindow('22:00-07:00', '06:59'));
        self::assertTrue(NoticeService::inQuietWindow('22:00-07:00', '02:00'));
        self::assertFalse(NoticeService::inQuietWindow('22:00-07:00', '07:00'));
        self::assertFalse(NoticeService::inQuietWindow('22:00-07:00', '12:00'));
        self::assertFalse(NoticeService::inQuietWindow('22:00-07:00', '21:59'));
    }

    public function testAQuietWindowInsideOneDay(): void
    {
        self::assertTrue(NoticeService::inQuietWindow('09:00-17:00', '09:00'));
        self::assertTrue(NoticeService::inQuietWindow('09:00-17:00', '16:59'));
        self::assertFalse(NoticeService::inQuietWindow('09:00-17:00', '17:00'));
        self::assertFalse(NoticeService::inQuietWindow('09:00-17:00', '08:59'));
    }

    public function testATypoIsNotAQuietWindow(): void
    {
        // The safe answer: a malformed window must never silence alarms.
        self::assertFalse(NoticeService::inQuietWindow('', '03:00'));
        self::assertFalse(NoticeService::inQuietWindow('9:00-17:00', '10:00'));
        self::assertFalse(NoticeService::inQuietWindow('09:00-09:00', '09:00'));
        self::assertFalse(NoticeService::inQuietWindow('soon', '10:00'));
        self::assertFalse(NoticeService::inQuietWindow('09:00-17:00', 'lunch'));
    }

    public function testQuietHoursNowReadsTheSetting(): void
    {
        $this->setSetting('notice_quiet_hours', '00:00-23:59');
        self::assertTrue(NoticeService::quietHoursNow());
        $this->setSetting('notice_quiet_hours', '');
        self::assertFalse(NoticeService::quietHoursNow());
    }

    public function testMailIsNotSentWhenItIsOff(): void
    {
        $res = NoticeService::notify('critical', 'Disk full', '/var is 99 % full', 'disk-full');
        self::assertFalse($res['emailed']);
        self::assertNull(getDB()->query('SELECT emailed_at FROM pbx_notices ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testAQuietWindowStopsMailEvenWhenItIsOn(): void
    {
        $this->setSetting('notice_email', '1');
        $this->setSetting('notice_quiet_hours', '00:00-23:59');
        $res = NoticeService::notify('critical', 'Disk full', '', 'disk-full');
        self::assertFalse($res['emailed'], 'quiet hours silence even a critical notice');
    }

    public function testSaveSettingsRefusesATypo(): void
    {
        self::assertFalse(NoticeService::saveSettings(['notice_quiet_hours' => '22:00'])['success']);
        self::assertFalse(NoticeService::saveSettings(['notice_window_hours' => 999])['success']);
        self::assertFalse(NoticeService::saveSettings(['notice_email' => 1, 'notice_email_to' => 'nope'])['success']);
        self::assertTrue(NoticeService::saveSettings([
            'notice_email' => 1,
            'notice_email_to' => 'ops@example.com',
            'notice_quiet_hours' => '22:00-07:00',
            'notice_window_hours' => 12,
        ])['success']);
        $settings = NoticeService::settings();
        self::assertTrue($settings['email']);
        self::assertSame('22:00-07:00', $settings['quiet_hours']);
        self::assertSame(12, $settings['window_hours']);
    }
}
