<?php

/**
 * Testler için bilinen minimal veri kümesi.
 *
 * Her test sınıfı setUp()'ta Fixtures::load() çağırarak temiz ve ÖNGÖRÜLEBİLİR
 * bir durumdan başlar.
 *
 * SADECE asterisk_test üzerinde çalışır — tests/bootstrap.php'deki emniyet
 * kilidi bunu garanti eder (DB_NAME farklıysa testler hiç başlamaz). Yine de
 * savunma amaçlı burada ikinci bir kontrol var: yanlışlıkla üretimde
 * çalıştırılırsa TRUNCATE gerçek veriyi silerdi.
 */
final class Fixtures
{
    public const TRUNK_NAME = 'testtrunk';
    public const QUEUE_NAME = 'testqueue';
    public const DID_NUMBER = '9990001';

    /**
     * Fixture'ların dokunduğu tablolar — load() bunları boşaltır.
     *
     * `sip` LİSTEDE OLMAK ZORUNDA: trunk ayarlarının bir kısmı bu anahtar-değer
     * tablosunda yaşıyor ve SyncTrunks.php onu pbx_trunks'tan ÖNCE okuyor
     * (satır 49: sip_map['t38_udptl'] varsa o kazanıyor). Temizlenmezse bir
     * testin yazdığı değer sonraki testi kirletir — 2026-09-01'de tam olarak
     * bu oldu: t38_support=0 yapılan test, önceki testten kalan
     * sip.t38_udptl='yes' yüzünden hâlâ t38_udptl=yes görüyordu.
     */
    private const TABLOLAR = [
        'pbx_dids',
        'pbx_ivrs',
        'pbx_queues',
        'pbx_trunks',
        'pbx_time_conditions',
        'pbx_announcements',
        'pbx_hangup_actions',
        'pbx_outbound_routes',
        'sip',
        'sys_pending_sync',
    ];

    public static function load(): void
    {
        self::uretimKontrolu();

        $db = getDB();
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLOLAR as $t) {
            $db->exec("TRUNCATE TABLE `{$t}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');

        // Trunk — zorunlu kolonlar: trunk_name, title, ip_address.
        $db->prepare(
            'INSERT INTO pbx_trunks
                (trunk_name, title, ip_address, port, codecs, is_active, t38_support, send_caller_name)
             VALUES (?, ?, ?, ?, ?, 1, 1, 0)'
        )->execute([self::TRUNK_NAME, 'Test Trunk', '192.0.2.10', 5060, 'alaw,ulaw']);

        // Kuyruk — zorunlu kolonlar: queue_name, title.
        $db->prepare(
            'INSERT INTO pbx_queues (queue_name, title, strategy, is_active) VALUES (?, ?, ?, 1)'
        )->execute([self::QUEUE_NAME, 'Test Queue', 'ringall']);

        // IVR — zorunlu kolon: title.
        $db->prepare('INSERT INTO pbx_ivrs (title) VALUES (?)')->execute(['Test IVR']);

        // DID — zorunlu kolonlar: did_number, title, dest_type, dest_id.
        $queueId = (int) $db->query(
            'SELECT id FROM pbx_queues WHERE queue_name = ' . $db->quote(self::QUEUE_NAME)
        )->fetchColumn();
        $db->prepare(
            'INSERT INTO pbx_dids (did_number, title, dest_type, dest_id) VALUES (?, ?, ?, ?)'
        )->execute([self::DID_NUMBER, 'Test DID', 'queue', $queueId]);

        // Dahili hedef numarası olan iki varlık — SyncInternalNumbers ve
        // InternalNumberTest testleri bu ikisine dayanır.
        $db->prepare('UPDATE pbx_ivrs SET internal_number = ? WHERE title = ?')
           ->execute(['1010', 'Test IVR']);
        $db->prepare(
            'INSERT INTO pbx_hangup_actions (action_key, title, action_type, internal_number, is_active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute(['testbusy', 'Test Mesgul', 'busy', '1020']);

        // Giden rota — zorunlu kolonlar: route_name, match_pattern, trunks_json
        $db->prepare(
            'INSERT INTO pbx_outbound_routes (route_name, match_pattern, trunks_json, is_internal, route_group, is_active)
             VALUES (?, ?, ?, 0, 1, 1)'
        )->execute(['Test Outbound', '_05XXXXXXXXX', json_encode([['trunk_name' => self::TRUNK_NAME]])]);
    }

    /**
     * Üretim veritabanında ASLA çalışmasın — TRUNCATE geri dönüşü olmayan bir
     * işlem. bootstrap.php zaten engelliyor, bu ikinci savunma hattı.
     */
    private static function uretimKontrolu(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            throw new RuntimeException(
                "Fixtures YALNIZCA asterisk_test uzerinde calisir, su an DB_NAME='" . DB_NAME . "'"
            );
        }
    }
}
