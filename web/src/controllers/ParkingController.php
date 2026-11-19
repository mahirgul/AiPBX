<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/ParkingService.php';

/**
 * Call Parking configuration page (/parking, res_parking.conf).
 * Admins only.
 */
class ParkingController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_parking' => fn() => ParkingService::save($_POST),
        ]);

        $config = ParkingService::getConfig();
        $destModules = \PBX\Destinations\DestinationRegistry::getModules();
        $mohClasses = [];
        try {
            $mohClasses = getDB()->query('SELECT name FROM pbx_moh_classes ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $mohClasses = [];
        }
        if (!in_array('default', $mohClasses, true)) {
            array_unshift($mohClasses, 'default');
        }
        $parkedCalls = !empty($config['is_active']) ? ParkingService::liveParkedCalls() : [];

        static::renderPage('parking/index', [
            'config' => $config,
            'dest_modules' => $destModules,
            'moh_classes' => $mohClasses,
            'parked_calls' => $parkedCalls,
        ], ['title' => t('parking.title')] + $notices);
    }
}
