<?php
require_once __DIR__ . '/../helpers.php';

/**
 * Outbound page (/outbound-routes).
 * Renders templates/views/outbound_routes/index.php with assets/js/outbound_routes.js.
 * Logic: OutboundRouteRepository, RouteService.
 * POST actions: delete_route, save_route, toggle_status.
 * Admins only.
 */
class OutboundRouteController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_route' => fn() => RouteService::saveOutboundRoute($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_outbound_routes', $_POST['route_id'] ?? 0, static::csrfToken()),
            'delete_route' => fn() => RouteService::deleteOutboundRoute($_POST['route_id'] ?? 0, static::csrfToken()),
        ]);

        $routes = OutboundRouteRepository::allOrdered();
        $trunks = OutboundRouteRepository::activeTrunksForDropdown();

        $page_title = t('outbound.title');
        static::renderPage('outbound_routes/index', [
            'routes' => $routes,
            'trunks' => $trunks,
        ], ['title' => $page_title] + $notices);
    }
}
