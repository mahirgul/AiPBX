<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/SipCaptureService.php';

/**
 * Troubleshooting → SIP traffic: one page each for the capture files (/sip-traffic),
 * the calls of a file (/sip-calls), one call as a ladder (/sip-call), the live view
 * (/sip-live) and the settings (/sip-capture-settings). Admins only.
 */
class SipTrafficController extends BaseController
{
    /** The capture files, with download. */
    public static function index(): void
    {
        static::requireRole('admin');
        $notices = ['message' => '', 'error' => ''];
        if (static::isPost() && isset($_POST['download'])) {
            $notices = static::notices(PBXHelper::handleAction(static::csrfToken(), function () {
                $path = SipCaptureService::path((string) ($_POST['name'] ?? ''));
                writeAuditLog('system', 'sip_capture', basename($path), 'SIP capture file downloaded: ' . basename($path), 'download', $_SESSION['user_id'] ?? null);
                header('Content-Type: application/vnd.tcpdump.pcap');
                header('Content-Disposition: attachment; filename="' . basename($path) . '"');
                header('Content-Length: ' . filesize($path));
                header('Cache-Control: no-store');
                readfile($path);
                exit;
            }));
        }
        static::renderPage('sip_traffic/files', [
            'csrf' => getCSRFToken(),
            'files' => SipCaptureService::files(),
            'settings' => SipCaptureService::settings(),
        ], ['title' => t('siptrace.title')] + $notices);
    }

    /** The calls (dialogs) of one file. */
    public static function calls(): void
    {
        static::requireRole('admin');
        $name = (string) ($_GET['file'] ?? '');
        $filter = trim((string) ($_GET['q'] ?? ''));
        $error = '';
        $calls = [];
        $truncated = false;
        try {
            $read = SipCaptureService::read(SipCaptureService::path($name));
            $truncated = count($read['messages']) >= SipCaptureService::MAX_MESSAGES;
            $calls = SipCaptureService::calls($read['messages'], $filter);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        static::renderPage('sip_traffic/calls', ['file' => $name, 'filter' => $filter, 'calls' => $calls, 'truncated' => $truncated],
            ['title' => t('siptrace.calls_title'), 'error' => $error]);
    }

    /** One call as a ladder, every message readable. */
    public static function call(): void
    {
        static::requireRole('admin');
        $name = (string) ($_GET['file'] ?? '');
        $callId = (string) ($_GET['id'] ?? '');
        $error = '';
        $messages = [];
        try {
            $messages = SipCaptureService::dialog(SipCaptureService::read(SipCaptureService::path($name))['messages'], $callId);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        static::renderPage('sip_traffic/call', ['file' => $name, 'callId' => $callId, 'messages' => $messages],
            ['title' => t('siptrace.call_title'), 'error' => $error]);
    }

    /** Live view; ?poll=1 returns the messages after a position of the newest file as JSON. */
    public static function live(): void
    {
        static::requireRole('admin');
        if (isset($_GET['poll'])) {
            $files = SipCaptureService::files();
            $newest = $files[0]['name'] ?? '';
            $file = (string) ($_GET['file'] ?? '');
            $offset = (int) ($_GET['offset'] ?? 0);
            if ($file !== $newest) {
                // sip-A.pcap is renamed sip-A_B.pcap when it is closed: the same file, keep reading where we were.
                $stem = static fn(string $n): string => (string) preg_replace('/(_\d{8}-\d{6})?\.pcap$/', '', $n);
                if ($file === '' || $newest === '') {
                    // First poll: start at the end of the newest file.
                    $offset = $newest !== '' ? (int) @filesize(SipCaptureService::DIR . '/' . $newest) : 0;
                } elseif ($stem($file) !== $stem($newest)) {
                    // A new file was started: read it from its first packet.
                    $offset = 24;
                }
                $file = $newest;
            }
            $messages = [];
            if ($file !== '') {
                $read = SipCaptureService::read(SipCaptureService::DIR . '/' . $file, $offset, 500);
                $offset = $read['offset'];
                foreach ($read['messages'] as $m) {
                    $messages[] = ['ts' => $m['ts'], 'label' => $m['label'], 'request' => $m['request'], 'status' => $m['status'],
                        'src' => $m['src'], 'dst' => $m['dst'], 'from' => $m['from'], 'to' => $m['to'], 'call_id' => $m['call_id'], 'text' => $m['text']];
                }
            }
            static::json(['file' => $file, 'offset' => $offset, 'messages' => $messages]);
        }
        static::renderPage('sip_traffic/live', ['settings' => SipCaptureService::settings()], ['title' => t('siptrace.live_title')]);
    }

    public static function settings(): void
    {
        static::requireRole('admin');
        $notices = static::handlePost(['save_sip_capture' => fn() => SipCaptureService::saveSettings($_POST)]);
        static::renderPage('sip_traffic/settings', [
            'csrf' => getCSRFToken(),
            'settings' => SipCaptureService::settings(),
            'status' => SipCaptureService::status(),
        ], ['title' => t('siptrace.settings_title')] + $notices);
    }
}
