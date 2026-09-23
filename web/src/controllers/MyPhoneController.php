<?php

require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../asterisk_helper.php';

class MyPhoneController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('my_phone', 'view');

        $user = getCurrentUser();
        $userId = (int)($user['id'] ?? 0);

        if (static::isPost() && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
            if (!static::verifyCsrf()) {
                static::notifyError(t('my_phone.msg_csrf_error'));
            } else {
                $dnd = isset($_POST['dnd_enabled']) ? 1 : 0;
                $forwardAlways = preg_replace('/[^0-9+*#]/', '', trim($_POST['call_forward_number'] ?? ''));
                $forwardBusy = preg_replace('/[^0-9+*#]/', '', trim($_POST['cf_busy_number'] ?? ''));
                $forwardNoAnswer = preg_replace('/[^0-9+*#]/', '', trim($_POST['cf_noanswer_number'] ?? ''));
                $noAnswerTimeout = max(5, min(120, intval($_POST['cf_noanswer_timeout'] ?? 20)));

                if (isset($_POST['phone_modes']) && is_array($_POST['phone_modes'])) {
                    $mode = formatPhoneModes($_POST['phone_modes']);
                } elseif (isset($_POST['allowed_phone_modes']) && is_array($_POST['allowed_phone_modes'])) {
                    $mode = formatPhoneModes($_POST['allowed_phone_modes']);
                } elseif (isset($_POST['allowed_phone_mode'])) {
                    $mode = formatPhoneModes(parsePhoneModes($_POST['allowed_phone_mode']));
                } else {
                    $mode = 'web';
                }

                $vmSettings = [
                    'voicemail_enabled' => isset($_POST['voicemail_enabled']) ? 1 : 0,
                    'voicemail_pin' => trim($_POST['voicemail_pin'] ?? ''),
                    'voicemail_email' => trim($_POST['voicemail_email'] ?? ''),
                    'voicemail_attach_audio' => isset($_POST['voicemail_attach_audio']) ? 1 : 0,
                    'vm_on_noanswer' => isset($_POST['vm_on_noanswer']) ? 1 : 0,
                    'vm_on_busy' => isset($_POST['vm_on_busy']) ? 1 : 0,
                    'vm_on_unavail' => isset($_POST['vm_on_unavail']) ? 1 : 0,
                    'vm_always' => isset($_POST['vm_always']) ? 1 : 0,
                ];

                MyPhoneRepository::updatePhoneSettings($userId, $dnd, $forwardAlways, $mode, $forwardBusy, $forwardNoAnswer, $noAnswerTimeout, $vmSettings);

                // Update session state for current user
                $_SESSION['allowed_phone_mode'] = $mode;

                // Sync dialplan and voicemail so changes take effect immediately
                try {
                    require_once dirname(__DIR__) . '/sync/SyncGeneralDialplan.php';
                    syncGeneralDialplan();
                    require_once dirname(__DIR__) . '/sync/SyncVoicemail.php';
                    syncVoicemail();
                } catch (\Throwable $e) {
                    // Non-fatal if reload fails; pending sync will catch it
                }

                $logDetail = "DND: {$dnd}, CFA: {$forwardAlways}, CFB: {$forwardBusy}, CFNA: {$forwardNoAnswer} ({$noAnswerTimeout}s), Mode: {$mode}";
                writeAuditLog(null, 'user_settings', 'phone', $logDetail, 'update', $userId);
                static::notifySuccess(t('my_phone.msg_settings_saved'));
            }
            static::redirect('/my-phone?tab=settings');
            return;
        }

        $extDetails = MyPhoneRepository::getUserExtensionDetails($userId);
        $ext = trim($extDetails['extension'] ?? '');

        require_once dirname(__DIR__) . '/services/VoicemailService.php';
        $voicemailMessages = VoicemailService::getVoicemailMessages($ext);

        $tab = trim($_GET['tab'] ?? 'history');
        if (!in_array($tab, ['history', 'settings', 'voicemail'], true)) {
            $tab = 'history';
        }

        $filter = trim($_GET['filter'] ?? 'all');
        if (!in_array($filter, ['all', 'in', 'out', 'missed'], true)) {
            $filter = 'all';
        }

        $search = trim($_GET['q'] ?? '');

        $stats = MyPhoneRepository::getCallStats($ext);
        $calls = MyPhoneRepository::getRecentCalls($ext, $filter === 'all' ? null : $filter, $search, 100);
        $directory = MyPhoneRepository::getInternalDirectory();

        $pjsipStatuses = AsteriskHelper::getPJSIPStatuses();
        $sipStatus = $ext !== '' ? ($pjsipStatuses["{$ext}-sip"] ?? null) : null;
        $webrtcStatus = $ext !== '' ? ($pjsipStatuses["{$ext}-webrtc"] ?? null) : null;
        $mobileStatus = $ext !== '' ? ($pjsipStatuses["{$ext}-mob-webrtc"] ?? null) : null;

        $page_title = t('my_phone.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('my_phone/index', [
            'extDetails' => $extDetails,
            'ext' => $ext,
            'stats' => $stats,
            'calls' => $calls,
            'directory' => $directory,
            'filter' => $filter,
            'search' => $search,
            'tab' => $tab,
            'sipStatus' => $sipStatus,
            'webrtcStatus' => $webrtcStatus,
            'mobileStatus' => $mobileStatus,
            'mobileDevices' => MyPhoneRepository::getUserMobileDevices($userId),
            'voicemailMessages' => $voicemailMessages,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
