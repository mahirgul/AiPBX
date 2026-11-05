<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Regions for desk phones: each can give its phones other addresses and settings than the general
 * provisioning settings. An empty field takes the general value, so these two change nothing until
 * somebody fills them, and every phone starts in the default region.
 */
final class CreatePhoneRegions extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_phone_regions')) {
            $this->execute("CREATE TABLE pbx_phone_regions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                sip_server VARCHAR(255) NOT NULL DEFAULT '',
                sip_proxy VARCHAR(255) NOT NULL DEFAULT '',
                base_url VARCHAR(255) NOT NULL DEFAULT '',
                transport VARCHAR(5) NOT NULL DEFAULT '',
                srtp VARCHAR(1) NOT NULL DEFAULT '',
                codecs VARCHAR(100) NOT NULL DEFAULT '',
                ntp VARCHAR(255) NOT NULL DEFAULT '',
                syslog VARCHAR(45) NOT NULL DEFAULT '',
                timezone VARCHAR(64) NOT NULL DEFAULT '',
                language VARCHAR(10) NOT NULL DEFAULT '',
                UNIQUE KEY uniq_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->execute("INSERT INTO pbx_phone_regions (name, is_default) VALUES ('Internal', 1), ('External', 0)");
        }
        $phones = $this->table('pbx_phones');
        if (!$phones->hasColumn('region_id')) {
            $phones->addColumn('region_id', 'integer', ['null' => true])->update();
            $this->execute('UPDATE pbx_phones SET region_id = (SELECT id FROM pbx_phone_regions WHERE is_default = 1 LIMIT 1)');
        }
    }

    public function down(): void
    {
        $this->table('pbx_phones')->removeColumn('region_id')->update();
        $this->execute('DROP TABLE IF EXISTS pbx_phone_regions');
    }
}
