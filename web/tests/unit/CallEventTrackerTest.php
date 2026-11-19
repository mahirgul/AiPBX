<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/CallEventTracker.php';

/**
 * The live call events from AMI event sequences as Asterisk sends them: one call.completed
 * per call, no call.answered while a caller waits in a queue, one offer per extension
 * however many of its phones ring.
 */
final class CallEventTrackerTest extends TestCase
{
    private CallEventTracker $t;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        $book = ['+905321112233' => ['id' => 7, 'display_name' => 'Kemal']];
        $this->t = new CallEventTracker(['netgsm'], fn(string $n) => $book[$n] ?? null);
        $this->events = [];
    }

    private function feed(array $ev, int $at = 1000): void
    {
        foreach ($this->t->handle($ev, $at) as $e) {
            $this->events[] = $e;
        }
    }

    private function names(): array
    {
        return array_column($this->events, 0);
    }

    private function last(string $name): array
    {
        foreach (array_reverse($this->events) as [$n, $d]) {
            if ($n === $name) {
                return $d;
            }
        }
        $this->fail("no $name");
    }

    public function testInboundQueueCallAnsweredByAnAgent(): void
    {
        $L = '1700.1';
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-00000001', 'Uniqueid' => $L, 'Linkedid' => $L,
            'CallerIDNum' => '+905321112233', 'CallerIDName' => '<unknown>', 'Exten' => '902125550000'], 1000);
        $this->feed(['Event' => 'QueueCallerJoin', 'Channel' => 'PJSIP/netgsm-00000001', 'Uniqueid' => $L, 'Linkedid' => $L, 'Queue' => 'sales'], 1002);
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'Local/19000@agents-00000001;1', 'Uniqueid' => '1700.2', 'Linkedid' => $L]);
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'Local/19000@agents-00000001;2', 'Uniqueid' => '1700.3', 'Linkedid' => $L]);
        $this->feed(['Event' => 'DialBegin', 'Uniqueid' => $L, 'Linkedid' => $L, 'DestChannel' => 'Local/19000@agents-00000001;1', 'DestUniqueid' => '1700.2']);
        foreach (['sip' => '1700.4', 'webrtc' => '1700.5'] as $ep => $uid) {
            $this->feed(['Event' => 'Newchannel', 'Channel' => "PJSIP/19000-$ep-0000000" . substr($uid, -1), 'Uniqueid' => $uid, 'Linkedid' => $L]);
            $this->feed(['Event' => 'DialBegin', 'Uniqueid' => '1700.3', 'Linkedid' => $L, 'DestChannel' => "PJSIP/19000-$ep-0000000" . substr($uid, -1), 'DestUniqueid' => $uid], 1005);
        }
        $this->assertSame(['call.ringing', 'call.offered'], $this->names(), 'two phones of one extension: one offer');
        $ring = $this->events[0][1];
        $this->assertSame('inbound', $ring['direction']);
        $this->assertSame('902125550000', $ring['did']);
        $this->assertSame('Kemal', $ring['caller']['contact']['display_name']);
        $this->assertSame('', $ring['caller']['name'], '<unknown> is no name');
        $this->assertSame('19000', $this->events[1][1]['agent_extension']);
        $this->assertSame('sales', $this->events[1][1]['queue']);

        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '1700.5', 'DialStatus' => 'ANSWER'], 1010);
        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '1700.4', 'DialStatus' => 'CANCEL'], 1010);
        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '1700.2', 'DialStatus' => 'ANSWER'], 1010);
        $this->assertSame(['call.ringing', 'call.offered', 'call.answered'], $this->names(), 'the Local leg answering is no second answer');

        foreach (['1700.4', '1700.5', '1700.3', '1700.2'] as $uid) {
            $this->feed(['Event' => 'Hangup', 'Uniqueid' => $uid, 'Linkedid' => $L], 1070);
        }
        $this->assertNotContains('call.completed', $this->names(), 'the caller is still there');
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => $L, 'Linkedid' => $L], 1070);
        $done = $this->last('call.completed');
        $this->assertSame('answered', $done['outcome']);
        $this->assertSame(10, $done['wait_seconds']);
        $this->assertSame(60, $done['talk_seconds']);
        $this->assertSame('19000', $done['agent_extension']);
        $this->assertSame(0, $this->t->openCalls());
    }

    public function testCallerWhoGivesUpInTheQueue(): void
    {
        $L = '1800.1';
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-00000002', 'Uniqueid' => $L, 'Linkedid' => $L, 'CallerIDNum' => '02125551111', 'CallerIDName' => 'ACME', 'Exten' => '100'], 2000);
        $this->feed(['Event' => 'QueueCallerJoin', 'Uniqueid' => $L, 'Linkedid' => $L, 'Queue' => 'support']);
        $this->feed(['Event' => 'QueueCallerAbandon', 'Uniqueid' => $L, 'Linkedid' => $L, 'Queue' => 'support']);
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => $L, 'Linkedid' => $L], 2045);
        $this->assertSame(['call.ringing', 'call.completed'], $this->names());
        $done = $this->last('call.completed');
        $this->assertSame('abandoned', $done['outcome']);
        $this->assertSame(45, $done['wait_seconds']);
        $this->assertNull($done['caller']['contact']);
        $this->assertSame('ACME', $done['caller']['name']);
    }

    public function testOutboundCallAnsweredByTheOtherSide(): void
    {
        $L = '1900.1';
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/19001-webrtc-0000000a', 'Uniqueid' => $L, 'Linkedid' => $L, 'CallerIDNum' => '19001', 'Exten' => '+905321112233'], 3000);
        $this->assertSame([], $this->names(), 'not ringing anyone yet');
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-0000000b', 'Uniqueid' => '1900.2', 'Linkedid' => $L]);
        $this->feed(['Event' => 'DialBegin', 'Uniqueid' => $L, 'Linkedid' => $L, 'DestChannel' => 'PJSIP/netgsm-0000000b', 'DestUniqueid' => '1900.2', 'DestExten' => 's'], 3001);
        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '1900.2', 'DialStatus' => 'ANSWER'], 3008);
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => '1900.2', 'Linkedid' => $L], 3030);
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => $L, 'Linkedid' => $L], 3030);
        $this->assertSame(['call.ringing', 'call.answered', 'call.completed'], $this->names(), 'no offer: nobody inside rings');
        $ring = $this->events[0][1];
        $this->assertSame('outbound', $ring['direction']);
        $this->assertSame('19001', $ring['from_extension']);
        $this->assertSame('Kemal', $ring['callee']['contact']['display_name'], 'the dialled number is looked up');
        $this->assertSame('19001', $this->last('call.answered')['agent_extension']);
        $this->assertSame(22, $this->last('call.completed')['talk_seconds']);
    }

    public function testInternalCallNobodyAnswers(): void
    {
        $L = '2000.1';
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/19000-sip-0000000c', 'Uniqueid' => $L, 'Linkedid' => $L, 'CallerIDNum' => '19000'], 4000);
        $this->feed(['Event' => 'DialBegin', 'Uniqueid' => $L, 'Linkedid' => $L, 'DestChannel' => 'PJSIP/19001-sip-0000000d', 'DestUniqueid' => '2000.2', 'DestExten' => '19001']);
        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '2000.2', 'DialStatus' => 'NOANSWER']);
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => $L, 'Linkedid' => $L], 4020);
        $this->assertSame(['call.ringing', 'call.offered', 'call.completed'], $this->names());
        $this->assertSame('internal', $this->events[0][1]['direction']);
        $this->assertSame('missed', $this->last('call.completed')['outcome']);
    }

    public function testChannelsNotOnAKnownCallAreIgnored(): void
    {
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => 'x', 'Linkedid' => 'x']);
        $this->feed(['Event' => 'DialEnd', 'Linkedid' => 'y', 'DestUniqueid' => 'z', 'DialStatus' => 'ANSWER']);
        $this->feed(['Event' => 'Newchannel', 'Uniqueid' => 'a']);
        $this->assertSame([], $this->names());
        $this->assertSame(['trunk', 'netgsm'], $this->t->classify('PJSIP/netgsm-0000abcd'));
        $this->assertSame(['ext', '19000'], $this->t->classify('PJSIP/19000-mob-webrtc-0000abcd'));
        $this->assertSame(['ext', '19000'], $this->t->classify('Local/19000@cc-internal-00000001;1'));
        $this->assertSame(['other', ''], $this->t->classify('Local/19000@agents-00000001;1'));
    }

    public function testClickToCallOutboundThroughCcInternal(): void
    {
        $L = '2100.1';
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'Local/19001@cc-internal-00000001;1', 'Uniqueid' => $L, 'Linkedid' => $L, 'Exten' => '+905321112233'], 5000);
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'Local/19001@cc-internal-00000001;2', 'Uniqueid' => '2100.2', 'Linkedid' => $L]);
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/19001-sip-00000002', 'Uniqueid' => '2100.3', 'Linkedid' => $L]);
        // Originating leg rings the agent's own phone first: must not ring/offer/answer prematurely
        $this->feed(['Event' => 'DialBegin', 'Uniqueid' => '2100.2', 'Linkedid' => $L, 'DestChannel' => 'PJSIP/19001-sip-00000002', 'DestUniqueid' => '2100.3', 'DestExten' => '19001'], 5001);
        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '2100.3', 'DialStatus' => 'ANSWER'], 5004);
        $this->assertSame([], $this->names(), 'picking up own phone for click-to-call must not fire call.ringing/offered/answered');

        // Now Local;1 dials the external target over the trunk
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-00000003', 'Uniqueid' => '2100.4', 'Linkedid' => $L]);
        $this->feed(['Event' => 'DialBegin', 'Uniqueid' => $L, 'Linkedid' => $L, 'DestChannel' => 'PJSIP/netgsm-00000003', 'DestUniqueid' => '2100.4', 'DestExten' => 's'], 5005);
        $this->assertSame(['call.ringing'], $this->names());
        $ring = $this->events[0][1];
        $this->assertSame('outbound', $ring['direction']);
        $this->assertSame('19001', $ring['from_extension']);
        $this->assertSame('netgsm', $ring['trunk']);
        $this->assertSame('+905321112233', $ring['callee']['number']);
        $this->assertSame('Kemal', $ring['callee']['contact']['display_name']);

        $this->feed(['Event' => 'DialEnd', 'Linkedid' => $L, 'DestUniqueid' => '2100.4', 'DialStatus' => 'ANSWER'], 5012);
        $this->assertSame('19001', $this->last('call.answered')['agent_extension']);

        foreach (['2100.4', '2100.3', '2100.2', $L] as $uid) {
            $this->feed(['Event' => 'Hangup', 'Uniqueid' => $uid, 'Linkedid' => $L], 5040);
        }
        $done = $this->last('call.completed');
        $this->assertSame('answered', $done['outcome']);
        $this->assertSame('19001', $done['from_extension']);
        $this->assertSame(0, $this->t->openCalls());
    }

    public function testTransferHangupWithChangedLinkedidAndStaleCleanup(): void
    {
        $L1 = '2200.1';
        $L2 = '2300.1';
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-00000010', 'Uniqueid' => $L1, 'Linkedid' => $L1, 'CallerIDNum' => '+905321112233', 'Exten' => '100'], 6000);
        // Missing Uniqueid on Newchannel must not throw warning
        $this->feed(['Event' => 'Newchannel', 'Linkedid' => $L1], 6001);
        // Simulate attended transfer: Hangup arrives with L1's Uniqueid under new Linkedid L2
        $this->feed(['Event' => 'Hangup', 'Uniqueid' => $L1, 'Linkedid' => $L2], 6015);
        $this->assertContains('call.completed', $this->names());
        $this->assertSame(0, $this->t->openCalls());

        // Stale call purged on idle purgeStale() and reset() clears open calls
        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-00000011', 'Uniqueid' => '2400.1', 'Linkedid' => '2400.1', 'CallerIDNum' => '0212'], 7000);
        $this->assertSame(1, $this->t->openCalls());
        $this->t->purgeStale(7000 + 43201);
        $this->assertSame(0, $this->t->openCalls());

        $this->feed(['Event' => 'Newchannel', 'Channel' => 'PJSIP/netgsm-00000012', 'Uniqueid' => '2500.1', 'Linkedid' => '2500.1', 'CallerIDNum' => '0212'], 8000);
        $this->assertSame(1, $this->t->openCalls());
        $this->t->reset();
        $this->assertSame(0, $this->t->openCalls());
    }
}
