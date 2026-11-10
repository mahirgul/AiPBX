<?php
/**
 * Sync Inbound DIDs Dialplan (/etc/asterisk/pbx/extensions_inbound.conf)
 */
/**
 * The contexts of Inbound Call Normalization: one extension per rule, so Asterisk picks the most
 * specific pattern. A trunk's own context includes the rules for all trunks, and its own rules win.
 * Nothing at all without rules.
 *
 * @param list<array<string, mixed>> $rules active rules
 * @param list<string> $trunkNames
 */
function buildInboundNormContexts(array $rules, array $trunkNames): string
{
    $conf = '';
    $variable = ['caller' => 'CALLERID(num)', 'did' => 'NORMALIZED_DID'];
    foreach ($variable as $target => $var) {
        $forTarget = array_values(array_filter($rules, static fn(array $r): bool => $r['target'] === $target));
        if ($forTarget === []) {
            continue;
        }
        $scopes = [''];
        foreach ($forTarget as $r) {
            if (!empty($r['trunk_name'])) {
                $scopes[] = (string) $r['trunk_name'];
            }
        }
        foreach (array_unique(array_merge($scopes, $trunkNames)) as $scope) {
            $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $scope);
            $conf .= '[innorm-' . $target . '-' . ($clean === '' ? 'all' : "t-{$clean}") . "]\n";
            if ($clean !== '') {
                $conf .= "include => innorm-{$target}-all\n";
            }
            foreach ($forTarget as $r) {
                if ((string) ($r['trunk_name'] ?? '') !== (string) $scope) {
                    continue;
                }
                $pattern = preg_replace('/[^0-9NXZnxz.\[\]_!*#+-]/', '', (string) $r['match_pattern']);
                $prepend = preg_replace('/[^0-9+*#]/', '', (string) $r['prepend']);
                $strip = max(0, (int) $r['strip_front']);
                $conf .= "exten => {$pattern},1,NoOp(Inbound normalization: " . toCleanAscii((string) $r['rule_name']) . ")\n";
                $conf .= " same => n,Set({$var}={$prepend}\${EXTEN:{$strip}})\n";
                $conf .= " same => n,Return()\n";
            }
            $conf .= "\n";
        }
    }
    return $conf;
}

/**
 * Applies the rules to a call of this trunk, if a rule matches. Goes through a filtered copy of the
 * number, which comes from outside: DIALPLAN_EXISTS is what makes "no rule matches" harmless.
 */
function inboundNormLines(array $rules, string $trunk, string $target): string
{
    if (!array_filter($rules, static fn(array $r): bool => $r['target'] === $target)) {
        return '';
    }
    $ctx = "innorm-{$target}-t-{$trunk}";
    $source = $target === 'caller' ? '${CALLERID(num)}' : '${NORMALIZED_DID}';
    return " same => n,Set(AIPBX_NORM_IN=\${FILTER(0-9+*#,{$source})})\n"
        . " same => n,GosubIf(\$[\${DIALPLAN_EXISTS({$ctx},\${AIPBX_NORM_IN},1)}]?{$ctx},\${AIPBX_NORM_IN},1)\n";
}

/**
 * Lines of a voice route that also takes faxes (issue #151). A route whose destination is the fax
 * itself needs none of this.
 *
 * @param array<string, mixed> $d row of pbx_dids
 * @param string $did the number as dialplan text, ${EXTEN} on a pattern route
 */
function faxDetectLines(array $d, string $did): string
{
    if (empty($d['fax_detect']) || ($d['dest_type'] ?? '') === 'fax') {
        return '';
    }
    $seconds = max(3, min(15, (int) ($d['fax_detect_seconds'] ?? 10)));
    $target = (string) preg_replace('/[^0-9]/', '', (string) ($d['fax_target'] ?? ''));
    return " same => n,Set(FAX_TARGET=" . ($target !== '' ? $target : $did) . ")\n"
        . " same => n,Gosub(sub-fax-detect,s,1({$seconds}))\n";
}

/**
 * Asterisk jumps to the extension "fax" of the channel's context when it hears the tone, so listening
 * has to happen in a context that has one: it cannot go on inside an IVR or a queue. It is its own
 * context because a trunk with fax_detect on would also jump to a "fax" extension in the inbound one,
 * on a route that never asked for this.
 *
 * The call is answered first: a fax machine sends its tone only then. Ringback plays meanwhile, so a
 * person hears a phone ringing.
 */
function faxDetectSubroutine(): string
{
    return "[sub-fax-detect]\n"
        . "exten => s,1,Answer()\n"
        . " same => n,Set(FAXOPT(faxdetect)=yes,\${ARG1})\n"
        . " same => n,Playtones(ring)\n"
        . " same => n,Wait(\${ARG1})\n"
        . " same => n,StopPlaytones()\n"
        . " same => n,Set(FAXOPT(faxdetect)=no)\n"
        . " same => n,Return()\n"
        . "exten => fax,1,NoOp(Fax tone on a voice route: \${FAX_TARGET})\n"
        . " same => n,StopPlaytones()\n"
        // The fax is filed under this number, also when it is not the number that was dialled.
        . " same => n,Set(CDR(did)=\${FAX_TARGET})\n"
        . " same => n,Goto(from-trunk-fax,\${FAX_TARGET},1)\n\n";
}


function syncInboundDialplan() {
    return withSyncLock('inbound_dialplan', '__syncInboundDialplanBody');
}

function __syncInboundDialplanBody() {
    $db = getDB();
    $dids = $db->query("SELECT * FROM pbx_dids WHERE is_active = 1 ORDER BY did_number ASC")->fetchAll();
    $trunks = $db->query("SELECT * FROM pbx_trunks WHERE is_active = 1 ORDER BY trunk_name ASC")->fetchAll();

    $conf = "; ==========================================================\n";
    $conf .= "; Native Inbound DID Dialplan (Auto-generated by AI PBX)\n";
    $conf .= "; ==========================================================\n\n";

    // Inbound normalization switched off: no rule is used, the rules stay (Inbound Normalization page).
    require_once dirname(__DIR__) . '/services/InboundNormService.php';
    $normRules = InboundNormService::isEnabled()
        ? $db->query("SELECT * FROM pbx_inbound_norm_rules WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $conf .= buildInboundNormContexts($normRules, array_column($trunks, 'trunk_name'));

    // ------------------------------------------------------------------
    // Per-trunk inbound call handling and DID normalization
    // ------------------------------------------------------------------
    if (!empty($trunks)) {
        foreach ($trunks as $t) {
            $t_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $t['trunk_name']);
            if (empty($t_name)) continue;
            $title = toCleanAscii($t['title'] ?? $t_name);
            $trim_digits = intval($t['did_trim_digits'] ?? 0);
            $allow_outbound = !empty($t['allow_outbound_routing']);
            $route_group = max(1, intval($t['outbound_route_group'] ?? 1));
            $is_busy_tone_wrapper = (($t['context'] ?? '') === 'from-trunk-kapanma-tonu');

            // Channel and calls-per-minute limits: a refused call is hung up with
            // cause 34 (no circuit), so the carrier may try another way.
            $max_ch = max(0, intval($t['max_channels'] ?? 0));
            $max_rate = max(0, intval($t['max_calls_per_minute'] ?? 0));
            $limit_lines = ($max_ch > 0 || $max_rate > 0)
                ? " same => n,Gosub(sub-trunk-limit,s,1({$t_name},{$max_ch},{$max_rate},trunkin))\n"
                    . " same => n,ExecIf(\$[\"\${TRUNK_LIMIT}\" != \"\"]?Hangup(34))\n"
                : '';

            $conf .= "; --- Trunk inbound: {$t_name} ({$title}) ---\n";
            $conf .= "[from-trunk-{$t_name}]\n";
            // Any DID: plain digits or +E.164 (see CATCH_ALL_EXTEN_PATTERNS).
            $same = " same => n,Set(CDR(direction)=inbound)\n";
            $same .= " same => n,Set(CDR(inbound_trunk)={$t_name})\n";
            // The caller's number first, so the blacklist sees the same form as everything after it.
            $same .= inboundNormLines($normRules, $t_name, 'caller');
            $same .= " same => n,Gosub(sub-inbound-blacklist,s,1)\n";
            $same .= " same => n,GosubIf(\$[\${DIALPLAN_EXISTS(sub-cnam-lookup,s,1)}]?sub-cnam-lookup,s,1)\n";

            if ($is_busy_tone_wrapper) {
                // Hang-up tone detector (in-band disconnect supervision)
                $same .= " same => n,Set(TONE_DETECT(0,,brg(kapanma-tonu,s,1))=)\n";
            }

            if ($trim_digits > 0) {
                $same .= " same => n,Set(NORMALIZED_DID=\${IF(\$[\${LEN(\${EXTEN})} >= {$trim_digits}]?\${EXTEN:-{$trim_digits}}:\${EXTEN})})\n";
                $same .= " same => n,NoOp(DID trim applied ({$trim_digits} digits): \${EXTEN} -> \${NORMALIZED_DID})\n";
            } else {
                $same .= " same => n,Set(NORMALIZED_DID=\${EXTEN})\n";
            }

            $same .= inboundNormLines($normRules, $t_name, 'did');
            $same .= " same => n,Set(CDR(did)=\${NORMALIZED_DID})\n";
            $same .= $limit_lines;
            $same .= " same => n,Goto(from-trunk-{$t_name}-route,\${NORMALIZED_DID},1)\n";
            $conf .= buildCatchAllExtensions(
                "NoOp(Inbound trunk call [{$t_name}] - caller: \${CALLERID(num)} - target: \${EXTEN})",
                $same
            ) . "\n";

            // For calls arriving without a DID (s), attempt to recover dialed DID from SIP To header
            $conf .= "exten => s,1,NoOp(Inbound call without DID [{$t_name}] - caller: \${CALLERID(num)})\n";
            $conf .= " same => n,Set(CDR(direction)=inbound)\n";
            $conf .= " same => n,Set(CDR(inbound_trunk)={$t_name})\n";
            $conf .= " same => n,Set(AIPBX_TO_USER=\${PJSIP_PARSE_URI(\${PJSIP_HEADER(read,To)},user)})\n";
            $conf .= " same => n,GotoIf(\$[\"\${AIPBX_TO_USER}\" != \"\" & \"\${AIPBX_TO_USER}\" != \"s\"]?from-trunk-{$t_name},\${AIPBX_TO_USER},1)\n";
            $conf .= inboundNormLines($normRules, $t_name, 'caller');
            $conf .= " same => n,Gosub(sub-inbound-blacklist,s,1)\n";
            $conf .= " same => n,GosubIf(\$[\${DIALPLAN_EXISTS(sub-cnam-lookup,s,1)}]?sub-cnam-lookup,s,1)\n";
            if ($is_busy_tone_wrapper) {
                $conf .= " same => n,Set(TONE_DETECT(0,,brg(kapanma-tonu,s,1))=)\n";
            }
            $conf .= " same => n,Set(CDR(did)=s)\n";
            $conf .= $limit_lines;
            $conf .= " same => n,Goto(from-trunk-{$t_name}-route,s,1)\n\n";

            // Routing context (route context)
            $conf .= "[from-trunk-{$t_name}-route]\n";
            $conf .= "; 1. Defined inbound routes (DID rules)\n";
            $conf .= "include => from-trunk-inbound\n";

            if ($allow_outbound) {
                // Extensions first: a number of ours must not go back out through
                // an outbound route that happens to match it.
                $conf .= "; 2. Extensions & internal numbers\n";
                $conf .= "include => from-internal-pbx-ortak\n";
                $conf .= "; 3. Trunk-to-trunk / transit: outbound routes (group {$route_group})\n";
                $conf .= "include => from-internal-outbound-{$route_group}\n";
            }

            $conf .= "; 4. Closing for unmatched routes\n";
            $conf .= "include => from-trunk-notfound\n\n";
        }
    }

    // ------------------------------------------------------------------
    // General inbound routes (DID matches)
    // ------------------------------------------------------------------
    require_once __DIR__ . '/../services/PrivacyService.php';
    $privacyNotice = PrivacyService::dialplanNoticeConfig();
    $beepOpt = PrivacyService::mixMonitorOptionSuffix();

    if ($privacyNotice !== null) {
        $conf .= "; --- Global Privacy / KVKK / GDPR / CCPA Pre-Call Notice & Consent ---\n";
        $conf .= "[sub-privacy-consent]\n";
        $conf .= "exten => s,1,NoOp(KVKK / GDPR Pre-Call Privacy Notice [{$privacyNotice['mode']}])\n";
        $conf .= " same => n,Answer()\n";
        if ($privacyNotice['mode'] === 'optout') {
            $conf .= " same => n,Read(PRIVACY_CHOICE,{$privacyNotice['sound_path']},1,,1,{$privacyNotice['optout_timeout']})\n";
            $conf .= " same => n,ExecIf(\$[\"\${PRIVACY_CHOICE}\" = \"{$privacyNotice['optout_digit']}\"]?Set(__PRIVACY_NO_RECORD=1))\n";
            $conf .= " same => n,ExecIf(\$[\"\${PRIVACY_NO_RECORD}\" = \"1\"]?Set(CDR(accountcode)=PRIVACY_OPTOUT))\n";
        } elseif ($privacyNotice['mode'] === 'optin') {
            $conf .= " same => n,Set(__PRIVACY_NO_RECORD=1)\n";
            $conf .= " same => n,Read(PRIVACY_CHOICE,{$privacyNotice['sound_path']},1,,1,{$privacyNotice['optout_timeout']})\n";
            $conf .= " same => n,ExecIf(\$[\"\${PRIVACY_CHOICE}\" = \"{$privacyNotice['optout_digit']}\"]?Set(__PRIVACY_NO_RECORD=0))\n";
            $conf .= " same => n,ExecIf(\$[\"\${PRIVACY_NO_RECORD}\" = \"1\"]?Set(CDR(accountcode)=PRIVACY_NO_CONSENT):Set(CDR(accountcode)=PRIVACY_CONSENT_OK))\n";
        } else {
            $conf .= " same => n,Playback({$privacyNotice['sound_path']})\n";
        }
        $conf .= " same => n,Return()\n\n";
    }

    $conf .= inboundBlacklistSubroutine();
    $conf .= trunkLimitSubroutine();
    $conf .= faxDetectSubroutine();
    $conf .= "[from-trunk-inbound]\n\n";

    $pattern_lengths = [];
    if (!empty($dids)) {
        foreach ($dids as $d) {
            $raw_did = ltrim(strtoupper(trim($d['did_number'])), '_');
            $num = (str_starts_with($raw_did, '+') ? '+' : '') . preg_replace('/[^0-9XZN.!]/', '', $raw_did);
            if ($num === '' || $num === '+') continue;
            $is_pattern = (bool)preg_match('/[XZN.!]/', $num);
            $exten_key = $is_pattern ? "_{$num}" : $num;
            $did_cdr = $is_pattern ? '${EXTEN}' : $num;
            $rec_to = $is_pattern ? '${FILTER(0-9+,${EXTEN})}' : $num;
            $label_id = intval($d['id'] ?? 0) ?: preg_replace('/[^0-9A-Za-z_]/', '', $num);
            if ($is_pattern && !preg_match('/[.!]/', $num)) {
                $pattern_lengths[strlen($num)] = true;
            }
            $title = toCleanAscii($d['title']);
            $conf .= "; DID: {$exten_key} ({$title})\n";
            $conf .= "exten => {$exten_key},1,NoOp(Inbound call for DID {$exten_key} - {$title})\n";
            $conf .= " same => n,Set(CDR(did)={$did_cdr})\n";
            $conf .= " same => n,Set(CDR(direction)=inbound)\n";
            if (!empty($d['language'])) {
                $lang = preg_replace('/[^a-zA-Z_]/', '', $d['language']);
                $conf .= " same => n,Set(CHANNEL(language)={$lang})\n";
            }
            // Before the privacy notice and the recording: a fax machine cannot answer one, and
            // the other would only be modem noise.
            $conf .= faxDetectLines($d, $did_cdr);
            if ($privacyNotice !== null) {
                $conf .= " same => n,Gosub(sub-privacy-consent,s,1)\n";
            }
            // Mandatory call recording (unless caller opted out via KVKK/GDPR consent key).
            // Never for a fax number: the recording is modem noise and only costs disk.
            if (!empty($d['record_call']) && $d['dest_type'] !== 'fax') {
                $rec = "/var/spool/asterisk/monitor/inbound_"
                     . "\${STRFTIME(\${EPOCH},,%Y%m%d_%H%M%S)}_\${FILTER(0-9+,\${CALLERID(num)})}_to_{$rec_to}.wav";
                $conf .= " same => n,GotoIf(\$[\"\${PRIVACY_NO_RECORD}\" = \"1\"]?skip_did_rec_{$label_id})\n";
                $conf .= " same => n,Set(REC_FILE={$rec})\n";
                $conf .= " same => n,MixMonitor(\${REC_FILE}{$beepOpt})\n";
                $conf .= " same => n,Set(CDR(userfield)=\${REC_FILE})\n";
                $conf .= " same => n(skip_did_rec_{$label_id}),NoOp(DID Recording check done)\n";
            }

            $orig_target = $is_pattern ? '${EXTEN}' : $num;
            $conf .= buildDestinationLines($d['dest_type'], $d['dest_id'], $orig_target) . "\n\n";
        }
    } else {
        $conf .= "; No specific DIDs configured yet\n\n";
    }

    // ------------------------------------------------------------------
    // Closing context for unmatched calls
    // ------------------------------------------------------------------
    $conf .= "[from-trunk-notfound]\n";
    $notfound_body = "";
    foreach (array_keys($pattern_lengths) as $pLen) {
        $pLen = (int)$pLen;
        $notfound_body .= " same => n,GotoIf(\$[\$[\${LEN(\${EXTEN})} > {$pLen}] & \$[\${DIALPLAN_EXISTS(from-trunk-inbound,\${EXTEN:-{$pLen}},1)}]]?from-trunk-inbound,\${EXTEN:-{$pLen}},1)\n";
    }
    $notfound_body .= " same => n,Playtones(congestion)\n same => n,Congestion(10)\n same => n,Hangup(1)\n";
    $conf .= buildCatchAllExtensions(
        "NoOp(No matching inbound route - DID: \${EXTEN})",
        $notfound_body
    ) . "\n";

    $conf .= "exten => s,1,NoOp(No matching inbound route - DID: s)\n";
    $conf .= " same => n,Playtones(congestion)\n";
    $conf .= " same => n,Congestion(10)\n";
    $conf .= " same => n,Hangup(1)\n\n";

    // ------------------------------------------------------------------
    // Hang-up tone detector wrapper (for backward compatibility)
    // "kapanma tonu" is Turkish for "hang-up tone": some analog/ISDN trunks
    // never send a hang-up, only play a busy tone, so the call would stay in
    // the IVR or queue. The context names keep the Turkish words because trunks
    // store "from-trunk-kapanma-tonu" as their context in the database.
    // ------------------------------------------------------------------
    $conf .= "; --- Inbound route wrapper with hang-up tone detection ---\n";
    $conf .= "[from-trunk-kapanma-tonu]\n";
    $conf .= "exten => _X.,1,NoOp(Hangup tone detector active - DID \${EXTEN})\n";
    $conf .= " same => n,Gosub(sub-inbound-blacklist,s,1)\n";
    $conf .= " same => n,GosubIf(\$[\${DIALPLAN_EXISTS(sub-cnam-lookup,s,1)}]?sub-cnam-lookup,s,1)\n";
    $conf .= " same => n,Set(TONE_DETECT(0,,brg(kapanma-tonu,s,1))=)\n";
    $conf .= " same => n,Goto(from-trunk-inbound,\${EXTEN},1)\n\n";

    $conf .= "[kapanma-tonu]\n";
    $conf .= "exten => s,1,NoOp(Hangup tone detected - ending the call)\n";
    $conf .= " same => n,Hangup()\n\n";

    return writeConfWithRollback('extensions_inbound.conf', $conf, function() {
        AsteriskHelper::assertReloadsOk([AsteriskHelper::reloadDialplan()], t('sync.ctx_inbound'));
    }, t('sync.ctx_inbound'));
}

/**
 * [sub-trunk-limit]: channels (inbound "trunkin" and outbound "trunk" groups
 * together) and calls per minute of one trunk. ARG1 trunk, ARG2 max channels,
 * ARG3 calls per minute (0 = none), ARG4 group of this call. TRUNK_LIMIT is
 * empty when the call may go on, "channels" or "rate" when it is refused; a
 * refused call leaves the group and is counted in astdb trunkblock/<trunk>
 * ("first:count:reason:last", a 15-minute window) for the health check
 * "Trunk limits". The minute counter is astdb trunkrate/<trunk>.
 */
function trunkLimitSubroutine(): string
{
    return "[sub-trunk-limit]\n"
        . "exten => s,1,Set(TRUNK_LIMIT=)\n"
        . " same => n,Set(GROUP(\${ARG4})=\${ARG1})\n"
        . " same => n,GotoIf(\$[0\${ARG2} = 0]?rate)\n"
        . " same => n,GotoIf(\$[\${GROUP_COUNT(\${ARG1}@trunk)} + \${GROUP_COUNT(\${ARG1}@trunkin)} > \${ARG2}]?full)\n"
        . " same => n(rate),GotoIf(\$[0\${ARG3} = 0]?ok)\n"
        . " same => n,Set(LOCAL(min)=\${STRFTIME(\${EPOCH},,%Y%m%d%H%M)})\n"
        . " same => n,Set(LOCAL(cnt)=\${DB(trunkrate/\${ARG1})})\n"
        . " same => n,Set(LOCAL(cnt)=\${IF(\$[\"\${CUT(cnt,:,1)}\" = \"\${min}\"]?\$[0\${CUT(cnt,:,2)} + 1]:1)})\n"
        . " same => n,Set(DB(trunkrate/\${ARG1})=\${min}:\${cnt})\n"
        . " same => n,GotoIf(\$[\${cnt} > \${ARG3}]?over)\n"
        . " same => n(ok),Return()\n"
        . " same => n(full),Set(TRUNK_LIMIT=channels)\n"
        . " same => n,Goto(refused)\n"
        . " same => n(over),Set(TRUNK_LIMIT=rate)\n"
        . " same => n(refused),Set(GROUP(\${ARG4})=)\n"
        . " same => n,Set(LOCAL(blk)=\${DB(trunkblock/\${ARG1})})\n"
        . " same => n,Set(LOCAL(first)=\${IF(\$[\${EPOCH} - 0\${CUT(blk,:,1)} < 900]?\${CUT(blk,:,1)}:\${EPOCH})})\n"
        . " same => n,Set(LOCAL(n)=\${IF(\$[\${EPOCH} - 0\${CUT(blk,:,1)} < 900]?\$[0\${CUT(blk,:,2)} + 1]:1)})\n"
        . " same => n,Set(DB(trunkblock/\${ARG1})=\${first}:\${n}:\${TRUNK_LIMIT}:\${EPOCH})\n"
        . " same => n,NoOp(Trunk \${ARG1}: call refused, limit \${TRUNK_LIMIT})\n"
        . " same => n,Return()\n\n";
}

/**
 * [sub-inbound-blacklist]: checks caller ID against AstDB blacklist and pattern rules.
 * Fast AstDB exact match, followed by pattern match context if any patterns exist.
 * Block actions: busy (Hangup 17), congestion (Hangup 34), drop (Hangup 16), announcement (ss-noservice).
 */
function inboundBlacklistSubroutine(): string
{
    $db = getDB();
    $patterns = [];
    try {
        $patterns = $db->query("SELECT number, block_action FROM pbx_inbound_blacklist WHERE is_active = 1 AND number LIKE '\\_%' ORDER BY number ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        $patterns = [];
    }

    $out = "; --- Inbound Spam & Blacklist Filter ---\n";
    $out .= "[sub-inbound-blacklist]\n";
    $out .= "exten => s,1,NoOp(Inbound Blacklist Filter - Caller: \${CALLERID(num)})\n";
    $out .= " same => n,Set(LOCAL(raw_cid)=\${CALLERID(num)})\n";
    $out .= " same => n,Set(LOCAL(clean_cid)=\${FILTER(0-9,\${LOCAL(raw_cid)})})\n";
    $out .= " same => n,GotoIf(\$[\"\${LOCAL(clean_cid)}\" = \"\"]?chk_raw)\n";
    $out .= " same => n,GotoIf(\$[\${DB_EXISTS(blacklist/\${LOCAL(clean_cid)})}]?blocked_clean)\n";
    $out .= " same => n(chk_raw),GotoIf(\$[\"\${LOCAL(raw_cid)}\" = \"\"]?allow)\n";
    $out .= " same => n,GotoIf(\$[\${DB_EXISTS(blacklist/\${LOCAL(raw_cid)})}]?blocked_raw)\n";
    if (!empty($patterns)) {
        $out .= " same => n,Set(LOCAL(match_target)=\${IF(\$[\"\${LOCAL(clean_cid)}\" != \"\"]?\${LOCAL(clean_cid)}:\${LOCAL(raw_cid)})})\n";
        $out .= " same => n,Set(BLK_MATCH=0)\n";
        $out .= " same => n,Set(BLK_ACTION=)\n";
        $out .= " same => n,GosubIf(\$[\${DIALPLAN_EXISTS(sub-inbound-blacklist-patterns,\${LOCAL(match_target)},1)}]?sub-inbound-blacklist-patterns,\${LOCAL(match_target)},1)\n";
        $out .= " same => n,GotoIf(\$[\"\${BLK_MATCH}\" = \"1\"]?do_block)\n";
    }
    $out .= " same => n(allow),Return()\n\n";

    $out .= " same => n(blocked_clean),Set(BLK_ACTION=\${DB(blacklist_action/\${LOCAL(clean_cid)})})\n";
    $out .= " same => n,Goto(do_block)\n";
    $out .= " same => n(blocked_raw),Set(BLK_ACTION=\${DB(blacklist_action/\${LOCAL(raw_cid)})})\n";
    $out .= " same => n,Goto(do_block)\n\n";

    $out .= " same => n(do_block),NoOp(Spam call BLOCKED by Inbound Blacklist - CID: \${LOCAL(raw_cid)} - Action: \${BLK_ACTION})\n";
    $out .= " same => n,Set(CDR(accountcode)=SPAM_BLOCKED)\n";
    $out .= " same => n,Set(CDR(userfield)=Blacklist: \${BLK_ACTION})\n";
    $out .= " same => n,GotoIf(\$[\"\${BLK_ACTION}\" = \"busy\"]?act_busy)\n";
    $out .= " same => n,GotoIf(\$[\"\${BLK_ACTION}\" = \"congestion\"]?act_congestion)\n";
    $out .= " same => n,GotoIf(\$[\"\${BLK_ACTION}\" = \"announcement\"]?act_announcement)\n";
    $out .= " same => n(act_drop),Hangup(16)\n";
    $out .= " same => n(act_busy),Playtones(busy)\n";
    $out .= " same => n,Busy(5)\n";
    $out .= " same => n,Hangup(17)\n";
    $out .= " same => n(act_congestion),Playtones(congestion)\n";
    $out .= " same => n,Congestion(5)\n";
    $out .= " same => n,Hangup(34)\n";
    $out .= " same => n(act_announcement),Answer()\n";
    $out .= " same => n,Wait(1)\n";
    $out .= " same => n,Playback(ss-noservice)\n";
    $out .= " same => n,Hangup(1)\n\n";

    if (!empty($patterns)) {
        $out .= "[sub-inbound-blacklist-patterns]\n";
        foreach ($patterns as $p) {
            $num = trim($p['number']);
            $act = in_array($p['block_action'], ['busy', 'congestion', 'drop', 'announcement'], true) ? $p['block_action'] : 'busy';
            $out .= "exten => {$num},1,Set(BLK_MATCH=1)\n";
            $out .= " same => n,Set(BLK_ACTION={$act})\n";
            $out .= " same => n,Return()\n";
        }
        $out .= "\n";
    }

    return $out;
}


