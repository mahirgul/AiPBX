<?php
namespace PBX\Destinations;

class AnnouncementModule implements PBXDestinationInterface {
    public function getKey(): string { return 'announcement'; }
    public function getName(): string { return '🔊 Sesli Anons'; }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT id, title AS name FROM pbx_announcements ORDER BY title ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'announcement',
            'id' => $destId
        ];
    }
}

DestinationRegistry::register(new AnnouncementModule());
