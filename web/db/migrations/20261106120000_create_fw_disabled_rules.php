<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Firewall rules turned off on the Firewall page. firewalld has no "off": the rule is taken out of
 * its zones and kept here, to be put back as it was. scope is 'common' or an interface name; zones
 * are the zones it was taken out of.
 */
final class CreateFwDisabledRules extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('fw_disabled_rules')) {
            $this->execute("CREATE TABLE fw_disabled_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(20) NOT NULL,
                zones VARCHAR(255) NOT NULL DEFAULT '',
                kind VARCHAR(10) NOT NULL,
                value VARCHAR(255) NOT NULL,
                port VARCHAR(11) NOT NULL DEFAULT '',
                protocol VARCHAR(3) NOT NULL DEFAULT '',
                source VARCHAR(18) NOT NULL DEFAULT '',
                disabled_by INT NULL,
                disabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rule (scope, kind, value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS fw_disabled_rules');
    }
}
