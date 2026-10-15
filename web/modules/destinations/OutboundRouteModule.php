<?php
namespace PBX\Destinations;

/**
 * Destination type 'outbound_route': an outbound route (pbx_outbound_routes): the
 * call goes out through its trunks.
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class OutboundRouteModule implements PBXDestinationInterface {
    public function getKey(): string { return 'outbound_route'; }
    public function getName(): string { return '🌐 ' . \t('dest.outbound_route'); }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, CONCAT(route_name, ' (', match_pattern, ')') AS name FROM pbx_outbound_routes WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'outbound_route',
            'val' => $destId
        ];
    }
}

DestinationRegistry::register(new OutboundRouteModule());
