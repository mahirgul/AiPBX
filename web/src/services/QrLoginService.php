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
    /**
     * Oturum açmış kullanıcı için yeni bir mobil QR eşleştirme kodu ve QR görseli üretir.
     *
     * @param int $userId sys_users tablosundaki kullanıcı ID'si
     * @param int $ttlSeconds QR kod geçerlilik süresi (varsayılan 600 saniye = 10 dakika)
     * @return array{success: bool, qr_token?: string, qr_data_uri?: string, expires_at?: string, expires_in?: int, server_url?: string, error?: string}
     */
    public static function generateQr(int $userId, int $ttlSeconds = 600): array
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

        // Güvenli 256-bit rastgele token
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expTimestamp = $now + $ttlSeconds;
        $expiresAt = date('Y-m-d H:i:s', $expTimestamp);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $ins = $db->prepare('INSERT INTO sys_user_qr_tokens (user_id, token, expires_at, ip_address) VALUES (?, ?, ?, ?)');
        $ins->execute([$userId, $token, $expiresAt, $clientIp]);

        // Sunucu URL'sini belirle
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
        $serverUrl = $scheme . '://' . $host;

        // QR kod içine konulacak güvenli JSON yükü
        $payload = [
            'type' => 'aipbx_qr_login',
            'v' => 1,
            'server' => $serverUrl,
            'qr_token' => $token,
            'ext' => $user['extension'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'exp' => $expTimestamp
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // QR SVG / Data URI üret
        $qrDataUri = '';
        if (class_exists(QRCode::class)) {
            try {
                $options = new QROptions([
                    'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                    'eccLevel' => QRCode::ECC_M,
                    'addQuietzone' => true,
                    'scale' => 5,
                ]);
                $qrDataUri = (new QRCode($options))->render($payloadJson);
            } catch (\Throwable $e) {
                // Fallback direct base64
                $qrDataUri = (new QRCode())->render($payloadJson);
            }
        }

        return [
            'success' => true,
            'qr_token' => $token,
            'qr_data_uri' => $qrDataUri,
            'payload' => $payload,
            'expires_at' => $expiresAt,
            'expires_in' => $ttlSeconds,
            'server_url' => $serverUrl
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
            return ['success' => false, 'error' => 'QR kodun geçerlilik süresi (10 dakika) dolmuş. Lütfen web ekranından yeni bir QR kod üretin.', 'code' => 401];
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
