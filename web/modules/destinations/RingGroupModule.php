<?php
namespace PBX\Destinations;

/**
 * Destination type 'ring_group': a ring group (pbx_ring_groups).
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class RingGroupModule implements PBXDestinationInterface {
    public function getKey(): string { return 'ring_group'; }
    public function getName(): string { return '🔔 ' . \t('dest.ring_group'); }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, CONCAT(name, ' (', group_number, ')') AS name FROM pbx_ring_groups WHERE is_active = 1 ORDER BY name ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'ring_group',
            'id' => $destId
        ];
    }
}

DestinationRegistry::register(new RingGroupModule());
