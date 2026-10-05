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

        $notices = static::handlePost([
            'save_ring_group' => fn() => RingGroupService::saveRingGroup($_POST),
            'delete_ring_group' => fn() => RingGroupService::deleteRingGroup($_POST['ring_group_id'] ?? 0, static::csrfToken()),
        ]);

        $ring_groups = RingGroupService::getRingGroups();
        $modules = DestinationRegistry::getModuleList();

        $page_title = t('ring_groups.title', 'Çalma Grupları');
        static::renderPage('ring_groups/index', [
            'ring_groups' => $ring_groups,
            'modules' => $modules,
        ], ['title' => $page_title] + $notices);
    }
}
