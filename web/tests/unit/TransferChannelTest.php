<?php

use PHPUnit\Framework\TestCase;

define('CC_DISPATCH_ACTIVE', true);
require_once dirname(__DIR__, 2) . '/api/cc_actions/cc_lib.php';

/**
 * Transferde ARAYANIN kanalının bulunması.
 *
 * 2026-09-15'te ölçülen hata: transfer aksiyonu TEMSİLCİNİN kanalını
 * Redirect ediyordu. Tek kanallı Redirect o kanalı köprüden çeker; arayan
 * ortada kalır, Queue() uygulamasından düşer ve `h` uzantısında kapanır.
 * Canlı logda temsilci 8915'e giderken aynı saniyede arayan Hangup yedi.
 *
 * Doğrusu arayanın kanalını hedefe yönlendirmektir. Zorluk, kuyruk
 * çağrılarında araya Local kanal çiftinin girmesi ve optimize edilip
 * yoldan çekilmemiş olmasıdır:
 *
 *   köprü A:  PJSIP/3002-webrtc  +  Local/3002@from-internal-pbx;2
 *   köprü B:  Local/3002@from-internal-pbx;1  +  PJSIP/ccisgw   <- arayan
 */
final class TransferChannelTest extends TestCase
{
    /** core show channels concise satırı üretir (14 sütun, '!' ayraçlı). */
    private function satir(string $kanal, string $kopru, string $cid = ''): string
    {
        $c = array_fill(0, 14, '');
        $c[0] = $kanal;
        $c[7] = $cid;
        $c[12] = $kopru;
        $c[13] = 'uid-' . substr(md5($kanal), 0, 8);
        return implode('!', $c);
    }

    /** Kuyruk çağrısı: araya Local çifti girmiş, optimize edilmemiş. */
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

    /** Local kanal optimize edilmişse temsilci doğrudan arayana köprülüdür. */
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

    /** Temsilcinin aktif çağrısı yoksa null dönmeli — yanlış kanal seçilmemeli. */
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

    /** Süre 11. sütundadır; 10. sütun amaflags (hep 3) — önceden her görüşme "00:03" görünüyordu. */
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

    /** queue show: bekleyen arayanlar üye sayılmaz; Asterisk 22 mola biçimi tanınır. */
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
        // Yalnızca çalan telefon görüşme değildir.
        $this->assertFalse($q['members']['3003']['is_busy']);
        $this->assertTrue($q['members']['3003']['is_ringing']);
        $this->assertFalse($q['members']['3002']['is_ringing']);
        $this->assertTrue($q['members']['3001']['is_paused']);
        $this->assertFalse($q['members']['3002']['is_paused']);
        $this->assertTrue($q['members']['3002']['is_busy']);
    }
}
