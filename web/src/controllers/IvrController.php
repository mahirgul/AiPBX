<?php
require_once __DIR__ . '/../helpers.php';

use PBX\Destinations\DestinationRegistry;

class IvrController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_ivr' => fn() => IVRService::saveIVR($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_ivrs', $_POST['ivr_id'] ?? 0, static::csrfToken()),
            'save_ivr_entry' => fn() => IVRService::saveIVREntry($_POST),
            'delete_ivr_entry' => fn() => IVRService::deleteIVREntry($_POST['entry_id'] ?? 0, static::csrfToken()),
            'delete_ivr' => fn() => IVRService::deleteIVR($_POST['ivr_id'] ?? 0, static::csrfToken()),
        ]);

        $ivrs = IvrRepository::allOrderedByTitle();
        $entries_by_ivr = IvrRepository::entriesByIvr();
        $modules = DestinationRegistry::getModuleList();
        $announcements = IvrRepository::activeAnnouncements();

        $page_title = t('ivr.title');
        static::renderPage('ivrs/index', [
            'ivrs' => $ivrs,
            'entries_by_ivr' => $entries_by_ivr,
            'modules' => $modules,
            'announcements' => $announcements,
        ], ['title' => $page_title] + $notices);
    }
}
