<?php
require_once __DIR__ . '/../helpers.php';

class OutboundRouteController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_route'])) {
                $res = PBXHelper::saveOutboundRoute($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_outbound_routes', $_POST['route_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_route'])) {
                $res = PBXHelper::deleteOutboundRoute($_POST['route_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $routes = OutboundRouteRepository::allOrdered();
        $trunks = OutboundRouteRepository::activeTrunksForDropdown();

        $page_title = t('outbound.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('outbound_routes/index', [
            'routes' => $routes,
            'trunks' => $trunks,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
