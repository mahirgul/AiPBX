<?php

use PHPUnit\Framework\TestCase;

define('CC_DISPATCH_ACTIVE', true);
require_once dirname(__DIR__, 2) . '/api/cc_actions/cc_lib.php';

/**
 * Finding the CALLER's channel on a transfer.
 *
 * The bug measured on 2026-09-15: the transfer action Redirected the
 * AGENT's channel. A single-channel Redirect pulls that channel out of the
 * bridge; the caller is left alone, drops out of Queue() and hangs up in the
 * `h` extension. In the live log the caller got Hangup in the same second
 * the agent went to 8915.
 *
 * The right thing is to redirect the caller's channel to the target. The
 * difficulty is the Local channel pair that queue calls get in between, which
 * is not optimized away:
 *
 *   bridge A:  PJSIP/3002-webrtc  +  Local/3002@from-internal-pbx;2
 *   bridge B:  Local/3002@from-internal-pbx;1  +  PJSIP/ccisgw   <- caller
 */
final class TransferChannelTest extends TestCase
{
    /** Builds a core show channels concise line (14 columns, '!'-separated). */
    private function satir(string $kanal, string $kopru, string $cid = ''): string
    {
        $c = array_fill(0, 14, '');
        $c[0] = $kanal;
        $c[7] = $cid;
        $c[12] = $kopru;
        $c[13] = 'uid-' . substr(md5($kanal), 0, 8);
        return implode('!', $c);
    }

    /** Queue call: a Local pair sits in between, not optimized away. */
    public function testLocalKanalZinciriBoyuncaArayaniBulur(): void
    {
        $lines = [
            $this->satir('PJSIP/3002-webrtc-00000114', 'ff4c7042'),
            $this->satir('Local/3002@from-internal-pbx-00000012;2', 'ff4c7042'),
            $this->satir('Local/3002@from-internal-pbx-00000012;1', '0518db58'),
            $this->satir('PJSIP/ccisgw-00000113', '0518db58', '05379902352'),
        ];

        $this->assertSame(
            'PJSIP/ccisgw-00000113',
            findCallerChannelForAgent('3002', $lines),
            'Local cifti asilarak arayanin trunk kanali bulunmaliydi'
        );
    }

    /** When the Local channel is optimized away, the agent is bridged straight to the caller. */
    public function testLocalOptimizeEdilmisseDogrudanEsiBulur(): void
    {
        $lines = [
            $this->satir('PJSIP/3001-sip-000000f6', 'aabbccdd'),
            $this->satir('PJSIP/ccisgw-000000f0', 'aabbccdd', '05379902352'),
        ];

        $this->assertSame(
            'PJSIP/ccisgw-000000f0',
            findCallerChannelForAgent('3001', $lines)
        );
    }

    /** With no active call for the agent it must return null — no wrong channel may be picked. */
    public function testAktifCagriYoksaNullDoner(): void
    {
        $lines = [
            $this->satir('PJSIP/9999-sip-00000001', 'zzzz'),
            $this->satir('PJSIP/ccisgw-00000002', 'zzzz'),
        ];

        $this->assertNull(findCallerChannelForAgent('3002', $lines));
    }

    /** Temsilcinin kendi kanali arayan olarak DONDURULMEMELI. */
    public function testTemsilcininKendiKanaliDondurulmez(): void
    {
        $lines = [
            $this->satir('PJSIP/3002-webrtc-00000114', 'ff4c7042'),
            $this->satir('Local/3002@from-internal-pbx-00000012;2', 'ff4c7042'),
        ];

        $this->assertNull(findCallerChannelForAgent('3002', $lines));
    }

    /** The duration is column 11; column 10 is amaflags (always 3) — every call used to show "00:03". */
    public function testGorusmeSuresiDogruSutundanOkunur(): void
    {
        $arayan = $this->satir('PJSIP/ccisgw-00000115', 'b1', '05321112233');
        $cols = explode('!', $arayan);
        $cols[10] = '3';
        $cols[11] = '125';
        $lines = [
            implode('!', $cols),
            $this->satir('PJSIP/3002-webrtc-00000114', 'b1'),
        ];

        $d = findAgentCallDetails('3002', $lines);
        $this->assertSame('PJSIP/ccisgw-00000115', $d['caller_channel']);
        $this->assertSame(125, $d['duration']);
        $this->assertSame('02:05', $d['duration_formatted']);
    }

    /** queue show: waiting callers are not members; the Asterisk 22 pause format is recognized. */
    public function testKuyrukCiktisiUyeVeMolaDurumu(): void
    {
        $out = [
            "queue_cc has 1 calls (max unlimited) in 'rrmemory' strategy (3s holdtime, 20s talktime), W:0, C:5, A:1, SL:0.0%, SL2:0.0% within 0s",
            "   Members: ",
            "      Temsilci 3001 (Local/3001@from-internal-pbx/n from hint:3001@from-internal-pbx) (ringinuse disabled) (paused:Yemek Molası was 42 secs ago) (Not in use) has taken no calls yet",
            "      Temsilci 3002 (Local/3002@from-internal-pbx/n from hint:3002@from-internal-pbx) (ringinuse disabled) (In use) has taken 3 calls",
            "      Temsilci 3003 (Local/3003@from-internal-pbx/n from hint:3003@from-internal-pbx) (ringinuse disabled) (Ringing) has taken 1 calls",
            "   Callers: ",
            "      1. PJSIP/ccisgw-00000116 (wait:0:07, prio:0)",
        ];
        $q = parseAsteriskQueuesOutput($out)['queue_cc'];

        $this->assertSame(['3001', '3002', '3003'], array_map('strval', array_keys($q['members'])));
        // A ringing phone alone is not a call.
        $this->assertFalse($q['members']['3003']['is_busy']);
        $this->assertTrue($q['members']['3003']['is_ringing']);
        $this->assertFalse($q['members']['3002']['is_ringing']);
        $this->assertTrue($q['members']['3001']['is_paused']);
        $this->assertFalse($q['members']['3002']['is_paused']);
        $this->assertTrue($q['members']['3002']['is_busy']);
    }
}
