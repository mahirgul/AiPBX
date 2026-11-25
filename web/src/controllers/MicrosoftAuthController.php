<?php
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../services/MicrosoftAuthService.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';
require_once __DIR__ . '/../services/SsoOnlyService.php';

/**
 * MicrosoftAuthController — Microsoft Entra ID OAuth 2.0 login & callback.
 */
class MicrosoftAuthController extends BaseController
{
    public static function auth(): void
    {
        if (!MicrosoftAuthService::isEnabled() || SsoOnlyService::localSignInBlocked()) {
            notify(t('microsoft.err_disabled'), 'warning');
            static::redirect('/login');
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        header('Location: ' . MicrosoftAuthService::getAuthUrl());
        exit;
    }

    public static function callback(): void
    {
        if (!MicrosoftAuthService::isEnabled() || SsoOnlyService::localSignInBlocked()) {
            notify(t('microsoft.err_disabled'), 'warning');
            static::redirect('/login');
            return;
        }
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (isset($_GET['error'])) {
            $err = htmlspecialchars((string) ($_GET['error_description'] ?? $_GET['error']));
            notify(sprintf(t('microsoft.err_cancelled'), $err), 'warning');
            static::redirect('/login');
            return;
        }

        if (!MicrosoftAuthService::verifyState((string) ($_GET['state'] ?? ''))) {
            notify(t('microsoft.err_state'), 'danger');
            static::redirect('/login');
            return;
        }

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            notify(t('microsoft.err_code'), 'danger');
            static::redirect('/login');
            return;
        }

        $tokenData = MicrosoftAuthService::exchangeCode($code);
        if (!$tokenData) {
            notify(t('microsoft.err_token'), 'danger');
            static::redirect('/login');
            return;
        }

        $profile = MicrosoftAuthService::resolveProfile($tokenData);
        if (!$profile) {
            notify(t('microsoft.err_email'), 'danger');
            static::redirect('/login');
            return;
        }

        $email = $profile['email'];
        if (!MicrosoftAuthService::isDomainAllowed($email)) {
            notify(t('microsoft.err_domain'), 'danger');
            static::redirect('/login');
            return;
        }

        $user = MicrosoftAuthService::findOrProvisionUser($email, $profile['name']);
        if (!$user) {
            notify(sprintf(t('microsoft.err_no_user'), htmlspecialchars($email)), 'danger');
            static::redirect('/login');
            return;
        }

        if (!empty($user['two_factor_enabled'])) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_username'] = $user['username'];
            $_SESSION['pending_2fa_full_name'] = $user['full_name'];
            static::redirect('/login-2fa');
            return;
        }

        $redirectUrl = GoogleAuthService::loginUser($user, $clientIp, 'Microsoft');
        notify(sprintf(t('microsoft.welcome'), $user['full_name']), 'success');
        static::redirect($redirectUrl);
    }
}
