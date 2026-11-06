<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * SIP over TLS on port 443 for a region, for networks that let TLS out only on 443. nginx on 443
 * sends a connection to Asterisk's TLS transport when it names the region's own SIP server name, or
 * (when the region has no own name) when it comes without ALPN from one of its addresses
 * (aipbx-priv sip443).
 */
final class RegionSip443Sources extends AbstractMigration
{
    public function up(): void
    {
        $regions = $this->table('pbx_phone_regions');
        if (!$regions->hasColumn('sip443')) {
            $regions->addColumn('sip443', 'boolean', ['default' => false, 'after' => 'sip_port'])->update();
        }
        if (!$regions->hasColumn('sip443_sources')) {
            $regions->addColumn('sip443_sources', 'string', ['limit' => 1000, 'default' => '', 'after' => 'sip443'])->update();
        }
    }

    public function down(): void
    {
        $regions = $this->table('pbx_phone_regions');
        foreach (['sip443_sources', 'sip443'] as $column) {
            if ($regions->hasColumn($column)) {
                $regions->removeColumn($column)->update();
            }
        }
    }
}
