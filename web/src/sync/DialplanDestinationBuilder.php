<?php
/**
 * PBX Destination & Call Center Fallback Dialplan Builder
 *
 * Extracted from DialplanBuilders.php to keep destination routing (queues, IVRs,
 * time conditions, extensions, ring groups, conferences, voicemail, fax,
 * outbound routes, announcements, AI apps, and hangup actions) in a focused module.
 */

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
 * Format Native Dialplan Destination Lines
 */
function buildDestinationLines($dest_type, $dest_id, $orig_did = '', $depth = 0) {
    $db = getDB();
    $lines = [];
    $internal_dial_timeout = intval(getSystemSetting('pjsip_internal_dial_timeout', '30'));

    switch ($dest_type) {
        case 'builtin_ai_agent':
        case 'ai_app':
            // AI → Applications: its own context answers, speaks and goes on (SyncAiApps.php).
            $lines[] = " same => n,Goto(aipbx-ai-app-" . (int) $dest_id . ",s,1)";
            break;

        case 'ai_agent':
            // AI → External agents: the agent at its SIP address (SyncAiAgents.php).
            $lines[] = " same => n,Goto(aipbx-ai-agent-" . (int) $dest_id . ",s,1)";
            break;

        case 'ivr_data':
            // Roadmap 2.3: IVR Data Step context (SyncIvrDataSteps.php).
            $lines[] = " same => n,Goto(aipbx-ivr-data-" . (int) $dest_id . ",s,1)";
            break;

        case 'call_modifier':
            $stMod = $db->prepare('SELECT * FROM pbx_call_modifiers WHERE id = ? AND is_active = 1');
            $stMod->execute([(int) $dest_id]);
            $mod = $stMod->fetch(PDO::FETCH_ASSOC);
            // $depth: a modifier that leads to itself (or a ring of them) must end.
            if ($mod && $depth < 5) {
                if (($mod['mod_type'] ?? '') === 'language') {
                    $lang = (string) ($mod['mod_value'] ?? '');
                    if (in_array($lang, getAvailableLanguages(), true)) {
                        $lines[] = " same => n,Set(CHANNEL(language)={$lang})";
                    }
                } elseif (($mod['mod_type'] ?? '') === 'cid_prefix') {
                    $prefix = dialplanText((string) ($mod['mod_value'] ?? ''));
                    if ($prefix !== '') {
                        $lines[] = " same => n,Set(CALLERID(name)={$prefix} \${CALLERID(name)})";
                    }
                }
                $next = buildDestinationLines((string) $mod['dest_type'], (string) ($mod['dest_id'] ?? ''), $orig_did, $depth + 1);
                if ($next !== '') {
                    $lines[] = $next;
                }
            } else {
                // Deleted or switched off: the call must not run on into whatever follows.
                $lines[] = " same => n,Hangup()";
            }
            break;

        case 'queue':
            // 2026-08-19: queue timeout/fallback/recording settings are per queue (pbx_queues)
            // now, not global sys_settings — several queues can behave differently.
            $q_row = is_numeric($dest_id)
                ? $db->prepare("SELECT * FROM pbx_queues WHERE id = ?")
                : $db->prepare("SELECT * FROM pbx_queues WHERE queue_name = ?");
            $q_row->execute([$dest_id]);
            $q = $q_row->fetch();
            $q_name = $q['queue_name'] ?? (is_numeric($dest_id) ? 'queue_cc' : preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$dest_id));
            $qId = (int) ($q['id'] ?? 0);
            $preAiId = (int) ($q['pre_queue_ai_app_id'] ?? 0);
            if ($qId > 0 && $preAiId > 0) {
                $aiActive = false;
                try {
                    $stAi = $db->prepare('SELECT 1 FROM pbx_ai_apps WHERE id = ? AND is_active = 1');
                    $stAi->execute([$preAiId]);
                    $aiActive = (bool) $stAi->fetchColumn();
                } catch (\Throwable $e) {
                }
                if ($aiActive) {
                    static $preq_seq = 0;
                    $preqSkip = "preq_{$qId}_" . (++$preq_seq);
                    $lines[] = " same => n,GotoIf(\$[\"\${AIPBX_PRE_QUEUE_DONE_{$qId}}\" = \"1\"]?{$preqSkip})";
                    $lines[] = " same => n,Set(__AIPBX_PRE_QUEUE_DONE_{$qId}=1)";
                    $lines[] = " same => n,Set(__AIPBX_PRE_QUEUE_Q={$qId})";
                    $lines[] = " same => n,Goto(aipbx-ai-app-{$preAiId},s,1)";
                    $lines[] = " same => n({$preqSkip}),NoOp(Pre-Queue AI completed for queue {$qId})";
                }
            }

            $recordQueue = !empty($q['record_enabled'] ?? 1);
            if ($recordQueue) {
                // Queue's AGI hook runs on the CALLER only after an agent answers.
                // Preparing a name here must not create a file or link an unanswered call to one.
                $rec_format = in_array($q['record_format'] ?? '', QUEUE_RECORD_FORMATS, true) ? $q['record_format'] : 'wav';
                require_once __DIR__ . '/../services/PrivacyService.php';
                $recordOptions = ltrim(PrivacyService::mixMonitorOptionSuffix(), ',');
                $lines[] = " same => n,Set(AIPBX_QUEUE_REC_FILE=/var/spool/asterisk/monitor/inbound_\${STRFTIME(\${EPOCH},,%Y%m%d_%H%M%S)}_\${FILTER(0-9+,\${CALLERID(num)})}_\${UNIQUEID}.{$rec_format})";
                $lines[] = " same => n,Set(AIPBX_QUEUE_REC_OPTIONS={$recordOptions})";
                if (($q['record_mode'] ?? 'answer') === 'enter') {
                    // From entering the queue (hold music included), as before 1.80. Not over a
                    // recording the inbound route already runs, nor when the caller opted out.
                    // The label is unique per use: a time condition can send several branches here.
                    static $queue_label_seq = 0;
                    $skip = 'queue_rec_' . (++$queue_label_seq);
                    $lines[] = " same => n,GotoIf(\$[\"\${PRIVACY_NO_RECORD}\" = \"1\" | \"\${MIXMONITOR_FILENAME}\" != \"\"]?{$skip})";
                    $lines[] = " same => n,MixMonitor(\${AIPBX_QUEUE_REC_FILE}" . ($recordOptions !== '' ? ",{$recordOptions}" : '') . ")";
                    $lines[] = " same => n,Set(CDR(userfield)=\${MIXMONITOR_FILENAME})";
                    $lines[] = " same => n({$skip}),NoOp(Queue recording: \${MIXMONITOR_FILENAME})";
                    $recordQueue = false;   // no hook after the answer
                }
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
            // Answered first: an unanswered caller heard none of the queue's
            // music or position/hold-time announcements (only 180 Ringing went out).
            $lines[] = " same => n,ExecIf(\$[\"\${CHANNEL(state)}\" != \"Up\"]?Answer())";
            // A member's own voicemail must not take the queue call (SKIP_VM, as for a secretary).
            $lines[] = " same => n,Set(__SKIP_VM=1)";
            $join_file = FileHelper::sanitizeAudioPath((string) ($q['join_announce'] ?? ''));
            if ($join_file !== '') {
                $lines[] = " same => n,Playback({$join_file})";
            }
            $lines[] = " same => n,ExecIf(\$[\"\${AI_SUMMARY}\" != \"\"]?Set(AIPBX_AI_HANDOVER=\${FILTER(A-Za-z0-9 ._-:,\${AI_SUMMARY})}))";
            $lines[] = " same => n,ExecIf(\$[\"\${AIPBX_AI_HANDOVER}\" != \"\"]?Set(CALLERID(name)=\${CALLERID(name)} | \${AIPBX_AI_HANDOVER:0:60}))";
            $queueAgi = $recordQueue ? ",/usr/local/bin/queue_record.php" : '';
            // After-call survey: with "c" the caller stays when the agent hangs up and is asked to rate.
            $survey = !empty($q['survey_enabled']) ? FileHelper::sanitizeAudioPath((string) ($q['survey_announce'] ?? '')) : '';
            if ($survey !== '') {
                $lines[] = " same => n,Set(MEMBERINTERFACE=)";
            }
            $lines[] = " same => n,Queue({$q_name}," . ($survey !== '' ? 'tTc' : 'tT') . ",,,{$queue_timeout}{$queueAgi})";
            $lines[] = " same => n,Set(__SKIP_VM=0)";
            if ($survey !== '') {
                static $survey_label_seq = 0;
                $nos = 'qsurvey_no_' . (++$survey_label_seq);
                $lines[] = " same => n,GotoIf(\$[\"\${MEMBERINTERFACE}\" = \"\"]?{$nos})";
                $lines[] = " same => n,Gosub(sub-queue-survey,s,1({$q_name},{$survey}))";
                $lines[] = " same => n,Hangup()";
                $lines[] = " same => n({$nos}),NoOp(Queue {$q_name}: not answered)";
            }
            $lines = array_merge($lines, buildCallCenterFallbackLines($q['fallback_action'] ?? 'hangup', $q['fallback_target'] ?? ''));
            break;

        case 'ivr':
            $lines[] = " same => n,Goto(app-ivr-" . intval($dest_id) . ",s,1)";
            break;

        case 'time_condition':
            $lines[] = " same => n,Goto(app-timecondition-" . intval($dest_id) . ",s,1)";
            break;

        case 'extension':
            if ($dest_id === '__did__') {
                $lines[] = " same => n,GotoIf(\$[\${DIALPLAN_EXISTS(from-internal-pbx-ortak,\${EXTEN},1)}]?from-internal-pbx-ortak,\${EXTEN},1)";
                $extLengths = [4, 5, 3, 6, 2];
                try {
                    $dbLens = $db->query("SELECT DISTINCT CHAR_LENGTH(extension) FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($dbLens as $dl) {
                        $dl = (int)$dl;
                        if ($dl > 0 && !in_array($dl, $extLengths, true)) {
                            $extLengths[] = $dl;
                        }
                    }
                } catch (\Throwable $e) {
                    // keep default lengths
                }
                foreach ($extLengths as $tailLen) {
                    $lines[] = " same => n,GotoIf(\$[\$[\${LEN(\${EXTEN})} > {$tailLen}] & \$[\${DIALPLAN_EXISTS(from-internal-pbx-ortak,\${EXTEN:-{$tailLen}},1)}]]?from-internal-pbx-ortak,\${EXTEN:-{$tailLen}},1)";
                }
                $lines[] = " same => n,NoOp(Auto-matched extension for DID \${EXTEN} not found)";
                $lines[] = " same => n,Playtones(congestion)";
                $lines[] = " same => n,Congestion(10)";
                $lines[] = " same => n,Hangup(1)";
                break;
            }
            // Into the extension's own line of the internal plan, not a second copy
            // of its dial lines: the copy skipped the boss-secretary filter, so a DID,
            // IVR key or time condition reached a boss unfiltered.
            $ext = preg_replace('/[^0-9]/', '', $dest_id);
            $lines[] = " same => n,Set(CHANNEL(accountcode)={$ext})";
            $lines[] = " same => n,GotoIf(\$[\${DIALPLAN_EXISTS(from-internal-pbx-ortak,{$ext},1)}]?from-internal-pbx-ortak,{$ext},1)";
            $lines[] = " same => n,NoOp(Extension {$ext} not found)";
            $lines[] = " same => n,Congestion(10)";
            $lines[] = " same => n,Hangup(1)";
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
            // Only number characters: the value comes from a form, and a line
            // break in it would have added its own dialplan lines (System()).
            // A pattern DID (_X. ...) passes ${EXTEN} itself: kept as is, the
            // filter would turn it into the literal extension "EXTEN".
            $target = ($orig_did === '${EXTEN}')
                ? '${EXTEN}'
                : preg_replace('/[^0-9A-Za-z_.+*#-]/', '', (string) (!empty($orig_did) ? $orig_did : $dest_id));
            $target = $target !== '' ? $target : 'default';
            $lines[] = " same => n,Goto(from-trunk-fax,{$target},1)";
            break;

        case 'outbound_route':
            // Sent straight to the chosen route (its trunks, number manipulation
            // and caller ID) with the DID as the dialled number; the route's
            // pattern is not checked. Inactive/missing route: normal group-1 routing.
            $target = !empty($orig_did)
                ? $orig_did
                : '${IF($["${CDR(did)}" != "" & "${CDR(did)}" != "s"]?${CDR(did)}:${EXTEN})}';
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
            // $depth keeps an announcement -> announcement chain from going
            // on forever.
            $next_type = '';
            $next_id  = '';
            if (is_numeric($dest_id)) {
                $ps = $db->prepare("SELECT post_dest_type, post_dest_id FROM pbx_announcements WHERE id = ?");
                $ps->execute([$dest_id]);
                if ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
                    $next_type = trim((string)($row['post_dest_type'] ?? ''));
                    $next_id  = trim((string)($row['post_dest_id'] ?? ''));
                }
            }

            if ($next_type !== '' && $depth < 3) {
                $lines[] = buildDestinationLines($next_type, $next_id, $orig_did, $depth + 1);
            } else {
                if ($depth >= 3) {
                    $lines[] = " same => n,NoOp(Announcement chain too deep, hanging up)";
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
