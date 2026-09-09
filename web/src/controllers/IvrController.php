<?php
require_once __DIR__ . '/../helpers.php';

use PBX\Destinations\DestinationRegistry;

class IvrController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_ivr'])) {
                $res = PBXHelper::saveIVR($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_ivrs', $_POST['ivr_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['save_ivr_entry'])) {
                $res = PBXHelper::saveIVREntry($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_ivr_entry'])) {
                $res = PBXHelper::deleteIVREntry($_POST['entry_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_ivr'])) {
                $res = PBXHelper::deleteIVR($_POST['ivr_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $ivrs = IvrRepository::allOrderedByTitle();
        $entries_by_ivr = IvrRepository::entriesByIvr();
        $modules = DestinationRegistry::getModuleList();
        $announcements = IvrRepository::activeAnnouncements();

        $page_title = t('ivr.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('ivrs/index', [
            'ivrs' => $ivrs,
            'entries_by_ivr' => $entries_by_ivr,
            'modules' => $modules,
            'announcements' => $announcements,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
