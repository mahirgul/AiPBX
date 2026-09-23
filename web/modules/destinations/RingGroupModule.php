<?php
namespace PBX\Destinations;

class RingGroupModule implements PBXDestinationInterface {
    public function getKey(): string { return 'ring_group'; }
    public function getName(): string { return '🔔 Çalma Grubu'; }

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
