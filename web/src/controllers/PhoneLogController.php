<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhoneLogService.php';
require_once __DIR__ . '/../services/NetworkServicesService.php';
require_once __DIR__ . '/../services/PhoneDiscoveryService.php';

/**
 * PBX → Phone logs: everything desk phones do with this server, one tab each:
 * syslog (followed live), HTTPS provisioning, FTP, TFTP and DHCP, by IP or MAC,
 * and the log of the phone discovery engine.
 */
class PhoneLogController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        if (static::isPost() && ($_POST['action'] ?? '') === 'read') {
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                static::json(['success' => false, 'message' => t('common.invalid_csrf')]);
            }
            $cursor = isset($_POST['ino'], $_POST['pos']) ? ['ino' => (int) $_POST['ino'], 'pos' => (int) $_POST['pos']] : null;
            $res = PhoneLogService::read(
                mb_substr((string) ($_POST['who'] ?? ''), 0, 64),
                mb_substr((string) ($_POST['text'] ?? ''), 0, 200),
                $cursor
            );
            static::json(['success' => true] + $res);
        }

        $tab = in_array($_GET['tab'] ?? '', [...PhoneLogService::SOURCES, 'discovery'], true) ? (string) $_GET['tab'] : 'syslog';
        $who = mb_substr((string) ($_GET['who'] ?? ''), 0, 64);
        $text = mb_substr((string) ($_GET['text'] ?? ''), 0, 200);
        $on = getSystemSetting('netsvc_syslog', '0') === '1';
        static::renderPage('phone_logs/index', [
            'tab' => $tab,
            'text' => $text,
            'events' => in_array($tab, ['syslog', 'discovery'], true) ? [] : PhoneLogService::events($tab, $who, $text),
            // The phone discovery engine's own log (every 5 minutes), newest first.
            'discoveryLog' => $tab === 'discovery' ? array_reverse(PhoneDiscoveryService::engineStatus(200)['log']) : [],
            'serviceOn' => [
                'http' => true,
                'ftp' => getSystemSetting('netsvc_ftp', '0') === '1',
                'tftp' => in_array(getSystemSetting('netsvc_mode', 'off'), ['tftp', 'dhcp'], true),
                'dhcp' => getSystemSetting('netsvc_mode', 'off') === 'dhcp',
            ],
            'csrf' => getCSRFToken(),
            'receiving' => $on && NetworkServicesService::syslogStatus() === 'active',
            'enabled' => $on,
            'labels' => PhoneLogService::phoneLabels(),
            'who' => $who,
        ], ['title' => t('phonelogs.title')]);
    }
}
