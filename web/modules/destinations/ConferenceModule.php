<?php
namespace PBX\Destinations;

class ConferenceModule implements PBXDestinationInterface {
    public function getKey(): string { return 'conference'; }
    public function getName(): string { return '👥 Konferans Odası'; }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, CONCAT(title, ' (', room_number, ')') AS name FROM pbx_conferences WHERE is_active = 1 ORDER BY title ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'conference',
            'id' => $destId
        ];
    }
}

DestinationRegistry::register(new ConferenceModule());
