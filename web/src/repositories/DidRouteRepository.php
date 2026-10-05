<?php
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

class DidRouteRepository extends BaseRepository
{
    protected static string $table = 'pbx_dids';

    public static function allOrdered(): array
    {
        return static::findAll('did_number ASC');
    }

    public static function didDepartmentMap(): array
    {
        $map = [];
        foreach (static::db()->query('SELECT did_extension, department_name FROM sys_did_mappings')->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $map[$m['did_extension']] = $m['department_name'];
        }
        return $map;
    }

    /**
     * Fax routes ignore dest_id at the dialplan level (routing is done by the
     * DID number itself, see SyncDialplan.php buildDestinationLines('fax'));
     * for a fax DID the meaningful "detail" is the unit mapping.
     */
    public static function resolveDestLabel(string $destType, $destId, string $didNumber, array &$cache, array $didDeptMap): ?string
    {
        if ($destType === 'fax') {
            return $didDeptMap[$didNumber] ?? null;
        }
        return DestinationRegistry::resolveLabel($destType, $destId, $cache);
    }
}
