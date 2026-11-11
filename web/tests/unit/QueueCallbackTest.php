<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/QueueCallbackService.php';

final class QueueCallbackTest extends TestCase
{
    private int $queue;

    protected function setUp(): void
    {
        getDB()->beginTransaction();
        getDB()->exec("INSERT INTO pbx_queues (queue_name, title, callback_enabled, is_active, members_json)
            VALUES ('callback-test', 'Callbacks', 1, 1, '[\"98761\",\"98762\"]')");
        $this->queue = (int) getDB()->lastInsertId();
    }

    protected function tearDown(): void
    {
        getDB()->rollBack();
    }

    public function testRepeatedRequestsAreOneEntryAndKeepTheDialledPrefix(): void
    {
        $this->assertTrue(QueueCallbackService::request($this->queue, '+903125550100'));
        $this->assertTrue(QueueCallbackService::request($this->queue, '00903125550100'));
        $rows = QueueCallbackService::pending('98761');
        $this->assertCount(1, $rows);
        $this->assertSame('+903125550100', $rows[0]['dial_number']);
        $this->assertSame([], QueueCallbackService::pending('98763'));
    }

    public function testDisabledQueuesAndInvalidCallerIdsCannotCreateRequests(): void
    {
        foreach (['', 'anonymous', '123;id', '12', '++12345', '123\n456'] as $phone) {
            $this->assertFalse(QueueCallbackService::request($this->queue, $phone));
        }
        getDB()->exec('UPDATE pbx_queues SET callback_enabled = 0 WHERE id = ' . $this->queue);
        $this->assertFalse(QueueCallbackService::request($this->queue, '03125550100'));
    }

    public function testOnlyOneAssignedAgentCanClaimAndFailedOriginateKeepsTheRequest(): void
    {
        QueueCallbackService::request($this->queue, '03125550100');
        $id = (int) QueueCallbackService::pending('98761')[0]['id'];
        $this->assertNull(QueueCallbackService::claim($id, '98763'));
        $this->assertSame('03125550100', QueueCallbackService::claim($id, '98761'));
        $this->assertNull(QueueCallbackService::claim($id, '98762'));
        QueueCallbackService::release($id, '98762');
        $this->assertNull(QueueCallbackService::claim($id, '98762'));
        QueueCallbackService::release($id, '98761');
        $this->assertSame('03125550100', QueueCallbackService::claim($id, '98762'));
        $this->assertCount(1, QueueCallbackService::pending('98761'));
    }

    public function testConversationWithAnyQueueAgentClosesRequestsForTheNumberOnly(): void
    {
        QueueCallbackService::request($this->queue, '+903125550100');
        QueueCallbackService::request($this->queue, '+903125550101');
        QueueCallbackService::complete('00903125550100', '98763', time() + 1);
        $this->assertCount(2, QueueCallbackService::pending('98761'));
        QueueCallbackService::complete('00903125550100', '98762', time() + 1);
        $rows = QueueCallbackService::pending('98761');
        $this->assertCount(1, $rows);
        $this->assertSame('+903125550101', $rows[0]['dial_number']);
    }

    public function testAnOlderConversationCannotDeleteANewerRequest(): void
    {
        QueueCallbackService::request($this->queue, '03125550100');
        QueueCallbackService::complete('03125550100', '98761', time() - 60);
        $this->assertCount(1, QueueCallbackService::pending('98761'));
    }

    public function testConfirmationIsRequiredSavedAndPlayedOnlyAfterSuccess(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/QueueService.php';
        require_once dirname(__DIR__, 2) . '/src/services/SoundService.php';
        require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
        $session = $_SESSION;
        $_SESSION['csrf_token'] = 'callback-confirmation-test';
        $_SESSION['user_id'] = null;
        try {
            getDB()->exec("INSERT INTO pbx_announcements (title, audio_file, is_active) VALUES ('Callback test', 'custom/callback-confirmation-test.wav', 1)");
            $announcement = (int) getDB()->lastInsertId();
            $data = ['csrf_token' => 'callback-confirmation-test', 'queue_id' => $this->queue,
                'queue_name' => 'callback-test', 'title' => 'Callbacks', 'callback_enabled' => 1,
                'periodic_announce' => 'custom/callback-confirmation-test.wav', 'periodic_frequency' => 30,
                'is_active' => 1];
            $this->assertFalse(QueueService::saveQueue($data)['success']);
            $this->assertFalse(QueueService::saveQueue($data + ['callback_announce' => 'custom/not-found'])['success']);
            $result = QueueService::saveQueue($data + ['callback_announce' => 'custom/callback-confirmation-test.wav']);
            $this->assertTrue($result['success'], $result['error'] ?? '');
            $this->assertSame('custom/callback-confirmation-test.wav', DBHelper::fetchColumn('SELECT callback_announce FROM pbx_queues WHERE id = ?', [$this->queue]));
            syncAllQueues();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_queue_callbacks.conf');
            $block = explode("\n\n", explode('[aipbx-callback-' . $this->queue . ']', $conf, 2)[1], 2)[0];
            $this->assertStringContainsString("Playback(custom/callback-confirmation-test)\n same => n,Hangup()", $block);
            $this->assertLessThan(strpos($block, 'Playback(custom/callback-confirmation-test)'), strpos($block, '?failed)'));
            $this->assertStringContainsString('n(failed),Playback(sorry)', $block);
            // It is protected even when it is used only as confirmation, not as the hold prompt.
            getDB()->prepare("UPDATE pbx_queues SET periodic_announce = '' WHERE id = ?")->execute([$this->queue]);
            $this->assertFalse(SoundService::deleteAnnouncement($announcement, 'callback-confirmation-test')['success']);
            $this->assertNotFalse(DBHelper::fetchColumn('SELECT id FROM pbx_announcements WHERE id = ?', [$announcement]));
        } finally {
            $_SESSION = $session;
        }
    }
}
