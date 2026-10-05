<?php
require_once __DIR__ . '/../services/AsteriskSettingsService.php';

class AsteriskSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_asterisk_settings' => fn() => AsteriskSettingsService::saveSettings($_POST),
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
        ], ['title' => $page_title] + $notices);
    }
}
