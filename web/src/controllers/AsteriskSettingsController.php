<?php
require_once __DIR__ . '/../services/AsteriskSettingsService.php';
require_once __DIR__ . '/../services/VoicemailPolicy.php';

/**
 * PBX Settings page (/asterisk-settings).
 * Renders templates/views/asterisk_settings/index.php.
 * Logic: AsteriskSettingsRepository, AsteriskSettingsService.
 * POST actions: save_asterisk_settings.
 * Admins only.
 */
class AsteriskSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $csrf = static::csrfToken();
        $notices = static::handlePost([
            'save_asterisk_settings' => fn() => AsteriskSettingsService::saveSettings($_POST),
            'enable_sip_debug' => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : AsteriskSettingsService::enableSipDebug(600),
            'disable_sip_debug' => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : AsteriskSettingsService::disableSipDebug(),
            'rotate_logs' => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : AsteriskSettingsService::rotateLogs(),
        ]);

        $defaults = AsteriskSettingsService::defaults();
        $current_db_settings = AsteriskSettingsRepository::currentSettings();
        $s = array_merge($defaults, $current_db_settings);

        $ring_sound_options = AsteriskSettingsRepository::activeRingSoundOptions();

        $active_codecs = explode(',', $s['pjsip_codecs']);
        $active_wired_codecs = explode(',', $s['pjsip_wired_codecs']);

        $page_title = t('asterisk_settings.title');
        static::renderPage('asterisk_settings/index', [
            's' => $s,
            'ring_sound_options' => $ring_sound_options,
            'active_codecs' => $active_codecs,
            'active_wired_codecs' => $active_wired_codecs,
            'signaling_status' => AsteriskSettingsService::signalingStatus(),
            'vm_settings' => VoicemailPolicy::settings(),
            'vm_weak_pins' => VoicemailPolicy::weakPinCount(),
        ], ['title' => $page_title] + $notices);
    }
}
