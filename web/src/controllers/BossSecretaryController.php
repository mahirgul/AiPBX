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

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_group'])) {
                $res = BossSecretaryService::saveGroup($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_group'])) {
                $res = BossSecretaryService::deleteGroup($_POST['group_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $groups = BossSecretaryService::getGroups();
        $extensions = BossSecretaryService::getAvailableExtensions();
        $modules = DestinationRegistry::getModuleList();

        $page_title = t('boss_secretary.title', 'Şef - Sekreter Grupları');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('boss_secretary/index', [
            'groups' => $groups,
            'extensions' => $extensions,
            'modules' => $modules,
            'message' => $message,
            'error' => $error,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
