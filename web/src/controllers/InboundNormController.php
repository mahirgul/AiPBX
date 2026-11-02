<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/InboundNormService.php';

/**
 * Trunk → Inbound Call Normalization (/inbound-normalization).
 * Renders templates/views/inbound_norm/index.php. Logic: InboundNormService.
 */
class InboundNormController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('did_routes', 'access');

        $simulation = null;
        if (static::isPost() && ($_POST['action'] ?? '') === 'simulate' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $simulation = [
                'trunk' => (string) ($_POST['trunk_name'] ?? ''),
                'target' => ($_POST['target'] ?? '') === 'did' ? 'did' : 'caller',
                'number' => mb_substr(trim((string) ($_POST['number'] ?? '')), 0, 40),
            ];
            $simulation += InboundNormService::simulate(InboundNormService::all(), $simulation['trunk'], $simulation['target'], $simulation['number']);
        }
        $notices = static::handlePost([
            'save_rule' => fn() => InboundNormService::save($_POST),
            'delete_rule' => fn() => InboundNormService::delete($_POST),
        ]);

        $rules = InboundNormService::all();
        $edit = null;
        foreach ($rules as $r) {
            if ((int) $r['id'] === (int) ($_GET['edit'] ?? 0)) {
                $edit = $r;
            }
        }
        static::renderPage('inbound_norm/index', [
            'csrf' => getCSRFToken(),
            'rules' => $rules,
            'edit' => $edit,
            'trunks' => InboundNormService::trunkNames(),
            'simulation' => $simulation,
            'canEdit' => hasModulePermission('did_routes', 'edit'),
        ] + $notices, ['title' => t('inbound_norm.title')]);
    }
}
