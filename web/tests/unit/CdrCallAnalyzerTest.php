<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/CdrCallAnalyzer.php';

/**
 * Call report: who called whom over which trunks. The leg shapes are taken
 * from a production PBX (numbers changed): calls coming in on one trunk and
 * leaving on another were shown with the wrong caller/callee and no trunks.
 */
final class CdrCallAnalyzerTest extends TestCase
{
    private CdrCallAnalyzer $an;

    protected function setUp(): void
    {
        $this->an = new CdrCallAnalyzer(
            ['VOIP' => 'VOIP', 'nectip' => 'NEC Tip', 'NecTeknik' => 'NEC Teknik'],
            ['3001' => 'Ayşe', '3002' => 'Mahir']
        );
    }

    private static function leg(int $id, array $f): array
    {
        return $f + ['id' => $id, 'clid' => '', 'src' => '', 'dst' => '', 'did' => '', 'channel' => '', 'dstchannel' => '',
            'lastapp' => 'Dial', 'lastdata' => '', 'disposition' => 'ANSWERED', 'accountcode' => ''];
    }

    public function testEndpoints(): void
    {
        $this->assertSame(['kind' => 'ext', 'id' => '3002', 'device' => 'sip'], $this->an->endpoint('PJSIP/3002-sip-000018b9'));
        $this->assertSame('webrtc', $this->an->endpoint('PJSIP/3002-webrtc-000018ba')['device']);
        $this->assertSame('mobil', $this->an->endpoint('PJSIP/3002-mob-webrtc-0000aa01')['device']);
        $this->assertSame(['kind' => 'ext', 'id' => '3002', 'device' => ''], $this->an->endpoint('PJSIP/3002-00005e17'));
        $this->assertSame(['kind' => 'trunk', 'id' => 'VOIP', 'device' => ''], $this->an->endpoint('PJSIP/VOIP-00003b2c'));
        // A trunk that no longer exists is still a trunk.
        $this->assertSame(['kind' => 'trunk', 'id' => 'neco', 'device' => ''], $this->an->endpoint('PJSIP/neco-00005e18'));
        $this->assertSame(['kind' => 'ext', 'id' => '3001', 'device' => ''], $this->an->endpoint('Local/3001@from-internal-pbx-0000188f;1'));
        $this->assertSame('', $this->an->endpoint('')['kind']);
    }

    /** Carrier → AiPBX → NEC: came in on VOIP, answered on the NEC behind the nectip trunk. */
    public function testTrunkToTrunk(): void
    {
        $f = $this->an->analyze([self::leg(1, [
            'clid' => '"" <05301112209>', 'src' => '05301112209', 'dst' => '9611', 'did' => '9611',
            'channel' => 'PJSIP/VOIP-00003b2c', 'dstchannel' => 'PJSIP/nectip-00003b2d', 'lastdata' => 'PJSIP/9611@nectip,60,Tb(x)',
        ])]);
        $this->assertSame(CdrCallAnalyzer::TRANSIT, $f['direction']);
        $this->assertSame('05301112209', $f['caller_number']);
        $this->assertSame('', $f['caller_name']);
        $this->assertSame('VOIP', $f['in_trunk']);
        $this->assertSame('9611', $f['dialed_number']);
        $this->assertSame('nectip', $f['out_trunk']);
        $this->assertSame('NEC Tip', $f['out_trunk_title']);
        $this->assertSame('9611', $f['out_number']);
        $this->assertSame('', $f['answered_ext'], 'nobody on AiPBX answered');
        $this->assertSame(['VOIP', 'NEC Tip'], array_column($f['path'], 'label'));
    }

    /** NEC user dials a mobile number out through the VOIP trunk. */
    public function testTrunkToTrunkOutbound(): void
    {
        $f = $this->an->analyze([self::leg(1, [
            'src' => '903704188571', 'dst' => '05371112248', 'did' => '05371112248',
            'channel' => 'PJSIP/NecTeknik-00003a93', 'dstchannel' => 'PJSIP/VOIP-00003a94', 'lastdata' => 'PJSIP/05371112248@VOIP,60,Tb(x)',
        ])]);
        $this->assertSame(CdrCallAnalyzer::TRANSIT, $f['direction']);
        $this->assertSame('NecTeknik', $f['in_trunk']);
        $this->assertSame('NEC Teknik', $f['in_trunk_title']);
        $this->assertSame('05371112248', $f['dialed_number'], 'not shown as "incoming line" any more');
        $this->assertSame('VOIP', $f['out_trunk']);
    }

    /** Came in on nectip, 3002 answered (desk phone; the browser leg was cancelled) and transferred it to NEC 8807. */
    public function testInboundAnsweredThenTransferredOut(): void
    {
        $f = $this->an->analyze([
            self::leg(1, ['src' => 'nec1', 'dst' => '3002', 'did' => '3002', 'channel' => 'PJSIP/nectip-000018b8', 'dstchannel' => 'PJSIP/3002-sip-000018b9']),
            self::leg(2, ['src' => 'nec1', 'dst' => '3002', 'did' => '3002', 'channel' => 'PJSIP/nectip-000018b8', 'dstchannel' => 'PJSIP/3002-webrtc-000018ba', 'disposition' => 'NO ANSWER']),
            self::leg(3, ['src' => 'nec1', 'dst' => '8807', 'did' => '3002', 'channel' => 'PJSIP/nectip-000018b8', 'dstchannel' => 'PJSIP/nectip-000018c3',
                'lastdata' => 'PJSIP/8807@nectip,60,Tb(x)', 'accountcode' => '3002']),
        ]);
        $this->assertSame(CdrCallAnalyzer::INBOUND, $f['direction']);
        $this->assertTrue($f['transferred']);
        $this->assertSame('3002', $f['dialed_number']);
        $this->assertSame('3002', $f['answered_ext'], 'the first person who answered, not the transfer target');
        $this->assertSame('Mahir', $f['answered_name']);
        $this->assertSame('sip', $f['answered_device']);
        $this->assertSame('nectip', $f['out_trunk']);
        $this->assertSame('8807', $f['out_number']);
        $this->assertSame(['trunk:nectip', 'ext:3002', 'trunk:nectip'], array_map(fn($n) => $n['kind'] . ':' . $n['id'], $f['path']));
    }

    public function testOutboundAndInternal(): void
    {
        $out = $this->an->analyze([self::leg(1, [
            'clid' => '"Mahir" <3002>', 'src' => '3002', 'dst' => '905321112233', 'channel' => 'PJSIP/3002-sip-00000001',
            'dstchannel' => 'PJSIP/VOIP-00000002', 'lastdata' => 'PJSIP/05321112233@VOIP,60',
        ])]);
        $this->assertSame(CdrCallAnalyzer::OUTBOUND, $out['direction']);
        $this->assertSame('Mahir', $out['caller_name']);
        $this->assertSame('905321112233', $out['dialed_number']);
        $this->assertSame('05321112233', $out['out_number'], 'the number actually sent to the trunk');

        $int = $this->an->analyze([self::leg(1, [
            'src' => '3001', 'dst' => '3002', 'channel' => 'PJSIP/3001-webrtc-00000003', 'dstchannel' => 'PJSIP/3002-mob-webrtc-00000004',
        ])]);
        $this->assertSame(CdrCallAnalyzer::INTERNAL, $int['direction']);
        $this->assertSame('Ayşe', $int['caller_name']);
        $this->assertSame('3002', $int['answered_ext']);
        $this->assertSame('mobil', $int['answered_device']);
        $this->assertFalse($int['transferred']);
    }

    /** IVR + queue, nobody answered: the queue members that were rung are listed as tried. */
    public function testUnansweredQueueCall(): void
    {
        $f = $this->an->analyze([
            self::leg(1, ['src' => '00151211176', 'dst' => 't', 'did' => '3000', 'channel' => 'PJSIP/VOIP-00005e20', 'lastapp' => 'Queue',
                'lastdata' => 'destek,t', 'dstchannel' => 'Local/3001@from-internal-pbx-0000188f;1', 'disposition' => 'NO ANSWER']),
            self::leg(2, ['src' => '00151211176', 'dst' => 't', 'did' => '3000', 'channel' => 'PJSIP/VOIP-00005e20', 'lastapp' => 'Queue',
                'lastdata' => 'destek,t', 'dstchannel' => 'Local/3002@from-internal-pbx-00001890;1', 'disposition' => 'NO ANSWER']),
        ]);
        $this->assertSame(CdrCallAnalyzer::INBOUND, $f['direction']);
        $this->assertSame('3000', $f['dialed_number'], 'the DID, not the IVR "t" extension');
        $this->assertSame('', $f['answered_ext']);
        $this->assertSame(['3001', '3002'], array_column(array_slice($f['path'], 1), 'id'));
        $this->assertFalse($f['path'][1]['answered']);
    }

    public function testAiAgentStandaloneAndAudioSocketDoesNotOverwriteCaller(): void
    {
        $an = new CdrCallAnalyzer(
            [],
            ['19000' => 'Mahir', '3002' => 'Ayşe'],
            ['7' => ['title' => 'Demo - Turkce Ajan', 'number' => '976286']]
        );
        $f = $an->analyze([
            // ARI AudioSocket external-media leg finishes first with a smaller id and empty caller.
            self::leg(10, [
                'calldate' => '2026-11-27 16:44:25', 'src' => '', 'dst' => 's', 'dcontext' => 'default',
                'channel' => 'AudioSocket/127.0.0.1:8791-442659db', 'lastapp' => 'Stasis',
                'lastdata' => 'aipbx-ai,442659db',
            ]),
            self::leg(11, [
                'calldate' => '2026-11-27 16:44:24', 'clid' => '"Mahir" <19000>', 'src' => '19000', 'dst' => 's',
                'dcontext' => 'aipbx-ai-app-7', 'channel' => 'PJSIP/19000-mob-webrtc-00000003',
                'dstchannel' => 'AudioSocket/127.0.0.1:8791-442659db', 'lastapp' => 'Stasis',
                'lastdata' => 'aipbx-ai,7,442659db',
            ]),
        ]);
        $this->assertSame(CdrCallAnalyzer::INTERNAL, $f['direction']);
        $this->assertSame('19000', $f['caller_number']);
        $this->assertSame('Mahir', $f['caller_name']);
        $this->assertSame('976286', $f['dialed_number']);
        $this->assertSame(['id' => '7', 'title' => 'Demo - Turkce Ajan', 'number' => '976286'], $f['answered_ai']);
        $this->assertSame('', $f['answered_ext']);
        $this->assertFalse($f['transferred']);
        $this->assertSame(['ext:19000', 'ai:7'], array_map(fn($n) => $n['kind'] . ':' . $n['id'], $f['path']));
        $this->assertSame('Demo - Turkce Ajan', $f['path'][1]['label']);
    }

    public function testAiAgentTransferredToExtension(): void
    {
        $an = new CdrCallAnalyzer(
            [],
            ['3001' => 'Ayşe', '3002' => 'Mahir'],
            ['7' => ['title' => 'Demo - Turkce Ajan', 'number' => '976286']]
        );
        $f = $an->analyze([
            self::leg(1, [
                'src' => '3001', 'dst' => 's', 'did' => '976286', 'dcontext' => 'aipbx-ai-app-7',
                'channel' => 'PJSIP/3001-webrtc-0001', 'lastapp' => 'Stasis',
                'lastdata' => 'aipbx-ai,7,uuid-1', 'disposition' => 'ANSWERED',
            ]),
            self::leg(2, [
                'src' => '3001', 'dst' => '3002', 'did' => '976286', 'dcontext' => 'from-internal-pbx-ortak',
                'channel' => 'PJSIP/3001-webrtc-0001', 'dstchannel' => 'PJSIP/3002-sip-0002',
                'lastapp' => 'Dial', 'disposition' => 'ANSWERED',
            ]),
        ]);
        $this->assertSame('3002', $f['answered_ext']);
        $this->assertSame('7', $f['answered_ai']['id'] ?? '');
        $this->assertTrue($f['transferred']);
        $this->assertSame(['ext:3001', 'ai:7', 'ext:3002'], array_map(fn($n) => $n['kind'] . ':' . $n['id'], $f['path']));
    }
}
