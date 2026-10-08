<?php

require_once __DIR__ . '/push/PushService.php';
require_once __DIR__ . '/../repositories/PushSettingsRepository.php';
require_once __DIR__ . '/../asterisk_sync.php';

use App\Services\Push\PushService;
use App\Services\Push\FcmPushProvider;

class PushSettingsService
{
    /**
     * Validate and save push settings.
     */
    public static function saveSettings(array $post): array
    {
        $db = getDB();
        $current = PushSettingsRepository::currentSettings();

        $provider = trim($post['push_provider'] ?? 'none');
        if (!in_array($provider, ['none', 'fcm', 'unifiedpush'], true)) {
            $provider = 'none';
        }

        $enabled = isset($post['push_enabled']) && (string)$post['push_enabled'] === '1' ? '1' : '0';
        $waitSeconds = max(3, min(30, (int)($post['push_wait_seconds'] ?? 8)));

        $projectId = trim($post['push_fcm_project_id'] ?? '');
        $appId = trim($post['push_fcm_app_id'] ?? '');
        $apiKey = trim($post['push_fcm_api_key'] ?? '');
        $senderId = trim($post['push_fcm_sender_id'] ?? '');

        // Service Account handling: if left empty in form, retain existing
        $serviceAccountInput = trim($post['push_fcm_service_account'] ?? '');
        if ($serviceAccountInput !== '') {
            $parsed = json_decode($serviceAccountInput, true);
            if (!is_array($parsed) || empty($parsed['client_email']) || empty($parsed['private_key'])) {
                return [
                    'success' => false,
                    'error' => t('srv_push.err_json')
                ];
            }
            $serviceAccount = $serviceAccountInput;
            if (empty($projectId) && !empty($parsed['project_id'])) {
                $projectId = $parsed['project_id'];
            }
        } else {
            $serviceAccount = $current['push_fcm_service_account'] ?? '';
        }

        // Apple Push Notification service (iOS app). The .p8 key, like the
        // service account, is kept when the field is left empty.
        $apnsEnabled = (string)($post['push_apns_enabled'] ?? '0') === '1' ? '1' : '0';
        $apnsKeyId = strtoupper(trim($post['push_apns_key_id'] ?? ''));
        $apnsTeamId = strtoupper(trim($post['push_apns_team_id'] ?? ''));
        $apnsBundleId = trim($post['push_apns_bundle_id'] ?? '') ?: 'com.mhrgl.AiPBX';
        $apnsEnvironment = ($post['push_apns_environment'] ?? '') === 'sandbox' ? 'sandbox' : 'production';
        $apnsKeyInput = trim($post['push_apns_key'] ?? '');
        if ($apnsKeyInput !== '') {
            $pkey = @openssl_pkey_get_private($apnsKeyInput);
            $details = $pkey ? openssl_pkey_get_details($pkey) : false;
            if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
                return [
                    'success' => false,
                    'error' => t('srv_push.err_apns_key')
                ];
            }
            $apnsKey = $apnsKeyInput;
        } else {
            $apnsKey = $current['push_apns_key'] ?? '';
        }

        if ($enabled === '1' && $apnsEnabled === '1') {
            if (!preg_match('/^[A-Z0-9]{10}$/', $apnsKeyId) || !preg_match('/^[A-Z0-9]{10}$/', $apnsTeamId)) {
                return [
                    'success' => false,
                    'error' => t('srv_push.err_apns_ids')
                ];
            }
            if ($apnsKey === '') {
                return [
                    'success' => false,
                    'error' => t('srv_push.err_apns_key')
                ];
            }
        }

        if ($enabled === '1' && $provider === 'fcm') {
            if (empty($projectId)) {
                return [
                    'success' => false,
                    'error' => t('srv_push.err_project')
                ];
            }
            if (empty($serviceAccount)) {
                return [
                    'success' => false,
                    'error' => t('srv_push.err_sa')
                ];
            }
        }

        $newSettings = [
            'push_enabled' => $enabled,
            'push_provider' => $provider,
            'push_fcm_project_id' => $projectId,
            'push_fcm_service_account' => $serviceAccount,
            'push_fcm_app_id' => $appId,
            'push_fcm_api_key' => $apiKey,
            'push_fcm_sender_id' => $senderId,
            'push_wait_seconds' => (string)$waitSeconds,
            'push_apns_enabled' => $apnsEnabled,
            'push_apns_key_id' => $apnsKeyId,
            'push_apns_team_id' => $apnsTeamId,
            'push_apns_bundle_id' => $apnsBundleId,
            'push_apns_key' => $apnsKey,
            'push_apns_environment' => $apnsEnvironment,
        ];

        $changed = false;
        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');

        foreach ($newSettings as $k => $v) {
            if (($current[$k] ?? '') !== $v) {
                $changed = true;
            }
            $stmt->execute([$k, $v]);
        }

        if ($changed) {
            writeAuditLog('general_dialplan', 'sys_settings', 'push_settings', 'Mobile push settings', 'update', $_SESSION['user_id'] ?? null);
            // The push settings affect the extensions' dial lines
            // (buildExtensionDialLines) → the general dialplan must be
            // regenerated. The previous markPendingSync('dialplan') call used a
            // domain that does not exist and too few arguments: saving died with
            // ArgumentCountError.
            markPendingSync('general_dialplan', 'sys_settings', 'push_settings', 'Mobile push settings', 'update', $_SESSION['user_id'] ?? null);
        }

        return [
            'success' => true,
            'message' => t('srv_push.saved')
        ];
    }

    /**
     * Send a test push notification to an extension or specific token.
     */
    public static function testPush(string $target, string $type = 'extension'): array
    {
        $provider = PushService::getProvider();
        if (!$provider->isConfigured()) {
            return [
                'success' => false,
                'message' => t('srv_push.err_not_configured')
            ];
        }

        $payload = [
            'action' => 'test_push',
            'title' => 'AI PBX Test Bildirimi',
            'body' => sprintf(t('srv_push.test_body'), date('H:i:s')),
            'timestamp' => (string)time()
        ];

        if ($type === 'token') {
            return $provider->sendToToken($target, $payload);
        }

        $res = $provider->sendToExtension($target, $payload);
        if ($res['success']) {
            return [
                'success' => true,
                'message' => sprintf(t('srv_push.test_ok'), $res['delivered'])
            ];
        }

        $errStr = !empty($res['errors']) ? implode(', ', $res['errors']) : ($res['message'] ?? t('common.unknown_error'));
        return [
            'success' => false,
            'message' => sprintf(t('srv_push.err_send'), $errStr)
        ];
    }
}
