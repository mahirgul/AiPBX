<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PreserveDeletedTrunkUsage extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_deleted_trunk_usage')) {
            $this->execute("
                CREATE TABLE pbx_deleted_trunk_usage (
                    trunk_name VARCHAR(100) NOT NULL,
                    month_start DATE NOT NULL,
                    minutes BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (trunk_name, month_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // The aggregate moves with the DELETE in the same transaction. It contains no call identifiers.
        $this->execute("
            CREATE TRIGGER IF NOT EXISTS aipbx_preserve_deleted_trunk_usage
            BEFORE DELETE ON asteriskcdr FOR EACH ROW
            INSERT INTO pbx_deleted_trunk_usage (trunk_name, month_start, minutes)
            SELECT t.trunk_name, DATE_FORMAT(OLD.calldate, '%Y-%m-01'), CEIL(OLD.billsec / 60)
            FROM pbx_trunks t
            WHERE OLD.disposition = 'ANSWERED' AND OLD.billsec > 0
              AND OLD.dstchannel REGEXP '^PJSIP/[a-zA-Z0-9_-]{1,100}-[0-9a-f]+$'
              AND t.trunk_name = LEFT(SUBSTRING(OLD.dstchannel, 7),
                  CHAR_LENGTH(OLD.dstchannel) - 7 - CHAR_LENGTH(SUBSTRING_INDEX(OLD.dstchannel, '-', -1)))
            ON DUPLICATE KEY UPDATE minutes = minutes + VALUES(minutes)
        ");
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS aipbx_preserve_deleted_trunk_usage');
        $this->execute('DROP TABLE IF EXISTS pbx_deleted_trunk_usage');
    }
}
