<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/queue_helper.php';

/** Queue position and periodic announcements reach queues_details (Asterisk realtime). */
final class QueueAnnouncementTest extends TestCase
{
    private const Q = 'zz_ann_test';

    protected function tearDown(): void
    {
        getDB()->prepare('DELETE FROM queues_details WHERE id = ?')->execute([self::Q]);
    }

    private function details(): array
    {
        $st = getDB()->prepare('SELECT keyword, data FROM queues_details WHERE id = ?');
        $st->execute([self::Q]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testDefaultsKeepTheBuiltInAnnouncements(): void
    {
        QueueHelper::syncQueueToDetails(['queue_name' => self::Q]);
        $d = $this->details();
        $this->assertSame(['yes', '45', 'queue-youarenext,queue-thankyou'], [$d['announce-position'], $d['periodic-announce-frequency'], $d['periodic-announce']]);
    }

    public function testOwnSettings(): void
    {
        QueueHelper::syncQueueToDetails(['queue_name' => self::Q, 'announce_position' => 'no', 'periodic_frequency' => 0, 'periodic_announce' => 'custom/please_hold.wav']);
        $d = $this->details();
        $this->assertSame(['no', '0', 'custom/please_hold'], [$d['announce-position'], $d['periodic-announce-frequency'], $d['periodic-announce']]);
    }
}
