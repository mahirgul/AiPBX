<?php
require_once __DIR__ . '/../services/TwoFactorService.php';
require_once __DIR__ . '/../services/PasskeyService.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';

class SecurityController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();

        $user = getCurrentUser();
        if (!$user) {
            static::redirect('/login');
            return;
        }

        $userId = (int)$user['id'];
        $message = '';
        $error = '';
        $newRecoveryCodes = null;

        $db = getDB();
        $uStmt = $db->prepare('SELECT two_factor_enabled, two_factor_confirmed_at FROM sys_users WHERE id = ?');
        $uStmt->execute([$userId]);
        $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
        $twoFactorEnabled = !empty($uData['two_factor_enabled']);

        if (static::isPost()) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                $error = t('login.csrf_error', 'Güvenlik doğrulaması (CSRF) geçersiz!');
            } else {
                $action = $_POST['action'] ?? '';

                // 1. Enable 2FA (confirm the code)
                if ($action === 'enable_2fa') {
                    $secret = trim($_POST['secret'] ?? '');
                    $code = trim($_POST['verify_code'] ?? '');
                    if (empty($secret) || empty($code)) {
                        $error = t('security.enter_code_error', 'Lütfen authenticator uygulamasındaki 6 haneli kodu girin.');
                    } else {
                        $res = TwoFactorService::enableTwoFactor($userId, $secret, $code);
                        if ($res['success']) {
                            $twoFactorEnabled = true;
                            $newRecoveryCodes = $res['recovery_codes'];
                            unset($_SESSION['pending_2fa_setup_secret']);
                            $message = t('security.2fa_enabled_success', 'İki faktörlü doğrulama başarıyla aktifleştirildi! Kurtarma kodlarınızı lütfen güvenli bir yere kaydedin.');
                        } else {
                            $error = $res['error'] ?? 'Doğrulama kodu geçersiz!';
                        }
                    }
                }

                // 2. Disable 2FA
                elseif ($action === 'disable_2fa') {
                    $password = (string)($_POST['current_password'] ?? '');
                    $res = TwoFactorService::disableTwoFactor($userId, $password);
                    if ($res['success']) {
                        $twoFactorEnabled = false;
                        unset($_SESSION['pending_2fa_setup_secret']);
                        $message = t('security.2fa_disabled_success', 'İki faktörlü doğrulama devre dışı bırakıldı.');
                    } else {
                        $error = $res['error'] ?? 'İşlem başarısız.';
                    }
                }

                // 3. Generate new recovery codes
                elseif ($action === 'regen_recovery_codes') {
                    $password = (string)($_POST['current_password'] ?? '');
                    $res = TwoFactorService::regenerateRecoveryCodes($userId, $password);
                    if ($res['success']) {
                        $newRecoveryCodes = $res['recovery_codes'];
                        $message = t('security.recovery_codes_regen_success', 'Yeni kurtarma kodları üretildi. Eski kurtarma kodları artık geçersizdir.');
                    } else {
                        $error = $res['error'] ?? 'İşlem başarısız.';
                    }
                }

                // 4. Change password
                elseif ($action === 'change_password') {
                    $curPass = (string)($_POST['current_password'] ?? '');
                    $newPass = (string)($_POST['new_password'] ?? '');
                    $confPass = (string)($_POST['confirm_password'] ?? '');

                    $pStmt = $db->prepare('SELECT password_hash FROM sys_users WHERE id = ?');
                    $pStmt->execute([$userId]);
                    $curHash = $pStmt->fetchColumn();

                    if (!password_verify($curPass, $curHash)) {
                        $error = t('security.wrong_current_password', 'Mevcut şifrenizi hatalı girdiniz.');
                    } elseif (mb_strlen($newPass) < 8) {
                        $error = t('security.password_min_length', 'Yeni şifre en az 8 karakter olmalıdır.');
                    } elseif ($newPass !== $confPass) {
                        $error = t('security.password_mismatch', 'Yeni şifreler birbiriyle uyuşmuyor.');
                    } else {
                        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                        // When the password changes, the sessions on the phones drop too (token_epoch).
                        $upd = $db->prepare('UPDATE sys_users SET password_hash = ?, token_epoch = token_epoch + 1 WHERE id = ?');
                        $upd->execute([$newHash, $userId]);
                        if (function_exists('writeAuditLog')) {
                            writeAuditLog($userId, 'sys_users', $userId, "Kullanıcı '{$user['username']}' web giriş şifresini değiştirdi.", 'password_change');
                        }
                        $message = t('security.password_changed_success', 'Şifreniz başarıyla güncellendi.');
                    }
                }

                // 5. Google sign-in settings (administrators only)
                elseif ($action === 'save_google_settings' && $user['role'] === 'admin') {
                    $res = GoogleAuthService::saveSettings($_POST);
                    if ($res['success']) {
                        $message = $res['message'];
                    } else {
                        $error = $res['error'];
                    }
                }
            }
        }

        // 2FA setup secret and QR code (if 2FA is not enabled yet)
        $setupSecret = '';
        $qrCodeDataUri = '';
        if (!$twoFactorEnabled) {
            if (empty($_SESSION['pending_2fa_setup_secret'])) {
                $_SESSION['pending_2fa_setup_secret'] = TwoFactorService::generateSecret(32);
            }
            $setupSecret = $_SESSION['pending_2fa_setup_secret'];
            $brandTitle = getSystemSetting('brand_title', 'AiPBX');
            $otpUri = TwoFactorService::getOtpAuthUri($user['username'], $setupSecret, $brandTitle);
            $qrCodeDataUri = TwoFactorService::getQrCodeDataUri($otpUri);
        }

        // The user's registered passkeys
        $passkeys = PasskeyService::getUserPasskeys($userId);


        $page_title = t('security.page_title', 'Güvenlik Ayarları (2FA, Passkey & Google)');
        static::renderPage('security/index', [
            'user' => $user,
            'twoFactorEnabled' => $twoFactorEnabled,
            'setupSecret' => $setupSecret,
            'qrCodeDataUri' => $qrCodeDataUri,
            'passkeys' => $passkeys,
            'googleSettings' => GoogleAuthService::settingsForPage(),
            'newRecoveryCodes' => $newRecoveryCodes,
            'csrf_token' => getCSRFToken(),
        ], ['title' => $page_title, 'message' => $message ?? '', 'error' => $error ?? '']);
    }
}
