<?php
require_once dirname(__DIR__) . '/repositories/FaxInboxRepository.php';
require_once __DIR__ . '/../asterisk_helper.php';
require_once __DIR__ . '/../text_fax_helper.php';
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/FaxCoverService.php';

/**
 * Fax send service
 */
class FaxSendService {
    /** Faxes one user may send in an hour, until an administrator changes it. */
    public const DEFAULT_LIMIT_PER_HOUR = 30;

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
            $sender_did = self::faxOwner($requested_sender_did) !== [] ? $requested_sender_did : $userExt;
        } elseif ($requested_sender_did !== '' && in_array($requested_sender_did, FaxInboxRepository::sendableNumbers((int) ($_SESSION['user_id'] ?? 0)), true)) {
            // A fax user with several numbers sends from the one they choose, and only from theirs.
            $sender_did = $requested_sender_did;
        } else {
            // All of a user's numbers can be shared, and then they have no login number; a person
            // linked to a unit has a phone extension, which is no fax number.
            $sender_did = FaxInboxRepository::sendableNumbers((int) ($_SESSION['user_id'] ?? 0))[0] ?? ($current_role === 'admin' ? $userExt : '');
        }

        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        if (empty($sender_did) || !preg_match('/^\d+$/', (string)$sender_did)) {
            return ['success' => false, 'error' => t('fax_send.no_number')];
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
        $refused = self::refusalFor($dest_number, (string) $sender_did, $userId, (string) $current_role);
        if ($refused !== null) {
            return ['success' => false, 'error' => $refused];
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
        // Group-writable whoever made it: mkdir's mode goes through the umask
        // (0775 -> 0755), and once an upgrade hands the directory to asterisk
        // (chown -R), the portal, which writes through group asterisk, could
        // no longer save an upload ("Permission denied", live system 2026-10-22).
        if (is_dir($sent_archive_dir) && (fileperms($sent_archive_dir) & 0020) === 0) {
            @chmod($sent_archive_dir, 02775);
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
        if (FaxCoverService::wanted($post)) {
            try {
                FaxCoverService::prepend($archived_pdf, FaxCoverService::fieldsFromPost($post), (string) $sender_did, FaxCoverService::settingsFor($post, (int) $userId, (string) $current_role), (string) $dest_number);
            } catch (\Throwable $e) {
                @unlink($archived_pdf);
                return ['success' => false, 'error' => sprintf(t('fax_cover.err_make'), $e->getMessage())];
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
     * Why this fax must not be sent, or null. Shared by a new send and a resend.
     *
     * The hourly limit stops a stolen account from sending in bulk (live system,
     * 2026-11-01). Deleted faxes count too: deleting the history must not free the
     * limit. Administrators are not limited.
     */
    public static function refusalFor(string $destNumber, string $senderExt, int $userId, string $role): ?string
    {
        $limit = (int) getSystemSetting('fax_send_limit_per_hour', (string) self::DEFAULT_LIMIT_PER_HOUR);
        if ($role !== 'admin' && $limit > 0 && self::sentInLastHour($userId) >= $limit) {
            writeAuditLog(null, 'fax_sent', $userId, "Fax refused: hourly limit of {$limit} reached (extension {$senderExt})", 'security');
            return sprintf(t('srv_fax.err_limit'), $limit);
        }
        if (!self::hasDialplanMatch($destNumber, self::dialContextFor($senderExt))) {
            return t('srv_fax.err_no_route');
        }
        return null;
    }

    private static function sentInLastHour(int $userId): int
    {
        $sent = (int) DBHelper::fetchColumn(
            'SELECT COUNT(*) FROM fax_sent WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [$userId]
        );
        $deleted = (int) DBHelper::fetchColumn(
            "SELECT COUNT(*) FROM sys_audit_log WHERE user_id = ? AND entity_type = 'fax_sent' AND action = 'delete'
             AND created_at > (NOW() - INTERVAL 1 HOUR)",
            [$userId]
        );
        return $sent + $deleted;
    }

    /**
     * Asks Asterisk whether the number can be dialled from this context: a number
     * with no route used to wait out every retry and then read "no answer". Only a
     * clear "no" refuses the fax; if Asterisk cannot be asked, the fax goes on.
     */
    private static function hasDialplanMatch(string $number, string $context): bool
    {
        $res = AsteriskHelper::execCLI("dialplan show {$number}@{$context}");
        return !str_contains((string) ($res['output'] ?? ''), 'There is no existence');
    }

    /**
     * The active fax user a number belongs to, by login number or by a further number of theirs.
     *
     * @return array<string, mixed>
     */
    private static function faxOwner(string $number): array
    {
        return DBHelper::fetchOne(
            "SELECT u.full_name, u.extension, u.cid_external, u.permission_group_id, u.outbound_group
             FROM sys_users u LEFT JOIN sys_did_mappings m ON m.assigned_user_id = u.id AND m.is_active = 1
             WHERE u.extension_type = 'fax' AND u.is_active = 1 AND (u.extension = ? OR m.did_extension = ?) LIMIT 1",
            [$number, $number]
        ) ?: [];
    }

    private static function dialContextFor(string $senderExt): string
    {
        $row = self::faxOwner($senderExt) ?: DBHelper::fetchOne('SELECT outbound_group FROM sys_users WHERE extension = ?', [$senderExt]);
        $group = max(1, (int) ($row['outbound_group'] ?? 1));
        return $group > 1 ? "from-internal-desktop-{$group}" : 'from-internal-desktop';
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
        $stmt = $db->prepare("SELECT full_name, cid_external, permission_group_id, outbound_group FROM sys_users WHERE extension = ?");
        $stmt->execute([$senderExt]);
        $sender_row = self::faxOwner($senderExt) ?: ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        // cid_external goes with the account's login number; another number of theirs is sent as itself.
        if (isset($sender_row['extension']) && (string) $sender_row['extension'] !== (string) $senderExt) {
            $sender_row['cid_external'] = '';
        }

        $perm_grp = (int)($sender_row['permission_group_id'] ?? 0);
        $out_grp = max(1, (int)($sender_row['outbound_group'] ?? 1));
        if (empty($sender_row) && !empty($_SESSION['user_id'])) {
            $uStmt = $db->prepare("SELECT permission_group_id, outbound_group FROM sys_users WHERE id = ?");
            $uStmt->execute([(int)$_SESSION['user_id']]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $perm_grp = (int)($uRow['permission_group_id'] ?? 0);
                $out_grp = max(1, (int)($uRow['outbound_group'] ?? 1));
            }
        }
        $dial_context = $out_grp > 1 ? "from-internal-desktop-{$out_grp}" : 'from-internal-desktop';

        $sender_name = trim(preg_replace('/[\r\n]+/', ' ', $sender_row['full_name'] ?? ''));
        $sender_name_var = $sender_name !== '' ? "{$sender_name} ({$senderExt})" : $senderExt;

        // The call file used to specify NO CallerID: at all — the call went out
        // as "Anonymous" and was blocked by the trunk (2026-08-31).
        // sys_users.cid_external (e.g. area code/prefix + extension) is used
        // here. When it is empty (cid_external not set) we fall back to the
        // extension itself (better than sending no CID at all).
        $sender_cid = preg_replace('/[^0-9]/', '', trim($sender_row['cid_external'] ?? ''));
        $has_own_cid = $sender_cid !== '';
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
        $safe_fax_dest = preg_replace('/[^0-9+*#]/', '', trim($destNumber));
        $safe_fax_sender = preg_replace('/[^0-9]/', '', trim($senderExt));
        $safe_sender_name = str_replace(["\r", "\n"], '', (string)$sender_name_var);

        $call_file_content = "Channel: Local/$dest_dial@$dial_context/n\n" .
                             "CallerID: $caller_id_line\n" .
                             "MaxRetries: $max_retries\n" .
                             "RetryTime: $retry_time\n" .
                             "WaitTime: $wait_time\n" .
                             "Context: outbound-fax\n" .
                             "Extension: s\n" .
                             "Priority: 1\n" .
                             ($perm_grp > 0 ? "SetVar: __PERMISSION_GROUP_ID=$perm_grp\n" : '') .
                             // The fax account's own outside number beats the trunk's, as on a phone call
                             // (the route reads CID_EXTERNAL; a Local channel has none of its own) (#157).
                             ($has_own_cid ? "SetVar: __CID_EXTERNAL=$sender_cid\n" : '') .
                             "SetVar: FAX_TIF_PATH=$tifPath\n" .
                             "SetVar: FAX_DEST=$safe_fax_dest\n" .
                             "SetVar: FAX_SENDER=$safe_fax_sender\n" .
                             "SetVar: FAX_SENDER_NAME=$safe_sender_name\n" .
                             "SetVar: FAX_ID=$faxId\n" .
                             // Inherited by the outbound leg: the route skips call recording
                             // and the jitter buffer for a fax.
                             "SetVar: __AIPBX_FAX=1\n";

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

            $prepend = cleanRoutePrefix((string)($r['prepend'] ?? ''));
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
