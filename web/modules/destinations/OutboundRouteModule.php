<?php
namespace PBX\Destinations;

class OutboundRouteModule implements PBXDestinationInterface {
    public function getKey(): string { return 'outbound_route'; }
    public function getName(): string { return '🌐 Giden Rota / Dış Hat'; }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, CONCAT(route_name, ' (', match_pattern, ')') AS name FROM pbx_outbound_routes WHERE is_active = 1 ORDER BY seq ASC, route_name ASC");
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
