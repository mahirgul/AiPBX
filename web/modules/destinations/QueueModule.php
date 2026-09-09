<?php
namespace PBX\Destinations;

class QueueModule implements PBXDestinationInterface {
    public function getKey(): string { return 'queue'; }
    public function getName(): string { return '🎧 Kuyruk'; }

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
            return !empty($options) ? $options : [['id' => 'queue_cc', 'name' => 'Ana Çağrı Merkezi (queue_cc)']];
        } catch (\Exception $e) {
            return [['id' => 'queue_cc', 'name' => 'Ana Çağrı Merkezi (queue_cc)']];
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
