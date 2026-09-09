<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once '/var/www/html/tests/Fixtures.php';
require_once '/var/www/html/src/sip_helper.php';
require_once '/var/www/html/src/asterisk_sync.php';

/**
 * `sip` anahtar-değer tablosunun sözleşmesi.
 *
 * Trunk ayarlarının bir kısmı bu gölge tabloda yaşıyor ve SyncTrunks onu
 * pbx_trunks'tan ÖNCE okuyor. Dolayısıyla bir alan panelden BOŞALTILDIĞINDA
 * buradaki eski satırın da silinmesi ŞART — yoksa eski değer kalıcı olarak
 * kazanır ve alan bir daha temizlenemez.
 *
 * Gerçek olay (2026-09-01): CCIS ağ geçidi trunk'ına match_hosts=127.0.0.1
 * yazıldı. Bu, PJSIP'in IP tabanlı endpoint eşleştirmesi yüzünden localhost'tan
 * gelen TÜM SIP trafiğini (WebRTC kayıtları dahil) o trunk'a atadı ve WebRTC
 * kaydı 404 almaya başladı. Alan panelden temizlendi ama `sip` tablosundaki
 * satır kaldığı için değişiklik HİÇ ETKİ ETMEDİ.
 */
final class SipShadowTableTest extends TestCase
{
    private const TRUNK = 'shadowtest';

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('testler yalnizca asterisk_test uzerinde kosmali');
        }
        getDB()->exec("DELETE FROM sip WHERE id = '" . self::TRUNK . "'");
    }

    /** Trunk satırını temsil eden minimal dizi. */
    private function trunk(array $ustyaz = []): array
    {
        return array_merge([
            'trunk_name'  => self::TRUNK,
            'title'       => 'Shadow Test',
            'ip_address'  => '192.0.2.50',
            'port'        => 5060,
            'transport'   => 'udp',
            'codecs'      => 'alaw,ulaw',
        ], $ustyaz);
    }

    private function sipDegeri(string $keyword): ?string
    {
        $st = getDB()->prepare('SELECT data FROM sip WHERE id = ? AND keyword = ?');
        $st->execute([self::TRUNK, $keyword]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testDegerYazilabiliyor(): void
    {
        SIPHelper::syncTrunkToSIP($this->trunk(['match_hosts' => '10.1.1.1']));
        $this->assertSame('10.1.1.1', $this->sipDegeri('match_hosts'));
    }

    public function testBOSALTILAN_ALAN_GERCEKTEN_SILINIYOR(): void
    {
        // Önce doldur
        SIPHelper::syncTrunkToSIP($this->trunk(['match_hosts' => '127.0.0.1']));
        $this->assertSame('127.0.0.1', $this->sipDegeri('match_hosts'), 'on kosul: deger yazilmali');

        // Sonra panelden boşaltılmış gibi kaydet
        SIPHelper::syncTrunkToSIP($this->trunk(['match_hosts' => '']));

        $this->assertNull(
            $this->sipDegeri('match_hosts'),
            'BOSALTILAN alan sip tablosunda KALDI — eski deger pbx_trunks\'i golgeler '
            . 've alan bir daha temizlenemez (WebRTC\'yi kiran hatanin ta kendisi)'
        );
    }

    public static function kosulluAlanlar(): array
    {
        return [
            'match_hosts'        => ['match_hosts', '10.9.9.9'],
            'outbound_proxy'     => ['outbound_proxy', 'sip:proxy.example.net'],
            'from_user'          => ['from_user', '5551234567'],
            'from_domain'        => ['from_domain', 'example.net'],
            'outbound_caller_id' => ['outbound_caller_id', '5551234567'],
            'auth_username'      => ['auth_username', 'kullanici'],
        ];
    }
    #[DataProvider('kosulluAlanlar')]
    public function testTumKosulluAlanlarGeriAlinabiliyor(string $alan, string $deger): void
    {
        SIPHelper::syncTrunkToSIP($this->trunk([$alan => $deger]));
        $this->assertSame($deger, $this->sipDegeri($alan), "on kosul: {$alan} yazilmali");

        SIPHelper::syncTrunkToSIP($this->trunk([$alan => '']));
        $this->assertNull($this->sipDegeri($alan), "{$alan} bosaltilinca sip tablosunda kaldi");
    }

    public function testDoluAlanlarSilinmiyor(): void
    {
        // Aynı anda hem dolu hem boş alanlar varken, sadece boşlar silinmeli.
        SIPHelper::syncTrunkToSIP($this->trunk([
            'match_hosts' => '10.2.2.2',
            'from_user'   => 'abc',
        ]));
        SIPHelper::syncTrunkToSIP($this->trunk([
            'match_hosts' => '10.2.2.2',
            'from_user'   => '',
        ]));

        $this->assertSame('10.2.2.2', $this->sipDegeri('match_hosts'), 'dolu alan yanlislikla silindi');
        $this->assertNull($this->sipDegeri('from_user'), 'bos alan silinmedi');
    }
}
