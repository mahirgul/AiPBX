<?php
namespace PBX\Destinations;

/**
 * Destination type 'time_condition': a time condition (pbx_time_conditions).
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class TimeConditionModule implements PBXDestinationInterface {
    public function getKey(): string { return 'time_condition'; }
    public function getName(): string { return '⏰ ' . \t('dest.time_condition'); }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, title AS name FROM pbx_time_conditions WHERE is_active = 1 ORDER BY title ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'time_condition',
            'id' => $destId
        ];
    }
}

DestinationRegistry::register(new TimeConditionModule());
