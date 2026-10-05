<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/RoleService.php';
require_once __DIR__ . '/../services/TwoFactorService.php';
require_once __DIR__ . '/../services/UserInvitationService.php';
require_once __DIR__ . '/../services/UserImportService.php';

class SystemUserController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';
        $generated_password = '';
        $generated_for = '';
        $import_preview = null;
        $import_result = null;

        // CSV template download (admin only — requireRole above)
        if (($_GET['download'] ?? '') === 'user_import_template') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="kullanici_sablonu.csv"');
            echo UserImportService::templateCsv();
            exit;
        }
        $modules_definition = RoleRepository::modulesDefinition();

        if (static::isPost()) {
            // Every form on this page posts a CSRF token; check it once here.
            // The services re-check it themselves.
            if (!static::verifyCsrf()) {
                $error = t('login.csrf_error', 'Güvenlik doğrulaması (CSRF) geçersiz!');
            } elseif (isset($_POST['save_system_user'])) {
                $res = UserService::saveUser($_POST);
                ['message' => $message, 'error' => $error] = static::notices($res);
                // A generated password is shown only in this response, in a
                // box that stays until closed (a 6 s toast would lose it).
                $generated_password = $res['generated_password'] ?? '';
                $generated_for = trim($_POST['username'] ?? '');
            } elseif (isset($_POST['csv_preview'])) {
                // Step 1: validate the file and write NOTHING; keep the valid
                // rows in the session until the confirmation step.
                if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                    $error = 'Lütfen bir CSV dosyası seçin.';
                } else {
                    $parsed = UserImportService::parse((string) file_get_contents($_FILES['csv_file']['tmp_name']));
                    if (!$parsed['success']) {
                        $error = $parsed['error'];
                    } else {
                        $defaultRole = trim($_POST['default_role'] ?? 'cc_agent');
                        $checked = UserImportService::validate($parsed['rows'], $defaultRole);
                        $valid = [];
                        foreach ($checked as $line => $item) {
                            if (!$item['errors']) { $valid[$line] = $item['row']; }
                        }
                        $key = bin2hex(random_bytes(16));
                        $_SESSION['user_import'] = [
                            'key' => $key,
                            'rows' => $valid,
                            'send_invitations' => !empty($_POST['send_invitations']),
                        ];
                        $import_preview = [
                            'key' => $key,
                            'items' => $checked,
                            'valid_count' => count($valid),
                            'send_invitations' => !empty($_POST['send_invitations']),
                            'file_name' => basename((string) ($_FILES['csv_file']['name'] ?? '')),
                        ];
                    }
                }
            } elseif (isset($_POST['csv_import'])) {
                // Step 2: insert the valid rows of the confirmed preview.
                $pending = $_SESSION['user_import'] ?? null;
                unset($_SESSION['user_import']);
                if (!$pending || !hash_equals($pending['key'], (string) ($_POST['import_key'] ?? ''))) {
                    $error = 'İçe aktarma oturumu bulunamadı veya süresi doldu; dosyayı yeniden yükleyin.';
                } else {
                    $import_result = UserImportService::import($pending['rows'], (bool) $pending['send_invitations'], static::csrfToken());
                }
            } elseif (isset($_POST['send_activation_mail'])) {
                $res = UserInvitationService::sendInvitationEmail((int) ($_POST['user_id'] ?? 0), false);
                if ($res['success']) {
                    $message = $res['message'] ?? 'Aktivasyon ve şifre belirleme maili başarıyla gönderildi.';
                } else {
                    $error = $res['error'] ?? 'E-posta gönderilemedi.';
                }
            } elseif (isset($_POST['bulk_send_activation_mail'])) {
                $rawSelected = $_POST['selected_users'] ?? [];
                $selectedIds = is_array($rawSelected) ? $rawSelected : explode(',', (string) $rawSelected);
                $res = UserInvitationService::sendBulkInvitations($selectedIds);
                if ($res['success']) {
                    $message = $res['message'];
                } else {
                    $error = $res['message'] ?: ($res['error'] ?? 'Toplu e-posta gönderimi başarısız oldu.');
                }
            } elseif (isset($_POST['reset_2fa'])) {
                $res = TwoFactorService::disableTwoFactor((int) ($_POST['user_id'] ?? 0), '', true);
                if ($res['success']) {
                    $message = t('system_users.2fa_reset_success', 'Kullanıcının iki faktörlü doğrulaması (2FA) başarıyla sıfırlandı.');
                } else {
                    $error = $res['error'] ?? 'İşlem başarısız.';
                }
            } elseif (isset($_POST['save_system_role'])) {
                // Uses the same RoleService::saveRole() as the full permission
                // matrix form on /roles, but stays on this page (its 'redirect'
                // result is NOT followed): this form is a quick role draft and
                // the matrix can be set on /roles later. The success message
                // comes from saveRole()'s own notify() call, so $message is
                // not set here (it would show two notifications).
                $res = RoleService::saveRole($_POST, $modules_definition);
                if (!isset($res['redirect'])) {
                    $error = $res['error'] ?? '';
                }
            } else {
                ['message' => $message, 'error' => $error] = static::handlePost([
                    'toggle_status' => fn() => PBXHelper::toggleStatus('sys_users', $_POST['user_id'] ?? 0, static::csrfToken()),
                    'reset_password' => fn() => UserService::resetPassword($_POST),
                    'delete_user' => fn() => UserService::deleteUser($_POST['user_id'] ?? 0, static::csrfToken()),
                ]);
            }
        }

        $users = SystemUserRepository::allWithRoleName();
        $all_roles = SystemUserRepository::allRolesForDropdown();
        $sys_roles_full = SystemUserRepository::allRolesFull();

        $page_title = t('system_users.title');
        static::renderPage('system_users/index', [
            'users' => $users,
            'all_roles' => $all_roles,
            'sys_roles_full' => $sys_roles_full,
            'generated_password' => $generated_password,
            'generated_for' => $generated_for,
            'import_preview' => $import_preview,
            'import_result' => $import_result,
        ], ['title' => $page_title, 'message' => $message ?? '', 'error' => $error ?? '']);
    }
}
