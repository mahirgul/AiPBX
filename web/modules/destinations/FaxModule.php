<?php
namespace PBX\Destinations;

class FaxModule implements PBXDestinationInterface {
    public function getKey(): string { return 'fax'; }
    public function getName(): string { return '📠 Faks Hattı'; }

    public function getOptions(): array {
        $db = \getDB();
        $stmtUser = $db->query("SELECT extension AS id, CONCAT(extension, ' - ', full_name, IF(email != '' AND email IS NOT NULL, CONCAT(' [', email, ']'), '')) AS name FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY extension ASC");
        return $stmtUser->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'fax',
            'val' => $destId
        ];
    }
}

DestinationRegistry::register(new FaxModule());
