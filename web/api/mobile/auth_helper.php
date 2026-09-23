<?php
/**
 * Mobile API Authentication Helper & Bearer Token Validator
 */
require_once __DIR__ . '/../../config.php';

function getMobileTokenSecret(): string
{
    if (defined('CHAT_JWT_SECRET') && CHAT_JWT_SECRET !== '') {
        return CHAT_JWT_SECRET;
    }
    if (defined('TURN_SECRET') && TURN_SECRET !== '') {
        return TURN_SECRET;
    }
    return DB_PASS;
}

/**
 * Generate HMAC Bearer token for given user array or user ID
 */
function generateMobileToken($user, int $ttl = 2592000): string
{
    $userId = is_array($user) ? (int)$user['id'] : (int)$user;
    $expiresAt = time() + $ttl;
    $payload = $userId . ':' . $expiresAt;
    $secret_key = getMobileTokenSecret();
    $sig = hash_hmac('sha256', $payload, $secret_key);
    return base64_encode($userId . ':' . $expiresAt . ':' . $sig);
}

/**
 * Extract Bearer token from HTTP Authorization header or query parameter
 */
function getMobileBearerToken(): ?string
{
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    if (!empty($headers) && preg_match('/Bearer\s+(\S+)/i', $headers, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Validate HMAC Bearer token and return user array if valid
 */
function validateMobileToken(?string $token): ?array
{
    if (empty($token)) {
        return null;
    }

    $decoded = base64_decode($token, true);
    if ($decoded === false) {
        return null;
    }

    $parts = explode(':', $decoded);
    if (count($parts) !== 3) {
        return null;
    }

    [$userId, $expiresAt, $sig] = $parts;

    // Expiry check
    if (time() > (int)$expiresAt) {
        return null;
    }

    // HMAC verification
    $payload = $userId . ':' . $expiresAt;
    $secret_key = getMobileTokenSecret();
    $expected_sig = hash_hmac('sha256', $payload, $secret_key);

    if (!hash_equals($expected_sig, $sig)) {
        return null;
    }

    // DB user check - T-6: sip_password eklendi ki refresh.php parolayı boş döndürmesin
    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, full_name, role, extension, extension_type, sip_password, is_active FROM sys_users WHERE id = ?');
    $stmt->execute([(int)$userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['is_active'])) {
        return null;
    }

    return $user;
}

/**
 * Require valid mobile authentication or terminate with 401
 */
function requireMobileAuth(): array
{
    $token = getMobileBearerToken();
    $user = validateMobileToken($token);

    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Yetkisiz erişim! Geçersiz veya süresi dolmuş oturum tokenı.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $user;
}

/**
 * Mobil istemciler için standart oturum yanıt paketini (token, SIP, TURN, push) oluşturur.
 */
function buildMobileLoginResponse(array $user): array
{
    $token = generateMobileToken($user, 30 * 86400);

    $webrtc_suffix = getSystemSetting('webrtc_username_suffix', '-webrtc');
    $ws_path = getSystemSetting('pjsip_ws_path', '/ws');

    // Coturn TURNS (TLS/TCP)
    $turn = null;
    if (defined('TURN_SECRET') && TURN_SECRET !== '') {
        $turn_username = (time() + 2592000) . ':' . $user['extension'];
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

    $host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
    $host_parts = explode(':', $host);
    $domain = $host_parts[0];

    $push_config = [
        'enabled' => getSystemSetting('push_enabled', '0') === '1',
        'provider' => getSystemSetting('push_provider', 'none'),
        'fcm_project_id' => getSystemSetting('push_fcm_project_id', ''),
        'fcm_app_id' => getSystemSetting('push_fcm_app_id', ''),
        'fcm_api_key' => getSystemSetting('push_fcm_api_key', ''),
        'fcm_sender_id' => getSystemSetting('push_fcm_sender_id', ''),
    ];

    return [
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'] ?? null,
            'extension' => $user['extension'],
            'role' => $user['role']
        ],
        'sip' => [
            'extension' => $user['extension'],
            'sip_username' => $user['extension'] . '-mob-webrtc',
            'native_sip_username' => $user['extension'],
            'webrtc_username' => $user['extension'] . '-mob-webrtc',
            'sip_password' => $user['sip_password'] ?? '',
            'domain' => $domain,
            'sip_port' => 5060,
            'ws_url' => 'wss://' . $domain . $ws_path,
            'turn' => $turn
        ],
        'push_config' => $push_config
    ];
}