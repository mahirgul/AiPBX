<?php
/**
 * Dialplan generation helpers (shared "builder" functions)
 *
 * Line-generating functions SHARED by the inbound/outbound/general dialplan
 * generators and SyncIVRs/SyncTimeConditions. Split out here on 2026-08-31
 * when SyncDialplan.php (516 lines) was divided into four areas — this file
 * knows "how lines are generated", not "which config file is written".
 */

require_once __DIR__ . '/../file_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';
/**
 * Behaviour after a queue timeout/failure (cc_fallback_action)
 */
function buildCallCenterFallbackLines($action = 'hangup', $target = '') {
    $target = preg_replace('/[^0-9]/', '', $target ?? '');

    if ($action === 'forward' && $target !== '') {
        $dest_lines = buildDestinationLines('extension', $target);
        return array_values(array_filter(explode("\n", $dest_lines), fn($l) => trim($l) !== ''));
    }
    if ($action === 'closed_msg') {
        return [
            " same => n,Playback(custom/closed)",
            " same => n,Hangup()"
        ];
    }
    return [" same => n,Hangup()"];
}

/**
 * Generates the CALLERID(num) line for one outbound route attempt (trunk entry).
 * The trunk's own caller ID masking wins if set (e.g. a number the trunk
 * carrier requires); otherwise the calling extension's own internal/external
 * CID preference is used (PJSIP endpoint set_var: CID_INTERNAL/CID_EXTERNAL,
 * see SyncExtensions.php). If both are empty the current CALLERID(num) stays.
 *
 * Display name (CALLERID(name)): when pbx_trunks.send_caller_name is off
 * (trunk settings, user request 2026-08-31) it is explicitly cleared on calls
 * leaving through this route/trunk — normally the name inherited from the
 * calling extension's own SIP client/endpoint identity went all the way to the
 * trunk; this line cuts that. When on, nothing is touched (the old behaviour
 * stays). NOTE: fax calls (FaxSendService::submitCallFile()) NEVER look at this
 * setting and are always nameless — a separate origination path outside the
 * scope of this function.
 */
/**
 * Trunk outbound caller ID normalization: keep the last N digits, then prepend.
 * E.g. keep 4 + prepend 90370418: 7840 → 903704187840.
 */
function buildTrunkCidNormalizeLine($norm) {
    $keep = intval($norm['keep_last'] ?? 0);
    $prepend = preg_replace('/[^0-9+]/', '', (string)($norm['prepend'] ?? ''));
    if ($keep <= 0 && $prepend === '') {
        return '';
    }
    $core = $keep > 0 ? "\${CALLERID(num):-{$keep}}" : "\${CALLERID(num)}";
    return " same => n,ExecIf(\$[\"\${CALLERID(num)}\" != \"\"]?Set(CALLERID(num)={$prepend}{$core}))\n";
}

function buildTrunkCallerIdLine($trunk_entry, $is_internal) {
    $lines = '';
    $override = preg_replace('/[^0-9]/', '', trim($trunk_entry['callerid_override'] ?? ''));
    $trunk_name = trim($trunk_entry['trunk_name'] ?? '');

    $trunk_cid = '';
    $send_name = 1;
    if ($trunk_name !== '') {
        $t_stmt = getDB()->prepare("SELECT outbound_caller_id, send_caller_name, from_user FROM pbx_trunks WHERE trunk_name = ?");
        $t_stmt->execute([$trunk_name]);
        $t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
        if ($t_row) {
            $trunk_cid = preg_replace('/[^0-9]/', '', trim($t_row['outbound_caller_id'] ?? ''));
            if ($trunk_cid === '' && !empty($t_row['from_user'])) {
                $trunk_cid = preg_replace('/[^0-9]/', '', trim($t_row['from_user'] ?? ''));
            }
            $send_name = intval($t_row['send_caller_name'] ?? 1);
        }
    }

    if ($is_internal) {
        if (!empty($override)) {
            $lines .= " same => n,Set(CALLERID(num)={$override})\n";
        } else {
            $lines .= " same => n,Set(CALLERID(num)=\${IF(\$[\"\${CID_INTERNAL}\" != \"\"]?\${CID_INTERNAL}:\${CALLERID(num)})})\n";
        }
    } else {
        // External call: the user's sys_users.cid_external (CID_EXTERNAL) wins.
        // If the user has no external CID, the trunk CID (outbound_caller_id) or the route override is sent.
        $fallback_cid = !empty($trunk_cid) ? $trunk_cid : $override;
        if (!empty($fallback_cid)) {
            // On transit calls (a call coming in from another trunk) keep the caller's number
            $lines .= " same => n,ExecIf(\$[\"\${CDR(inbound_trunk)}\" = \"\"]?Set(CALLERID(num)=\${IF(\$[\"\${CID_EXTERNAL}\" != \"\"]?\${CID_EXTERNAL}:{$fallback_cid})}))\n";
        } else {
            $lines .= " same => n,ExecIf(\$[\"\${CID_EXTERNAL}\" != \"\"]?Set(CALLERID(num)=\${CID_EXTERNAL}))\n";
        }
    }

    if (!$send_name) {
        $lines .= " same => n,Set(CALLERID(name)=)\n";
    }

    return $lines;
}

/**
 * Added before dialing an extension (Dial): Busy when DND is on, Goto to the
 * forwarding target when there is one — both are "terminal" (no Dial block is
 * written). The forwarding target is a Goto into [from-internal-pbx]; that
 * context includes [from-internal-outbound], so it works whether the target
 * is an extension or an outside number (external numbers resolve through the
 * include chain).
 */
function buildDndCfCheckLines($ext, $db) {
    $stmt = $db->prepare("SELECT dnd_enabled, call_forward_number FROM sys_users WHERE extension = ?");
    $stmt->execute([$ext]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($u['dnd_enabled'])) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(DND aktif - {$ext})",
                " same => n,Hangup(17)"
            ]
        ];
    }

    $cf_target = preg_replace('/[^0-9]/', '', trim($u['call_forward_number'] ?? ''));
    if ($cf_target !== '') {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(Cagri Yonlendirme aktif - {$ext} -> {$cf_target})",
                " same => n,Goto(from-internal-pbx,{$cf_target},1)"
            ]
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

    $stmt = $db->prepare("SELECT dnd_enabled, call_forward_number, cf_busy_number, cf_noanswer_number, cf_noanswer_timeout, allowed_phone_mode, voicemail_enabled, vm_on_noanswer, vm_on_busy, vm_on_unavail, vm_always FROM sys_users WHERE extension = ?");
    $stmt->execute([$ext]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $dnd = !empty($u['dnd_enabled']);
    $cfAlways = preg_replace('/[^0-9]/', '', trim($u['call_forward_number'] ?? ''));
    $cfBusy = preg_replace('/[^0-9]/', '', trim($u['cf_busy_number'] ?? ''));
    $cfNoAnswer = preg_replace('/[^0-9]/', '', trim($u['cf_noanswer_number'] ?? ''));
    $cfTimeout = intval($u['cf_noanswer_timeout'] ?? 20);
    if ($cfTimeout < 5 || $cfTimeout > 120) {
        $cfTimeout = 20;
    }

    $globalTimeout = intval(getSystemSetting('pjsip_internal_dial_timeout', '30')) ?: 30;
    $dialTimeout = ($cfNoAnswer !== '') ? $cfTimeout : $globalTimeout;
    $hasAnyCf = ($cfAlways !== '' || $cfBusy !== '' || $cfNoAnswer !== '');

    // 1. DND on: straight to Busy
    if ($dnd) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(DND aktif - {$ext})",
                " same => n,Hangup(17)"
            ]
        ];
    }

    // 1.1 Unconditional voicemail forwarding on: straight to voicemail
    if (!empty($u['voicemail_enabled']) && !empty($u['vm_always'])) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(Kosulsuz Sesli Postaya Yonlendirme aktif - {$ext})",
                " same => n,VoiceMail({$ext}@default,u)",
                " same => n,Hangup()"
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
        $lines[] = " same => n,NoOp(Her Zaman Yonlendirme aktif - {$ext} -> {$cfAlways})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfAlways},1)";
        $lines[] = " same => n(cf_loop_{$lbl}),NoOp(Cagri Yonlendirme Dongusu Engellendi - {$ext})";
        $lines[] = " same => n,Hangup(17)";
        return [
            'terminal' => true,
            'lines' => $lines
        ];
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
    $pushEnabled = (string)getSystemSetting('push_enabled', '0') === '1';
    $pushProvider = (string)getSystemSetting('push_provider', 'none');
    $hasMobileDevice = false;

    if ($allowMob && $pushEnabled && $pushProvider !== 'none') {
        $mStmt = $db->prepare("SELECT 1 FROM sys_mobile_devices WHERE extension = ? AND is_active = 1 AND fcm_token IS NOT NULL AND fcm_token <> '' AND push_type <> 'none' LIMIT 1");
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
        $lines[] = " same => n(lbl_has_mob_{$lbl}),NoOp(Mobil cihaz kontrolu tamamlandi)";
    }

    $lines[] = " same => n,Set(DIAL_CONTACTS=\${C_SIP}\${IF(\$[\"\${C_SIP}\" != \"\" & \"\${C_WEB}\" != \"\"]?&)}\${C_WEB})";
    $lines[] = " same => n,Set(DIAL_CONTACTS=\${DIAL_CONTACTS}\${IF(\$[\"\${DIAL_CONTACTS}\" != \"\" & \"\${C_MOB}\" != \"\"]?&)}\${C_MOB})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIAL_CONTACTS}\" = \"\"]?lbl_unavail_{$lbl})";
    $lines[] = " same => n,Set(JITTERBUFFER(adaptive)=default)";
    $lines[] = " same => n,Dial(\${DIAL_CONTACTS},{$dialTimeout},tTb(sub-callee-jb^s^1))";

    // 5. Post-Dial status routing
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"BUSY\"]?lbl_busy_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"CONGESTION\"]?lbl_busy_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"NOANSWER\"]?lbl_noans_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"CHANUNAVAIL\"]?lbl_unavail_{$lbl})";
    $lines[] = " same => n,Hangup()";

    // 6. Busy
    $lines[] = " same => n(lbl_busy_{$lbl}),NoOp(Dahili {$ext} Mesgul - DIALSTATUS=\${DIALSTATUS})";
    if ($cfBusy !== '') {
        $lines[] = " same => n,NoOp(Mesgulken Yonlendirme aktif - {$ext} -> {$cfBusy})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfBusy},1)";
    } elseif (!empty($u['voicemail_enabled']) && !empty($u['vm_on_busy'])) {
        $lines[] = " same => n,NoOp(Mesgulken Sesli Posta aktif - {$ext})";
        $lines[] = " same => n,VoiceMail({$ext}@default,b)";
        $lines[] = " same => n,Hangup()";
    } else {
        $lines[] = " same => n,Hangup(17)";
    }

    // 7. No answer
    $lines[] = " same => n(lbl_noans_{$lbl}),NoOp(Dahili {$ext} Cevapsiz - DIALSTATUS=\${DIALSTATUS})";
    if ($cfNoAnswer !== '') {
        $lines[] = " same => n,NoOp(Cevapsizken Yonlendirme aktif - {$ext} -> {$cfNoAnswer})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfNoAnswer},1)";
    } elseif (!empty($u['voicemail_enabled']) && !empty($u['vm_on_noanswer'])) {
        $lines[] = " same => n,NoOp(Cevapsizken Sesli Posta aktif - {$ext})";
        $lines[] = " same => n,VoiceMail({$ext}@default,u)";
        $lines[] = " same => n,Hangup()";
    } else {
        $lines[] = " same => n,Hangup()";
    }

    // 8. Unreachable / offline
    $lines[] = " same => n(lbl_unavail_{$lbl}),NoOp(Dahili {$ext} Kayitli Cihaz Yok veya Ulasilamiyor)";
    if ($cfNoAnswer !== '') {
        $lines[] = " same => n,NoOp(Ulasilamadi -> Cevapsizken Yonlendirme: {$ext} -> {$cfNoAnswer})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfNoAnswer},1)";
    } elseif ($cfBusy !== '') {
        $lines[] = " same => n,NoOp(Ulasilamadi -> Mesgulken Yonlendirme: {$ext} -> {$cfBusy})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfBusy},1)";
    } elseif (!empty($u['voicemail_enabled']) && (!empty($u['vm_on_unavail']) || !empty($u['vm_on_noanswer']))) {
        $lines[] = " same => n,NoOp(Ulasilamadi -> Sesli Posta aktif - {$ext})";
        $lines[] = " same => n,VoiceMail({$ext}@default,u)";
        $lines[] = " same => n,Hangup()";
    } else {
        $lines[] = " same => n,Hangup()";
    }

    // 9. Loop-prevention exit
    if ($hasAnyCf) {
        $lines[] = " same => n(cf_loop_{$lbl}),NoOp(Cagri Yonlendirme Dongusu Engellendi - {$ext})";
        $lines[] = " same => n,Hangup(17)";
    }

    return [
        'terminal' => false,
        'lines' => $lines
    ];
}

/**
 * Format Native Dialplan Destination Lines
 */
function buildDestinationLines($dest_type, $dest_id, $orig_did = '', $derinlik = 0) {
    $db = getDB();
    $lines = [];
    $internal_dial_timeout = intval(getSystemSetting('pjsip_internal_dial_timeout', '30'));

    switch ($dest_type) {
        case 'queue':
            // 2026-08-19: queue timeout/fallback/recording settings are per queue (pbx_queues)
            // now, not global sys_settings — several queues can behave differently.
            $q_row = is_numeric($dest_id)
                ? $db->prepare("SELECT * FROM pbx_queues WHERE id = ?")
                : $db->prepare("SELECT * FROM pbx_queues WHERE queue_name = ?");
            $q_row->execute([$dest_id]);
            $q = $q_row->fetch();
            $q_name = $q['queue_name'] ?? (is_numeric($dest_id) ? 'queue_cc' : preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$dest_id));

            if (!empty($q['record_enabled'] ?? 1)) {
                $rec_format = $q['record_format'] ?? 'wav';
                // Do not start a second recording if one is ALREADY running.
                //
                // When the inbound route has "mandatory recording" ticked,
                // MixMonitor starts there; starting another one here attaches
                // two recorders to the same channel, produces two files and
                // CDR(userfield) is overwritten by the second — clicking the
                // recording in the panel played only the queue part.
                // MixMonitor fills MIXMONITOR_FILENAME itself.
                $lines[] = " same => n,GotoIf(\$[\"\${MIXMONITOR_FILENAME}\" != \"\"]?kayit_var_{$q_name})";
                $lines[] = " same => n,Set(REC_FILE=/var/spool/asterisk/monitor/inbound_\${STRFTIME(\${EPOCH},,%Y%m%d_%H%M%S)}_\${FILTER(0-9+,\${CALLERID(num)})}.{$rec_format})";
                $lines[] = " same => n,MixMonitor(\${REC_FILE})";
                $lines[] = " same => n,Set(CDR(userfield)=\${REC_FILE})";
                $lines[] = " same => n(kayit_var_{$q_name}),NoOp(Kuyruk kaydi: \${MIXMONITOR_FILENAME})";
            }
            // A language set on the queue itself overrides the language set so
            // far (inbound route/IVR) — queue hold prompts (announce-holdtime/
            // position) play in the caller's channel language; the most specific
            // (closest to the end) setting wins.
            if (!empty($q['language'])) {
                $q_lang = preg_replace('/[^a-zA-Z_]/', '', $q['language']);
                $lines[] = " same => n,Set(CHANNEL(language)={$q_lang})";
            }
            $queue_timeout = intval($q['max_wait_seconds'] ?? 300) ?: 300;
            $lines[] = " same => n,Queue({$q_name},tT,,,{$queue_timeout})";
            $lines = array_merge($lines, buildCallCenterFallbackLines($q['fallback_action'] ?? 'hangup', $q['fallback_target'] ?? ''));
            break;

        case 'ivr':
            $lines[] = " same => n,Goto(app-ivr-" . intval($dest_id) . ",s,1)";
            break;

        case 'time_condition':
            $lines[] = " same => n,Goto(app-timecondition-" . intval($dest_id) . ",s,1)";
            break;

        case 'extension':
            // buildDestinationLines('extension', ...) can be called more than
            // once inside the same context/exten block (e.g. a multi-rule time
            // condition with the same extension as both match and nomatch
            // target). A fixed "ext_unavail_{$ext}" label would then be
            // generated twice and break the Asterisk dialplan reload — a unique
            // counter is added per call site.
            static $ext_label_seq = 0;
            $ext_label_seq++;
            $ext = preg_replace('/[^0-9]/', '', $dest_id);
            $lines[] = " same => n,Set(CHANNEL(accountcode)={$ext})";

            $extDial = buildExtensionDialLines($ext, $db, "dest_{$ext_label_seq}");
            $lines = array_merge($lines, $extDial['lines']);
            break;

        case 'ring_group':
            $lines[] = " same => n,Goto(app-ringgroup-" . intval($dest_id) . ",s,1)";
            break;

        case 'conference':
            $lines[] = " same => n,Goto(app-confbridge-" . intval($dest_id) . ",s,1)";
            break;

        case 'voicemail':
            $vm_ext = preg_replace('/[^0-9]/', '', (string)$dest_id);
            $lines[] = " same => n,VoiceMail({$vm_ext}@default,u)";
            $lines[] = " same => n,Hangup()";
            break;

        case 'fax':
            // The Goto target must be the DID number itself; the [from-trunk-fax]
            // context matches the _X. pattern (single-digit sequence numbers would not).
            $target = !empty($orig_did) ? $orig_did : (!empty($dest_id) ? $dest_id : 'default');
            $lines[] = " same => n,Goto(from-trunk-fax,{$target},1)";
            break;

        case 'outbound_route':
            // Sent straight to the chosen route (its trunks, number manipulation
            // and caller ID) with the DID as the dialled number; the route's
            // pattern is not checked. Inactive/missing route: normal group-1 routing.
            $target = !empty($orig_did) ? $orig_did : '${EXTEN}';
            $route_active = false;
            if (is_numeric($dest_id)) {
                $st = $db->prepare("SELECT 1 FROM pbx_outbound_routes WHERE id = ? AND is_active = 1");
                $st->execute([(int)$dest_id]);
                $route_active = (bool)$st->fetchColumn();
            }
            $lines[] = " same => n,Set(CDR(direction)=outbound)";
            $lines[] = $route_active
                ? " same => n,Goto(outbound-route-" . (int)$dest_id . ",{$target},1)"
                : " same => n,Goto(from-internal-outbound-1,{$target},1)";
            break;

        case 'announcement':
            $sound_file = '';
            if (is_numeric($dest_id)) {
                $st = $db->prepare("SELECT audio_file FROM pbx_announcements WHERE id = ? AND is_active = 1");
                $st->execute([$dest_id]);
                $sound_file = $st->fetchColumn() ?: '';
            } else {
                $sound_file = toCleanAscii($dest_id);
            }
            if (!empty($sound_file)) {
                $sound_clean = FileHelper::sanitizeAudioPath($sound_file);
                $lines[] = " same => n,Playback({$sound_clean})";
            }

            // Destination after the announcement ends. `post_dest_type`/
            // `post_dest_id` were saved by the panel but NEVER READ HERE: the
            // call was hung up whatever was selected (2026-09-01 field audit).
            //
            // $derinlik keeps an announcement -> announcement chain from going
            // on forever.
            $sonraki_tip = '';
            $sonraki_id  = '';
            if (is_numeric($dest_id)) {
                $ps = $db->prepare("SELECT post_dest_type, post_dest_id FROM pbx_announcements WHERE id = ?");
                $ps->execute([$dest_id]);
                if ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
                    $sonraki_tip = trim((string)($row['post_dest_type'] ?? ''));
                    $sonraki_id  = trim((string)($row['post_dest_id'] ?? ''));
                }
            }

            if ($sonraki_tip !== '' && $sonraki_tip !== 'hangup' && $derinlik < 3) {
                $lines[] = buildDestinationLines($sonraki_tip, $sonraki_id, $orig_did, $derinlik + 1);
            } else {
                if ($derinlik >= 3) {
                    $lines[] = " same => n,NoOp(Anons zinciri cok derin, kapatiliyor)";
                }
                $lines[] = " same => n,Hangup()";
            }
            break;

        case 'hangup':
        default:
            $ha_stmt = $db->prepare("SELECT ha.action_type, anc.audio_file FROM pbx_hangup_actions ha LEFT JOIN pbx_announcements anc ON ha.announcement_id = anc.id WHERE ha.action_key = ? AND ha.is_active = 1 AND (anc.id IS NULL OR anc.is_active = 1)");
            $ha_stmt->execute([$dest_id]);
            $ha_row = $ha_stmt->fetch(PDO::FETCH_ASSOC);
            $action_type = $ha_row['action_type'] ?? $dest_id;

            if (!empty($ha_row['audio_file'])) {
                $sound_clean = FileHelper::sanitizeAudioPath($ha_row['audio_file']);
                $lines[] = " same => n,Playback({$sound_clean})";
            }

            if ($action_type === 'busy' || $dest_id === 'busy') {
                $lines[] = " same => n,Busy(10)";
            } elseif ($action_type === 'congestion' || $dest_id === 'congestion') {
                $lines[] = " same => n,Congestion(10)";
            } else {
                $lines[] = " same => n,Hangup()";
            }
            break;
    }

    return implode("\n", $lines);
}
