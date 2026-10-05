<?php
require_once __DIR__ . '/../helpers.php';

class SoundController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $custom_dir = SOUNDS_CUSTOM_DIR;

        $notices = static::handlePost([
            'upload_sound' => fn() => SoundService::uploadAnnouncement($_POST, $_FILES),
            'delete_announcement' => fn() => SoundService::deleteAnnouncement($_POST['anc_id'] ?? 0, static::csrfToken()),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_announcements', $_POST['anc_id'] ?? 0, static::csrfToken()),
            'save_announcement' => fn() => SoundService::saveAnnouncement($_POST, $_FILES),
            'save_moh_class' => fn() => SoundService::saveMOHClass($_POST),
            'delete_moh_class' => fn() => SoundService::deleteMOHClass($_POST['moh_id'] ?? 0, static::csrfToken()),
            'upload_moh_file' => fn() => SoundService::uploadMOHFile($_POST, $_FILES, static::csrfToken()),
        ]);

        $announcements = SoundRepository::allAnnouncementsOrdered();
        $moh_classes = SoundRepository::allMohClasses();

        $page_title = t('sounds.title');
        static::renderPage('sounds/index', [
            'announcements' => $announcements,
            'moh_classes' => $moh_classes,
            'custom_dir' => $custom_dir,
        ], ['title' => $page_title] + $notices);
    }
}
