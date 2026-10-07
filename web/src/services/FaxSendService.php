<?php
require_once __DIR__ . '/../asterisk_helper.php';
require_once __DIR__ . '/../text_fax_helper.php';
require_once __DIR__ . '/../db_helper.php';

/**
 * Fax send service
 */
class FaxSendService {
    /**
     * Uploads a PDF and converts it to TIFF G4, records it in fax_sent and
     * creates a .call spool file for Asterisk SendFAX(). It used to be
     * embedded in fax_send.php; moved here during the MVC migration
     * (2026-08-22), logic UNCHANGED (security notes included).
     *
     * @return array{success:bool, message?:string, error?:string}
     */
    public static function sendFax(array $post, array $files, int $userId, string $userExt): array
    {
        $csrf_token = $post['csrf_token'] ?? '';
        // These two fields are embedded raw into the Asterisk call file (.call)
        // (Channel:/SetVar: lines) — if non-digit characters (especially \r\n)
        // were accepted, arbitrary extra directives (e.g. Application: System)
        // could be injected into the file, so they are limited to digits.
        $dest_number = preg_replace('/[^0-9]/', '', trim($post['dest_number'] ?? ''));
        // The "Upload PDF" / "Write text" tabs share the same form (fax_send/index.php)
        // — this field says which tab is active; PDF is the default (backward compatible).
        $compose_mode = (($post['compose_mode'] ?? 'pdf') === 'text') ? 'text' : 'pdf';

        // Sender identity: a regular fax user ALWAYS uses their own extension —
        // a different value in the POST (the field is read-only, but that is
        // not a server-side guarantee) is silently ignored. An admin can pick
        // a real/active fax unit from a dropdown in the panel
        // (fax_send/index.php) and send ON ITS BEHALF — but the choice is still
        // checked against the DB, so a missing/inactive extension cannot be
        // injected into the TSID/header by tampering with the POST
        // (2026-08-31, user request).
        $requested_sender_did = preg_replace('/[^0-9]/', '', trim($post['sender_did'] ?? ''));
        $current_role = $_SESSION['user_role'] ?? 'user';
        if ($current_role === 'admin' && $requested_sender_did !== '') {
            $valid_ext = DBHelper::fetchColumn(
                "SELECT extension FROM sys_users WHERE extension = ? AND extension_type = 'fax' AND is_active = 1",
                [$requested_sender_did]
            );
            $sender_did = $valid_ext ?: $userExt;
        } else {
            $sender_did = $userExt;
        }

        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        if (empty($dest_number)) {
            return ['success' => false, 'error' => t('srv_fax.err_to')];
        }

        $safe_text_html = '';
        if ($compose_mode === 'pdf') {
            if (!isset($files['pdf_file']) || $files['pdf_file']['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => t('srv_fax.err_pdf')];
            }

            $file_tmp = $files['pdf_file']['tmp_name'];
            $file_name = $files['pdf_file']['name'];
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if ($ext !== 'pdf') {
                return ['success' => false, 'error' => t('srv_fax.err_pdf_only')];
            }
        } else {
            // The editor's HTML comes from the browser (it can be tampered with
            // in the POST, untrusted) — it is cleaned against a whitelist by
            // TextFaxHelper::sanitizeHtml().
            $safe_text_html = TextFaxHelper::sanitizeHtml($post['fax_text_content'] ?? '');
            if (trim(strip_tags($safe_text_html)) === '') {
                return ['success' => false, 'error' => t('srv_fax.err_text')];
            }
        }
        if (AsteriskHelper::getPrimaryTrunkName() === null) {
            return ['success' => false, 'error' => t('srv_fax.err_no_trunk')];
        }

        $db = getDB();

        // Process Fax Upload
        $timestamp = date('Ymd_His');
        $unique_id = uniqid();
        $year = date('Y');

        // Web-accessible archive directory for sent faxes (yol: /etc/ai-pbx.env)
        $sent_archive_dir = FAX_STORAGE_PATH . "/sent/$year";
        if (!is_dir($sent_archive_dir)) {
            @mkdir($sent_archive_dir, 0775, true);
            @chown($sent_archive_dir, 'asterisk');
        }

        $spool_dir = FAX_OUTGOING_SPOOL; // /etc/ai-pbx.env
        if (!is_dir($spool_dir)) {
            @mkdir($spool_dir, 0775, true);
            @chown($spool_dir, 'asterisk');
        }

        $archived_pdf = "$sent_archive_dir/send_{$userExt}_{$timestamp}_{$unique_id}.pdf";
        $archived_tif = "$sent_archive_dir/send_{$userExt}_{$timestamp}_{$unique_id}.tif";

        if ($compose_mode === 'pdf') {
            if (!move_uploaded_file($file_tmp, $archived_pdf)) {
                return ['success' => false, 'error' => t('srv_fax.err_save')];
            }
        } else {
            try {
                TextFaxHelper::htmlToPdf($safe_text_html, $archived_pdf);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => sprintf(t('srv_fax.err_text_pdf'), $e->getMessage())];
            }
        }
        @chown($archived_pdf, 'asterisk');

        // Convert PDF to Fax TIFF G4 format via Ghostscript (gs)
        $gs_cmd = escapeshellarg(GS_BINARY) . " -q -dNOPAUSE -dBATCH -sDEVICE=tiffg4 -r204x196 -sOutputFile=" . escapeshellarg($archived_tif) . " " . escapeshellarg($archived_pdf) . " 2>&1";
        exec($gs_cmd, $output, $return_code);

        if (!(file_exists($archived_tif) && filesize($archived_tif) > 0)) {
            return ['success' => false, 'error' => sprintf(t('srv_fax.err_tiff'), implode(" ", $output))];
        }
        @chown($archived_tif, 'asterisk');

        // Detect page count using tiffinfo or pdfinfo
        $page_count = 1;
        $tiffinfo_cmd = "tiffinfo " . escapeshellarg($archived_tif) . " | grep -c 'TIFF Directory' 2>/dev/null";
        $detected_pages = intval(trim(shell_exec($tiffinfo_cmd)));
        if ($detected_pages > 0) {
            $page_count = $detected_pages;
        }

        // Insert record into MySQL fax_sent table
        $stmt = $db->prepare('INSERT INTO fax_sent (user_id, sender_extension, destination_number, pdf_path, tif_path, pages, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "PENDING", NOW())');
        $stmt->execute([$userId, $sender_did, $dest_number, $archived_pdf, $archived_tif, $page_count]);
        $fax_id = $db->lastInsertId();

        // Create Asterisk Call File for outbound SendFAX()
        try {
            self::submitCallFile((int)$fax_id, $dest_number, $sender_did, $archived_tif);
        } catch (\Exception $e) {
            $db->prepare("UPDATE fax_sent SET status = 'FAILED', error_message = ?, completed_at = NOW() WHERE id = ?")
               ->execute([$e->getMessage(), $fax_id]);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true, 'message' => sprintf(t('srv_fax.queued'), $fax_id, $page_count)];
    }

    /**
     * Creates and submits the real Asterisk .call spool file for the given
     * fax_sent record — SHARED by sendFax() (a new send) and
     * FaxSentService::resendFax() (re-sending a failed record with the same
     * TIFF, 2026-08-31). Kept in one place so the MaxRetries/header/TSID logic
     * does not drift apart in two places.
     */
    public static function submitCallFile(int $faxId, string $destNumber, string $senderExt, string $tifPath): void
    {
        $max_retries = intval(getSystemSetting('fax_max_retries', '2'));
        $retry_time = intval(getSystemSetting('fax_retry_time', '60'));
        $wait_time = intval(getSystemSetting('fax_wait_time', '30'));
        $trunk_name = AsteriskHelper::getPrimaryTrunkName();

        // Sender name shown in the fax header (the dialplan's FAXOPT(headerinfo)
        // appends to it) — ALWAYS the owner of the extension that really sends
        // (senderExt), NOT the name of the signed-in person (an admin may have
        // picked another fax unit; for a regular fax user senderExt is their
        // own extension anyway, same behaviour as the old code).
        $db = getDB();
        $stmt = $db->prepare("SELECT full_name, cid_external FROM sys_users WHERE extension = ?");
        $stmt->execute([$senderExt]);
        $sender_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sender_name = trim(preg_replace('/[\r\n]+/', ' ', $sender_row['full_name'] ?? ''));
        $sender_name_var = $sender_name !== '' ? "{$sender_name} ({$senderExt})" : $senderExt;

        // The call file used to specify NO CallerID: at all — the call went out
        // as "Anonymous" and was blocked by the trunk (2026-08-31).
        // sys_users.cid_external (e.g. area code/prefix + extension) is used
        // here. When it is empty (cid_external not set) we fall back to the
        // extension itself (better than sending no CID at all).
        $sender_cid = preg_replace('/[^0-9]/', '', trim($sender_row['cid_external'] ?? ''));
        if ($sender_cid === '') $sender_cid = $senderExt;
        $caller_id_line = "<$sender_cid>";

        // Fax calls NEVER send a display name — this is fixed ON PURPOSE and
        // does NOT look at the send_caller_name option in the trunk settings
        // (2026-08-31, the user clarified: "fax calls must never send a name,
        // the other subscribers' calls should follow that setting" — that
        // setting is applied only to normal extension->outside calls in
        // SyncDialplan.php::buildTrunkCallerIdLine()).
        // Faxes no longer go out through a fixed PJSIP trunk but through the
        // system's real numbering plan and outbound routes (pbx_outbound_routes)
        // over a native Local channel (Local/$dest@from-internal-pbx/n).
        $dest_dial = preg_replace('/[^0-9+*#]/', '', trim($destNumber));

        $call_file_content = "Channel: Local/$dest_dial@from-internal-pbx/n\n" .
                             "CallerID: $caller_id_line\n" .
                             "MaxRetries: $max_retries\n" .
                             "RetryTime: $retry_time\n" .
                             "WaitTime: $wait_time\n" .
                             "Context: outbound-fax\n" .
                             "Extension: s\n" .
                             "Priority: 1\n" .
                             "SetVar: FAX_TIF_PATH=$tifPath\n" .
                             "SetVar: FAX_DEST=$destNumber\n" .
                             "SetVar: FAX_SENDER=$senderExt\n" .
                             "SetVar: FAX_SENDER_NAME=$sender_name_var\n" .
                             "SetVar: FAX_ID=$faxId\n";

        // The temp file is written on the SAME file system as the spool: only
        // then is rename() atomic — moving from /tmp becomes copy+delete and
        // Asterisk could read a half file. 0660 instead of 0666 (world-writable).
        $tmp_call_file = FAX_OUTGOING_SPOOL . "/.fax_$faxId.call.tmp";
        $asterisk_spool = ASTERISK_CALL_SPOOL . "/fax_$faxId.call";

        if (file_put_contents($tmp_call_file, $call_file_content) === false) {
            throw new \Exception('Fax call file could not be written: ' . $tmp_call_file);
        }
        @chgrp($tmp_call_file, 'asterisk');
        chmod($tmp_call_file, 0660);
        if (!rename($tmp_call_file, $asterisk_spool)) {
            @unlink($tmp_call_file);
            throw new \Exception('Fax call file could not be placed in the Asterisk spool: ' . ASTERISK_CALL_SPOOL);
        }
    }

    /**
     * Transforms the given number by the SAME outbound route
     * (pbx_outbound_routes) rules real extension calls use in the
     * [from-internal-outbound] dialplan (prepend/strip_front/strip_back/
     * append) — the pure-function PHP counterpart of the $dial_num logic in
     * SyncDialplan.php::__syncOutboundDialplanBody() (the dialplan itself is
     * NOT changed, it is only mimicked here for the fax call file's Channel:
     * line). When no route matches, the number is returned UNCHANGED (safe
     * default).
     */
    public static function resolveOutboundDialNumber(string $rawNumber): string {
        $db = getDB();
        $routes = $db->query(
            "SELECT match_pattern, prepend, append, strip_front, strip_back FROM pbx_outbound_routes WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll();

        foreach ($routes as $r) {
            $pattern = trim($r['match_pattern'] ?? '');
            if ($pattern === '') continue;
            $regex = self::asteriskPatternToRegex($pattern);
            if ($regex === null) continue;
            if (@preg_match($regex, $rawNumber) !== 1) continue;

            $strip_front = intval($r['strip_front'] ?? 0);
            $strip_back = intval($r['strip_back'] ?? 0);
            $core = $rawNumber;
            if ($strip_front > 0) $core = substr($core, $strip_front);
            if ($strip_back > 0) $core = substr($core, 0, max(0, strlen($core) - $strip_back));

            $prepend = preg_replace('/[^0-9]/', '', trim($r['prepend'] ?? ''));
            $append = preg_replace('/[^0-9]/', '', trim($r['append'] ?? ''));
            return $prepend . $core . $append;
        }

        return $rawNumber;
    }

    /**
     * Turns Asterisk dialplan pattern syntax (_X/_Z/_N/./!/[a-b] and plain
     * digits) into a regex. Covers only simple number patterns made of digits/
     * wildcards (the kind this project writes into pbx_outbound_routes) —
     * SyncDialplan.php already strips characters outside
     * `[^0-9NXZnxz.\[\]_!*#-]` when saving match_pattern, so the same
     * character set is assumed here.
     */
    private static function asteriskPatternToRegex(string $pattern): ?string {
        if ($pattern[0] !== '_') {
            // Plain number without wildcards: exact match.
            return '/^' . preg_quote($pattern, '/') . '$/';
        }

        $body = substr($pattern, 1);
        $regex = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === 'X' || $ch === 'x') { $regex .= '[0-9]'; }
            elseif ($ch === 'Z' || $ch === 'z') { $regex .= '[1-9]'; }
            elseif ($ch === 'N' || $ch === 'n') { $regex .= '[2-9]'; }
            elseif ($ch === '.') { $regex .= '[0-9]+'; }
            elseif ($ch === '!') { $regex .= '[0-9]*'; }
            elseif ($ch === '[') {
                $close = strpos($body, ']', $i);
                if ($close === false) return null;
                $set = substr($body, $i, $close - $i + 1);
                if (!preg_match('/^\[[0-9\-]+\]$/', $set)) return null;
                $regex .= $set;
                $i = $close;
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }
        return '/^' . $regex . '$/';
    }
}
