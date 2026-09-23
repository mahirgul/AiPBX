<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/RingGroupService.php';
require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

class RingGroupController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_ring_group'])) {
                $res = RingGroupService::saveRingGroup($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_ring_group'])) {
                $res = RingGroupService::deleteRingGroup($_POST['ring_group_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $ring_groups = RingGroupService::getRingGroups();
        $modules = DestinationRegistry::getModuleList();

        $page_title = t('ring_groups.title', 'Çalma Grupları');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('ring_groups/index', [
            'ring_groups' => $ring_groups,
            'modules' => $modules,
            'message' => $message,
            'error' => $error,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
