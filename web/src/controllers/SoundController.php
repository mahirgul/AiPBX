<?php
require_once __DIR__ . '/../helpers.php';

class SoundController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';
        $custom_dir = SOUNDS_CUSTOM_DIR;

        if (static::isPost()) {
            if (isset($_POST['upload_sound'])) {
                $res = PBXHelper::uploadAnnouncement($_POST, $_FILES);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_announcement'])) {
                $res = PBXHelper::deleteAnnouncement($_POST['anc_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_announcements', $_POST['anc_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['save_announcement'])) {
                $res = PBXHelper::saveAnnouncement($_POST, $_FILES);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['save_moh_class'])) {
                $res = PBXHelper::saveMOHClass($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_moh_class'])) {
                $res = PBXHelper::deleteMOHClass($_POST['moh_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['upload_moh_file'])) {
                $res = PBXHelper::uploadMOHFile($_POST, $_FILES, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $announcements = SoundRepository::allAnnouncementsOrdered();
        $moh_classes = SoundRepository::allMohClasses();

        $page_title = t('sounds.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('sounds/index', [
            'announcements' => $announcements,
            'moh_classes' => $moh_classes,
            'custom_dir' => $custom_dir,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
