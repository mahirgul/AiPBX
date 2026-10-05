<?php
require_once __DIR__ . '/../helpers.php';

class TrunkController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_trunk' => fn() => TrunkService::saveTrunk($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_trunks', $_POST['trunk_id'] ?? 0, static::csrfToken()),
            'delete_trunk' => fn() => TrunkService::deleteTrunk($_POST['trunk_id'] ?? 0, static::csrfToken()),
        ]);

        $trunks = TrunkRepository::allOrdered();
        $trunk_statuses = TrunkRepository::livePjsipStatuses();

        $page_title = t('trunks.title');
        static::renderPage('trunks/index', [
            'trunks' => $trunks,
            'trunk_statuses' => $trunk_statuses,
        ], ['title' => $page_title] + $notices);
    }
}
