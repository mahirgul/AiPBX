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

        // CSV şablonu indirme (yalnızca admin — requireRole yukarıda)
        if (($_GET['download'] ?? '') === 'user_import_template') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="kullanici_sablonu.csv"');
            echo UserImportService::templateCsv();
            exit;
        }
        $modules_definition = RoleRepository::modulesDefinition();

        if (static::isPost()) {
            if (isset($_POST['save_system_user'])) {
                $res = PBXHelper::saveUser($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
                // Otomatik üretilen şifre: yalnızca bu yanıtta, kapatılana kadar
                // duran bir kutuda gösterilir (6 sn'lik bildirimde kaybolurdu).
                $generated_password = $res['generated_password'] ?? '';
                $generated_for = trim($_POST['username'] ?? '');
            } elseif (isset($_POST['csv_preview'])) {
                // 1. adım: dosyayı doğrula, HİÇBİR ŞEY yazma; geçerli satırları
                // onay adımına kadar oturumda tut.
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    $error = 'Geçersiz CSRF güvenlik kodu!';
                } elseif (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
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
                // 2. adım: onaylanan önizlemedeki geçerli satırları ekle.
                $pending = $_SESSION['user_import'] ?? null;
                unset($_SESSION['user_import']);
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    $error = 'Geçersiz CSRF güvenlik kodu!';
                } elseif (!$pending || !hash_equals($pending['key'], (string) ($_POST['import_key'] ?? ''))) {
                    $error = 'İçe aktarma oturumu bulunamadı veya süresi doldu; dosyayı yeniden yükleyin.';
                } else {
                    $import_result = UserImportService::import($pending['rows'], (bool) $pending['send_invitations'], (string) $_POST['csrf_token']);
                }
            } elseif (isset($_POST['send_activation_mail'])) {
                $csrf = $_POST['csrf_token'] ?? '';
                if (!verifyCSRFToken($csrf)) {
                    $error = t('login.csrf_error', 'Güvenlik doğrulaması (CSRF) geçersiz!');
                } else {
                    $targetUserId = (int)($_POST['user_id'] ?? 0);
                    $res = UserInvitationService::sendInvitationEmail($targetUserId, false);
                    if ($res['success']) {
                        $message = $res['message'] ?? 'Aktivasyon ve şifre belirleme maili başarıyla gönderildi.';
                    } else {
                        $error = $res['error'] ?? 'E-posta gönderilemedi.';
                    }
                }
            } elseif (isset($_POST['bulk_send_activation_mail'])) {
                $csrf = $_POST['csrf_token'] ?? '';
                if (!verifyCSRFToken($csrf)) {
                    $error = t('login.csrf_error', 'Güvenlik doğrulaması (CSRF) geçersiz!');
                } else {
                    $rawSelected = $_POST['selected_users'] ?? [];
                    $selectedIds = is_array($rawSelected) ? $rawSelected : explode(',', (string)$rawSelected);
                    $res = UserInvitationService::sendBulkInvitations($selectedIds);
                    if ($res['success']) {
                        $message = $res['message'];
                    } else {
                        $error = $res['message'] ?: ($res['error'] ?? 'Toplu e-posta gönderimi başarısız oldu.');
                    }
                }
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
