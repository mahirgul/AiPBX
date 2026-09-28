<?php
require_once __DIR__ . '/../services/SystemUpdateService.php';

/**
 * /system-update — kurulu sürüm, yeni sürüm ve güncelleme (yalnızca admin).
 * İşlemler JS ile /api/system_update.php üzerinden yapılır (güncelleme
 * sırasında Apache yeniden başladığı için ilerleme sayfada yoklanır).
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
