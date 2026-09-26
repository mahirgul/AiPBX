<?php
require_once __DIR__ . '/../asterisk_sync.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Mobile QR Code Quick Login Service
 * Web "Dahilim" ekranında tek kullanımlık, süreli QR kod üretir ve mobil uygulamaların
 * bu kodu tarayarak anında oturum açmasını sağlar.
 */
class QrLoginService
{
    /** Kodun amacı → geçerlilik süresi (saniye). */
    const TTL = [
        'screen' => 600,        // web "Dahilim" ekranındaki QR
        'email'  => 7 * 86400,  // davet e-postasındaki mobil giriş bağlantısı
        'google' => 120,        // Google girişi → uygulamaya dönen kod
    ];

    const ANDROID_PACKAGE = 'com.mhrgl.AiPBX';

    /**
     * Oturum açmış kullanıcı için yeni bir mobil QR eşleştirme kodu ve QR görseli üretir.
     *
     * @param int $userId sys_users tablosundaki kullanıcı ID'si
     * @param int $ttlSeconds QR kod geçerlilik süresi (varsayılan 600 saniye = 10 dakika)
     * @return array{success: bool, qr_token?: string, qr_data_uri?: string, expires_at?: string, expires_in?: int, server_url?: string, error?: string}
     */
    public static function generateQr(int $userId, int $ttlSeconds = 600): array
    {
        $res = self::createToken($userId, 'screen', $ttlSeconds);
        if (!$res['success']) {
            return $res;
        }
        $serverUrl = self::serverUrl();
        $payload = self::payload($serverUrl, $res['token'], $res['user'], $res['expires_ts']);

        return [
            'success' => true,
            'qr_token' => $res['token'],
            'qr_data_uri' => self::renderQr($payload),
            'payload' => $payload,
            'expires_at' => $res['expires_at'],
            'expires_in' => $ttlSeconds,
            'server_url' => $serverUrl
        ];
    }

    /**
     * Davet e-postası için 7 gün geçerli, tek kullanımlık mobil giriş bağlantısı.
     * Bağlantı yalnızca /mobile-login sayfasını açar; kod, uygulama giriş
     * yaptığında harcanır — e-posta güvenlik tarayıcıları (Outlook Safe Links
     * vb.) bağlantıyı önceden açsa bile kod bozulmaz. Aynı kullanıcı için
     * önceki kullanılmamış e-posta kodları iptal edilir (yalnızca son davet geçerli).
     *
     * @return array{success: bool, url?: string, expires_at?: string, error?: string}
     */
    public static function createEmailLink(int $userId): array
    {
        $res = self::createToken($userId, 'email', self::TTL['email'], true);
        if (!$res['success']) {
            return $res;
        }
        return [
            'success' => true,
            'url' => self::serverUrl() . '/mobile-login?token=' . $res['token'],
            'expires_at' => $res['expires_at'],
        ];
    }

    /**
     * Google girişi sonrası uygulamaya aipbx://auth ile dönülecek kısa ömürlü kod.
     * Uygulama bunu /api/mobile/qr_login.php ile giriş bilgisine çevirir; oturum
     * token'ı ve SIP şifresi artık URL'de taşınmaz.
     */
    public static function createGoogleCode(int $userId): array
    {
        return self::createToken($userId, 'google', self::TTL['google']);
    }

    /**
     * /mobile-login sayfası için SALT-OKUNUR kontrol — kodu harcamaz.
     *
     * @return array{valid: bool, reason?: string, user?: array, server_url?: string, token?: string, payload?: array}
     */
    public static function inspectToken(string $token): array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        $stmt = getDB()->prepare('SELECT q.expires_at, q.used_at, q.purpose, u.id, u.username, u.full_name, u.extension, u.is_active
                                  FROM sys_user_qr_tokens q JOIN sys_users u ON u.id = q.user_id
                                  WHERE q.token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['purpose'] === 'google') {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        if (!empty($row['used_at'])) {
            return ['valid' => false, 'reason' => 'used'];
        }
        if (strtotime($row['expires_at']) < time()) {
            return ['valid' => false, 'reason' => 'expired'];
        }
        if (empty($row['is_active']) || empty($row['extension'])) {
            return ['valid' => false, 'reason' => 'inactive'];
        }

        $serverUrl = self::serverUrl();
        return [
            'valid' => true,
            'user' => $row,
            'server_url' => $serverUrl,
            'token' => $token,
            'payload' => self::payload($serverUrl, $token, $row, strtotime($row['expires_at'])),
        ];
    }

    /** Uygulamayı açan bağlantı (iOS ve genel). */
    public static function appLink(string $serverUrl, string $token): string
    {
        return 'aipbx://login?server=' . rawurlencode($serverUrl) . '&token=' . rawurlencode($token);
    }

    /**
     * Android için intent:// bağlantısı: uygulama yüklü değilse Chrome doğrudan
     * Play Store sayfasına düşer (browser_fallback_url).
     */
    public static function androidIntentLink(string $serverUrl, string $token): string
    {
        $fallback = 'https://play.google.com/store/apps/details?id=' . self::ANDROID_PACKAGE;
        return 'intent://login?server=' . rawurlencode($serverUrl) . '&token=' . rawurlencode($token)
            . '#Intent;scheme=aipbx;package=' . self::ANDROID_PACKAGE
            . ';S.browser_fallback_url=' . rawurlencode($fallback) . ';end';
    }

    /** QR içeriğini SVG data URI olarak çizer (uygulamanın okuduğu JSON). */
    public static function renderQr(array $payload): string
    {
        if (!class_exists(QRCode::class)) {
            return '';
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'eccLevel' => QRCode::ECC_M,
                'addQuietzone' => true,
                'scale' => 5,
            ]);
            return (new QRCode($options))->render($payloadJson);
        } catch (\Throwable $e) {
            return (new QRCode())->render($payloadJson);
        }
    }

    /**
     * @return array{success: bool, token?: string, user?: array, expires_at?: string, expires_ts?: int, error?: string}
     */
    private static function createToken(int $userId, string $purpose, int $ttlSeconds, bool $revokePrevious = false): array
    {
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Geçersiz kullanıcı oturumu!'];
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, full_name, extension, is_active FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['is_active'])) {
            return ['success' => false, 'error' => 'Kullanıcı hesabı bulunamadı veya pasif durumda.'];
        }

        if (empty($user['extension'])) {
            return ['success' => false, 'error' => 'Bu kullanıcıya atanmış bir dahili numara bulunmuyor. Mobil giriş için dahili zorunludur.'];
        }

        // Eski kullanılmamış token'ları temizle
        $db->prepare('DELETE FROM sys_user_qr_tokens WHERE user_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())')->execute([$userId]);
        if ($revokePrevious) {
            $db->prepare('DELETE FROM sys_user_qr_tokens WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$userId, $purpose]);
        }

        // Güvenli 256-bit rastgele token
        $token = bin2hex(random_bytes(32));
        $expTimestamp = time() + $ttlSeconds;
        $expiresAt = date('Y-m-d H:i:s', $expTimestamp);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $ins = $db->prepare('INSERT INTO sys_user_qr_tokens (user_id, token, purpose, expires_at, ip_address) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$userId, $token, $purpose, $expiresAt, $clientIp]);

        return ['success' => true, 'token' => $token, 'user' => $user, 'expires_at' => $expiresAt, 'expires_ts' => $expTimestamp];
    }

    private static function serverUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
        return $scheme . '://' . $host;
    }

    /** Uygulamanın QR'dan okuduğu JSON yükü (Android/iOS handleScannedQr). */
    private static function payload(string $serverUrl, string $token, array $user, int $expTs): array
    {
        return [
            'type' => 'aipbx_qr_login',
            'v' => 1,
            'server' => $serverUrl,
            'qr_token' => $token,
            'ext' => $user['extension'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'exp' => $expTs
        ];
    }

    /**
     * QR kodun taranıp taranmadığını kontrol eder (Web tarafında durum yoklaması için).
     */
    public static function checkStatus(string $token): array
    {
        if (empty($token)) {
            return ['used' => false, 'expired' => true];
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT used_at, expires_at, device_name FROM sys_user_qr_tokens WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['used' => false, 'expired' => true];
        }

        $expired = strtotime($row['expires_at']) < time();
        $used = !empty($row['used_at']);

        return [
            'used' => $used,
            'expired' => $expired,
            'device_name' => $row['device_name'] ?? null,
            'used_at' => $row['used_at'] ?? null
        ];
    }

    /**
     * Mobil uygulamanın QR kodu tarayarak oturum açmasını sağlar.
     * Başarılı olursa login.php ile birebir aynı LoginResponse formatında yanıt döner.
     *
     * @param string $qrToken QR koddan okunan tek kullanımlık token
     * @param string $deviceName Mobil cihaz adı (örn: "Samsung SM-S918B" veya "iPhone 15 Pro")
     * @param string $clientIp İstemci IP adresi
     * @return array{success: bool, response?: array, error?: string, code?: int}
     */
    public static function authenticateMobile(string $qrToken, string $deviceName, string $clientIp): array
    {
        $qrToken = trim($qrToken);
        if (empty($qrToken)) {
            return ['success' => false, 'error' => 'QR kod anahtarı (qr_token) gereklidir.', 'code' => 400];
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT q.id AS token_id, q.user_id, q.expires_at, q.used_at,
                                     u.id, u.username, u.full_name, u.role, u.extension,
                                     u.extension_type, u.sip_password, u.is_active
                              FROM sys_user_qr_tokens q
                              JOIN sys_users u ON q.user_id = u.id
                              WHERE q.token = ?
                              LIMIT 1');
        $stmt->execute([$qrToken]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return ['success' => false, 'error' => 'Geçersiz QR kod! Lütfen web ekranından yeni bir QR kod üretin.', 'code' => 401];
        }

        if (!empty($record['used_at'])) {
            return ['success' => false, 'error' => 'Bu QR kod daha önce kullanılmış. Güvenlik nedeniyle her QR kod yalnızca tek seferliktir.', 'code' => 401];
        }

        if (strtotime($record['expires_at']) < time()) {
            return ['success' => false, 'error' => 'Giriş kodunun süresi dolmuş. Lütfen yeni bir QR kod veya davet bağlantısı isteyin.', 'code' => 401];
        }

        if (empty($record['is_active'])) {
            return ['success' => false, 'error' => 'Kullanıcı hesabı devre dışıdır.', 'code' => 403];
        }

        if (empty($record['extension'])) {
            return ['success' => false, 'error' => 'Bu kullanıcıya atanmış bir dahili numara bulunmamaktadır.', 'code' => 400];
        }

        // Token'ı kullanıldı olarak işaretle
        $upd = $db->prepare('UPDATE sys_user_qr_tokens SET used_at = NOW(), device_name = ?, ip_address = ? WHERE id = ?');
        $upd->execute([$deviceName, $clientIp, $record['token_id']]);

        // Giriş denemesini logla
        if (function_exists('logLoginAttempt')) {
            logLoginAttempt($clientIp, $record['username'], 'SUCCESS');
        }

        // Coturn TURNS
        $turn = null;
        if (defined('TURN_SECRET') && TURN_SECRET !== '') {
            $turn_username = (time() + 2592000) . ':' . $record['extension'];
            $turn_password = base64_encode(hash_hmac('sha1', $turn_username, TURN_SECRET, true));
            $turn_host = defined('TURN_HOST') && TURN_HOST !== '' ? TURN_HOST : ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
            $turn_port = defined('TURNS_PORT') && TURNS_PORT !== '' ? TURNS_PORT : '443';
            $turn = [
                'username' => $turn_username,
                'credential' => $turn_password,
                'urls' => [
                    'turns:' . $turn_host . ':' . $turn_port . '?transport=tcp'
                ]
            ];
        }

        require_once dirname(__DIR__, 2) . '/api/mobile/auth_helper.php';
        $token = generateMobileToken($record, 30 * 86400);

        $host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
        $host_parts = explode(':', $host);
        $domain = $host_parts[0];
        $ws_path = getSystemSetting('pjsip_ws_path', '/ws');

        $push_config = [
            'enabled' => getSystemSetting('push_enabled', '0') === '1',
            'provider' => getSystemSetting('push_provider', 'none'),
            'fcm_project_id' => getSystemSetting('push_fcm_project_id', ''),
            'fcm_app_id' => getSystemSetting('push_fcm_app_id', ''),
            'fcm_api_key' => getSystemSetting('push_fcm_api_key', ''),
            'fcm_sender_id' => getSystemSetting('push_fcm_sender_id', ''),
        ];

        writeAuditLog(null, 'user_account', $record['id'], "Mobil QR kod ile giriş başarılı: {$record['username']} ({$deviceName})", 'login', $record['id']);

        $loginResponse = [
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$record['id'],
                'username' => $record['username'],
                'full_name' => $record['full_name'],
                'extension' => $record['extension'],
                'role' => $record['role']
            ],
            'sip' => [
                'extension' => $record['extension'],
                'sip_username' => $record['extension'] . '-mob-webrtc',
                'native_sip_username' => $record['extension'],
                'webrtc_username' => $record['extension'] . '-mob-webrtc',
                'sip_password' => $record['sip_password'] ?? '',
                'domain' => $domain,
                'sip_port' => 5060,
                'ws_url' => 'wss://' . $domain . $ws_path,
                'turn' => $turn
            ],
            'push_config' => $push_config
        ];

        return ['success' => true, 'response' => $loginResponse];
    }
}
