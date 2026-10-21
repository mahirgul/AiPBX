<?php
namespace PBX\Destinations;

/**
 * Destination type 'extension': a user's extension (sys_users).
 * Offered in the destination pickers (DestinationRegistry, /api/destinations.php); the
 * dialplan lines are built by buildDestinationLines() in web/src/sync/DialplanBuilders.php.
 */
class ExtensionModule implements PBXDestinationInterface {
    public function getKey(): string { return 'extension'; }
    public function getName(): string { return '📱 ' . \t('dest.extension'); }

    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT extension AS id, CONCAT(extension, ' - ', full_name) AS name FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 ORDER BY extension ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * "Extension = the called DID number": only meaningful on an inbound DID route
     * (it needs the DID in ${EXTEN}); in an IVR or time condition it would send
     * every call to congestion. Offered by /api/destinations.php?context=did only.
     */
    public static function autoDidOption(): array {
        return ['id' => '__did__', 'name' => '🔄 ' . \t('dest.extension_auto_did')];
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'extension',
            'val' => $destId
        ];
    }
}

DestinationRegistry::register(new ExtensionModule());
