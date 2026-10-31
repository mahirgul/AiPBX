<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../services/OidcAuthService.php';

/**
 * OidcAuthController
 * OpenID Connect (OIDC) & Corporate SSO Web and Mobile Authorization / Callback Handler.
 */
class OidcAuthController extends BaseController
{
    /**
     * Redirects the user to the IdP authorization endpoint.
     */
    public static function auth(): void
    {
        if (!OidcAuthService::isEnabled()) {
            notify(t('oidc.err_disabled', 'Corporate SSO (OIDC) is not enabled on this PBX.'), 'warning');
            static::redirect('/login');
            return;
        }

        $isMobile = !empty($_GET['mobile']) || (isset($_GET['platform']) && in_array($_GET['platform'], ['android', 'ios'], true));

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $authUrl = OidcAuthService::getAuthUrl($isMobile);
        if (empty($authUrl)) {
            $lastErr = OidcAuthService::getLastError();
            notify(sprintf(t('oidc.err_config', 'OIDC configuration error: %s'), $lastErr ?: 'Endpoints unreachable'), 'danger');
            static::redirect('/login');
            return;
        }

        header('Location: ' . $authUrl);
        exit;
    }

    /**
     * Handles the callback from the IdP with authorization code.
     */
    public static function callback(): void
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. Check for IdP errors
        if (!empty($_GET['error'])) {
            $err = htmlspecialchars((string)$_GET['error']);
            $desc = htmlspecialchars((string)($_GET['error_description'] ?? ''));
            $msg = $desc ? "{$err}: {$desc}" : $err;
            notify(sprintf(t('oidc.err_cancelled', 'SSO authentication cancelled or failed (%s).'), $msg), 'warning');
            static::redirect('/login');
            return;
        }

        // 2. Validate state & CSRF token
        $state = (string)($_GET['state'] ?? '');
        $stateResult = OidcAuthService::verifyState($state);
        if (!$stateResult['valid']) {
            notify(t('oidc.err_state', 'Security validation timed out or state token is invalid. Please try again.'), 'danger');
            static::redirect('/login');
            return;
        }

        $isMobile = $stateResult['mobile'];

        // 3. Validate authorization code
        $code = trim((string)($_GET['code'] ?? ''));
        if (empty($code)) {
            notify(t('oidc.err_code', 'No authorization code received from identity provider.'), 'danger');
            static::redirect('/login');
            return;
        }

        // 4. Exchange authorization code for tokens
        $tokenData = OidcAuthService::exchangeCode($code);
        if (!$tokenData || empty($tokenData['access_token'])) {
            $lastErr = OidcAuthService::getLastError();
            notify(sprintf(t('oidc.err_token', 'Failed to retrieve access token from identity provider: %s'), $lastErr ?: 'Invalid response'), 'danger');
            static::redirect('/login');
            return;
        }

        // 5. Fetch user profile from UserInfo / ID Token
        $userInfo = OidcAuthService::getUserInfo(
            (string)$tokenData['access_token'],
            isset($tokenData['id_token']) ? (string)$tokenData['id_token'] : null
        );

        if (!$userInfo || empty($userInfo['email'])) {
            $lastErr = OidcAuthService::getLastError();
            notify(sprintf(t('oidc.err_userinfo', 'Could not obtain a verified email from identity provider: %s'), $lastErr ?: 'Missing email claim'), 'danger');
            static::redirect('/login');
            return;
        }

        $email = $userInfo['email'];

        // 6. Check domain allowlist
        if (!OidcAuthService::isDomainAllowed($email)) {
            $safeEmail = htmlspecialchars($email);
            notify(sprintf(t('oidc.err_domain', 'The email domain for %s is not permitted to sign in to this PBX.'), $safeEmail), 'danger');
            static::redirect('/login');
            return;
        }

        // 7. Lookup user or auto-provision
        $user = OidcAuthService::findUserByEmail($email);
        if (!$user) {
            $autoProvision = (OidcAuthService::getSetting('auto_provision', '1') === '1');
            if ($autoProvision) {
                $user = OidcAuthService::autoProvisionUser($userInfo);
            }

            if (!$user) {
                $safeEmail = htmlspecialchars($email);
                if ($isMobile) {
                    self::renderMobileCallback(false, t('srv_2fa.err_user', 'User not found'), null, sprintf(t('oidc.err_no_user', 'No user found for %s'), $email));
                    return;
                }
                notify(sprintf(t('oidc.err_no_user', 'No active AiPBX user is registered with this SSO account (%s). Please contact your administrator.'), $safeEmail), 'danger');
                static::redirect('/login');
                return;
            }
        }

        // 8. Check if user is active
        if (empty($user['is_active'])) {
            notify(t('oidc.err_inactive', 'Your account has been deactivated. Please contact your system administrator.'), 'danger');
            static::redirect('/login');
            return;
        }

        // 9. Two-factor authentication check
        if (!empty($user['two_factor_enabled'])) {
            if ($isMobile) {
                self::renderMobileCallback(false, t('google.otp_title', 'Two-step verification'), null, t('google.otp_text', 'Two-step verification is required.'));
                return;
            }
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_username'] = $user['username'];
            $_SESSION['pending_2fa_full_name'] = $user['full_name'];
            static::redirect('/login-2fa');
            return;
        }

        // 10. Mobile app sign-in deep link
        if ($isMobile) {
            require_once dirname(__DIR__) . '/services/QrLoginService.php';
            $codeRes = QrLoginService::createGoogleCode((int)$user['id']);
            if (empty($codeRes['success'])) {
                self::renderMobileCallback(false, t('mobile_api.login_failed', 'Login failed'), null, $codeRes['error'] ?? 'Token error');
                return;
            }
            if (function_exists('writeAuditLog')) {
                writeAuditLog(null, 'user_account', (string)$user['id'], "Mobile sign-in code created via OIDC SSO: {$user['username']}", 'login', (int)$user['id']);
            }
            self::renderMobileCallback(true, t('google.login_ok', 'Signed in'), ['code' => $codeRes['token']]);
            return;
        }

        // 11. Web login session
        $redirectUrl = OidcAuthService::loginUser($user, $clientIp);
        notify(sprintf(t('oidc.welcome', 'Welcome, %s! Signed in via Corporate SSO.'), htmlspecialchars((string)$user['full_name'])), 'success');
        static::redirect($redirectUrl);
    }

    /**
     * Builds the deep-link redirect screen for the mobile application.
     *
     * @param array<string, mixed>|null $data
     */
    private static function renderMobileCallback(bool $success, string $title, ?array $data = null, string $errorMessage = ''): void
    {
        $deepLink = 'aipbx://auth?success=' . ($success ? '1' : '0');
        if ($success && !empty($data['code'])) {
            $deepLink .= '&code=' . urlencode((string)$data['code']);
        } else {
            $deepLink .= '&error=' . urlencode($errorMessage);
        }

        header('Referrer-Policy: no-referrer');
        static::renderAuthPage('google_auth/mobile_callback', [
            'success'       => $success,
            'title'         => $title,
            'error_message' => $errorMessage,
            'deep_link'     => $deepLink,
        ], ['title' => $title, 'head' => '<meta name="referrer" content="no-referrer">']);
        exit;
    }
}
