<?php

use Phinx\Migration\AbstractMigration;

/**
 * Standardizes cdrs SQL view queue_name tokens (i18n compliance).
 * Translates them to machine-readable tokens so the presentation layer
 * can localize them across all system languages.
 */
final class StandardizeCdrsViewLabels extends AbstractMigration
{
    public function up(): void
    {
        $target = "coalesce(nullif(`c`.`did`, ''), `c`.`dst`)";
        $this->execute("CREATE OR REPLACE VIEW `cdrs` AS SELECT
            `c`.`id` AS `id`,
            `c`.`uniqueid` AS `call_id`,
            coalesce(nullif(`c`.`linkedid`, ''), `c`.`uniqueid`) AS `linkedid`,
            `c`.`src` AS `caller_num`,
            CASE
                WHEN `c`.`lastapp` = 'ReceiveFAX' THEN 'fax_in'
                WHEN `c`.`lastapp` = 'SendFAX' THEN 'fax_out'
                WHEN `c`.`lastapp` = 'Queue' THEN substring_index(`c`.`lastdata`, ',', 1)
                WHEN `d`.`title` IS NOT NULL THEN `d`.`title`
                WHEN `c`.`channel` LIKE 'Local/%@from-internal-pbx%' AND (`c`.`src` REGEXP '^[0-9]{10,14}$' OR `c`.`src` LIKE '+%') THEN 'queue_call'
                WHEN `c`.`dcontext` = 'from-internal-pbx' THEN 'internal'
                ELSE concat('did:', {$target})
            END AS `queue_name`,
            CASE
                WHEN `c`.`accountcode` IS NOT NULL AND `c`.`accountcode` <> '' AND `c`.`accountcode` <> `c`.`src` THEN `c`.`accountcode`
                WHEN nullif(`c`.`did`, '') IS NOT NULL THEN `c`.`did`
                WHEN `c`.`dst` IS NOT NULL AND `c`.`dst` <> '' AND `c`.`dst` <> '0' AND `c`.`dst` <> `c`.`src` THEN `c`.`dst`
                ELSE NULL
            END AS `agent_extension`,
            `u`.`full_name` AS `agent_name`,
            `c`.`calldate` AS `start_time`,
            `c`.`calldate` + INTERVAL greatest(`c`.`duration` - `c`.`billsec`, 0) SECOND AS `answer_time`,
            `c`.`calldate` + INTERVAL `c`.`duration` SECOND AS `end_time`,
            GREATEST(`c`.`duration`, `c`.`billsec`) AS `duration`,
            `c`.`billsec` AS `billsec`,
            GREATEST(`c`.`duration`, `c`.`billsec`) - `c`.`billsec` AS `ring_sec`,
            `c`.`disposition` AS `status`,
            CASE
                WHEN `c`.`userfield` IS NOT NULL AND `c`.`userfield` <> '' THEN `c`.`userfield`
                WHEN `c`.`lastapp` = 'ReceiveFAX' THEN substring_index(`c`.`lastdata`, ',', 1)
                ELSE ''
            END AS `recording_path`,
            `c`.`calldate` AS `created_at`,
            `c`.`channel` AS `channel`,
            `c`.`dstchannel` AS `dstchannel`,
            `c`.`lastapp` AS `lastapp`,
            `c`.`lastdata` AS `lastdata`,
            `c`.`did` AS `did`,
            CASE
                WHEN `c`.`dstchannel` LIKE '%-mob-webrtc%' THEN 'mobil'
                WHEN `c`.`dstchannel` LIKE '%-webrtc%' THEN 'webrtc'
                WHEN `c`.`dstchannel` LIKE '%-sip%' THEN 'sip'
                WHEN `c`.`channel` LIKE '%-mob-webrtc%' THEN 'mobil'
                WHEN `c`.`channel` LIKE '%-webrtc%' THEN 'webrtc'
                WHEN `c`.`channel` LIKE '%-sip%' THEN 'sip'
                ELSE ''
            END AS `device_type`
        FROM `asteriskcdr` `c`
        LEFT JOIN `sys_users` `u` ON `u`.`extension` = CASE
                WHEN `c`.`accountcode` IS NOT NULL AND `c`.`accountcode` <> '' AND `c`.`accountcode` <> `c`.`src` THEN `c`.`accountcode`
                WHEN `c`.`dst` IS NOT NULL AND `c`.`dst` <> '' AND `c`.`dst` <> '0' AND `c`.`dst` <> `c`.`src` THEN `c`.`dst`
                ELSE NULL
            END
        LEFT JOIN `pbx_dids` `d` ON `d`.`did_number` = {$target}");
    }

    public function down(): void
    {
        $target = "coalesce(nullif(`c`.`did`, ''), `c`.`dst`)";
        $this->execute("CREATE OR REPLACE VIEW `cdrs` AS SELECT
            `c`.`id` AS `id`,
            `c`.`uniqueid` AS `call_id`,
            coalesce(nullif(`c`.`linkedid`, ''), `c`.`uniqueid`) AS `linkedid`,
            `c`.`src` AS `caller_num`,
            CASE
                WHEN `c`.`lastapp` = 'ReceiveFAX' THEN 'Gelen Faks'
                WHEN `c`.`lastapp` = 'SendFAX' THEN 'Giden Faks'
                WHEN `c`.`lastapp` = 'Queue' THEN substring_index(`c`.`lastdata`, ',', 1)
                WHEN `d`.`title` IS NOT NULL THEN `d`.`title`
                WHEN `c`.`channel` LIKE 'Local/%@from-internal-pbx%' AND (`c`.`src` REGEXP '^[0-9]{10,14}$' OR `c`.`src` LIKE '+%') THEN 'Kuyruk Çağrısı'
                WHEN `c`.`dcontext` = 'from-internal-pbx' THEN 'Dahili Görüşme'
                ELSE concat('Gelen Rota: ', {$target})
            END AS `queue_name`,
            CASE
                WHEN `c`.`accountcode` IS NOT NULL AND `c`.`accountcode` <> '' AND `c`.`accountcode` <> `c`.`src` THEN `c`.`accountcode`
                WHEN nullif(`c`.`did`, '') IS NOT NULL THEN `c`.`did`
                WHEN `c`.`dst` IS NOT NULL AND `c`.`dst` <> '' AND `c`.`dst` <> '0' AND `c`.`dst` <> `c`.`src` THEN `c`.`dst`
                ELSE NULL
            END AS `agent_extension`,
            `u`.`full_name` AS `agent_name`,
            `c`.`calldate` AS `start_time`,
            `c`.`calldate` + INTERVAL greatest(`c`.`duration` - `c`.`billsec`, 0) SECOND AS `answer_time`,
            `c`.`calldate` + INTERVAL `c`.`duration` SECOND AS `end_time`,
            GREATEST(`c`.`duration`, `c`.`billsec`) AS `duration`,
            `c`.`billsec` AS `billsec`,
            GREATEST(`c`.`duration`, `c`.`billsec`) - `c`.`billsec` AS `ring_sec`,
            `c`.`disposition` AS `status`,
            CASE
                WHEN `c`.`userfield` IS NOT NULL AND `c`.`userfield` <> '' THEN `c`.`userfield`
                WHEN `c`.`lastapp` = 'ReceiveFAX' THEN substring_index(`c`.`lastdata`, ',', 1)
                ELSE ''
            END AS `recording_path`,
            `c`.`calldate` AS `created_at`,
            `c`.`channel` AS `channel`,
            `c`.`dstchannel` AS `dstchannel`,
            `c`.`lastapp` AS `lastapp`,
            `c`.`lastdata` AS `lastdata`,
            `c`.`did` AS `did`,
            CASE
                WHEN `c`.`dstchannel` LIKE '%-mob-webrtc%' THEN 'mobil'
                WHEN `c`.`dstchannel` LIKE '%-webrtc%' THEN 'webrtc'
                WHEN `c`.`dstchannel` LIKE '%-sip%' THEN 'sip'
                WHEN `c`.`channel` LIKE '%-mob-webrtc%' THEN 'mobil'
                WHEN `c`.`channel` LIKE '%-webrtc%' THEN 'webrtc'
                WHEN `c`.`channel` LIKE '%-sip%' THEN 'sip'
                ELSE ''
            END AS `device_type`
        FROM `asteriskcdr` `c`
        LEFT JOIN `sys_users` `u` ON `u`.`extension` = CASE
                WHEN `c`.`accountcode` IS NOT NULL AND `c`.`accountcode` <> '' AND `c`.`accountcode` <> `c`.`src` THEN `c`.`accountcode`
                WHEN `c`.`dst` IS NOT NULL AND `c`.`dst` <> '' AND `c`.`dst` <> '0' AND `c`.`dst` <> `c`.`src` THEN `c`.`dst`
                ELSE NULL
            END
        LEFT JOIN `pbx_dids` `d` ON `d`.`did_number` = {$target}");
    }
}
