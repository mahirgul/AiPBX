<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/RegistrationService.php';

/** Troubleshooting → Registrations (/registrations): phones, browsers, apps and trunks as Asterisk sees them now. */
class RegistrationsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        static::renderPage('registrations/index', ['reg' => RegistrationService::overview()], ['title' => t('registrations.title')]);
    }
}
