<?php

use Phinx\Migration\AbstractMigration;

/**
 * The table Asterisk's queue_log is written to (extconfig.conf: queue_log => odbc,asterisk,asteriskqueue).
 *
 * No migration created this table: on existing servers it had been created by
 * hand long ago, while on a clean install install.sh stopped with a "table
 * does not exist" error when granting the ODBC rights (2026-09-28, clean
 * install test in a container). The columns are Asterisk's realtime queue_log
 * fields + the id/created_at used by bin/sync_queue_logs.php.
 */
final class CreateAsteriskqueueTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('asteriskqueue')) {
            return;
        }
        $this->execute("
            CREATE TABLE `asteriskqueue` (
              `id` bigint(25) unsigned NOT NULL AUTO_INCREMENT,
              `time` varchar(32) DEFAULT NULL,
              `callid` varchar(64) DEFAULT NULL,
              `queuename` varchar(64) DEFAULT NULL,
              `agent` varchar(64) DEFAULT NULL,
              `event` varchar(32) DEFAULT NULL,
              `data` varchar(255) DEFAULT NULL,
              `data1` varchar(64) DEFAULT NULL,
              `data2` varchar(64) DEFAULT NULL,
              `data3` varchar(64) DEFAULT NULL,
              `data4` varchar(64) DEFAULT NULL,
              `data5` varchar(64) DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `time` (`time`),
              KEY `callid` (`callid`),
              KEY `queuename` (`queuename`),
              KEY `agent` (`agent`),
              KEY `event` (`event`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public function down(): void
    {
        // On existing installs the table existed before this migration: it is not dropped.
    }
}
