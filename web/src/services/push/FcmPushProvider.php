<?php

namespace App\Services\Push;

use PDO;
use Exception;

require_once __DIR__ . '/PushProviderInterface.php';
require_once __DIR__ . '/../../../config.php';

class FcmPushProvider implements PushProviderInterface
{
    private string $projectId;
    private string $serviceAccountJson;
    private ?array $serviceAccountData = null;

    public function __construct(string $projectId = '', string $serviceAccountJson = '')
    {
        $this->projectId = trim($projectId ?: (string)getSystemSetting('push_fcm_project_id', ''));
        $this->serviceAccountJson = trim($serviceAccountJson ?: (string)getSystemSetting('push_fcm_service_account', ''));

        if ($this->serviceAccountJson !== '') {
            $parsed = json_decode($this->serviceAccountJson, true);
            if (is_array($parsed) && !empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                $this->serviceAccountData = $parsed;
                if ($this->projectId === '' && !empty($parsed['project_id'])) {
                    $this->projectId = $parsed['project_id'];
                }
            }
        }
    }

    public function getIdentifier(): string
    {
        return 'fcm';
    }

    public function isConfigured(): bool
    {
        return !empty($this->projectId) && !empty($this->serviceAccountData);
    }

    /**
     * Send wake-up push notification to an extension's active mobile device(s).
     */
    public function sendToExtension(string $extension, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'delivered' => 0,
                'failed' => 0,
                'errors' => ['FCM provider is not fully configured (missing project ID or valid service account).']
            ];
        }

        $cleanExt = preg_replace('/[^0-9]/', '', $extension);
        if ($cleanExt === '') {
            return [
                'success' => false,
                'delivered' => 0,
                'failed' => 0,
                'errors' => ['Invalid extension number.']
            ];
        }

        $db = getDB();
        $stmt = $db->prepare("SELECT id, fcm_token, device_id, platform 
                              FROM sys_mobile_devices 
                              WHERE extension = ? AND is_active = 1 
                              ORDER BY updated_at DESC");
        $stmt->execute([$cleanExt]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($devices)) {
            return [
                'success' => true,
                'delivered' => 0,
                'failed' => 0,
                'errors' => [],
                'message' => 'No active mobile devices registered for extension ' . $cleanExt
            ];
        }

        $delivered = 0;
        $failed = 0;
        $errors = [];

        foreach ($devices as $device) {
            $token = trim($device['fcm_token'] ?? '');
            if ($token === '' || strpos($token, 'revoked:') === 0) {
                continue;
            }

            $res = $this->sendToToken($token, $payload);
            if ($res['success']) {
                $delivered++;
            } else {
                $failed++;
                $errors[] = "Device {$device['id']}: " . ($res['error'] ?? 'Unknown error');
            }
        }

        return [
            'success' => $delivered > 0,
            'delivered' => $delivered,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Send direct push notification to a specific FCM registration token.
     */
    public function sendToToken(string $token, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'FCM is not configured.',
                'error' => 'NOT_CONFIGURED'
            ];
        }

        try {
            $accessToken = $this->getAccessToken();
        } catch (Exception $e) {
            error_log('FcmPushProvider getAccessToken error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to obtain Google OAuth access token: ' . $e->getMessage(),
                'error' => 'OAUTH_FAILED'
            ];
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        // Construct high-priority DATA payload (bypasses Android Doze/battery saving)
        $data = [
            'action' => (string)($payload['action'] ?? 'incoming_call'),
            'caller_id' => (string)($payload['caller_id'] ?? ''),
            'caller_name' => (string)($payload['caller_name'] ?? ''),
            'timestamp' => (string)time()
        ];
        if (isset($payload['title'])) $data['title'] = (string)$payload['title'];
        if (isset($payload['body'])) $data['body'] = (string)$payload['body'];
        foreach ($payload as $k => $v) {
            if (!isset($data[$k]) && is_scalar($v)) {
                $data[$k] = (string)$v;
            }
        }

        $msgBody = [
            'message' => [
                'token' => $token,
                'data' => $data,
                'android' => [
                    'priority' => 'HIGH',
                    'direct_boot_ok' => true
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8'
            ],
            CURLOPT_POSTFIELDS => json_encode($msgBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'message' => 'CURL network error: ' . $curlErr,
                'error' => 'NETWORK_ERROR'
            ];
        }

        $jsonResp = json_decode($response, true) ?: [];

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'message' => 'FCM message delivered successfully.',
                'message_id' => $jsonResp['name'] ?? null
            ];
        }

        // Check if token was unregistered / invalid, automatically clean up
        $errorCode = $jsonResp['error']['status'] ?? $jsonResp['error']['details'][0]['errorCode'] ?? 'UNKNOWN';
        $errorMsg = $jsonResp['error']['message'] ?? "HTTP {$httpCode}";

        if ($httpCode === 404 || in_array($errorCode, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
            $this->deactivateInvalidToken($token);
        }

        return [
            'success' => false,
            'message' => "FCM error [{$errorCode}]: {$errorMsg}",
            'error' => $errorCode
        ];
    }

    /**
     * Mark an expired or revoked token inactive in DB.
     */
    private function deactivateInvalidToken(string $token): void
    {
        try {
            $db = getDB();
            $stmt = $db->prepare("UPDATE sys_mobile_devices 
                                  SET is_active = 0, updated_at = NOW() 
                                  WHERE fcm_token = ?");
            $stmt->execute([$token]);
            error_log("FcmPushProvider: Marked invalid FCM token as inactive: " . substr($token, 0, 20) . '...');
        } catch (Exception $e) {
            // Ignored
        }
    }

    /**
     * Generate or fetch cached Google OAuth2 Access Token via Service Account JWT assertion.
     */
    private function getAccessToken(): string
    {
        $clientEmail = $this->serviceAccountData['client_email'] ?? '';
        $privateKey = $this->serviceAccountData['private_key'] ?? '';

        if (empty($clientEmail) || empty($privateKey)) {
            throw new Exception('Missing client_email or private_key in Service Account');
        }

        $cacheFile = sys_get_temp_dir() . '/aipbx_fcm_oauth_' . md5($clientEmail) . '.json';
        if (file_exists($cacheFile)) {
            $cacheData = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cacheData) && !empty($cacheData['access_token']) && ($cacheData['expires_at'] ?? 0) > (time() + 60)) {
                return $cacheData['access_token'];
            }
        }

        // Build JWT
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ];

        $encodedHeader = $this->base64UrlEncode((string)json_encode($header));
        $encodedClaims = $this->base64UrlEncode((string)json_encode($claims));
        $signatureInput = $encodedHeader . '.' . $encodedClaims;

        $privKeyResource = openssl_pkey_get_private($privateKey);
        if (!$privKeyResource) {
            throw new Exception('Invalid RSA private key in Service Account: ' . openssl_error_string());
        }

        $signature = '';
        $signed = openssl_sign($signatureInput, $signature, $privKeyResource, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new Exception('Failed to sign JWT with RSA private key: ' . openssl_error_string());
        }

        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

        // Exchange JWT for OAuth2 Access Token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $tokenResponse = curl_exec($ch);
        $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tokenErr = curl_error($ch);
        curl_close($ch);

        if ($tokenResponse === false) {
            throw new Exception('OAuth token curl failed: ' . $tokenErr);
        }

        $tokenData = json_decode($tokenResponse, true) ?: [];
        if ($tokenHttpCode !== 200 || empty($tokenData['access_token'])) {
            $errDesc = $tokenData['error_description'] ?? $tokenData['error'] ?? "HTTP {$tokenHttpCode}";
            throw new Exception('Google OAuth token request failed: ' . $errDesc);
        }

        $accessToken = $tokenData['access_token'];
        $expiresIn = intval($tokenData['expires_in'] ?? 3600);

        @file_put_contents($cacheFile, json_encode([
            'access_token' => $accessToken,
            'expires_at' => $now + $expiresIn
        ]));

        return $accessToken;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
