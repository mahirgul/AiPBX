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
            notify('Google ile giriş şu anda sistemde etkin değildir.', 'warning');
            static::redirect('/login');
            return;
        }

        $isMobile = !empty($_GET['mobile']) || (isset($_GET['platform']) && in_array($_GET['platform'], ['android', 'ios']));
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
            notify('Google girişi tamamlanamadı veya iptal edildi (' . $err . ').', 'warning');
            static::redirect('/login');
            return;
        }

        // 2. State & CSRF validation
        $state = $_GET['state'] ?? '';
        $stateResult = GoogleAuthService::verifyState($state);
        if (!$stateResult['valid']) {
            notify('Güvenlik doğrulaması zaman aşımına uğradı. Lütfen tekrar deneyin.', 'danger');
            static::redirect('/login');
            return;
        }

        $isMobile = $stateResult['mobile'];

        // 3. Authorization code check
        $code = trim($_GET['code'] ?? '');
        if (empty($code)) {
            notify('Google yetkilendirme kodu alınamadı.', 'danger');
            static::redirect('/login');
            return;
        }

        // 4. Kodu Token ile Takas Et
        $tokenData = GoogleAuthService::exchangeCode($code);
        if (!$tokenData || empty($tokenData['access_token'])) {
            notify('Google sunucularından kimlik doğrulaması alınamadı.', 'danger');
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
            notify('Google hesabınızdan doğrulanmış bir e-posta adresi temin edilemedi.', 'danger');
            static::redirect('/login');
            return;
        }

        // 6. Find the AiPBX user by email
        $user = GoogleAuthService::findUserByEmail($email);
        if (!$user) {
            $safeEmail = htmlspecialchars($email);
            if ($isMobile) {
                // Raw email: the view escapes it (it used to be escaped twice).
                self::renderMobileCallback(false, 'Kullanıcı bulunamadı', null, "Google hesabınız ({$email}) ile kayıtlı bir AiPBX dahili kullanıcısı bulunamadı.");
                return;
            }

            notify("Google hesabınız ({$safeEmail}) ile kayıtlı aktif bir AiPBX kullanıcısı bulunamadı. Lütfen yöneticinizle iletişime geçin.", 'danger');
            static::redirect('/login');
            return;
        }

        // On an account with two-step verification, Google sign-in must not skip 2FA.
        // Web: to the code step, as with password sign-in. Mobile: the flow returning
        // from the browser to the app has no code step — sign in with password + code or by QR.
        if (!empty($user['two_factor_enabled'])) {
            if ($isMobile) {
                self::renderMobileCallback(false, 'İki adımlı doğrulama', null, 'Bu hesapta iki adımlı doğrulama açık. Uygulamaya kullanıcı adı, şifre ve doğrulama koduyla ya da web portalındaki QR kodla girin.');
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
                self::renderMobileCallback(false, 'Giriş yapılamadı', null, $codeRes['error'] ?? 'Mobil giriş kodu oluşturulamadı.');
                return;
            }
            writeAuditLog(null, 'user_account', $user['id'], "Google ile mobil giriş kodu üretildi: {$user['username']}", 'login', $user['id']);
            self::renderMobileCallback(true, 'Giriş Başarılı', ['code' => $codeRes['token']]);
            return;
        }

        // 8. Web sign-in and redirect
        $redirectUrl = GoogleAuthService::loginUser($user, $clientIp);
        notify("Hoş geldiniz, {$user['full_name']}! Google hesabınızla başarıyla giriş yaptınız.", 'success');
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
