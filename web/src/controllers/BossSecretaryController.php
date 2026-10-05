<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/BossSecretaryService.php';
require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

class BossSecretaryController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_group' => fn() => BossSecretaryService::saveGroup($_POST),
            'delete_group' => fn() => BossSecretaryService::deleteGroup($_POST['group_id'] ?? 0, static::csrfToken()),
        ]);

        $groups = BossSecretaryService::getGroups();
        $extensions = BossSecretaryService::getAvailableExtensions();
        $modules = DestinationRegistry::getModuleList();

        $page_title = t('boss_secretary.title', 'Şef - Sekreter Grupları');
        static::renderPage('boss_secretary/index', [
            'groups' => $groups,
            'extensions' => $extensions,
            'modules' => $modules,
        ], ['title' => $page_title] + $notices);
    }
}
