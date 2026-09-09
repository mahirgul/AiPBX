<?php
require_once __DIR__ . '/../helpers.php';

use PBX\Destinations\DestinationRegistry;

class DidRouteController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_did_route'])) {
                $res = PBXHelper::saveDIDRoute($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_dids', $_POST['route_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_did_route'])) {
                $res = PBXHelper::deleteDIDRoute($_POST['route_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $routes = DidRouteRepository::allOrdered();
        $modules = DestinationRegistry::getModuleList();
        $didDeptMap = DidRouteRepository::didDepartmentMap();

        $page_title = t('did.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('did_routes/index', [
            'routes' => $routes,
            'modules' => $modules,
            'didDeptMap' => $didDeptMap,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
