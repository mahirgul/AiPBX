<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhoneProvisionService.php';
require_once __DIR__ . '/../services/NetworkServicesService.php';
require_once __DIR__ . '/../services/PhoneDiscoveryService.php';

/** PBX → Phones: phone list, waiting phones, phones found on the network, provisioning settings. */
class PhoneController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // In-page request: the web admin password of one phone (not printed in the page).
        if (static::isPost() && ($_POST['action'] ?? '') === 'admin_password') {
            static::requireAjaxAccess('phones', 'edit', t('auth.no_edit_module'), t('common.invalid_csrf'));
            $phone = PhoneProvisionService::getPhone((int) ($_POST['phone_id'] ?? 0));
            if ($phone !== null) {
                writeAuditLog('phones', 'phone', (int) $phone['id'], PhoneModels::formatMac((string) $phone['mac']), 'show_password', $_SESSION['user_id'] ?? null);
            }
            static::json($phone === null
                ? ['success' => false, 'message' => t('phones.err_not_found')]
                : ['success' => true, 'password' => PhoneProvisionService::adminPassword($phone)]);
        }

        // In-page request: the file(s) the phone gets now, passwords hidden unless asked for.
        if (static::isPost() && ($_POST['action'] ?? '') === 'preview') {
            $reveal = !empty($_POST['reveal']);
            static::requireAjaxAccess('phones', $reveal ? 'edit' : 'view', t('auth.no_edit_module'), t('common.invalid_csrf'));
            $phone = PhoneProvisionService::getPhone((int) ($_POST['phone_id'] ?? 0));
            if ($phone === null) {
                static::json(['success' => false, 'message' => t('phones.err_not_found')]);
            }
            if ($reveal) {
                writeAuditLog('phones', 'phone', (int) $phone['id'], PhoneModels::formatMac((string) $phone['mac']), 'show_file_passwords', $_SESSION['user_id'] ?? null);
            }
            $files = PhoneProvisionService::previewFiles($phone, $reveal);
            static::json($files === null
                ? ['success' => false, 'message' => t('phones.preview_unassigned')]
                : ['success' => true, 'files' => $files, 'revealed' => $reveal]);
        }

        $csrf = static::csrfToken();
        $notices = static::handlePost([
            'save_phone' => fn() => PhoneProvisionService::savePhone($_POST),
            'delete_phone' => fn() => PhoneProvisionService::deletePhone((int) ($_POST['phone_id'] ?? 0), $csrf),
            'regenerate_token' => fn() => PhoneProvisionService::regenerateToken((int) ($_POST['phone_id'] ?? 0), $csrf),
            'resync_phone' => fn() => PhoneProvisionService::resync((int) ($_POST['phone_id'] ?? 0), false, $csrf),
            'reboot_phone' => fn() => PhoneProvisionService::resync((int) ($_POST['phone_id'] ?? 0), true, $csrf),
            'assign_waiting' => fn() => PhoneProvisionService::assignWaiting(
                (int) ($_POST['waiting_id'] ?? 0),
                (string) ($_POST['model'] ?? ''),
                (int) ($_POST['user_id'] ?? 0),
                $csrf
            ),
            'delete_waiting' => fn() => PhoneProvisionService::deleteWaiting((int) ($_POST['waiting_id'] ?? 0), $csrf),
            'import_csv' => fn() => PhoneProvisionService::importCsvAction(self::uploadedCsv(), $csrf),
            'save_settings' => fn() => PhoneProvisionService::saveSettings($_POST),
            // The discovery switches are their own form (their own modal): they
            // are read by a different service and a different process.
            'save_discovery_settings' => fn() => PhoneDiscoveryService::saveSettingsAction($_POST, $csrf),
            'approve_discovery' => fn() => PhoneDiscoveryService::approve(
                (int) ($_POST['discovery_id'] ?? 0),
                (string) ($_POST['model'] ?? ''),
                (int) ($_POST['user_id'] ?? 0),
                $csrf
            ),
            'ignore_discovery' => fn() => PhoneDiscoveryService::ignore((int) ($_POST['discovery_id'] ?? 0), $csrf),
            'restore_discovery' => fn() => PhoneDiscoveryService::restore((int) ($_POST['discovery_id'] ?? 0), $csrf),
        ]);

        // Phones that got an address from AiPBX's DHCP (Network services) wait here too.
        NetworkServicesService::syncLeasesToWaiting();

        $phones = PhoneProvisionService::listPhones();
        foreach ($phones as &$p) {
            $tpl = PhoneTemplates::forModel($p['model']);
            $p['url'] = PhoneProvisionService::provisioningUrl($p);
            $p['device_file'] = $tpl ? $tpl->deviceFile($p['mac']) : '';
            $p['can_reboot'] = $tpl !== null && $tpl->notifyReboot() !== '';
            unset($p['admin_password'], $p['token']);
        }
        unset($p);

        static::renderPage('phones/index', [
            'phones' => $phones,
            'waiting' => PhoneProvisionService::listWaiting(),
            'users' => PhoneProvisionService::assignableUsers(),
            'settings' => PhoneProvisionService::settings(),
            // Phones the discovery engine (cron, every 5 minutes) found on the
            // network, plus the switches that decide what may happen to them.
            'discovered' => PhoneDiscoveryService::listDiscovered(),
            'discoverySummary' => PhoneDiscoveryService::summary(),
            'discovery' => PhoneDiscoveryService::settings(),
            'discoveryPoolFree' => PhoneDiscoveryService::poolFree(),
            'discoveryEngine' => PhoneDiscoveryService::engineStatus(),
            'macBaseUrl' => ForgotPasswordService::portalUrl() . '/provision/',
        ], ['title' => t('phones.title')] + $notices);
    }

    /** CSV from the uploaded file, or from the text area. */
    private static function uploadedCsv(): string
    {
        $f = $_FILES['csv_file'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])
            && ($f['size'] ?? 0) <= 1024 * 1024) {
            return (string) file_get_contents($f['tmp_name']);
        }
        return (string) ($_POST['csv_text'] ?? '');
    }
}
