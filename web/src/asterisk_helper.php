<?php
/**
 * Asterisk CLI & Reload Execution Helper
 */

class AsteriskHelper {
    /**
     * Safely execute an Asterisk CLI command via asterisk -rx.
     *
     * IMPORTANT (found in the 2026-08-24 review, verified live):
     * `asterisk -rx` ALWAYS exits with code 0 EVEN WHEN THE COMMAND ITSELF
     * FAILS — even `asterisk -rx "a command that does not exist"` exits 0. So
     * $ret was never a real success/failure indicator (the old code wrote
     * `'success' => ($ret === 0)`, which was the same as saying "always true";
     * the syncXxx() functions never knew whether the reload REALLY worked).
     * $ret is no longer checked — a HEURISTIC looks for known error patterns
     * in the output text. It is not EXACT (the Asterisk CLI output format is
     * not an official/stable API) but definitely better than assuming "always
     * success" — and the FULL output is returned to the caller in every case,
     * so the admin can see the raw text if needed.
     */
    public static function execCLI($command) {
        // TEST LOCK: automated tests must NEVER send commands to the live
        // Asterisk. Generators such as syncAllTrunks() trigger reloads from
        // inside writeConfWithRollback(); without this lock the unit tests
        // would reload the production Asterisk. tests/bootstrap.php sets this
        // variable; it is never defined in production, so the normal flow
        // does not change at all.
        if (getenv('AIPBX_NO_ASTERISK') === '1') {
            return ['success' => true, 'output' => '[test-modu] atlandi: ' . $command];
        }

        $cmd = 'asterisk -rx ' . escapeshellarg($command);
        exec($cmd . ' 2>&1', $out, $ret);
        $output = implode("\n", $out);
        return [
            'success' => !self::looksLikeCliFailure($output),
            'output' => $output
        ];
    }

    /**
     * Shared heuristic that looks for known error patterns in `asterisk -rx`
     * output — split into its own method so the other places that process
     * `asterisk -rx` output share the same check as execCLI().
     */
    public static function looksLikeCliFailure($output) {
        return (bool) preg_match('/no such command|not found|unable to|error|failed|invalid|usage:/i', (string) $output);
    }

    /**
     * Checks one or more execCLI() results (arrays with $success/$output) and
     * throws an Exception joining ALL error outputs if any of them failed.
     * The syncXxx() functions use it after every reload call — the existing
     * try/catch in applyPendingSync() catches this Exception and marks that
     * domain "failed" (the row stays in the pending list and the admin sees
     * the raw Asterisk output on the /pending-sync page).
     */
    public static function assertReloadsOk(array $results, $context) {
        $failures = [];
        foreach ($results as $r) {
            if (empty($r['success'])) {
                $failures[] = trim($r['output'] ?? '') ?: t('sync.err_empty_reply');
            }
        }
        if (!empty($failures)) {
            throw new \Exception(sprintf(t('sync.err_asterisk'), $context, implode(' | ', $failures)));
        }
    }

    /**
     * Reload PJSIP module
     */
    public static function reloadPJSIP() {
        return self::execCLI('pjsip reload');
    }

    /**
     * Reload Dialplan
     */
    public static function reloadDialplan() {
        return self::execCLI('dialplan reload');
    }

    /**
     * Reload Queues
     */
    public static function reloadQueue($queue = 'all') {
        return self::execCLI($queue === 'all' ? 'queue reload all' : "queue reload {$queue}");
    }

    /**
     * Reload Music on Hold
     */
    public static function reloadMOH() {
        return self::execCLI('moh reload');
    }

    /**
     * Reload RTP configuration (/etc/asterisk/rtp.conf)
     *
     * CAUTION — scope limit: this reload activates behaviour settings such as
     * `strictrtp`, but changing the `rtpstart`/`rtpend` port RANGE needs a
     * FULL Asterisk RESTART (the port pool is allocated once when the module
     * loads). The interface tells the user about this separately.
     */
    public static function reloadRTP() {
        return self::execCLI('module reload res_rtp_asterisk.so');
    }

    /**
     * Reload UDPTL configuration (/etc/asterisk/udptl.conf)
     */
    public static function reloadUDPTL() {
        return self::execCLI('module reload udptl');
    }

    /**
     * Reload Voicemail configuration (/etc/asterisk/voicemail.conf)
     */
    public static function reloadVoicemail() {
        return self::execCLI('voicemail reload');
    }

    /**
     * Resolve the trunk to dial through when a specific outbound route isn't in play
     * (fax sending, the outbound dialplan's zero-routes-configured bootstrap fallback).
     * Reads the first active row from `pbx_trunks`. Returns null if no trunk is configured
     * at all — callers must handle that case explicitly (no trunk means no trunk; never
     * invent a name that doesn't exist in this installation).
     */
    public static function getPrimaryTrunkName() {
        try {
            $db = getDB();
            $row = $db->query("SELECT trunk_name FROM pbx_trunks WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1")->fetch();
            return !empty($row['trunk_name']) ? $row['trunk_name'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get live PJSIP endpoint statuses (e.g. 3001-sip => Not in use, main_trunk => Not in use).
     * 2026-08-19 fix: `pjsip show endpoints` prints every line as `<name>/<CID>`
     * (e.g. `3001-sip/3001`) and the status can be SEVERAL words such as "Not in
     * use". The old regex caught only the first word ("Not") and included the
     * `/<CID>` suffix in the key — so bare-number lookups like
     * `$statuses["3001"]` NEVER matched (with dual endpoints the real keys are
     * `3001-sip`/`3001-webrtc`). The regex below drops the `/<CID>` suffix and
     * captures the full status text ("Not in use").
     */
    public static function getPJSIPStatuses() {
        $res = self::execCLI("pjsip show endpoints");
        $output = $res['output'] ?? '';
        $statuses = [];

        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/^\s*Endpoint:\s+([^\s\/]+)(?:\/\S+)?\s+(.+?)\s+\d+\s+of\s+\S+\s*$/i', $line, $matches)) {
                $ep = trim($matches[1]);
                $st = trim($matches[2]);
                $statuses[$ep] = $st;
            }
        }
        return $statuses;
    }

    /**
     * Classifies a raw getPJSIPStatuses() value into one of: 'busy' (in use), 'idle' (not in
     * use / registered), 'down' (unavailable/unreachable), 'unknown' (endpoint exists but state
     * unrecognized), or null (no status at all — endpoint not found/never registered).
     */
    public static function classifyStatus($raw) {
        if (empty($raw)) return null;
        $r = strtolower($raw);
        if (strpos($r, 'not in use') !== false) return 'idle';
        if (strpos($r, 'in use') !== false) return 'busy';
        if (strpos($r, 'unavailable') !== false || strpos($r, 'unreachable') !== false) return 'down';
        return 'unknown';
    }
}

/**
 * Global Alias Function for Backwards Compatibility
 */
if (!function_exists('getAsteriskPJSIPStatuses')) {
    function getAsteriskPJSIPStatuses() {
        return AsteriskHelper::getPJSIPStatuses();
    }
}
