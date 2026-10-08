<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhoneProvisionService.php';

/** Key layout of one user's desk phone (reached from Extensions and Phones). */
class PhoneKeysController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $userId = (int) ($_GET['user'] ?? $_POST['user_id'] ?? 0);
        $csrf = static::csrfToken();
        $notices = static::handlePost([
            'save_keys' => fn() => PhoneProvisionService::saveKeys(
                $userId,
                (array) (json_decode((string) ($_POST['keys_json'] ?? '[]'), true) ?: []),
                $csrf
            ),
            'copy_keys' => fn() => PhoneProvisionService::copyKeys($userId, (array) ($_POST['to_users'] ?? []), $csrf),
        ]);

        $users = PhoneProvisionService::assignableUsers();
        $user = null;
        foreach ($users as $u) {
            if ((int) $u['id'] === $userId) {
                $user = $u;
            }
        }

        // The model of the user's phone decides the drawing; ?model= previews another one.
        $model = (string) ($_GET['model'] ?? '');
        if (PhoneModels::get($model) === null) {
            $model = $user ? PhoneProvisionService::userPhoneModel((int) $user['id']) : '';
        }
        if (PhoneModels::get($model) === null) {
            $model = 'yealink-t46u';
        }

        static::renderPage('phone_keys/index', [
            'users' => $users,
            'user' => $user,
            'model' => $model,
            'keys' => $user ? PhoneProvisionService::getKeys((int) $user['id']) : [],
        ], ['title' => t('phones.keys_title')] + $notices);
    }
}
