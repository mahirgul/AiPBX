<?php
require_once __DIR__ . '/../services/BackupService.php';

/**
 * /backup — backups of the system: list, make, download, upload, restore (admin only).
 * Actions go through /api/backup.php via JS: a restore replaces the database and restarts
 * Apache, so the page polls for progress.
 */
class BackupController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        static::renderPage('backup/index', [
            'backups' => BackupService::all(),
            'creating' => BackupService::creating(),
            'keep_days' => BackupService::keepDays(),
            'status' => BackupService::status(),
            'log_tail' => BackupService::logTail(),
            'sso_only' => SsoOnlyService::isActive(),
        ], ['title' => t('backup.page_title')]);
    }
}
