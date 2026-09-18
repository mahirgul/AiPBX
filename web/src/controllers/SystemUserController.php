<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/RoleService.php';
require_once __DIR__ . '/../services/TwoFactorService.php';

class SystemUserController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';
        $modules_definition = RoleRepository::modulesDefinition();

        if (static::isPost()) {
            if (isset($_POST['save_system_user'])) {
                $res = PBXHelper::saveUser($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('sys_users', $_POST['user_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['reset_password'])) {
                $res = PBXHelper::resetUserPassword($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['reset_2fa'])) {
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                $csrf = $_POST['csrf_token'] ?? '';
                if (!verifyCSRFToken($csrf)) {
                    $error = t('login.csrf_error', 'Güvenlik doğrulaması (CSRF) geçersiz!');
                } else {
                    $res = TwoFactorService::disableTwoFactor($targetUserId, '', true);
                    if ($res['success']) {
                        $message = t('system_users.2fa_reset_success', 'Kullanıcının iki faktörlü doğrulaması (2FA) başarıyla sıfırlandı.');
                    } else {
                        $error = $res['error'] ?? 'İşlem başarısız.';
                    }
                }
            } elseif (isset($_POST['delete_user'])) {
                $res = PBXHelper::deleteUser($_POST['user_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['save_system_role'])) {
                // 2026-08-24: bu, /roles'un tam izin-matrisli formuyla AYNI
                // RoleService::saveRole()'u kullanıyor (önceden ayrı, izin
                // matrisi hiç yazmayan bir UserService::saveRole() kopyası
                // vardı — kullanıcı doğrulama turunda bulunan mükerrer kod
                // olarak işaretleyip birleştirilmesini istedi). /roles'un
                // aksine BURADA sayfada kalınır (RoleService'in 'redirect'
                // dönüşü TAKİP EDİLMEZ) — bu formun amacı zaten sayfadan
                // ayrılmadan hızlı bir rol taslağı oluşturmak; admin izin
                // matrisini ayrıca /roles'tan yapılandırabilir. Başarı mesajı
                // RoleService::saveRole()'un KENDİ notify() çağrısından gelir
                // (burada ayrıca $message set edilirse iki bildirim üst üste
                // biner).
                $res = RoleService::saveRole($_POST, $modules_definition);
                if (!isset($res['redirect'])) {
                    $error = $res['error'] ?? '';
                }
            }
        }

        $users = SystemUserRepository::allWithRoleName();
        $all_roles = SystemUserRepository::allRolesForDropdown();
        $sys_roles_full = SystemUserRepository::allRolesFull();

        $page_title = t('system_users.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('system_users/index', [
            'users' => $users,
            'all_roles' => $all_roles,
            'sys_roles_full' => $sys_roles_full,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
