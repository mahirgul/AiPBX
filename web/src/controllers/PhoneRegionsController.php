<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhoneRegionService.php';
require_once __DIR__ . '/../services/NetworkSettingsService.php';

/** Provisioning → Regions: the address and settings groups desk phones are put in. Admin only. */
class PhoneRegionsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_region' => fn() => PhoneRegionService::save($_POST),
            'delete_region' => fn() => PhoneRegionService::delete($_POST),
            // The general addresses every region starts from; a region's own value overrides them.
            'save_phones' => fn() => NetworkSettingsService::savePhones($_POST),
        ]);

        static::renderPage('phone_regions/index', [
            'csrf' => getCSRFToken(),
            'regions' => PhoneRegionService::all(),
            'phoneCounts' => PhoneRegionService::phoneCounts(),
            'settings' => PhoneProvisionService::settings(),
            'codecs' => PhoneProvisionService::CODECS,
            'localIps' => NetworkSettingsService::localIps(),
        ] + $notices, ['title' => t('phones.region_title')]);
    }
}
