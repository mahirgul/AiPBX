<?php
namespace PBX\Destinations;

class TimeConditionModule implements PBXDestinationInterface {
    public function getKey(): string { return 'time_condition'; }
    public function getName(): string { return '⏰ Zaman Koşulu'; }

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
