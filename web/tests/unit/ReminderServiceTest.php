<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/ReminderService.php';
require_once __DIR__ . '/../../src/services/ai/PbxToolRegistry.php';

final class ReminderServiceTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->beginTransaction();
        getDB()->exec("INSERT INTO sys_users (username, password_hash, full_name, extension, role, is_active)
                       VALUES ('zz_rem', 'x', 'Zeynep Test', '7892', 'user', 1)");
    }

    protected function tearDown(): void
    {
        getDB()->rollBack();
    }

    public function testCreateDispatchRecurrenceAndTools(): void
    {
        $res = ReminderService::create([
            'extension' => '7892',
            'kind' => 'wakeup',
            'remind_at' => date('Y-m-d H:i:s', time() - 60),
            'recurrence' => 'daily',
        ]);
        $this->assertTrue($res['ok']);
        $id = (int) $res['id'];

        $due = array_values(array_filter(ReminderService::dueReminders(50), static fn (array $r): bool => (int) $r['id'] === $id));
        $this->assertCount(1, $due);

        $this->assertTrue(ReminderService::dispatchOne($due[0], static fn (): bool => true));
        $row = getDB()->query("SELECT status, remind_at FROM ai_reminders WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status'], 'daily wakeup advances to next day');

        $list = PbxToolRegistry::execute('reminder_list', ['extension' => '7892'], ['caller' => '7892']);
        $this->assertTrue($list['ok']);
        $this->assertStringContainsString('wakeup', $list['text']);

        $cancel = PbxToolRegistry::execute('reminder_cancel', ['id' => $id, 'confirmed' => true], ['caller' => '7892']);
        $this->assertTrue($cancel['ok']);
    }

    public function testBuildOriginateFieldsSyncAndAudioSocket(): void
    {
        $rem = ['id' => 42, 'extension' => '7892', 'kind' => 'reminder', 'message' => 'Toplantı'];
        $fallback = ReminderService::buildOriginateFields($rem, '');
        $this->assertSame('false', $fallback['Async']);
        $this->assertSame('Playback', $fallback['Application']);
        $this->assertSame('beep', $fallback['Data']);

        $uuid = '12345678-1234-4234-8234-1234567890ab';
        $tts = ReminderService::buildOriginateFields($rem, $uuid);
        $this->assertSame('false', $tts['Async']);
        $this->assertSame('AudioSocket', $tts['Application']);
        $this->assertSame($uuid . ',127.0.0.1:8791', $tts['Data']);
    }

    public function testStaleReminderIsNotDispatchedTwiceOrAfterCancellation(): void
    {
        $remindAt = date('Y-m-d H:i:s', time() - 60);
        $create = static fn (): array => ReminderService::create([
            'extension' => '7892',
            'message' => 'Test reminder',
            'remind_at' => $remindAt,
        ]);
        $first = $create()['id'];
        $second = $create()['id'];
        $rows = array_column(ReminderService::dueReminders(), null, 'id');
        $this->assertFalse(ReminderService::dispatchOne($rows[$first], static fn (): bool => false));
        $this->assertTrue(ReminderService::cancel($second, null, true));
        $calls = 0;
        foreach ([$first, $second] as $id) {
            $this->assertFalse(ReminderService::dispatchOne($rows[$id], static function () use (&$calls): bool {
                $calls++;
                return false;
            }));
        }
        $this->assertSame(0, $calls, 'Stale and cancelled reminders never reach the phone');
        $this->assertSame(1, (int) DBHelper::fetchColumn('SELECT attempts FROM ai_reminders WHERE id = ?', [$first]));
    }

    public function testOverdueRecurringReminderJumpsToNextFutureOccurrence(): void
    {
        $next = strtotime(ReminderService::nextOccurrence(date('Y-m-d H:i:s', time() - 7 * 86400), 'daily'));
        $this->assertGreaterThan(time(), $next);
        $this->assertLessThanOrEqual(time() + 25 * 3600, $next);
    }
}
