<?php
/**
 * Master Asterisk Sync Orchestrator (/etc/asterisk/pbx/)
 * Facade linking domain-driven sync modules in src/sync/
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/file_helper.php';
require_once __DIR__ . '/asterisk_helper.php';

/**
 * Helper to safely write file with ownership
 */
function writePBXConf($filename, $content) {
    $file_path = ASTERISK_PBX_DIR . '/' . $filename;
    // A failed write used to pass silently: the reload then succeeded with the
    // old file and the pending change was marked as applied.
    if (FileHelper::writeFile($file_path, $content, 'asterisk', 'asterisk', 0644) === false) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new \Exception("Yapılandırma dosyası yazılamadı: {$file_path} ({$err})");
    }
    return $file_path;
}

/**
 * Write config + reload + AUTOMATIC ROLLBACK ON FAILURE.
 * 2026-08-24/25: the natural follow-up of the "reload failures were never
 * caught" fix — now that we know when a reload REALLY fails, this function
 * uses that to keep Asterisk on the LAST WORKING version.
 *
 * Flow: BEFORE the new content is written, a copy of the current file is
 * kept as ".prev". If $reloadFn() (usually one or more AsteriskHelper::
 * assertReloadsOk() calls) fails (throws):
 *   - If a previous version EXISTS: it is written back and the SAME reload is
 *     tried AGAIN. If that succeeds, a clear "rolled back" error is thrown
 *     (the admin is told "the broken config was rejected, the old one is
 *     still running"). If that fails too (very rare — Asterisk itself may be
 *     in trouble for another reason), a SEPARATE "manual intervention
 *     needed" error is thrown — it never silently says "success".
 *   - If there is NO previous version (first write for this domain): there
 *     is nothing to go back to, the new (probably broken) file stays as it
 *     is and only the original error is thrown.
 *
 * $reloadFn is ALWAYS called (whether the write succeeded or not) — this
 * function does NOT write files itself, it only WRAPS $writeFn; the syncXxx()
 * functions move their writePBXConf() and reload calls in here.
 */
function writeConfWithRollback($filename, $content, callable $reloadFn, $context) {
    $file_path = ASTERISK_PBX_DIR . '/' . $filename;
    $backup_path = $file_path . '.prev';
    $had_previous = is_file($file_path);
    $previous_content = $had_previous ? @file_get_contents($file_path) : null;

    writePBXConf($filename, $content);

    try {
        $reloadFn();
        // Success — keep this content as the "known good" version to go back
        // to on the next failure (the current file is exactly this already).
        if ($previous_content !== null) {
            @file_put_contents($backup_path, $previous_content);
            @chown($backup_path, 'asterisk');
            @chgrp($backup_path, 'asterisk');
        }
        return true;
    } catch (\Exception $e) {
        if ($previous_content === null) {
            throw new \Exception("{$context} başarısız oldu (bu ayar için önceki bir sürüm olmadığından otomatik geri alma yapılamadı): " . $e->getMessage());
        }
        writePBXConf($filename, $previous_content);
        try {
            $reloadFn();
        } catch (\Exception $e2) {
            throw new \Exception("{$context} başarısız oldu VE otomatik geri alma da başarısız oldu — ELLE MÜDAHALE GEREKİYOR. İlk hata: " . $e->getMessage() . " | Geri alma hatası: " . $e2->getMessage());
        }
        throw new \Exception("{$context} başarısız oldu, ÖNCEKİ ÇALIŞAN AYARLARA OTOMATİK OLARAK GERİ ALINDI (değişikliğiniz uygulanmadı, sistem önceki hâliyle çalışmaya devam ediyor). Asterisk'in verdiği hata: " . $e->getMessage());
    }
}

/**
 * Adds a per-consolidation-domain file lock (flock) around the "read DB →
 * regenerate the WHOLE .conf file → write → reload" sync functions.
 * FileHelper::writeFile() makes WRITING a single file atomic, but that did
 * not stop two concurrent requests from overlapping the SAME syncAllXxx()
 * (A writing its older DB snapshot AFTER B, temporarily losing B's change in
 * the generated file) — a race condition found in the 2026-08-23
 * architecture review. The lock is per domain (extensions, queues, ivrs, ...
 * separately) — concurrent edits in unrelated areas do not wait for each
 * other, only calls writing the SAME target file are serialized.
 * If the lock file cannot be opened (permission/disk problem) it SILENTLY
 * continues without a lock — it never blocks the sync completely, at worst
 * it falls back to the old (lock-free) behaviour.
 */
function withSyncLock($lock_name, callable $fn) {
    $lock_dir = sys_get_temp_dir() . '/aipbx_sync_locks';
    if (!is_dir($lock_dir)) {
        @mkdir($lock_dir, 0700, true);
    }
    $lock_file = $lock_dir . '/' . preg_replace('/[^a-z0-9_]/', '', $lock_name) . '.lock';
    $lock_fp = @fopen($lock_file, 'c');
    if ($lock_fp === false) {
        return $fn();
    }
    flock($lock_fp, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($lock_fp, LOCK_UN);
        fclose($lock_fp);
    }
}

/**
 * Deferred reload system: domain -> real syncXxx() function name.
 * Exactly the same list as the 11 domains syncEverything() calls — on
 * purpose, so the "which domains exist" definition lives in one place.
 */
const PENDING_SYNC_DOMAIN_MAP = [
    'extensions'        => 'syncAllExtensions',
    'trunks'            => 'syncAllTrunks',
    'queues'            => 'syncAllQueues',
    'ivrs'              => 'syncAllIVRs',
    'time_conditions'   => 'syncAllTimeConditions',
    'inbound_dialplan'  => 'syncInboundDialplan',
    'outbound_dialplan' => 'syncOutboundDialplan',
    'general_dialplan'  => 'syncGeneralDialplan',
    'moh'               => 'syncAsteriskMOH',
    'featurecodes'      => 'syncFeatureCodes',
    'transports'        => 'syncTransports',
    'rtp'               => 'syncRtpSettings',
    'udptl'             => 'syncUdptlSettings',
    'internal_numbers'  => 'syncInternalNumbers',
    'ring_groups'       => 'syncRingGroups',
    'conferences'       => 'syncConferences',
    'voicemail'         => 'syncVoicemail',
    'permissions'       => 'syncPermissions',
];

/**
 * Marks that a record must be PUSHED to Asterisk — it does NOT run the real
 * regen+reload right away; it waits until the admin presses "Apply" on the
 * /pending-sync page (2026-08-24, user request: "the admin may want to make
 * several changes and apply them all at once").
 * $domain must be in PENDING_SYNC_DOMAIN_MAP. If the same (domain,
 * entity_type, entity_id) is edited again before Apply, the row is UPDATED
 * (deduplicated) — the list does not grow for the same record, it shows the
 * latest state.
 *
 * OUT of scope (on purpose): phone feature codes (feature_code_action.php
 * KEEPS its own syncEverything() call — dialing *78 must take effect at
 * once, without waiting for admin approval) and agent queue login/pause
 * (live AMI commands with no config file at all, outside this system).
 */
function markPendingSync($domain, $entity_type, $entity_id, $entity_label, $action = 'update', $user_id = null) {
    if (!array_key_exists($domain, PENDING_SYNC_DOMAIN_MAP)) {
        throw new \InvalidArgumentException("Bilinmeyen pending-sync domain'i: {$domain}");
    }
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO sys_pending_sync (domain, entity_type, entity_id, entity_label, action, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE entity_label = VALUES(entity_label), action = VALUES(action), changed_by = VALUES(changed_by), changed_at = NOW()"
    );
    $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id]);

    // sys_pending_sync ROWS are DELETED after Apply — a permanent "who did
    // what and when" record goes to the separate, INSERT-only sys_audit_log.
    writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id);
}

/**
 * Permanent audit record — no row is ever deleted or updated (2026-08-24).
 * Called by markPendingSync() (when a record changes) and applyPendingSync()
 * (when Apply is pressed, per domain). $user_id may be null (e.g. an action
 * started from the CLI) — the username is denormalized from the current DB
 * snapshot and stored, so the record stays readable even if the user is
 * deleted later.
 */
function writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id = null) {
    try {
        $db = getDB();
        $username = null;
        if ($user_id) {
            $username = $db->prepare("SELECT full_name FROM sys_users WHERE id = ?");
            $username->execute([$user_id]);
            $username = $username->fetchColumn() ?: null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare(
            "INSERT INTO sys_audit_log (domain, entity_type, entity_id, entity_label, action, user_id, username, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id, $username, $ip]);
    } catch (\Exception $e) {
        // Failing to write the audit record must NEVER block the actual
        // operation (saving/applying settings) — it is silently skipped.
    }
}

/**
 * A lightweight counter for the sidebar badge.
 */
function getPendingSyncCount() {
    try {
        return (int) getDB()->query("SELECT COUNT(*) FROM sys_pending_sync")->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * For the /pending-sync page: all pending rows, grouped by domain.
 */
function getPendingSyncList() {
    $db = getDB();
    $rows = $db->query(
        "SELECT ps.*, u.full_name AS changed_by_name
         FROM sys_pending_sync ps
         LEFT JOIN sys_users u ON u.id = ps.changed_by
         ORDER BY ps.domain ASC, ps.changed_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['domain']][] = $r;
    }
    return $grouped;
}

/**
 * Runs when "Apply" is pressed: calls the real syncXxx() function once for
 * EVERY pending domain (ONE regen per domain however many records changed
 * in it — syncAllQueues() already generates all queues in one go, and the
 * withSyncLock() lock applies here too). A successful domain's rows are
 * deleted; if a domain fails its rows STAY (retried on the next Apply) and
 * the other domains are NOT AFFECTED.
 * Returns: ['domain' => ['success' => bool, 'error' => string|null]].
 */
function applyPendingSync($user_id = null) {
    $db = getDB();
    $domains = $db->query("SELECT DISTINCT domain FROM sys_pending_sync")->fetchAll(PDO::FETCH_COLUMN);

    $results = [];
    foreach ($domains as $domain) {
        $fn = PENDING_SYNC_DOMAIN_MAP[$domain] ?? null;
        if (!$fn || !function_exists($fn)) {
            $results[$domain] = ['success' => false, 'error' => "Bilinmeyen domain: {$domain}"];
            continue;
        }
        try {
            $fn();
            $del = $db->prepare("DELETE FROM sys_pending_sync WHERE domain = ?");
            $del->execute([$domain]);
            $results[$domain] = ['success' => true, 'error' => null];
            writeAuditLog($domain, 'sync_apply', $domain, $domain, 'apply', $user_id);
        } catch (\Throwable $e) {
            $results[$domain] = ['success' => false, 'error' => $e->getMessage()];
            // 2026-08-25: only the domain name ("queues") used to be written
            // here — the REAL text of the rollback/reload error (including
            // writeConfWithRollback()'s "AUTOMATICALLY ROLLED BACK"/"MANUAL
            // INTERVENTION NEEDED" messages) only appeared in that HTTP
            // response and was lost from the permanent record.
            // entity_label is varchar(255) — raw Asterisk output can exceed
            // it, so it is trimmed safely.
            $label = "{$domain}: " . mb_substr($e->getMessage(), 0, 230);
            writeAuditLog($domain, 'sync_apply', $domain, $label, 'apply_failed', $user_id);
        }
    }
    return $results;
}

// Require Domain Sync Modules
require_once __DIR__ . '/sync/SyncTransports.php';
// SyncDialplan.php was split into four areas on 2026-08-31 (it was 516 lines in one file).
// DialplanBuilders must come FIRST — the others use the line functions it produces.
require_once __DIR__ . '/sync/DialplanBuilders.php';
require_once __DIR__ . '/sync/SyncInboundDialplan.php';
require_once __DIR__ . '/sync/SyncOutboundDialplan.php';
require_once __DIR__ . '/sync/SyncGeneralDialplan.php';
require_once __DIR__ . '/sync/SyncMOH.php';
require_once __DIR__ . '/sync/SyncExtensions.php';
require_once __DIR__ . '/sync/SyncTrunks.php';
require_once __DIR__ . '/sync/SyncQueues.php';
require_once __DIR__ . '/sync/SyncIVRs.php';
require_once __DIR__ . '/sync/SyncTimeConditions.php';
require_once __DIR__ . '/sync/SyncFeatureCodes.php';
require_once __DIR__ . '/sync/SyncInternalNumbers.php';
require_once __DIR__ . '/sync/SyncRtpSettings.php';
require_once __DIR__ . '/sync/SyncUdptlSettings.php';
require_once __DIR__ . '/sync/SyncRingGroups.php';
require_once __DIR__ . '/sync/SyncConferences.php';
require_once __DIR__ . '/sync/SyncVoicemail.php';
require_once __DIR__ . '/sync/SyncPermissions.php';

/**
 * Updates the system default language (/etc/asterisk/asterisk.conf [options]
 * defaultlanguage=). CAUTION: unlike every other sync* function this does NOT
 * take effect with "core reload", only with a FULL Asterisk restart
 * (asterisk.conf [options] is read only at startup). So it returns true only
 * when the value REALLY changed, false otherwise — the caller
 * (asterisk_settings.php) must trigger a restart only on true, not on every
 * save.
 */
function syncDefaultLanguage($lang) {
    $lang = preg_replace('/[^a-zA-Z_]/', '', $lang) ?: 'en';
    $conf_path = ASTERISK_CONF_DIR . '/asterisk.conf';
    $content = @file_get_contents($conf_path);
    if ($content === false) return false;

    if (preg_match('/^defaultlanguage\s*=\s*(.*)$/m', $content, $m) && trim($m[1]) === $lang) {
        return false; // same value already, nothing changed
    }

    if (preg_match('/^defaultlanguage\s*=.*$/m', $content)) {
        $content = preg_replace('/^defaultlanguage\s*=.*$/m', "defaultlanguage = {$lang}", $content, 1);
    } else {
        // Append at the end of the [options] section (up to the next [section] or end of file)
        $content = preg_replace('/(\[options\][^\[]*)/', "$1defaultlanguage = {$lang}\n", $content, 1);
    }

    file_put_contents($conf_path, $content);
    return true;
}

/**
 * Full Initial / Bulk System Sync Trigger
 */
function syncEverything() {
    // Every syncXxx() function already calls ITS OWN reload (dialplan/pjsip/
    // queue/moh) — triggering the same reloads again here was redundant (one
    // syncEverything() call reloaded the dialplan 5 times, pjsip 2 times and
    // moh 2 times; found in the 2026-08-21 audit).
    syncTransports();
    syncPermissions();
    syncAllExtensions();
    syncAllTrunks();
    syncAllQueues();
    syncAllIVRs();
    syncAllTimeConditions();
    syncRingGroups();
    syncConferences();
    syncVoicemail();
    syncInboundDialplan();
    syncOutboundDialplan();
    syncFeatureCodes();
    // The number context must be generated BEFORE the general dialplan that
    // includes it; otherwise on a fresh install Asterisk includes a context
    // that does not exist.
    syncInternalNumbers();
    syncGeneralDialplan();
    syncAsteriskMOH();
    syncRtpSettings();
    syncUdptlSettings();

    return true;
}

if (php_sapi_name() === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    syncEverything();
}

