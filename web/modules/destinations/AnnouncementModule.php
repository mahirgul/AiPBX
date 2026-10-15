<?php
namespace PBX\Destinations;

/**
 * Destination type 'announcement': an announcement (rows of pbx_announcements),
 * then its own next destination.
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class AnnouncementModule implements PBXDestinationInterface {
    public function getKey(): string { return 'announcement'; }
    public function getName(): string { return '🔊 ' . \t('dest.announcement'); }

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
