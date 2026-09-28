<?php

use Phinx\Migration\AbstractMigration;

/**
 * Asterisk queue_log'un yazıldığı tablo (extconfig.conf: queue_log => odbc,asterisk,asteriskqueue).
 *
 * Hiçbir migration bu tabloyu oluşturmuyordu: mevcut sunucularda eskiden elle
 * açılmıştı, temiz kurulumda ise install.sh ODBC yetkisini verirken "tablo yok"
 * hatasıyla duruyordu (2026-09-28, kapsayıcıda temiz kurulum testi). Sütunlar
 * Asterisk'in realtime queue_log alanları + bin/sync_queue_logs.php'nin
 * kullandığı id/created_at.
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
        // Mevcut kurulumlarda tablo bu migration'dan önce de vardı: silinmez.
    }
}
