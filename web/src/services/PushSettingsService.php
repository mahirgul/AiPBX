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
                    'error' => 'Geçersiz Firebase Servis Hesabı JSON formatı. "client_email" ve "private_key" alanları bulunmalıdır.'
                ];
            }
            $serviceAccount = $serviceAccountInput;
            if (empty($projectId) && !empty($parsed['project_id'])) {
                $projectId = $parsed['project_id'];
            }
        } else {
            $serviceAccount = $current['push_fcm_service_account'] ?? '';
        }

        if ($enabled === '1' && $provider === 'fcm') {
            if (empty($projectId)) {
                return [
                    'success' => false,
                    'error' => 'FCM sağlayıcısı için Firebase Proje Kimliği (Project ID) zorunludur.'
                ];
            }
            if (empty($serviceAccount)) {
                return [
                    'success' => false,
                    'error' => 'FCM sağlayıcısı için Google Servis Hesabı JSON içeriği zorunludur.'
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
            writeAuditLog('dialplan', 'sys_settings', 'push_settings', 'Mobil Bildirim Ayarları', 'UPDATE', $_SESSION['user_id'] ?? null);
            markPendingSync('dialplan');
        }

        return [
            'success' => true,
            'message' => 'Mobil bildirim ayarları başarıyla kaydedildi.'
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
                'message' => 'Bildirim servisi henüz yapılandırılmamış veya etkinleştirilmemiş.'
            ];
        }

        $payload = [
            'action' => 'test_push',
            'title' => 'AI PBX Test Bildirimi',
            'body' => 'Mobil bildirim katmanı başarıyla çalışıyor! (Zaman: ' . date('H:i:s') . ')',
            'timestamp' => (string)time()
        ];

        if ($type === 'token') {
            return $provider->sendToToken($target, $payload);
        }

        $res = $provider->sendToExtension($target, $payload);
        if ($res['success']) {
            return [
                'success' => true,
                'message' => "Başarılı! {$res['delivered']} cihaza bildirim iletildi."
            ];
        }

        $errStr = !empty($res['errors']) ? implode(', ', $res['errors']) : ($res['message'] ?? 'Bilinmeyen hata');
        return [
            'success' => false,
            'message' => "Bildirim gönderilemedi: {$errStr}"
        ];
    }
}
