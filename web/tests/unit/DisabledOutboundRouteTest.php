<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class DisabledOutboundRouteTest extends TestCase
{
    public function testDisabledRoutesCannotFallBackToAnActiveTrunk(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM pbx_outbound_routes');
            $db->exec('DELETE FROM pbx_trunks');
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active) VALUES ('zz_blocked', 'Test', '127.0.0.1', 1)");
            $db->exec("INSERT INTO pbx_outbound_routes (route_name, match_pattern, route_group, is_active) VALUES ('zz_disabled', '_X.', 1, 0)");
            syncOutboundDialplan();
            $file = ASTERISK_PBX_DIR . '/extensions_outbound.conf';
            $this->assertStringNotContainsString('@zz_blocked', file_get_contents($file));
            // The initial-install fallback remains available when no route was configured.
            $db->exec('DELETE FROM pbx_outbound_routes');
            syncOutboundDialplan();
            $this->assertStringContainsString('@zz_blocked', file_get_contents($file));
        } finally {
            $db->rollBack();
            syncOutboundDialplan();
        }
    }
}
