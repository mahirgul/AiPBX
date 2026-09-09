<?php
namespace PBX\Destinations;

class HangupModule implements PBXDestinationInterface {
    public function getKey(): string { return 'hangup'; }
    public function getName(): string { return '🛑 Çağrı Sonlandır'; }

    public function getOptions(): array {
        $options = [];

        try {
            $db = \getDB();
            $sql = "SELECT ha.*, anc.title AS anc_title 
                    FROM pbx_hangup_actions ha 
                    LEFT JOIN pbx_announcements anc ON ha.announcement_id = anc.id 
                    WHERE ha.is_active = 1 
                    ORDER BY ha.id ASC";
            $actions = $db->query($sql)->fetchAll();

            foreach ($actions as $a) {
                $label = $a['title'];
                if (!empty($a['anc_title'])) {
                    $label .= ' (Anons: ' . $a['anc_title'] . ')';
                }
                $options[] = [
                    'id' => $a['action_key'],
                    'name' => $label
                ];
            }
        } catch (\Exception $e) {
            // Fallback default options
            $options = [
                ['id' => 'hangup', 'name' => '🛑 Hemen Kapat'],
                ['id' => 'busy', 'name' => '🚫 Meşgul Tonu Ver'],
                ['id' => 'congestion', 'name' => '⚠️ Şebeke Meşgul']
            ];
        }

        return !empty($options) ? $options : [
            ['id' => 'hangup', 'name' => '🛑 Hemen Kapat'],
            ['id' => 'busy', 'name' => '🚫 Meşgul Tonu Ver'],
            ['id' => 'congestion', 'name' => '⚠️ Şebeke Meşgul']
        ];
    }

    public function resolve($agi, string $destId): array {
        $mode = !empty($destId) ? $destId : 'hangup';
        return [
            'type' => 'hangup',
            'val' => $mode
        ];
    }
}

DestinationRegistry::register(new HangupModule());
