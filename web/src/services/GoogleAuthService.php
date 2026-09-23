<?php
/**
 * GoogleAuthService (Google OAuth 2.0 & OpenID Connect Kimlik Doğrulama Servisi)
 * Kullanıcıların Google hesaplarıyla Web portalına ve Mobil uygulamalara şifresiz giriş yapmasını sağlar.
 */

class GoogleAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /**
     * Google ile giriş özelliğinin aktif olup olmadığını kontrol eder.
     */
    public static function isEnabled(): bool
    {
        $enabled = function_exists('getSystemSetting') ? getSystemSetting('google_oauth_enabled', '0') : '0';
        return $enabled === '1' && !empty(self::getClientId());
    }

    /**
     * Google OAuth Client ID
     */
    public static function getClientId(): string
    {
        return function_exists('getSystemSetting') ? trim(getSystemSetting('google_client_id', '')) : '';
    }

    /**
     * Google OAuth Client Secret
     */
    public static function getClientSecret(): string
    {
        return function_exists('getSystemSetting') ? trim(getSystemSetting('google_client_secret', '')) : '';
    }

    /**
     * Web yönlendirme geri çağırma (Redirect URI) adresini üretir.
     */
    public static function getRedirectUri(): string
    {
        $customUri = function_exists('getSystemSetting') ? trim(getSystemSetting('google_redirect_uri', '')) : '';
        if (!empty($customUri)) {
            return $customUri;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host)[0]);
        }
        return $scheme . '://' . $host . '/auth/google/callback';
    }

    /**
     * OAuth state parametresi üretir ve oturuma kaydeder.
     */
    public static function generateState(bool $isMobile = false): string
    {
        $stateData = [
            'csrf' => bin2hex(random_bytes(16)),
            'mobile' => $isMobile ? 1 : 0,
            'time' => time()
        ];
        $state = base64_encode(json_encode($stateData));
        if (session_status() === PHP_SESSION_ACTIVE || !headers_sent()) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $_SESSION['google_oauth_state'] = $state;
        }
        return $state;
    }

    /**
     * Google OAuth yetkilendirme yönlendirme URL'sini üretir.
     */
    public static function getAuthUrl(bool $isMobile = false): string
    {
        $state = self::generateState($isMobile);

        $params = [
            'client_id' => self::getClientId(),
            'redirect_uri' => self::getRedirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account'
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Gelen OAuth state parametresini doğrular.
     * @return array{valid:bool, mobile:bool}
     */
    public static function verifyState(?string $state): array
    {
        if (empty($state) || empty($_SESSION['google_oauth_state'])) {
            return ['valid' => false, 'mobile' => false];
        }

        if (!hash_equals($_SESSION['google_oauth_state'], $state)) {
            return ['valid' => false, 'mobile' => false];
        }

        $decoded = json_decode(base64_decode($state), true);
        unset($_SESSION['google_oauth_state']);

        if (!is_array($decoded) || empty($decoded['time']) || (time() - $decoded['time']) > 600) {
            return ['valid' => false, 'mobile' => false];
        }

        return [
            'valid' => true,
            'mobile' => !empty($decoded['mobile'])
        ];
    }

    /**
     * Authorization code parametresini Google Access/ID token ile takas eder.
     */
    public static function exchangeCode(string $code): ?array
    {
        $clientId = self::getClientId();
        $clientSecret = self::getClientSecret();
        $redirectUri = self::getRedirectUri();

        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Access token ile Google kullanıcı profilini çeker.
     */
    public static function getUserInfo(string $accessToken): ?array
    {
        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Mobil uygulamalardan (Android / iOS) gelen Google id_token'ı doğrular.
     */
    public static function verifyIdToken(string $idToken): ?array
    {
        if (empty($idToken)) {
            return null;
        }

        $url = self::TOKENINFO_URL . '?id_token=' . urlencode($idToken);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['email'])) {
            return null;
        }

        // E-posta Google tarafından doğrulanmış mı?
        $verified = $data['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true' && $verified !== 1 && $verified !== '1') {
            return null;
        }

        return $data;
    }

    /**
     * Veritabanında e-posta adresi ile eşleşen aktif kullanıcıyı bulur.
     */
    public static function findUserByEmail(string $email): ?array
    {
        $cleanEmail = trim(mb_strtolower($email));
        if (empty($cleanEmail)) {
            return null;
        }

        $db = getDB();
        $stmt = $db->prepare('
            SELECT id, username, full_name, email, role, extension, extension_type,
                   sip_password, theme_preference, language_preference, is_active, must_reset_password
            FROM sys_users
            WHERE LOWER(TRIM(email)) = ? AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$cleanEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Web oturumu başlatır (şifre doğrulanmış gibi oturum değişkenlerini doldurur).
     */
    public static function loginUser(array $user, string $clientIp = '127.0.0.1'): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['extension'] = $user['extension'];
        $_SESSION['last_activity'] = time();
        $_SESSION['theme'] = $user['theme_preference'] ?? 'light';
        $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$user['language_preference'] ?? '']))
            ? $user['language_preference']
            : 'tr';

        unset($_SESSION['captcha_num1'], $_SESSION['captcha_num2']);

        // Loglama
        if (function_exists('logLoginAttempt')) {
            logLoginAttempt($clientIp, $user['username'], 'SUCCESS');
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog($user['id'], 'sys_users', $user['id'], "Kullanıcı '{$user['username']}' Google ({$user['email']}) ile giriş yaptı.", 'google_login');
        }

        // Rol yönlendirmesi
        if ($user['role'] === 'admin') {
            return '/dashboard';
        } elseif ($user['role'] === 'cc_agent') {
            return '/cc-agent';
        } elseif ($user['role'] === 'cc_manager') {
            return '/cc-supervisor';
        } else {
            return '/fax-inbox';
        }
    }

    /**
     * Google ayarlarını günceller.
     */
    public static function saveSettings(array $post): array
    {
        $enabled = !empty($post['google_oauth_enabled']) ? '1' : '0';
        $clientId = trim($post['google_client_id'] ?? '');
        $clientSecret = trim($post['google_client_secret'] ?? '');

        if ($enabled === '1' && empty($clientId)) {
            return ['success' => false, 'error' => 'Google ile giriş açıldığında Client ID boş bırakılamaz.'];
        }

        try {
            $db = getDB();
            $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');

            $stmt->execute(['google_oauth_enabled', $enabled]);
            $stmt->execute(['google_client_id', $clientId]);
            if (!empty($clientSecret) || $enabled === '0') {
                $stmt->execute(['google_client_secret', $clientSecret]);
            }

            return ['success' => true, 'message' => 'Google ile giriş ayarları başarıyla kaydedildi.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Ayarlar kaydedilemedi: ' . $e->getMessage()];
        }
    }
}
