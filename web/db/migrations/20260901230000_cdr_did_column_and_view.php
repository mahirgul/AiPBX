<?php

use Phinx\Migration\AbstractMigration;

/**
 * Gelen çağrılarda aranan numara raporda "s" görünüyordu.
 *
 * Neden: gelen rota dialplan'ı `Goto(app-timecondition-1,s,1)` yapıyor ve
 * CDR'ın `dst` alanı bu Goto hedefiyle ("s") kalıyor. `cdrs` görünümü de
 * `pbx_dids`'e `dst` üzerinden bağlandığı için eşleşme hiç tutmuyor ve
 * "Gelen Rota: s" yazıyordu.
 *
 * Dialplan zaten `Set(CDR(did)=...)` yazıyordu ama `asteriskcdr` tablosunda
 * `did` sütunu YOKTU — cdr_adaptive_odbc yalnızca var olan sütunları yazar,
 * dolayısıyla DID hiçbir yere kaydedilmiyordu.
 */
final class CdrDidColumnAndView extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('asteriskcdr')->hasColumn('did')) {
            $this->table('asteriskcdr')
                 ->addColumn('did', 'string', ['limit' => 50, 'null' => true, 'after' => 'dst'])
                 ->addIndex(['did'], ['name' => 'idx_did'])
                 ->update();
        }

        // Görünüm, hedefi önce `did`'den okur; yoksa eski davranışa (dst) döner.
        $this->execute($this->gorunum('coalesce(nullif(`c`.`did`, \'\'), `c`.`dst`)'));
    }

    public function down(): void
    {
        // Görünümü did'siz haline döndür.
        $this->execute($this->gorunum('`c`.`dst`'));
    }

    /** Görünüm tanımı; hedef ifadesi tek yerden veriliyor ki up/down ayrışmasın. */
    private function gorunum(string $hedef): string
    {
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
            `c`.`calldate` AS `answer_time`,
            `c`.`calldate` + INTERVAL `c`.`duration` SECOND AS `end_time`,
            `c`.`duration` AS `duration`,
            `c`.`billsec` AS `billsec`,
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
