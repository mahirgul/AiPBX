<?php
require_once __DIR__ . '/../helpers.php';

class TrunkController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_trunk'])) {
                $res = PBXHelper::saveTrunk($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_trunks', $_POST['trunk_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_trunk'])) {
                $res = PBXHelper::deleteTrunk($_POST['trunk_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $trunks = TrunkRepository::allOrdered();
        $trunk_statuses = TrunkRepository::livePjsipStatuses();

        $page_title = t('trunks.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('trunks/index', [
            'trunks' => $trunks,
            'trunk_statuses' => $trunk_statuses,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
