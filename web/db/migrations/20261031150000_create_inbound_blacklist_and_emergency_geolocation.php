<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Inbound Caller ID Blacklist (Spam Blocking) & Emergency Route Geolocation (RFC 6442).
 */
final class CreateInboundBlacklistAndEmergencyGeolocation extends AbstractMigration
{
    public function up(): void
    {
        // 1. Inbound Caller ID Blacklist
        if (!$this->hasTable('pbx_inbound_blacklist')) {
            $this->table('pbx_inbound_blacklist', ['signed' => false])
                ->addColumn('number', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('title', 'string', ['limit' => 120, 'null' => true, 'default' => null])
                ->addColumn('block_action', 'string', ['limit' => 32, 'null' => false, 'default' => 'busy'])
                ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1])
                ->addColumn('hit_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
                ->addColumn('last_blocked_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['number'])
                ->addIndex(['is_active'])
                ->create();
        }

        // 2. Outbound Emergency Routes & RFC 6442 Geolocation
        $routes = $this->table('pbx_outbound_routes');
        if (!$routes->hasColumn('is_emergency')) {
            $routes->addColumn('is_emergency', 'boolean', ['null' => false, 'default' => 0, 'after' => 'is_internal']);
        }
        if (!$routes->hasColumn('geolocation_uri')) {
            $routes->addColumn('geolocation_uri', 'string', ['limit' => 512, 'null' => true, 'default' => null, 'after' => 'is_emergency']);
        }
        if (!$routes->hasColumn('geolocation_routing')) {
            $routes->addColumn('geolocation_routing', 'string', ['limit' => 16, 'null' => false, 'default' => 'yes', 'after' => 'geolocation_uri']);
        }
        $routes->update();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_inbound_blacklist')) {
            $this->table('pbx_inbound_blacklist')->drop()->save();
        }

        $routes = $this->table('pbx_outbound_routes');
        foreach (['is_emergency', 'geolocation_uri', 'geolocation_routing'] as $col) {
            if ($routes->hasColumn($col)) {
                $routes->removeColumn($col);
            }
        }
        $routes->update();
    }
}
