<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/QueueCallbackService.php';
require_once dirname(__DIR__, 2) . '/src/services/QueueCallbackDispatcher.php';

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

    public function testAutomaticDispatchUsesOnlyReadyMembersAndRetainsUnansweredRequests(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO sys_users (username, password_hash, full_name, extension, role, is_active, outbound_group, permission_group_id)
            VALUES ('cb_auto_98761', '', 'Test Agent One', '98761', 'cc_agent', 1, 2, 3), ('cb_auto_98762', '', 'Test Agent Two', '98762', 'cc_agent', 1, 1, 1)");
        QueueCallbackService::request($this->queue, '03125550100');
        QueueCallbackService::request($this->queue, '03125550101');

        $ready = ['is_busy' => false, 'is_ringing' => false, 'is_unavailable' => false, 'is_paused' => false, 'raw_line' => '(Not in use)'];
        $waiting = 1;
        $live = ['98761' => $ready, '98762' => ['is_paused' => true] + $ready];
        $read = static function () use (&$live, &$waiting): array {
            return ['callback-test' => ['waiting_count' => $waiting, 'members' => $live]];
        };
        $calls = [];
        $originate = static function (array $agent, string $number) use (&$calls): bool {
            $calls[] = [$agent['extension'], (int) $agent['outbound_group'], (int) $agent['permission_group_id'], $number];
            return true;
        };
        $this->assertSame(0, QueueCallbackDispatcher::dispatch($read, $originate), 'manual-only by default');
        $db->prepare('UPDATE pbx_queues SET callback_auto_enabled = 1, wrapuptime = 10 WHERE id = ?')->execute([$this->queue]);
        $this->assertSame(0, QueueCallbackDispatcher::dispatch($read, $originate), 'live callers take priority');
        $waiting = 0;
        $live['98761']['raw_line'] = '(Not in use) (last was 3 secs ago)';
        $this->assertSame(0, QueueCallbackDispatcher::dispatch($read, $originate), 'respect wrap-up');
        $live['98761']['raw_line'] = '(Not in use) (last was 15 secs ago)';

        $this->assertSame(1, QueueCallbackDispatcher::dispatch($read, $originate));
        $this->assertSame([['98761', 2, 3, '03125550100']], $calls);
        $requests = QueueCallbackService::pending('98761');
        $this->assertCount(2, $requests);
        $this->assertSame(1, (int) $requests[0]['auto_attempts']);
        $this->assertSame('queued', $requests[0]['last_auto_result']);
        $this->assertNotNull($requests[0]['next_auto_at']);
        $requestId = (int) $requests[0]['id'];
        QueueCallbackService::recordAutomaticResult($requestId, 1, 'calling_customer');
        QueueCallbackService::recordAutomaticResult($requestId, 1, 'agent_unanswered');
        $this->assertSame('calling_customer', QueueCallbackService::pending('98761')[0]['last_auto_result']);
        QueueCallbackService::recordAutomaticResult($requestId, 1, 'customer_unanswered');
        $this->assertSame('customer_unanswered', QueueCallbackService::pending('98761')[0]['last_auto_result']);

        $this->assertSame(0, QueueCallbackDispatcher::dispatch($read, $originate), 'the agent is reserved');
        $this->assertNull(QueueCallbackService::claim((int) $requests[0]['id'], '98762'));

        $db->prepare('UPDATE cc_callbacks SET auto_attempts = ?, claimed_until = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')
            ->execute([QueueCallbackService::MAX_AUTOMATIC_ATTEMPTS, (int) $requests[0]['id']]);
        $this->assertSame(1, QueueCallbackDispatcher::dispatch($read, $originate));
        $this->assertSame('03125550101', $calls[1][3]);
        $this->assertSame('03125550100', QueueCallbackService::claim((int) $requests[0]['id'], '98762'), 'exhausted requests remain manual');
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
