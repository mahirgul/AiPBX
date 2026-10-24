<?php
/**
 * Dialplan generation helpers (shared "builder" functions)
 *
 * Line-generating functions SHARED by the inbound/outbound/general dialplan
 * generators and SyncIVRs/SyncTimeConditions.
 *
 * Sub-modules:
 * - DialplanExtensionBuilder.php: DND, call forwarding, mobile push wait & extension dialing
 * - DialplanDestinationBuilder.php: Destination routing & call center fallback lines
 */

require_once __DIR__ . '/../file_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';
require_once __DIR__ . '/DialplanExtensionBuilder.php';
require_once __DIR__ . '/DialplanDestinationBuilder.php';

/**
 * Catch-all patterns used instead of '_.'.
 *
 * Asterisk warns about '_.' on every reload: it also matches the special
 * extensions (h, i, t, ...), so a hangup could run a catch-all block. These
 * two match every number '_.' was meant for: anything starting with a digit,
 * '+', '*' or '#' (a DID like +4930..., a dialled 0090..., a *code), with
 * one character ('.' needs at least one more, hence the first pattern) or more.
 */
const CATCH_ALL_EXTEN_PATTERNS = ['_[0-9+*#]', '_[0-9+*#].'];

/**
 * One catch-all block per pattern in CATCH_ALL_EXTEN_PATTERNS.
 *
 * @param string $first     application of priority 1, e.g. "NoOp(...)"
 * @param string $sameLines further " same => n,..." lines (newline-terminated)
 */
function buildCatchAllExtensions(string $first, string $sameLines = ''): string {
    $out = '';
    foreach (CATCH_ALL_EXTEN_PATTERNS as $pattern) {
        $out .= "exten => {$pattern},1,{$first}\n" . $sameLines;
    }
    return $out;
}

/**
 * Trunk outbound dialed number normalization (Roadmap 2.4):
 * Transforms the destination number to the carrier's required format.
 * E.g., strip '+90' and add '0': +905321234567 -> 05321234567.
 * Or prepend carrier prefix: 532... -> 0532...
 */
function buildTrunkCarrierDialLine(?array $carrierNorm): string {
    if (!$carrierNorm) {
        return " same => n,Set(AIPBX_TRUNK_DIAL=\${AIPBX_DIAL_TARGET})\n";
    }
    $strip = preg_replace('/[^0-9+*#]/', '', (string)($carrierNorm['strip'] ?? ''));
    $add = preg_replace('/[^0-9+*#]/', '', (string)($carrierNorm['add'] ?? ''));

    if ($strip === '' && $add === '') {
        return " same => n,Set(AIPBX_TRUNK_DIAL=\${AIPBX_DIAL_TARGET})\n";
    }

    $lines = " same => n,Set(AIPBX_TRUNK_DIAL=\${AIPBX_DIAL_TARGET})\n";
    if ($strip !== '') {
        $len = strlen($strip);
        $lines .= " same => n,ExecIf(\$[\"\${AIPBX_TRUNK_DIAL:0:{$len}}\" = \"{$strip}\"]?Set(AIPBX_TRUNK_DIAL={$add}\${AIPBX_TRUNK_DIAL:{$len}}))\n";
    } elseif ($add !== '') {
        $lines .= " same => n,Set(AIPBX_TRUNK_DIAL={$add}\${AIPBX_TRUNK_DIAL})\n";
    }
    return $lines;
}

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
 * The jitter buffer line before a Dial (PBX settings → Tuning): nothing when
 * switched off, Asterisk's defaults unless a size was changed, and never for
 * a fax (it reshapes the modem's timing).
 */
function jitterBufferLine(): string
{
    if ((string) getSystemSetting('jitterbuffer_enabled', '1') === '0') {
        return '';
    }
    $max = (string) getSystemSetting('jitterbuffer_max_size', '');
    $init = (string) getSystemSetting('jitterbuffer_initial_size', '');
    $spec = (($max !== '' && $max !== '200') || ($init !== '' && $init !== '100'))
        ? max(50, (int) ($max ?: 200)) . ',' . max(20, (int) ($init ?: 100)) : 'default';
    return " same => n,ExecIf(\$[\"\${AIPBX_FAX}\" != \"1\"]?Set(JITTERBUFFER(adaptive)={$spec}))\n";
}
