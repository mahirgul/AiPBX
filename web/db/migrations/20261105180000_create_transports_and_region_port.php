<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Transports an administrator adds beside the built-in ones, and a SIP port per phone region. */
final class CreateTransportsAndRegionPort extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_transports')) {
            $this->execute("CREATE TABLE pbx_transports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(32) NOT NULL,
                protocol VARCHAR(5) NOT NULL,
                bind_addr VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
                port INT NOT NULL,
                external_address VARCHAR(255) NOT NULL DEFAULT '',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uniq_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $regions = $this->table('pbx_phone_regions');
        if (!$regions->hasColumn('sip_port')) {
            $regions->addColumn('sip_port', 'string', ['limit' => 5, 'default' => '', 'after' => 'sip_proxy'])->update();
        }
    }

    public function down(): void
    {
        $this->table('pbx_phone_regions')->removeColumn('sip_port')->update();
        $this->execute('DROP TABLE IF EXISTS pbx_transports');
    }
}
