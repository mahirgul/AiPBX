<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/ChatCallService.php';

/** A conference started from a group chat: who Asterisk lets in, and when the call closes. */
final class ChatCallServiceTest extends TestCase
{
    private int $conv = 0;

    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $this->cleanUp();
        $db->exec("INSERT INTO chat_conversations (type, title, created_by) VALUES ('group', 'zz chat call test', '7001')");
        $this->conv = (int) $db->lastInsertId();
        $db->exec("INSERT INTO chat_participants (conversation_id, extension) VALUES ({$this->conv}, '7001'), ({$this->conv}, '7002')");
        $db->exec("INSERT INTO chat_participants (conversation_id, extension, left_at) VALUES ({$this->conv}, '7003', NOW())");
        $db->exec("INSERT INTO chat_calls (conversation_id, room, video, started_by) VALUES ({$this->conv}, '555000111222', 1, '7001')");
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = getDB();
        $ids = $db->query("SELECT id FROM chat_conversations WHERE title = 'zz chat call test'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $db->exec('DELETE FROM chat_participants WHERE conversation_id = ' . (int) $id);
            $db->exec('DELETE FROM chat_calls WHERE conversation_id = ' . (int) $id);
        }
        $db->exec("DELETE FROM chat_conversations WHERE title = 'zz chat call test'");
    }

    public function testOnlyAMembersOwnEndpointEntersAnOpenCall(): void
    {
        $this->assertSame('JOIN:8:follow_talker', ChatCallService::joinCheck('555000111222', '7001-mob-webrtc'));
        $this->assertSame('JOIN:8:follow_talker', ChatCallService::joinCheck('555000111222', '7002-sip'));
        $this->assertSame('DENY', ChatCallService::joinCheck('555000111222', '7003-webrtc'), 'left the group');
        $this->assertSame('DENY', ChatCallService::joinCheck('555000111222', '7009-sip'), 'never a member');
        $this->assertSame('DENY', ChatCallService::joinCheck('555000111222', 'testtrunk'), 'a trunk is no member');
        $this->assertSame('DENY', ChatCallService::joinCheck('555000111223', '7001-sip'), 'another room');
    }

    public function testAVoiceCallHasItsOwnLimitAndNoVideo(): void
    {
        getDB()->exec("UPDATE chat_calls SET video = 0 WHERE room = '555000111222'");
        $this->assertSame('JOIN:25:none', ChatCallService::joinCheck('555000111222', '7001-sip'));
    }

    public function testACallNobodyClosedIsNotJoinedForEver(): void
    {
        getDB()->exec("UPDATE chat_calls SET started_at = DATE_SUB(NOW(), INTERVAL 7 HOUR) WHERE room = '555000111222'");
        $this->assertSame('DENY', ChatCallService::joinCheck('555000111222', '7001-sip'));
    }

    public function testTheCallClosesWhenTheLastOneLeft(): void
    {
        ChatCallService::parties('555000111222', 1);
        ChatCallService::parties('555000111222', 3);
        ChatCallService::parties('555000111222', 2);
        $row = getDB()->query("SELECT ended_at, peak_parties FROM chat_calls WHERE room = '555000111222'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['ended_at']);
        $this->assertSame(3, (int) $row['peak_parties']);

        ChatCallService::parties('555000111222', 0);
        $this->assertNotNull(getDB()->query("SELECT ended_at FROM chat_calls WHERE room = '555000111222'")->fetchColumn());
        $this->assertSame('DENY', ChatCallService::joinCheck('555000111222', '7001-sip'));
    }

    public function testTheDialplanChecksEveryCallerAndReportsWhoLeft(): void
    {
        $c = chatCallsDialplan();
        $this->assertStringContainsString('exten => _ccX.,1,', $c);
        $check = strpos($c, 'chat_call_check.php?event=join&room=${CC_ROOM}&endpoint=${CC_EP}');
        $enter = strpos($c, 'ConfBridge(chat${CC_ROOM})');
        $this->assertNotFalse($check);
        $this->assertNotFalse($enter);
        $this->assertLessThan($enter, $check, 'the check comes before the room');
        $this->assertStringContainsString('GotoIf($["${CC_CHECK:0:5}" != "JOIN:"]?deny)', $c);
        $this->assertStringContainsString('CC_EP=${FILTER(a-zA-Z0-9_\\-,${CHANNEL(endpoint)})}', $c, 'the authenticated endpoint, not a number the caller sent');
        $this->assertStringContainsString('hangup_handler_push)=chat-call-left,s,1(${CC_ROOM})', $c);
        $this->assertStringContainsString("[chat-call-left]\n", $c);
    }
}
