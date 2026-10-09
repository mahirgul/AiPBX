<?php

namespace App\Services\Push;

use PDO;
use Exception;

require_once __DIR__ . '/PushProviderInterface.php';
require_once __DIR__ . '/../../../config.php';

/**
 * Apple Push Notification service (APNs) provider for the iOS app.
 *
 * Uses token-based authentication (an Apple .p8 signing key, ES256 JWT) over
 * HTTP/2. None of the Apple values can be guessed; the admin enters them on the
 * Mobile Notifications page:
 *   - push_apns_key_id      10-character Key ID of the .p8 key
 *   - push_apns_team_id     10-character Apple Developer Team ID
 *   - push_apns_bundle_id   the app's bundle id (default com.mhrgl.AiPBX)
 *   - push_apns_key         contents of AuthKey_XXXXXXXXXX.p8
 *   - push_apns_environment 'production' (App Store / TestFlight / ad hoc / enterprise)
 *                           or 'sandbox' (builds signed with a development profile)
 *
 * iOS devices register two tokens (api/mobile/fcm_token.php):
 *   - fcm_token  (push_type = 'apns'): regular alert pushes (chat, test)
 *   - voip_token: PushKit VoIP pushes that wake the app for an incoming call.
 *     Without a VoIP token the incoming call falls back to an alert.
 */
class ApnsPushProvider implements PushProviderInterface
{
    private const HOST_PRODUCTION = 'https://api.push.apple.com';
    private const HOST_SANDBOX = 'https://api.sandbox.push.apple.com';
    /** Apple rejects a JWT older than one hour and throttles refreshing more often than every 20 min. */
    private const JWT_TTL = 2400;

    private string $keyId;
    private string $teamId;
    private string $bundleId;
    private string $privateKey;
    private string $environment;

    public function __construct(
        string $keyId = '',
        string $teamId = '',
        string $bundleId = '',
        string $privateKey = '',
        string $environment = ''
    ) {
        $this->keyId = strtoupper(trim($keyId ?: (string)getSystemSetting('push_apns_key_id', '')));
        $this->teamId = strtoupper(trim($teamId ?: (string)getSystemSetting('push_apns_team_id', '')));
        $this->bundleId = trim($bundleId ?: (string)getSystemSetting('push_apns_bundle_id', 'com.mhrgl.AiPBX'));
        $this->privateKey = trim($privateKey ?: (string)getSystemSetting('push_apns_key', ''));
        $env = trim($environment ?: (string)getSystemSetting('push_apns_environment', 'production'));
        $this->environment = $env === 'sandbox' ? 'sandbox' : 'production';
    }

    public function getIdentifier(): string
    {
        return 'apns';
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->teamId !== '' && $this->bundleId !== '' && $this->privateKey !== '';
    }

    /**
     * An APNs device token is 32 bytes, sent by the app as 64 hex characters.
     * FCM registration tokens never look like this.
     */
    public static function looksLikeApnsToken(string $token): bool
    {
        return (bool)preg_match('/^[0-9a-fA-F]{64}$/', $token);
    }

    public function sendToExtension(string $extension, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'delivered' => 0,
                'failed' => 0,
                'errors' => ['APNs provider is not fully configured (missing Key ID, Team ID, bundle id or .p8 key).']
            ];
        }

        $cleanExt = preg_replace('/[^0-9]/', '', $extension);
        if ($cleanExt === '') {
            return ['success' => false, 'delivered' => 0, 'failed' => 0, 'errors' => ['Invalid extension number.']];
        }

        $stmt = getDB()->prepare("SELECT id, fcm_token, voip_token
                                  FROM sys_mobile_devices
                                  WHERE extension = ? AND is_active = 1 AND push_type = 'apns'
                                  ORDER BY updated_at DESC");
        $stmt->execute([$cleanExt]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $delivered = 0;
        $failed = 0;
        $errors = [];
        foreach ($devices as $device) {
            $res = $this->sendToDevice($device, $payload);
            if ($res === null) {
                continue;
            }
            if ($res['success']) {
                $delivered++;
            } else {
                $failed++;
                $errors[] = "Device {$device['id']}: " . ($res['error'] ?? 'Unknown error');
            }
        }

        return [
            'success' => $delivered > 0 || ($failed === 0),
            'delivered' => $delivered,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Picks the right token for the payload: an incoming call goes to the
     * PushKit VoIP token when the device has one, everything else to the
     * regular APNs token. Returns null when the device has no usable token.
     *
     * @param array{id?: mixed, fcm_token?: ?string, voip_token?: ?string} $device
     */
    public function sendToDevice(array $device, array $payload): ?array
    {
        $action = (string)($payload['action'] ?? 'incoming_call');
        $voipToken = trim((string)($device['voip_token'] ?? ''));
        $alertToken = trim((string)($device['fcm_token'] ?? ''));

        if ($action === 'incoming_call' && self::looksLikeApnsToken($voipToken)) {
            return $this->send($voipToken, $payload, true);
        }
        if (self::looksLikeApnsToken($alertToken)) {
            return $this->send($alertToken, $payload, false);
        }
        return null;
    }

    public function sendToToken(string $token, array $payload = []): array
    {
        return $this->send(trim($token), $payload, false);
    }

    /**
     * Builds the HTTP/2 request: [headers, JSON body]. Kept separate from the
     * network call so it can be unit tested.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    public function buildRequest(array $payload, bool $voip): array
    {
        $action = (string)($payload['action'] ?? 'incoming_call');

        // Custom keys the app reads (same names as the FCM data payload).
        $custom = [];
        foreach ($payload as $k => $v) {
            if ($k !== 'aps' && is_scalar($v)) {
                $custom[$k] = (string)$v;
            }
        }
        $custom['action'] = $action;
        $custom['timestamp'] = $custom['timestamp'] ?? (string)time();

        $headers = [
            'apns-topic' => $voip ? $this->bundleId . '.voip' : $this->bundleId,
            'apns-push-type' => $voip ? 'voip' : 'alert',
            'apns-priority' => '10',
        ];

        if ($voip) {
            // A call that was not delivered within half a minute is over anyway.
            $headers['apns-expiration'] = (string)(time() + 30);
            return [$headers, ['aps' => new \stdClass()] + $custom];
        }

        $title = (string)($payload['title'] ?? '');
        $body = (string)($payload['body'] ?? '');
        $aps = ['sound' => 'default'];

        if ($action === 'incoming_call') {
            // Fallback for a device without a VoIP token: a plain notification.
            $caller = trim((string)($payload['caller_name'] ?? '')) ?: (string)($payload['caller_id'] ?? '');
            $title = $title !== '' ? $title : t('push.apns_incoming_call');
            $body = $body !== '' ? $body : $caller;
            $headers['apns-expiration'] = (string)(time() + 30);
        }

        $aps['alert'] = ['title' => $title, 'body' => $body];

        $convId = (int)($payload['conversation_id'] ?? 0);
        if ($convId > 0) {
            // Same category and thread as ChatNotifications.swift, so the
            // Reply action works from the lock screen.
            $aps['category'] = 'CHAT_MESSAGE';
            $aps['thread-id'] = 'conv_' . $convId;
            $headers['apns-collapse-id'] = 'chat_' . $convId;
            $custom['conversation_id'] = $convId;
            $custom['chat_title'] = $title;
        }

        return [$headers, ['aps' => $aps] + $custom];
    }

    private function send(string $token, array $payload, bool $voip): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'APNs is not configured.', 'error' => 'NOT_CONFIGURED'];
        }
        if (!self::looksLikeApnsToken($token)) {
            return ['success' => false, 'message' => 'Not an APNs device token.', 'error' => 'BadDeviceToken'];
        }

        try {
            $jwt = $this->getJwt();
        } catch (Exception $e) {
            error_log('ApnsPushProvider JWT error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not sign the APNs token: ' . $e->getMessage(), 'error' => 'JWT_FAILED'];
        }

        [$headers, $body] = $this->buildRequest($payload, $voip);
        $httpHeaders = ['authorization: bearer ' . $jwt, 'content-type: application/json'];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = $k . ': ' . $v;
        }

        $host = $this->environment === 'sandbox' ? self::HOST_SANDBOX : self::HOST_PRODUCTION;
        $ch = curl_init($host . '/3/device/' . $token);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'CURL network error: ' . $curlErr, 'error' => 'NETWORK_ERROR'];
        }
        if ($httpCode === 200) {
            return ['success' => true, 'message' => 'APNs message delivered successfully.'];
        }

        $json = json_decode((string)$response, true) ?: [];
        $reason = (string)($json['reason'] ?? "HTTP {$httpCode}");

        // 410 = the app was removed or the token expired. BadDeviceToken is
        // NOT cleaned up: it is also what a sandbox/production mismatch in the
        // settings returns, and that must not wipe every iOS device.
        if ($httpCode === 410 || $reason === 'Unregistered') {
            $this->forgetToken($token, $voip);
        }
        if ($reason === 'ExpiredProviderToken' || $reason === 'InvalidProviderToken') {
            @unlink($this->jwtCacheFile());
        }

        return ['success' => false, 'message' => "APNs error [{$reason}]", 'error' => $reason];
    }

    private function forgetToken(string $token, bool $voip): void
    {
        try {
            $db = getDB();
            if ($voip) {
                $db->prepare("UPDATE sys_mobile_devices SET voip_token = NULL, updated_at = NOW() WHERE voip_token = ?")
                   ->execute([$token]);
            } else {
                $db->prepare("UPDATE sys_mobile_devices SET is_active = 0, updated_at = NOW() WHERE fcm_token = ? AND push_type = 'apns'")
                   ->execute([$token]);
            }
            error_log('ApnsPushProvider: forgot unregistered ' . ($voip ? 'VoIP' : 'APNs') . ' token ' . substr($token, 0, 12) . '...');
        } catch (Exception $e) {
            // Ignored
        }
    }

    private function jwtCacheFile(): string
    {
        return sys_get_temp_dir() . '/aipbx_apns_jwt_' . md5($this->teamId . '|' . $this->keyId) . '.json';
    }

    /**
     * Provider authentication token (ES256 JWT), cached and reused because
     * APNs throttles providers that sign a new one for every request.
     */
    private function getJwt(): string
    {
        $cacheFile = $this->jwtCacheFile();
        if (is_file($cacheFile)) {
            $cache = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cache) && !empty($cache['jwt']) && ($cache['issued_at'] ?? 0) > time() - self::JWT_TTL) {
                return (string)$cache['jwt'];
            }
        }

        $now = time();
        $jwt = self::signJwt($this->privateKey, $this->keyId, $this->teamId, $now);
        @file_put_contents($cacheFile, json_encode(['jwt' => $jwt, 'issued_at' => $now]));
        @chmod($cacheFile, 0600);
        return $jwt;
    }

    public static function signJwt(string $privateKeyPem, string $keyId, string $teamId, int $issuedAt): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if (!$key) {
            throw new Exception('Invalid .p8 private key: ' . openssl_error_string());
        }

        $header = self::base64UrlEncode((string)json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $claims = self::base64UrlEncode((string)json_encode(['iss' => $teamId, 'iat' => $issuedAt]));
        $input = $header . '.' . $claims;

        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new Exception('Failed to sign the JWT: ' . openssl_error_string());
        }

        return $input . '.' . self::base64UrlEncode(self::derToRawSignature($der));
    }

    /**
     * OpenSSL returns an ECDSA signature as DER (SEQUENCE of two INTEGERs);
     * a JWT needs the raw 64-byte R||S form.
     */
    public static function derToRawSignature(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new Exception('Unexpected ECDSA signature format.');
            }
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $offset += 2 + $len;
            $int = ltrim($int, "\x00");
            $parts[] = str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
