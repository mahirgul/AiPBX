<?php
require_once __DIR__ . '/../services/FileStorageService.php';

/** Admin → File storage: local disk or an S3-compatible bucket for chat files. */
class FileStorageController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_storage' => fn() => FileStorageService::save($_POST),
            'test_storage' => fn() => FileStorageService::test($_POST),
            'migrate_storage' => fn() => FileStorageService::migrate(static::csrfToken()),
        ]);

        $settings = FileStorageService::settings();
        static::renderPage('file_storage/index', [
            'csrf' => getCSRFToken(),
            'settings' => $settings,
            'maskedSecret' => FileStorageService::maskedSecret($settings),
            'status' => FileStorageService::status(),
        ], ['title' => t('storage.title')] + $notices);
    }
}
