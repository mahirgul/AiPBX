<?php

require_once __DIR__ . '/../services/PushSettingsService.php';
require_once __DIR__ . '/../repositories/PushSettingsRepository.php';

class PushSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // AJAX: send a test push
        if (($_GET['action'] ?? '') === 'test_push') {
            static::requireAjaxAccess('push_settings', 'edit', 'Bu işlem için yetkiniz bulunmamaktadır.', 'Geçersiz güvenlik oturumu (CSRF).');
            $target = trim($_POST['target'] ?? '');
            if ($target === '') {
                static::json(['success' => false, 'message' => 'Lütfen test yapılacak dahili veya cihazı seçin.']);
            }
            static::json(PushSettingsService::testPush($target, trim($_POST['type'] ?? 'extension')));
        }

        $notices = static::handlePost([
            'save_push_settings' => fn() => static::verifyCsrf()
                ? PushSettingsService::saveSettings($_POST)
                : ['success' => false, 'error' => t('common.invalid_csrf')],
        ]);

        $settings = PushSettingsRepository::currentSettings();
        $devices = PushSettingsRepository::activeMobileDevices();

        $page_title = 'Mobil Bildirim';
        static::renderPage('push_settings/index', [
            'settings' => $settings,
            'devices' => $devices,
        ], ['title' => $page_title] + $notices);
    }
}
