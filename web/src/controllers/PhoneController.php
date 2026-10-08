<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhoneProvisionService.php';

/** PBX → Phones: phone list, waiting phones, CSV import, provisioning settings. */
class PhoneController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // In-page request: the web admin password of one phone (not printed in the page).
        if (static::isPost() && ($_POST['action'] ?? '') === 'admin_password') {
            static::requireAjaxAccess('phones', 'edit', t('auth.no_edit_module'), t('common.invalid_csrf'));
            $phone = PhoneProvisionService::getPhone((int) ($_POST['phone_id'] ?? 0));
            static::json($phone === null
                ? ['success' => false, 'message' => t('phones.err_not_found')]
                : ['success' => true, 'password' => PhoneProvisionService::adminPassword($phone)]);
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
        ]);

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
            'log' => PhoneProvisionService::recentLog(50),
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
