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
                self::renderMobileCallback(false, 'Kullanıcı bulunamadı', null, "Google hesabınız ({$safeEmail}) ile kayıtlı bir AiPBX dahili kullanıcısı bulunamadı.");
                return;
            }

            notify("Google hesabınız ({$safeEmail}) ile kayıtlı aktif bir AiPBX kullanıcısı bulunamadı. Lütfen yöneticinizle iletişime geçin.", 'danger');
            static::redirect('/login');
            return;
        }

        // 7. Mobil Giriş Yönlendirmesi
        if ($isMobile) {
            require_once dirname(__DIR__, 2) . '/api/mobile/auth_helper.php';
            $mobileData = buildMobileLoginResponse($user);
            self::renderMobileCallback(true, 'Giriş Başarılı', $mobileData);
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
        if ($success && !empty($data['token'])) {
            $deepLink .= '&token=' . urlencode($data['token']) . '&data=' . urlencode(json_encode($data));
        } else {
            $deepLink .= '&error=' . urlencode($errorMessage);
        }

        echo '<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . ' - AiPBX</title>
    <link rel="stylesheet" href="/assets/css/variables.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="auth-body" style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
    <div class="auth-card" style="max-width:400px; text-align:center; padding:32px 24px; border-radius:16px;">
        <div style="font-size:48px; margin-bottom:16px; color:' . ($success ? 'var(--primary, #0284c7)' : 'var(--danger, #ef4444)') . ';">
            <i class="fas ' . ($success ? 'fa-check-circle' : 'fa-exclamation-circle') . '"></i>
        </div>
        <h2 style="font-size:20px; font-weight:800; margin:0 0 10px 0;">' . htmlspecialchars($title) . '</h2>
        <p style="font-size:14px; color:var(--text-muted); line-height:1.5; margin:0 0 24px 0;">
            ' . ($success ? 'Uygulamaya dönülüyor, lütfen bekleyin...' : htmlspecialchars($errorMessage)) . '
        </p>
        <a id="btnReturn" href="' . htmlspecialchars($deepLink) . '" class="btn btn-primary" style="width:100%; justify-content:center; padding:12px; font-weight:700;">
            ' . ($success ? 'Uygulamayı Aç' : 'Uygulamaya Geri Dön') . '
        </a>
    </div>
    <script>
        // Otomatik uygulamaya dönmeyi dene
        window.location.href = ' . json_encode($deepLink) . ';
        setTimeout(function() {
            var btn = document.getElementById("btnReturn");
            if (btn) btn.focus();
        }, 1500);
    </script>
</body>
</html>';
        exit;
    }
}
