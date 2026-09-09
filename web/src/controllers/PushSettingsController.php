<?php

require_once __DIR__ . '/../services/PushSettingsService.php';
require_once __DIR__ . '/../repositories/PushSettingsRepository.php';

class PushSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // Handle AJAX Test Push
        if (isset($_GET['action']) && $_GET['action'] === 'test_push') {
            header('Content-Type: application/json; charset=utf-8');
            $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz güvenlik oturumu (CSRF).']);
                exit;
            }

            $target = trim($_POST['target'] ?? '');
            $type = trim($_POST['type'] ?? 'extension');

            if ($target === '') {
                echo json_encode(['success' => false, 'message' => 'Lütfen test yapılacak dahili veya cihazı seçin.']);
                exit;
            }

            $result = PushSettingsService::testPush($target, $type);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['save_push_settings'])) {
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $error = 'Geçersiz form tokeni (CSRF). Lütfen sayfayı yenileyip tekrar deneyin.';
            } else {
                $res = PushSettingsService::saveSettings($_POST);
                if ($res['success']) {
                    $message = $res['message'];
                } else {
                    $error = $res['error'];
                }
            }
        }

        $settings = PushSettingsRepository::currentSettings();
        $devices = PushSettingsRepository::activeMobileDevices();

        $page_title = 'Mobil Bildirim Ayarları';
        $active_page = 'push_settings.php';

        require_once dirname(__DIR__) . '/../header.php';
        static::render('push_settings/index', [
            'settings' => $settings,
            'devices' => $devices,
            'message' => $message,
            'error' => $error,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
