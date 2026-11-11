<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Add verify_server column to pbx_transports and seed default tls_verify_server in pjsipsettings. */
final class AddTlsVerifyServerSupport extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_transports')) {
            $transports = $this->table('pbx_transports');
            if (!$transports->hasColumn('verify_server')) {
                $transports->addColumn('verify_server', 'boolean', ['default' => false, 'after' => 'is_active'])->update();
            }
        }

        if ($this->hasTable('pjsipsettings')) {
            $row = $this->fetchRow("SELECT keyword FROM pjsipsettings WHERE keyword = 'tls_verify_server'");
            if (!$row) {
                $this->execute("INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES ('tls_verify_server', 'no', 1, 0)");
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_transports')) {
            $transports = $this->table('pbx_transports');
            if ($transports->hasColumn('verify_server')) {
                $transports->removeColumn('verify_server')->update();
            }
        }

        if ($this->hasTable('pjsipsettings')) {
            $this->execute("DELETE FROM pjsipsettings WHERE keyword = 'tls_verify_server'");
        }
    }
}
