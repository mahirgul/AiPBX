<?php

use Phinx\Migration\AbstractMigration;

/**
 * Raporda çalma / konuşma / toplam sürelerin ayrı ayrı gösterilebilmesi.
 *
 * Veri zaten CDR'da vardı ama görünüm kaybediyordu:
 *   - `answer_time` doğrudan `calldate` olarak veriliyordu, yani cevaplama
 *     anı çağrının başlangıcına eşitleniyor ve çalma süresi sıfırlanıyordu.
 *   - Çalma süresi hiç hesaplanmıyordu.
 *
 * Doğrusu: toplam = duration, konuşma = billsec, çalma = duration - billsec.
 *
 * `billsec`in `duration`ı aştığı kayıtlar görüldü (14 / 15) — Asterisk'in
 * yuvarlamasından kaynaklanıyor. GREATEST ile negatif çalma süresi üretilmesi
 * engellendi.
 */
final class CdrRingAndTalkDurations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute($this->gorunum(true));
    }

    public function down(): void
    {
        $this->execute($this->gorunum(false));
    }

    private function gorunum(bool $sureleriEkle): string
    {
        $hedef = "coalesce(nullif(`c`.`did`, ''), `c`.`dst`)";

        $cevap = $sureleriEkle
            ? "`c`.`calldate` + INTERVAL greatest(`c`.`duration` - `c`.`billsec`, 0) SECOND"
            : "`c`.`calldate`";

        $ekstra = $sureleriEkle
            ? ", greatest(`c`.`duration` - `c`.`billsec`, 0) AS `ring_sec`"
            : "";

        return "CREATE OR REPLACE VIEW `cdrs` AS SELECT
            `c`.`id` AS `id`,
            `c`.`uniqueid` AS `call_id`,
            `c`.`src` AS `caller_num`,
            CASE
                WHEN `c`.`lastapp` = 'ReceiveFAX' THEN 'Gelen Faks'
                WHEN `c`.`lastapp` = 'SendFAX' THEN 'Giden Faks'
                WHEN `c`.`lastapp` = 'Queue' THEN substring_index(`c`.`lastdata`, ',', 1)
                WHEN `d`.`title` IS NOT NULL THEN `d`.`title`
                WHEN `c`.`dcontext` = 'from-internal-pbx' THEN 'Dahili Görüşme'
                ELSE concat('Gelen Rota: ', {$hedef})
            END AS `queue_name`,
            CASE
                WHEN `c`.`accountcode` IS NOT NULL AND `c`.`accountcode` <> '' AND `c`.`accountcode` <> `c`.`src` THEN `c`.`accountcode`
                WHEN nullif(`c`.`did`, '') IS NOT NULL THEN `c`.`did`
                WHEN `c`.`dst` IS NOT NULL AND `c`.`dst` <> '' AND `c`.`dst` <> '0' AND `c`.`dst` <> `c`.`src` THEN `c`.`dst`
                ELSE NULL
            END AS `agent_extension`,
            `u`.`full_name` AS `agent_name`,
            `c`.`calldate` AS `start_time`,
            {$cevap} AS `answer_time`,
            `c`.`calldate` + INTERVAL `c`.`duration` SECOND AS `end_time`,
            `c`.`duration` AS `duration`,
            `c`.`billsec` AS `billsec`{$ekstra},
            `c`.`disposition` AS `status`,
            CASE
                WHEN `c`.`userfield` IS NOT NULL AND `c`.`userfield` <> '' THEN `c`.`userfield`
                WHEN `c`.`lastapp` = 'ReceiveFAX' THEN substring_index(`c`.`lastdata`, ',', 1)
                ELSE ''
            END AS `recording_path`,
            `c`.`calldate` AS `created_at`
        FROM `asteriskcdr` `c`
        LEFT JOIN `sys_users` `u` ON `u`.`extension` = CASE
                WHEN `c`.`accountcode` IS NOT NULL AND `c`.`accountcode` <> '' AND `c`.`accountcode` <> `c`.`src` THEN `c`.`accountcode`
                WHEN `c`.`dst` IS NOT NULL AND `c`.`dst` <> '' AND `c`.`dst` <> '0' AND `c`.`dst` <> `c`.`src` THEN `c`.`dst`
                ELSE NULL
            END
        LEFT JOIN `pbx_dids` `d` ON `d`.`did_number` = {$hedef}";
    }
}
