<?php
require_once __DIR__ . '/../asterisk_sync.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;

/**
 * Mobile QR Code Quick Login Service
 * Issues a one-time, time-limited QR code on the web "My Phone" screen and
 * lets the mobile apps sign in instantly by scanning it.
 */
class QrLoginService
{
    /** Purpose of the code → lifetime (seconds). */
    const TTL = [
        'screen' => 600,        // QR on the web "My Phone" screen
        'email'  => 7 * 86400,  // mobile sign-in link in the invitation email
        'google' => 120,        // Google sign-in → code handed back to the app
    ];

    const ANDROID_PACKAGE = 'com.mhrgl.AiPBX';

    /**
     * Issues a new mobile QR pairing code and QR image for the signed-in user.
     *
     * @param int $userId user ID in sys_users
     * @param int $ttlSeconds QR code lifetime (default 600 seconds = 10 minutes)
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
     * One-time mobile sign-in link valid for 7 days, for the invitation email.
     * The link only opens the /mobile-login page; the code is spent when the
     * app signs in — so it survives email security scanners (Outlook Safe
     * Links etc.) opening the link beforehand. Earlier unused email codes of
     * the same user are cancelled (only the latest invitation is valid).
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
     * Short-lived code handed back to the app through aipbx://auth after a
     * Google sign-in. The app exchanges it for sign-in data via
     * /api/mobile/qr_login.php; the session token and SIP password no longer
     * travel in the URL.
     */
    public static function createGoogleCode(int $userId): array
    {
        return self::createToken($userId, 'google', self::TTL['google']);
    }

    /**
     * READ-ONLY check for the /mobile-login page — does not spend the code.
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

    /** Link that opens the app (iOS and generic). */
    public static function appLink(string $serverUrl, string $token): string
    {
        return 'aipbx://login?server=' . rawurlencode($serverUrl) . '&token=' . rawurlencode($token);
    }

    /**
     * intent:// link for Android: if the app is not installed, Chrome goes
     * straight to the Play Store page (browser_fallback_url).
     */
    public static function androidIntentLink(string $serverUrl, string $token): string
    {
        $fallback = 'https://play.google.com/store/apps/details?id=' . self::ANDROID_PACKAGE;
        return 'intent://login?server=' . rawurlencode($serverUrl) . '&token=' . rawurlencode($token)
            . '#Intent;scheme=aipbx;package=' . self::ANDROID_PACKAGE
            . ';S.browser_fallback_url=' . rawurlencode($fallback) . ';end';
    }

    /** Draws the QR content as an SVG data URI (the JSON the app reads). */
    public static function renderQr(array $payload): string
    {
        if (!class_exists(QRCode::class)) {
            return '';
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // php-qrcode v6 API: the old QRCode::OUTPUT_MARKUP_SVG / ECC_M
        // constants were removed — the previous code failed every time and
        // fell back to the option-less path.
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'scale' => 5,
        ]);
        return (new QRCode($options))->render($payloadJson);
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

        // Clean up old unused tokens
        $db->prepare('DELETE FROM sys_user_qr_tokens WHERE user_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())')->execute([$userId]);
        if ($revokePrevious) {
            $db->prepare('DELETE FROM sys_user_qr_tokens WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$userId, $purpose]);
        }

        // Secure 256-bit random token
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

    /** JSON payload the app reads from the QR (Android/iOS handleScannedQr). */
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
     * Checks whether the QR code has been scanned (for status polling on the web side).
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
     * Lets the mobile app sign in by scanning the QR code.
     * On success it answers in exactly the same LoginResponse format as login.php.
     *
     * @param string $qrToken one-time token read from the QR code
     * @param string $deviceName mobile device name (e.g. "Samsung SM-S918B" or "iPhone 15 Pro")
     * @param string $clientIp client IP address
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

        // Mark the token as used
        $upd = $db->prepare('UPDATE sys_user_qr_tokens SET used_at = NOW(), device_name = ?, ip_address = ? WHERE id = ?');
        $upd->execute([$deviceName, $clientIp, $record['token_id']]);

        // Log the sign-in attempt
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
