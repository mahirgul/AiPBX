<?php
require_once __DIR__ . '/../services/SystemUpdateService.php';

/**
 * /system-update — installed version, latest release and updating (admin only).
 * Actions go through /api/system_update.php via JS (Apache restarts during an
 * update, so the page polls for progress).
 */
class SystemUpdateController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        static::renderPage('system_update/index', [
            'current' => SystemUpdateService::currentVersion(),
            'check' => SystemUpdateService::lastCheck(),
            'status' => SystemUpdateService::status(),
            'log_tail' => SystemUpdateService::logTail(),
        ], ['title' => t('system_update.page_title')]);
    }
}
