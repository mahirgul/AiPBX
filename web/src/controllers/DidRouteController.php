<?php
require_once __DIR__ . '/../helpers.php';

use PBX\Destinations\DestinationRegistry;

class DidRouteController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_did_route' => fn() => RouteService::saveDIDRoute($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_dids', $_POST['route_id'] ?? 0, static::csrfToken()),
            'delete_did_route' => fn() => RouteService::deleteDIDRoute($_POST['route_id'] ?? 0, static::csrfToken()),
        ]);

        $routes = DidRouteRepository::allOrdered();
        $modules = DestinationRegistry::getModuleList();
        $didDeptMap = DidRouteRepository::didDepartmentMap();

        $page_title = t('did.title');
        static::renderPage('did_routes/index', [
            'routes' => $routes,
            'modules' => $modules,
            'didDeptMap' => $didDeptMap,
        ], ['title' => $page_title] + $notices);
    }
}
