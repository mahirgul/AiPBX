<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePbxParking extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_parking')) {
            $this->execute("
                CREATE TABLE pbx_parking (
                    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    is_active TINYINT(1) NOT NULL DEFAULT 0,
                    parkext VARCHAR(16) NOT NULL DEFAULT '700',
                    slot_start INT NOT NULL DEFAULT 701,
                    slot_end INT NOT NULL DEFAULT 710,
                    parking_time INT NOT NULL DEFAULT 45,
                    findslot VARCHAR(16) NOT NULL DEFAULT 'next',
                    comeback_to_origin TINYINT(1) NOT NULL DEFAULT 1,
                    comeback_dial_time INT NOT NULL DEFAULT 30,
                    timeout_dest_type VARCHAR(50) NULL,
                    timeout_dest_id VARCHAR(50) NULL,
                    moh_class VARCHAR(64) NOT NULL DEFAULT 'default',
                    parked_play VARCHAR(16) NOT NULL DEFAULT 'caller',
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $count = (int) $this->fetchRow('SELECT COUNT(*) AS cnt FROM pbx_parking')['cnt'];
        if ($count === 0) {
            $this->execute("
                INSERT INTO pbx_parking (
                    id, is_active, parkext, slot_start, slot_end, parking_time,
                    findslot, comeback_to_origin, comeback_dial_time,
                    timeout_dest_type, timeout_dest_id, moh_class, parked_play
                ) VALUES (
                    1, 0, '700', 701, 710, 45,
                    'next', 1, 30,
                    NULL, NULL, 'default', 'caller'
                )
            ");
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS pbx_parking');
    }
}
