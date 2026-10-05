<?php
/**
 * Shared entry point of the mobile JSON API: response helpers and the
 * Bearer token issue/validation used by the Android and iOS apps.
 */
require_once __DIR__ . '/../../config.php';

/**
 * Common JSON/CORS headers; answers the CORS preflight (OPTIONS) and ends it.
 */
function mobileApiStart(string $methods = 'GET, POST, OPTIONS'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: ' . $methods);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/** Sends a JSON response with the given HTTP status and ends the request. */
function mobileJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Shorthand for an error response: {"success": false, "error": ...}. */
function mobileError(string $error, int $status, array $extra = []): never
{
    mobileJson(['success' => false] + $extra + ['error' => $error], $status);
}

/** JSON request body as an array (empty when the body is not JSON). */
function mobileInput(): array
{
    $json = json_decode((string) file_get_contents('php://input'), true);
    return is_array($json) ? $json : [];
}

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
 * The signed text. With token_epoch 0 the old form ("id:exp") — so tokens
 * issued before the column was added stay valid. chat/auth.go does the same.
 */
function mobileTokenPayload(int $userId, int $expiresAt, int $epoch): string
{
    return $epoch > 0 ? "{$userId}:{$expiresAt}:{$epoch}" : "{$userId}:{$expiresAt}";
}

/**
 * Generate HMAC Bearer token for given user array or user ID
 */
function generateMobileToken($user, int $ttl = 2592000): string
{
    $userId = is_array($user) ? (int)$user['id'] : (int)$user;
    $stmt = getDB()->prepare('SELECT token_epoch FROM sys_users WHERE id = ?');
    $stmt->execute([$userId]);
    $epoch = (int) $stmt->fetchColumn();
    $expiresAt = time() + $ttl;
    $sig = hash_hmac('sha256', mobileTokenPayload($userId, $expiresAt, $epoch), getMobileTokenSecret());
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

    // DB user check - T-6: sip_password added so refresh.php does not return an empty password
    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, full_name, email, role, extension, extension_type, sip_password, is_active, token_epoch FROM sys_users WHERE id = ?');
    $stmt->execute([(int)$userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['is_active'])) {
        return null;
    }

    // HMAC verification (token_epoch included — old tokens drop when the password is reset)
    $payload = mobileTokenPayload((int)$userId, (int)$expiresAt, (int)$user['token_epoch']);
    $expected_sig = hash_hmac('sha256', $payload, getMobileTokenSecret());

    if (!hash_equals($expected_sig, $sig) || $userId !== (string)(int)$userId) {
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
        header('Content-Type: application/json; charset=utf-8');
        mobileError('Yetkisiz erişim! Geçersiz veya süresi dolmuş oturum tokenı.', 401);
    }

    return $user;
}

/**
 * Builds the standard session response package (token, SIP, TURN, push) for mobile clients.
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