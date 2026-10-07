<?php
require_once __DIR__ . '/../services/TwoFactorService.php';

class TwoFactorLoginController extends BaseController
{
    public static function index(): void
    {
        // Back to login if the session has no user waiting for 2FA
        if (empty($_SESSION['pending_2fa_user_id'])) {
            static::redirect('/login');
            return;
        }

        $userId = (int)$_SESSION['pending_2fa_user_id'];
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, full_name, role, extension, theme_preference, language_preference, two_factor_enabled, two_factor_secret FROM sys_users WHERE id = ? AND is_active = 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)$user['two_factor_enabled'] !== 1) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_full_name']);
            static::redirect('/login');
            return;
        }

        $error = '';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (static::isPost()) {
            $action = $_POST['action'] ?? 'verify';

            if ($action === 'cancel') {
                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_full_name']);
                static::redirect('/login');
                return;
            }

            $csrf = $_POST['csrf_token'] ?? '';
            // Brute force: failed 2FA attempts were logged but the lockout was
            // not checked — someone knowing the password could try the 6-digit
            // code without limit. The same IP/user lockout as the web login.
            if (checkBruteForceLockout($clientIp, $user['username'])) {
                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_full_name'], $_SESSION['pending_2fa_failures']);
                notify(t('login.too_many_attempts', 'Çok fazla hatalı deneme yapıldı. Lütfen 15 dakika sonra tekrar deneyin.'), 'danger');
                static::redirect('/login');
                return;
            }
            if (!verifyCSRFToken($csrf)) {
                $error = t('login.csrf_error', 'Güvenlik doğrulaması (CSRF) başarısız! Lütfen sayfayı yenileyip tekrar deneyin.');
            } else {
                $authMode = $_POST['auth_mode'] ?? 'totp';
                $isValid = false;

                if ($authMode === 'recovery') {
                    $recoveryCode = trim($_POST['recovery_code'] ?? '');
                    if (empty($recoveryCode)) {
                        $error = t('login_2fa.enter_recovery_code', 'Lütfen kurtarma kodunu girin.');
                    } else {
                        $isValid = TwoFactorService::verifyAndConsumeRecoveryCode($userId, $recoveryCode);
                        if (!$isValid) {
                            $error = t('login_2fa.invalid_recovery_code', 'Geçersiz veya daha önce kullanılmış kurtarma kodu!');
                        }
                    }
                } else {
                    $totpCode = trim($_POST['totp_code'] ?? '');
                    if (empty($totpCode) || strlen($totpCode) !== 6) {
                        $error = t('login_2fa.enter_valid_totp', 'Lütfen 6 haneli doğrulama kodunu eksiksiz girin.');
                    } else {
                        $secret = $user['two_factor_secret'] ?? '';
                        $isValid = TwoFactorService::verifyCode($secret, $totpCode);
                        if (!$isValid) {
                            $error = t('login_2fa.invalid_totp_code', 'Hatalı veya süresi dolmuş doğrulama kodu! Lütfen telefonunuzdaki güncel kodu girin.');
                        }
                    }
                }

                if ($isValid) {
                    unset($_SESSION['pending_2fa_failures']);
                    // Regenerate the session ID
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['extension'] = $user['extension'];
                    $_SESSION['theme'] = $user['theme_preference'] ?? 'light';
                    $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$user['language_preference'] ?? '']))
                        ? $user['language_preference']
                        : DEFAULT_UI_LANGUAGE;

                    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_full_name']);

                    if (function_exists('logLoginAttempt')) {
                        logLoginAttempt($clientIp, $user['username'], 'SUCCESS');
                    }
                    if (function_exists('writeAuditLog')) {
                        writeAuditLog($user['id'], 'sys_users', $user['id'], "Kullanıcı '{$user['username']}' 2FA ile başarılı oturum açtı.", 'two_factor_login');
                    }

                    static::redirect(roleHomePath($user['role']));
                    return;
                } else {
                    if (function_exists('logLoginAttempt')) {
                        logLoginAttempt($clientIp, $user['username'], 'FAILED');
                    }
                    // After 5 wrong codes in the same pending session the user
                    // has to start over with the password (that step is subject to the lockout too).
                    $_SESSION['pending_2fa_failures'] = ($_SESSION['pending_2fa_failures'] ?? 0) + 1;
                    if ($_SESSION['pending_2fa_failures'] >= 5) {
                        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_full_name'], $_SESSION['pending_2fa_failures']);
                        notify(t('login_2fa.too_many_failures', 'Çok fazla hatalı doğrulama kodu girildi. Lütfen yeniden giriş yapın.'), 'danger');
                        static::redirect('/login');
                        return;
                    }
                }
            }
        }

        $csrf_token = getCSRFToken();
        $site_title = getSystemSetting('site_title', 'AiPBX');
        $brand_title = getSystemSetting('brand_title', 'AiPBX');
        $brand_sub = getSystemSetting('brand_sub', 'Santral & Çağrı Merkezi');
        $site_logo_type = getSystemSetting('site_logo_type', 'image');
        $site_logo_icon = getSystemSetting('site_logo_icon', 'fa-network-wired');
        $site_logo_image = getSystemSetting('site_logo_image', BRAND_DEFAULT_LOGO_URL);
        $site_favicon_url = getSystemSetting('site_favicon_url', '');

        static::renderAuthPage('login/two_factor', [
            'error' => $error,
            'user' => $user,
            'csrf_token' => $csrf_token,
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
            'site_logo_type' => $site_logo_type,
            'site_logo_icon' => $site_logo_icon,
            'site_logo_image' => $site_logo_image,
            'site_favicon_url' => $site_favicon_url,
        ], ['title' => t('login_2fa.page_title', 'İki Faktörlü Doğrulama')]);
    }
}
