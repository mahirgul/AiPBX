<?php
/**
 * GoogleAuthController
 * Google OAuth 2.0 Web ve Mobil Yetkilendirme / Geri Çağırma Yöneticisi
 */

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';

class GoogleAuthController extends BaseController
{
    /**
     * Kullanıcıyı Google yetkilendirme ekranına yönlendirir.
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
     * Google'dan dönen yetkilendirme kodunu işler ve oturumu açar.
     */
    public static function callback(): void
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. Kullanıcı iptal etti mi veya hata oluştu mu?
        if (isset($_GET['error'])) {
            $err = htmlspecialchars($_GET['error']);
            notify('Google girişi tamamlanamadı veya iptal edildi (' . $err . ').', 'warning');
            static::redirect('/login');
            return;
        }

        // 2. State & CSRF Doğrulaması
        $state = $_GET['state'] ?? '';
        $stateResult = GoogleAuthService::verifyState($state);
        if (!$stateResult['valid']) {
            notify('Güvenlik doğrulaması zaman aşımına uğradı. Lütfen tekrar deneyin.', 'danger');
            static::redirect('/login');
            return;
        }

        $isMobile = $stateResult['mobile'];

        // 3. Authorization Code Kontrolü
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

        // 5. Kullanıcı Bilgilerini Al
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

        // 6. E-posta ile AiPBX Kullanıcısını Bul
        $user = GoogleAuthService::findUserByEmail($email);
        if (!$user) {
            $safeEmail = htmlspecialchars($email);
            if ($isMobile) {
                // Ham e-posta: görünüm kaçışlıyor (önceden çift kaçışlanıyordu).
                self::renderMobileCallback(false, 'Kullanıcı bulunamadı', null, "Google hesabınız ({$email}) ile kayıtlı bir AiPBX dahili kullanıcısı bulunamadı.");
                return;
            }

            notify("Google hesabınız ({$safeEmail}) ile kayıtlı aktif bir AiPBX kullanıcısı bulunamadı. Lütfen yöneticinizle iletişime geçin.", 'danger');
            static::redirect('/login');
            return;
        }

        // İki adımlı doğrulama açık hesapta Google girişi 2FA'yı atlamasın.
        // Web: şifreli girişteki gibi kod adımına. Mobil: tarayıcıdan uygulamaya
        // dönen akışta kod adımı yok — şifre + kod ya da QR ile girilir.
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

        // 7. Mobil Giriş Yönlendirmesi
        if ($isMobile) {
            // Uygulamaya giriş yanıtının kendisi (oturum token'ı + SIP şifresi)
            // DEĞİL, 2 dakikalık tek kullanımlık bir kod dönülür: aipbx://
            // şemasını başka bir uygulama da kaydedebilir ve URL'yi yakalayabilir.
            // Uygulama kodu, Google girişini başlattığı sunucuda
            // /api/mobile/qr_login.php ile giriş bilgisine çevirir.
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

        // 8. Web Girişi ve Yönlendirme
        $redirectUrl = GoogleAuthService::loginUser($user, $clientIp);
        notify("Hoş geldiniz, {$user['full_name']}! Google hesabınızla başarıyla giriş yaptınız.", 'success');
        static::redirect($redirectUrl);
    }

    /**
     * Mobil uygulama için deep link yönlendirme ekranı oluşturur.
     */
    private static function renderMobileCallback(bool $success, string $title, ?array $data = null, string $errorMessage = ''): void
    {
        $deepLink = 'aipbx://auth?success=' . ($success ? '1' : '0');
        if ($success && !empty($data['code'])) {
            $deepLink .= '&code=' . urlencode($data['code']);
        } else {
            $deepLink .= '&error=' . urlencode($errorMessage);
        }

        // Tek kullanımlık kod URL'de: başka siteye Referer ile sızmasın.
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
