<?php
namespace PBX\Destinations;

class ExtensionModule implements PBXDestinationInterface {
    public function getKey(): string { return 'extension'; }
    public function getName(): string { return '📱 Dahili Abone'; }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT extension AS id, CONCAT(extension, ' - ', full_name) AS name FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 ORDER BY extension ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'extension',
            'val' => $destId
        ];
    }
}

DestinationRegistry::register(new ExtensionModule());
