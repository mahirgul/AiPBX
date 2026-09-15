<?php

use PHPUnit\Framework\TestCase;

define('CC_DISPATCH_ACTIVE', true);
require_once '/var/www/html/api/cc_actions/cc_lib.php';

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
}
