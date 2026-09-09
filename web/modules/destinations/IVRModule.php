<?php
namespace PBX\Destinations;

class IVRModule implements PBXDestinationInterface {
    public function getKey(): string { return 'ivr'; }
    public function getName(): string { return '🎙️ IVR Menüsü'; }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, title AS name FROM pbx_ivrs ORDER BY title ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'ivr',
            'id' => $destId
        ];
    }
}

DestinationRegistry::register(new IVRModule());
