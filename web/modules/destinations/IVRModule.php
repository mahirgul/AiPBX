<?php
namespace PBX\Destinations;

/**
 * Destination type 'ivr': an IVR menu (pbx_ivrs).
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class IVRModule implements PBXDestinationInterface {
    public function getKey(): string { return 'ivr'; }
    public function getName(): string { return '🎙️ ' . \t('dest.ivr'); }

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
