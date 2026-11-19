<?php
/**
 * Extension Dialing, DND, Call Forwarding & Mobile Push Wait Dialplan Builder
 *
 * Extracted from DialplanBuilders.php to keep extension-specific routing logic
 * (DND, CFU/CFB/CFNA, hop-loop protection, PJSIP multi-contact dialing, and
 * FCM/APNs mobile push wake-up wait loops) in a dedicated module.
 */

/**
 * Added before dialing an extension (Dial): Busy when DND is on, Goto to the
 * forwarding target when there is one — both are "terminal" (no Dial block is
 * written). The forwarding target is a Goto into [from-internal-pbx]; that
 * context includes [from-internal-outbound], so it works whether the target
 * is an extension or an outside number (external numbers resolve through the
 * include chain).
 */
function buildDndCfCheckLines($ext, $db) {
    $stmt = $db->prepare("SELECT dnd_enabled, call_forward_number, permission_group_id, outbound_group FROM sys_users WHERE extension = ?");
    $stmt->execute([$ext]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($u['dnd_enabled'])) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(DND on - {$ext})",
                ...statusPromptLines('busy', $ext),
                " same => n,Hangup(17)"
            ]
        ];
    }

    $cf_target = preg_replace('/[^0-9+]/', '', trim($u['call_forward_number'] ?? ''));
    if ($cf_target !== '') {
        $perm_grp = (int)($u['permission_group_id'] ?? 0);
        $out_grp = max(1, (int)($u['outbound_group'] ?? 1));
        $cf_ctx = $out_grp > 1 ? "from-internal-desktop-{$out_grp}" : 'from-internal-desktop';
        $cf_lines = [
            " same => n,NoOp(Call forwarding on - {$ext} -> {$cf_target})",
        ];
        if ($perm_grp > 0) {
            $cf_lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$perm_grp})";
        }
        $cf_lines[] = " same => n,Goto({$cf_ctx},{$cf_target},1)";
        return [
            'terminal' => true,
            'lines' => $cf_lines
        ];
    }

    return ['terminal' => false, 'lines' => []];
}

/**
 * Generates all dialplan lines needed to call an extension:
 * 1. DND (do not disturb) check
 * 2. Always forward (unconditional - CFU) check
 * 3. Hop counter (prevents forwarding loops)
 * 4. Parallel dialing of the PJSIP contacts (SIP, WebRTC, mobile WebRTC)
 * 5. Post-Dial status routing:
 *    - BUSY / CONGESTION -> forward on busy (CFB)
 *    - NOANSWER -> forward on no answer (CFNA)
 *    - CHANUNAVAIL / no contact -> unreachable -> CFNA / CFB
 * 6. Loop-prevention exit label
 *
 * @param string $ext Extension number (e.g. 3001)
 * @param PDO $db Database connection
 * @param string $labelPrefix Prefix that keeps labels from colliding
 * @return array ['terminal' => bool, 'lines' => string[]]
 */
function buildExtensionDialLines(string $ext, PDO $db, string $labelPrefix = ''): array
{
    static $seq = 0;
    $seq++;
    $lbl = ($labelPrefix !== '' ? $labelPrefix . '_' : '') . $ext . '_' . $seq;

    $stmt = $db->prepare("SELECT record_policy, dnd_enabled, call_forward_number, cf_busy_number, cf_noanswer_number, cf_noanswer_timeout, allowed_phone_mode, voicemail_enabled, vm_on_noanswer, vm_on_busy, vm_on_unavail, vm_always, permission_group_id, outbound_group, followme_numbers, followme_mode, followme_ring_time, followme_confirm FROM sys_users WHERE extension = ?");
    $stmt->execute([$ext]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $dnd = !empty($u['dnd_enabled']);
    $cfAlways = preg_replace('/[^0-9+]/', '', trim($u['call_forward_number'] ?? ''));
    $cfBusy = preg_replace('/[^0-9+]/', '', trim($u['cf_busy_number'] ?? ''));
    $cfNoAnswer = preg_replace('/[^0-9+]/', '', trim($u['cf_noanswer_number'] ?? ''));
    $cfTimeout = intval($u['cf_noanswer_timeout'] ?? 20);
    if ($cfTimeout < 5 || $cfTimeout > 120) {
        $cfTimeout = 20;
    }
    $permGrp = (int)($u['permission_group_id'] ?? 0);
    $outGrp = max(1, (int)($u['outbound_group'] ?? 1));
    $cfContext = $outGrp > 1 ? "from-internal-desktop-{$outGrp}" : 'from-internal-desktop';

    $globalTimeout = intval(getSystemSetting('pjsip_internal_dial_timeout', '30')) ?: 30;
    $dialTimeout = ($cfNoAnswer !== '') ? $cfTimeout : $globalTimeout;
    $hasAnyCf = ($cfAlways !== '' || $cfBusy !== '' || $cfNoAnswer !== '');
    // Follow Me: the extension (with the list, in parallel mode) rings for its no-answer time;
    // in sequential mode each number of the list then rings for the Follow Me ring time.
    $fm = followMeConfig($u, $cfContext);
    if ($fm !== null) {
        $dialTimeout = $cfTimeout;
    }

    // 1. DND on: straight to Busy
    if ($dnd) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(DND on - {$ext})",
                ...statusPromptLines('busy', $ext),
                " same => n,Hangup(17)"
            ]
        ];
    }

    // 1.1 Unconditional voicemail forwarding on: straight to voicemail (unless SKIP_VM=1)
    if (!empty($u['voicemail_enabled']) && !empty($u['vm_always'])) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(Unconditional voicemail forwarding on - {$ext})",
                " same => n,GotoIf(\$[\"\${SKIP_VM}\" = \"1\" & \"\${BLINDTRANSFER}\" = \"\"]?vm_skip_always_{$lbl})",
                " same => n,VoiceMail({$ext}@default,u)",
                " same => n(vm_skip_always_{$lbl}),Hangup(17)"
            ]
        ];
    }

    $lines = [];

    // 2. Forwarding loop counter (hop counter)
    if ($hasAnyCf) {
        $lines[] = " same => n,Set(CF_HOPS=\$[0\${CF_HOPS} + 1])";
    }

    // 3. Always (unconditional) forwarding
    if ($cfAlways !== '') {
        $lines[] = " same => n,NoOp(Unconditional forwarding on - {$ext} -> {$cfAlways})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        if ($permGrp > 0) {
            $lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$permGrp})";
        }
        $lines[] = " same => n,Goto({$cfContext},{$cfAlways},1)";
        $lines[] = " same => n(cf_loop_{$lbl}),NoOp(Call forwarding loop blocked - {$ext})";
        $lines[] = " same => n,Hangup(17)";
        return [
            'terminal' => true,
            'lines' => $lines
        ];
    }

    // 3.5 Recording by a user's choice ("always"): this extension's, or the calling user's
    // (AIPBX_REC_POLICY from their endpoint). Never over one already running, nor when the
    // caller opted out. Only written while some user records always.
    if ($db->query("SELECT 1 FROM sys_users WHERE record_policy = 'always' LIMIT 1")->fetchColumn()) {
        require_once __DIR__ . '/../services/PrivacyService.php';
        $lines[] = " same => n,GotoIf(\$[\"\${MIXMONITOR_FILENAME}\" != \"\" | \"\${PRIVACY_NO_RECORD}\" = \"1\" | \"\${AIPBX_FAX}\" = \"1\"]?rec_skip_{$lbl})";
        if (($u['record_policy'] ?? '') !== 'always') {
            $lines[] = " same => n,GotoIf(\$[\"\${AIPBX_REC_POLICY}\" != \"always\"]?rec_skip_{$lbl})";
        }
        $lines[] = " same => n,Set(REC_FILE=/var/spool/asterisk/monitor/internal_\${STRFTIME(\${EPOCH},,%Y%m%d_%H%M%S)}_\${FILTER(0-9+,\${CALLERID(num)})}_to_{$ext}.wav)";
        $lines[] = " same => n,MixMonitor(\${REC_FILE}" . PrivacyService::mixMonitorOptionSuffix() . ")";
        $lines[] = " same => n,Set(CDR(userfield)=\${REC_FILE})";
        $lines[] = " same => n(rec_skip_{$lbl}),NoOp()";
    }

    // 4. Dial the devices (SIP, WebRTC, mobile WebRTC) - filtered by the active phone modes
    $modes = function_exists('parsePhoneModes')
        ? parsePhoneModes($u['allowed_phone_mode'] ?? null)
        : ['web', 'mobil', 'sip', 'video'];

    $allowSip = in_array('sip', $modes, true);
    $allowWeb = in_array('web', $modes, true);
    $allowMob = in_array('mobil', $modes, true);

    if ($allowSip) {
        $lines[] = " same => n,Set(C_SIP=\${PJSIP_DIAL_CONTACTS({$ext}-sip)})";
    } else {
        $lines[] = " same => n,Set(C_SIP=)";
    }

    if ($allowWeb) {
        $lines[] = " same => n,Set(C_WEB=\${PJSIP_DIAL_CONTACTS({$ext}-webrtc)})";
    } else {
        $lines[] = " same => n,Set(C_WEB=)";
    }

    if ($allowMob) {
        $lines[] = " same => n,Set(C_MOB=\${PJSIP_DIAL_CONTACTS({$ext}-mob-webrtc)})";
    } else {
        $lines[] = " same => n,Set(C_MOB=)";
    }

    // Mobile push notification hook (layer 1 - only when push is on, mobile is allowed and the subscriber has a mobile device)
    // Android (FCM) and iOS (APNs) are separate channels; either one counts.
    $pushEnabled = (string)getSystemSetting('push_enabled', '0') === '1';
    $pushChannelOn = (string)getSystemSetting('push_provider', 'none') !== 'none'
        || (string)getSystemSetting('push_apns_enabled', '0') === '1';
    $hasMobileDevice = false;

    if ($allowMob && $pushEnabled && $pushChannelOn) {
        $mStmt = $db->prepare("SELECT 1 FROM sys_mobile_devices WHERE extension = ? AND is_active = 1 AND ((fcm_token IS NOT NULL AND fcm_token <> '') OR voip_token IS NOT NULL) AND push_type <> 'none' LIMIT 1");
        $mStmt->execute([$ext]);
        $hasMobileDevice = (bool)$mStmt->fetchColumn();
    }

    if ($hasMobileDevice) {
        $pushWait = max(3, min(30, intval(getSystemSetting('push_wait_seconds', '8'))));
        $lines[] = " same => n,GotoIf(\$[\"\${C_MOB}\" != \"\"]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Set(PUSH_CNUM=\${FILTER(0-9+,\${CALLERID(num)})})";
        $lines[] = " same => n,Set(PUSH_CNAM=\${FILTER(a-zA-Z0-9 ._+-,\${CALLERID(name)})})";
        $lines[] = " same => n,System(/usr/local/bin/push_dispatcher.php {$ext} \"\${PUSH_CNUM}\" \"\${PUSH_CNAM}\" &)";
        $lines[] = " same => n,GotoIf(\$[\"\${C_SIP}\" != \"\" | \"\${C_WEB}\" != \"\"]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Ringing()";
        $lines[] = " same => n,Set(PUSH_WAIT={$pushWait})";
        $lines[] = " same => n(push_loop_{$lbl}),GotoIf(\$[0\${PUSH_WAIT} <= 0]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Wait(1)";
        $lines[] = " same => n,Set(C_MOB=\${PJSIP_DIAL_CONTACTS({$ext}-mob-webrtc)})";
        $lines[] = " same => n,GotoIf(\$[\"\${C_MOB}\" != \"\"]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Set(PUSH_WAIT=\$[0\${PUSH_WAIT} - 1])";
        $lines[] = " same => n,Goto(push_loop_{$lbl})";
        $lines[] = " same => n(lbl_has_mob_{$lbl}),NoOp(Mobile device check done)";
    }

    $lines[] = " same => n,Set(DIAL_CONTACTS=\${C_SIP}\${IF(\$[\"\${C_SIP}\" != \"\" & \"\${C_WEB}\" != \"\"]?&)}\${C_WEB})";
    $lines[] = " same => n,Set(DIAL_CONTACTS=\${DIAL_CONTACTS}\${IF(\$[\"\${DIAL_CONTACTS}\" != \"\" & \"\${C_MOB}\" != \"\"]?&)}\${C_MOB})";
    if ($fm !== null && $fm['mode'] === 'parallel') {
        // The list rings with the extension's own devices (not when this call is itself a Follow Me leg).
        $lines[] = " same => n,GotoIf(\$[\"\${FM_ACTIVE}\" = \"1\"]?fm_par_skip_{$lbl})";
        array_push($lines, ...followMeVarLines($fm, $permGrp));
        $legs = implode('&', $fm['legs']);
        $lines[] = " same => n,Set(DIAL_CONTACTS=\${DIAL_CONTACTS}\${IF(\$[\"\${DIAL_CONTACTS}\" != \"\"]?&)}{$legs})";
        $lines[] = " same => n(fm_par_skip_{$lbl}),NoOp()";
    }
    $lines[] = " same => n,GotoIf(\$[\"\${DIAL_CONTACTS}\" = \"\"]?lbl_unavail_{$lbl})";
    if (($jb = rtrim(jitterBufferLine())) !== '') {
        $lines[] = $jb;
    }
    $lines[] = " same => n,Dial(\${DIAL_CONTACTS},{$dialTimeout},tTb(sub-callee-jb^s^1)U(aipbx-callback-answered^\${FILTER(0-9+,\${CALLERID(num)})}^{$ext}))";

    // 5. Post-Dial status routing
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"BUSY\"]?lbl_busy_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"CONGESTION\"]?lbl_busy_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"NOANSWER\"]?lbl_noans_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"CHANUNAVAIL\"]?lbl_unavail_{$lbl})";
    $lines[] = " same => n,Hangup()";

    // 6. Busy
    $lines[] = " same => n(lbl_busy_{$lbl}),NoOp(Extension {$ext} busy - DIALSTATUS=\${DIALSTATUS})";
    if ($cfBusy !== '') {
        $lines[] = " same => n,NoOp(Forward on busy on - {$ext} -> {$cfBusy})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        if ($permGrp > 0) {
            $lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$permGrp})";
        }
        $lines[] = " same => n,Goto({$cfContext},{$cfBusy},1)";
    } elseif (!empty($u['voicemail_enabled']) && !empty($u['vm_on_busy'])) {
        $lines[] = " same => n,NoOp(Voicemail on busy - {$ext})";
        $lines[] = " same => n,GotoIf(\$[\"\${SKIP_VM}\" = \"1\" & \"\${BLINDTRANSFER}\" = \"\"]?vm_skip_busy_{$lbl})";
        $lines[] = " same => n,VoiceMail({$ext}@default,b)";
        $lines[] = " same => n(vm_skip_busy_{$lbl}),Hangup(17)";
    } else {
        array_push($lines, ...statusPromptLines('busy', $ext));
        $lines[] = " same => n,Hangup(17)";
    }

    // 7. No answer
    $lines[] = " same => n(lbl_noans_{$lbl}),NoOp(Extension {$ext} no answer - DIALSTATUS=\${DIALSTATUS})";
    if ($fm !== null && $fm['mode'] === 'sequential') {
        $lines[] = " same => n,Gosub(fm_seq_{$lbl})";
    }
    if ($cfNoAnswer !== '') {
        $lines[] = " same => n,NoOp(Forward on no answer on - {$ext} -> {$cfNoAnswer})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        if ($permGrp > 0) {
            $lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$permGrp})";
        }
        $lines[] = " same => n,Goto({$cfContext},{$cfNoAnswer},1)";
    } elseif (!empty($u['voicemail_enabled']) && !empty($u['vm_on_noanswer'])) {
        $lines[] = " same => n,NoOp(Voicemail on no answer - {$ext})";
        $lines[] = " same => n,GotoIf(\$[\"\${SKIP_VM}\" = \"1\" & \"\${BLINDTRANSFER}\" = \"\"]?vm_skip_noans_{$lbl})";
        $lines[] = " same => n,VoiceMail({$ext}@default,u)";
        $lines[] = " same => n(vm_skip_noans_{$lbl}),Hangup()";
    } else {
        array_push($lines, ...statusPromptLines('noanswer', $ext));
        $lines[] = " same => n,Hangup()";
    }

    // 8. Unreachable / offline
    $lines[] = " same => n(lbl_unavail_{$lbl}),NoOp(Extension {$ext} has no registered device or is unreachable)";
    if ($fm !== null && $fm['mode'] === 'sequential') {
        $lines[] = " same => n,Gosub(fm_seq_{$lbl})";
    }
    if ($cfNoAnswer !== '') {
        $lines[] = " same => n,NoOp(Unreachable -> forward on no answer: {$ext} -> {$cfNoAnswer})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        if ($permGrp > 0) {
            $lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$permGrp})";
        }
        $lines[] = " same => n,Goto({$cfContext},{$cfNoAnswer},1)";
    } elseif ($cfBusy !== '') {
        $lines[] = " same => n,NoOp(Unreachable -> forward on busy: {$ext} -> {$cfBusy})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        if ($permGrp > 0) {
            $lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$permGrp})";
        }
        $lines[] = " same => n,Goto({$cfContext},{$cfBusy},1)";
    } elseif (!empty($u['voicemail_enabled']) && (!empty($u['vm_on_unavail']) || !empty($u['vm_on_noanswer']))) {
        $lines[] = " same => n,NoOp(Unreachable -> voicemail - {$ext})";
        $lines[] = " same => n,GotoIf(\$[\"\${SKIP_VM}\" = \"1\" & \"\${BLINDTRANSFER}\" = \"\"]?vm_skip_unavail_{$lbl})";
        $lines[] = " same => n,VoiceMail({$ext}@default,u)";
        $lines[] = " same => n(vm_skip_unavail_{$lbl}),Hangup()";
    } else {
        array_push($lines, ...statusPromptLines('unavail', $ext));
        $lines[] = " same => n,Hangup()";
    }

    // 9. Loop-prevention exit
    if ($hasAnyCf) {
        $lines[] = " same => n(cf_loop_{$lbl}),NoOp(Call forwarding loop blocked - {$ext})";
        $lines[] = " same => n,Hangup(17)";
    }

    // 10. Follow Me, one number after the other (reached by Gosub from no answer / unreachable).
    // An answered leg ends the call here; otherwise the extension's own no-answer handling follows.
    if ($fm !== null && $fm['mode'] === 'sequential') {
        $lines[] = " same => n(fm_seq_{$lbl}),GotoIf(\$[\"\${FM_ACTIVE}\" = \"1\"]?fm_ret_{$lbl})";
        array_push($lines, ...followMeVarLines($fm, $permGrp));
        foreach ($fm['legs'] as $leg) {
            $lines[] = " same => n,Dial({$leg},{$fm['ring']},t)";
            $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"ANSWER\"]?fm_end_{$lbl})";
        }
        $lines[] = " same => n(fm_ret_{$lbl}),Return()";
        $lines[] = " same => n(fm_end_{$lbl}),Hangup()";
    }

    return [
        'terminal' => false,
        'lines' => $lines
    ];
}

/**
 * Follow Me of a user: the numbers to ring as Dial legs, or null when the list is empty.
 * A number is dialled in the user's outbound context (permissions and routes apply); with
 * confirm on, through [followme-confirm] so a voicemail cannot take the call.
 *
 * @return array{legs: list<string>, mode: string, ring: int, ctx: string}|null
 */
function followMeConfig(array $u, string $cfContext): ?array
{
    $numbers = [];
    foreach (explode(',', (string) ($u['followme_numbers'] ?? '')) as $n) {
        $n = (string) preg_replace('/[^0-9+]/', '', trim($n));
        if ($n !== '' && preg_match('/^\+?[0-9]{2,20}$/', $n) && !in_array($n, $numbers, true)) {
            $numbers[] = $n;
        }
    }
    if (!$numbers) {
        return null;
    }
    $confirm = !isset($u['followme_confirm']) || !empty($u['followme_confirm']);
    $legs = array_map(static fn(string $n): string => $confirm ? "Local/{$n}@followme-confirm/n" : "Local/{$n}@{$cfContext}/n", array_slice($numbers, 0, 5));
    $ring = (int) ($u['followme_ring_time'] ?? 20);
    return [
        'legs' => $legs,
        'mode' => ($u['followme_mode'] ?? '') === 'parallel' ? 'parallel' : 'sequential',
        'ring' => $ring >= 5 && $ring <= 120 ? $ring : 20,
        'ctx' => $cfContext,
    ];
}

/**
 * Variables the Follow Me legs inherit: FM_ACTIVE stops a leg that reaches another extension
 * from starting that one's Follow Me (no loops); FM_CTX / FM_RING are for [followme-confirm].
 *
 * @param array{legs: list<string>, mode: string, ring: int, ctx: string} $fm
 * @return list<string>
 */
function followMeVarLines(array $fm, int $permGrp): array
{
    $lines = [
        " same => n,Set(__FM_ACTIVE=1)",
        " same => n,Set(__FM_CTX={$fm['ctx']})",
        " same => n,Set(__FM_RING={$fm['ring']})",
    ];
    if ($permGrp > 0) {
        $lines[] = " same => n,Set(__PERMISSION_GROUP_ID={$permGrp})";
    }
    return $lines;
}

/**
 * A spoken message before an extension's call ends busy, unanswered or
 * unreachable (PBX settings → Ringtone), instead of only a tone. Played by
 * [sub-status-prompt] (SyncGeneralDialplan) in the channel's language.
 *
 * @return list<string> dialplan lines, none when the case is switched off
 */
function statusPromptLines(string $case, string $ext): array
{
    if (getSystemSetting('status_prompt_' . $case, '0') !== '1') {
        return [];
    }
    $digits = preg_replace('/[^0-9]/', '', $ext);
    return [" same => n,Gosub(sub-status-prompt,s,1({$case},{$digits}))"];
}

