<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/InboundNormService.php';

/**
 * Trunk → Inbound Call Normalization (/inbound-normalization).
 * Renders templates/views/inbound_norm/index.php with assets/js/inbound_norm.js.
 * Logic: InboundNormService. POST actions: save_rule, delete_rule, toggle_status, simulate.
 */
class InboundNormController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('did_routes', 'access');

        if (static::isPost() && ($_POST['action'] ?? '') === 'simulate') {
            header('Content-Type: application/json; charset=utf-8');
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false]);
                exit;
            }
            $rules = InboundNormService::all();
            $trunk = (string) ($_POST['trunk_name'] ?? '');
            $number = mb_substr(trim((string) ($_POST['number'] ?? '')), 0, 40);
            echo json_encode(['success' => true, 'data' => [
                'caller' => InboundNormService::simulate($rules, $trunk, 'caller', $number),
                'did' => InboundNormService::simulate($rules, $trunk, 'did', $number),
            ]]);
            exit;
        }

        $notices = static::handlePost([
            'save_rule' => fn() => InboundNormService::save($_POST),
            'delete_rule' => fn() => InboundNormService::delete($_POST),
            'toggle_status' => fn() => InboundNormService::toggle($_POST),
        ]);

        static::renderPage('inbound_norm/index', [
            'csrf' => getCSRFToken(),
            'rules' => InboundNormService::all(),
            'trunks' => InboundNormService::trunkNames(),
            'canEdit' => hasModulePermission('did_routes', 'edit'),
        ], ['title' => t('inbound_norm.title')] + $notices);
    }
}
