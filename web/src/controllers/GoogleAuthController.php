<?php
/**
 * GoogleAuthController
 * Google OAuth 2.0 web and mobile authorization / callback handler
 */

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';

class GoogleAuthController extends BaseController
{
    /**
     * Sends the user to the Google authorization screen.
     */
    public static function auth(): void
    {
        if (!GoogleAuthService::isEnabled()) {
            notify(t('mobile_api.google_disabled'), 'warning');
            static::redirect('/login');
            return;
        }

        $isMobile = !empty($_GET['mobile']) || (isset($_GET['platform']) && in_array($_GET['platform'], ['android', 'ios']));
        // A new session id re-sends the session cookie with the current
        // attributes (SameSite=Lax): a cookie the browser got while it was
        // still Strict would otherwise be withheld on Google's redirect back (#9).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $authUrl = GoogleAuthService::getAuthUrl($isMobile);

        header('Location: ' . $authUrl);
        exit;
    }

    /**
     * Handles the authorization code returned by Google and signs the user in.
     */
    public static function callback(): void
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. Did the user cancel or did an error occur?
        if (isset($_GET['error'])) {
            $err = htmlspecialchars($_GET['error']);
            notify(sprintf(t('google.err_cancelled'), $err), 'warning');
            static::redirect('/login');
            return;
        }

        // 2. State & CSRF validation
        $state = $_GET['state'] ?? '';
        $stateResult = GoogleAuthService::verifyState($state);
        if (!$stateResult['valid']) {
            notify(t('google.err_state'), 'danger');
            static::redirect('/login');
            return;
        }

        $isMobile = $stateResult['mobile'];

        // 3. Authorization code check
        $code = trim($_GET['code'] ?? '');
        if (empty($code)) {
            notify(t('google.err_code'), 'danger');
            static::redirect('/login');
            return;
        }

        // 4. Kodu Token ile Takas Et
        $tokenData = GoogleAuthService::exchangeCode($code);
        if (!$tokenData || empty($tokenData['access_token'])) {
            notify(t('google.err_token'), 'danger');
            static::redirect('/login');
            return;
        }

        // 5. Get the user info
        $userInfo = null;
        if (!empty($tokenData['id_token'])) {
            $userInfo = GoogleAuthService::verifyIdToken($tokenData['id_token']);
        }
        if (!$userInfo && !empty($tokenData['access_token'])) {
            $userInfo = GoogleAuthService::getUserInfo($tokenData['access_token']);
        }

        $email = $userInfo['email'] ?? '';
        if (empty($email)) {
            notify(t('google.err_email'), 'danger');
            static::redirect('/login');
            return;
        }

        // 6. Find the AiPBX user by email
        $user = GoogleAuthService::findUserByEmail($email);
        if (!$user) {
            $safeEmail = htmlspecialchars($email);
            if ($isMobile) {
                // Raw email: the view escapes it (it used to be escaped twice).
                self::renderMobileCallback(false, t('srv_2fa.err_user'), null, sprintf(t('mobile_api.google_no_match'), $email));
                return;
            }

            notify(sprintf(t('google.err_no_user'), $safeEmail), 'danger');
            static::redirect('/login');
            return;
        }

        // On an account with two-step verification, Google sign-in must not skip 2FA.
        // Web: to the code step, as with password sign-in. Mobile: the flow returning
        // from the browser to the app has no code step — sign in with password + code or by QR.
        if (!empty($user['two_factor_enabled'])) {
            if ($isMobile) {
                self::renderMobileCallback(false, t('google.otp_title'), null, t('google.otp_text'));
                return;
            }
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_username'] = $user['username'];
            $_SESSION['pending_2fa_full_name'] = $user['full_name'];
            static::redirect('/login-2fa');
            return;
        }

        // 7. Mobile sign-in redirect
        if ($isMobile) {
            // The app gets a single-use 2-minute code, NOT the sign-in
            // response itself (session token + SIP password): another app can
            // register the aipbx:// scheme too and catch the URL. The app
            // exchanges the code for sign-in data with
            // /api/mobile/qr_login.php on the server where it started the
            // Google sign-in.
            require_once dirname(__DIR__) . '/services/QrLoginService.php';
            $codeRes = QrLoginService::createGoogleCode((int) $user['id']);
            if (empty($codeRes['success'])) {
                self::renderMobileCallback(false, t('mobile_api.login_failed'), null, $codeRes['error'] ?? t('google.err_mobile_code'));
                return;
            }
            writeAuditLog(null, 'user_account', $user['id'], "Mobile sign-in code created with Google: {$user['username']}", 'login', $user['id']);
            self::renderMobileCallback(true, t('google.login_ok'), ['code' => $codeRes['token']]);
            return;
        }

        // 8. Web sign-in and redirect
        $redirectUrl = GoogleAuthService::loginUser($user, $clientIp);
        notify(sprintf(t('google.welcome'), $user['full_name']), 'success');
        static::redirect($redirectUrl);
    }

    /**
     * Builds the deep-link redirect screen for the mobile app.
     */
    private static function renderMobileCallback(bool $success, string $title, ?array $data = null, string $errorMessage = ''): void
    {
        $deepLink = 'aipbx://auth?success=' . ($success ? '1' : '0');
        if ($success && !empty($data['code'])) {
            $deepLink .= '&code=' . urlencode($data['code']);
        } else {
            $deepLink .= '&error=' . urlencode($errorMessage);
        }

        // The single-use code is in the URL: it must not leak to another site via Referer.
        header('Referrer-Policy: no-referrer');
        static::renderAuthPage('google_auth/mobile_callback', [
            'success' => $success,
            'title' => $title,
            'error_message' => $errorMessage,
            'deep_link' => $deepLink,
        ], ['title' => $title, 'head' => '<meta name="referrer" content="no-referrer">']);
        exit;
    }
}
