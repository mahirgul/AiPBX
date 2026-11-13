<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/VoicemailService.php';
require_once dirname(__DIR__, 2) . '/src/services/QueueService.php';
require_once dirname(__DIR__, 2) . '/src/services/PhoneProvisionService.php';

/** Service checks of the 1.76.0 review that had no test of their own. */
final class ReviewServiceChecksTest extends TestCase
{
    private string $spool = '';

    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION['csrf_token'] = 'rev';
        $this->spool = sys_get_temp_dir() . '/aipbx-spool-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spool . '/*/*/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (array_merge(glob($this->spool . '/*/*') ?: [], glob($this->spool . '/*') ?: [], [$this->spool]) as $d) {
            if (is_dir($d)) {
                rmdir($d);
            }
        }
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key IN ('vm_notify_new_7001', 'vm_notify_new_7002')");
        $db->exec("DELETE FROM pbx_queues WHERE queue_name = 'zzrev_queue'");
        $db->exec('DELETE FROM pbx_phones_waiting');
    }

    private function message(string $box, string $folder, string $msg, int $origtime, string $callerid): string
    {
        $dir = "{$this->spool}/{$box}/{$folder}";
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents("{$dir}/{$msg}.txt", "[message]\norigtime={$origtime}\ncallerid={$callerid}\n");
        file_put_contents("{$dir}/{$msg}.wav", 'x');
        return "{$dir}/{$msg}";
    }

    public function testSpoolPurgeDeletesOnlyWhatMatches(): void
    {
        $old = $this->message('7001', 'INBOX', 'msg0000', time() - 100 * 86400, '"A" <05325550001>');
        $new = $this->message('7001', 'INBOX', 'msg0001', time() - 86400, '"B" <05325550002>');
        $other = $this->message('7002', 'Old', 'msg0000', time() - 100 * 86400, '"C" <05325550003>');
        $noTime = $this->message('7002', 'INBOX', 'msg0001', 0, '"D" <05325550004>');

        // Retention: older than 90 days, and a message without a time is never taken for old.
        $cutoff = time() - 90 * 86400;
        $n = VoicemailService::purgeSpool(fn(string $box, int $t) => $t > 0 && $t < $cutoff, $this->spool);
        $this->assertSame(2, $n);
        $this->assertFileDoesNotExist($old . '.txt');
        $this->assertFileDoesNotExist($old . '.wav');
        $this->assertFileDoesNotExist($other . '.wav');
        $this->assertFileExists($new . '.wav');
        $this->assertFileExists($noTime . '.txt');
        $this->assertSame('1', getSystemSetting('vm_notify_new_7001', ''), 'the lamp count follows');

        // Erasure by caller: only that caller's message.
        $n = VoicemailService::purgeSpool(fn(string $box, int $t, string $cid) => str_contains($cid, '<05325550002>'), $this->spool);
        $this->assertSame(1, $n);
        $this->assertFileDoesNotExist($new . '.wav');
        $this->assertFileExists($noTime . '.wav');
    }

    private function queue(array $extra): array
    {
        return QueueService::saveQueue($extra + ['csrf_token' => 'rev', 'queue_name' => 'zzrev_queue', 'title' => 'Review', 'strategy' => 'ringall',
            'timeout' => '15', 'retry' => '5', 'wrapuptime' => '0', 'maxlen' => '0', 'max_wait_seconds' => '300',
            'fallback_action' => 'hangup', 'musicclass' => 'default', 'members' => [], 'static_members' => [], 'record_format' => 'wav']);
    }

    public function testQueueNameAndMusicClassAreChecked(): void
    {
        $this->assertFalse($this->queue(['musicclass' => 'no-such-class'])['success']);
        $first = $this->queue(['musicclass' => '']);
        $this->assertTrue($first['success'], $first['error'] ?? '');
        $this->assertSame('default', getDB()->query("SELECT musicclass FROM pbx_queues WHERE queue_name = 'zzrev_queue'")->fetchColumn());
        $this->assertFalse($this->queue([])['success'], 'a second queue with the same name');
    }

    public function testWaitingPhonesStopAtFiveHundred(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM pbx_phones_waiting');
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = sprintf("('0015650%05x', 'yealink', '192.0.2.1', 'ua')", $i);
        }
        $db->exec('INSERT INTO pbx_phones_waiting (mac, vendor, ip, user_agent) VALUES ' . implode(',', $rows));
        $record = new ReflectionMethod(PhoneProvisionService::class, 'recordWaiting');

        $record->invoke(null, '001565ffffff', 'yealink', '192.0.2.9', 'ua');
        $this->assertSame(500, (int) $db->query('SELECT COUNT(*) FROM pbx_phones_waiting')->fetchColumn());

        $record->invoke(null, '001565000000', 'yealink', '192.0.2.9', 'ua');
        $this->assertSame(2, (int) $db->query("SELECT request_count FROM pbx_phones_waiting WHERE mac = '001565000000'")->fetchColumn(), 'a known phone is still updated');
    }
}
