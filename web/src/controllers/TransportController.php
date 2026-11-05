<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/TransportService.php';

/** Admin → Transports: where Asterisk listens for SIP. Admin only. */
class TransportController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_builtin' => fn() => TransportService::saveBuiltin($_POST),
            'save_custom' => fn() => TransportService::saveCustom($_POST),
            'delete_custom' => fn() => TransportService::deleteCustom($_POST),
        ]);

        static::renderPage('transports/index', [
            'csrf' => getCSRFToken(),
            'builtin' => TransportService::builtin(),
            'custom' => TransportService::custom(),
            'restartNeeded' => TransportService::restartNeeded(),
            'protocols' => TransportService::PROTOCOLS,
        ] + $notices, ['title' => t('transports.title')]);
    }
}
