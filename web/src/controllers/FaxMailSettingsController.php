<?php
require_once __DIR__ . '/../services/FaxMailSettingsService.php';

class FaxMailSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['save_fax_email_settings'])) {
            $res = FaxMailSettingsService::saveSettings($_POST);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        }

        $sys_settings = FaxMailSettingsRepository::allSettings();

        $page_title = t('fax_mail_settings.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('fax_mail_settings/index', [
            'fax_from_addr' => $sys_settings['fax_email_from_address'] ?? 'fax@example.com',
            'fax_from_name' => $sys_settings['fax_email_from_name'] ?? 'AI PBX Faks Sistemi',
            'fax_rx_enabled' => $sys_settings['fax_email_rx_enabled'] ?? 'yes',
            'fax_rx_attach' => $sys_settings['fax_email_rx_attach_pdf'] ?? 'yes',
            'fax_tx_enabled' => $sys_settings['fax_email_tx_enabled'] ?? 'yes',
            'fax_retention' => $sys_settings['fax_retention_days'] ?? '60',
            'fax_header_info' => $sys_settings['fax_header_info'] ?? 'AI PBX Fax Server',
            'fax_station_id' => $sys_settings['fax_local_station_id'] ?? 'AiPBX',
            'fax_max_retries' => $sys_settings['fax_max_retries'] ?? '3',
            'fax_retry_time' => $sys_settings['fax_retry_time'] ?? '60',
            'fax_wait_time' => $sys_settings['fax_wait_time'] ?? '30',
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
