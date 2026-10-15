<?php
namespace PBX\Destinations;

/**
 * Destination type 'queue': a call center queue (pbx_queues).
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class QueueModule implements PBXDestinationInterface {
    public function getKey(): string { return 'queue'; }
    public function getName(): string { return '🎧 ' . \t('dest.queue'); }

    public function getOptions(): array {
        try {
            $db = \getDB();
            $stmt = $db->query("SELECT queue_name, title FROM pbx_queues WHERE is_active = 1 ORDER BY id ASC");
            $rows = $stmt->fetchAll();
            $options = [];
            foreach ($rows as $r) {
                $options[] = [
                    'id' => $r['queue_name'],
                    'name' => $r['title'] . ' (' . $r['queue_name'] . ')'
                ];
            }
            return !empty($options) ? $options : [['id' => 'queue_cc', 'name' => \t('dest.queue_default')]];
        } catch (\Exception $e) {
            return [['id' => 'queue_cc', 'name' => \t('dest.queue_default')]];
        }
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'queue',
            'val' => !empty($destId) ? $destId : 'queue_cc'
        ];
    }
}

DestinationRegistry::register(new QueueModule());
