<?php
/**
 * Writing the generated Asterisk files (/etc/asterisk/pbx/): the lowest layer of the sync code.
 *
 * Every Sync*.php module writes its files through these functions, and the orchestrator
 * (src/asterisk_sync.php) calls the modules. The functions used to live in the orchestrator, so
 * each module needed the file that calls it. They now live here, below both, and know nothing
 * about the modules or the order they run in.
 *
 * - writePBXConf(): one file, owned by asterisk, with a note when it was changed by hand
 * - writeConfWithRollback() / writeConfsWithRollback(): write, reload, go back when the reload fails
 * - withSyncLock(): one lock per domain around "read the database, regenerate, write, reload"
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../file_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * Helper to safely write file with ownership
 */
function writePBXConf($filename, $content) {
    $file_path = ASTERISK_PBX_DIR . '/' . $filename;
    keepHandEditedConf($filename, $file_path);
    // A failed write used to pass silently: the reload then succeeded with the
    // old file and the pending change was marked as applied.
    // 0640: the files hold voicemail PINs, the night-guard PIN and SIP passwords.
    // Asterisk and the portal (www-data is in the asterisk group) still read them.
    if (FileHelper::writeFile($file_path, $content, 'asterisk', 'asterisk', 0640) === false) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new \Exception(sprintf(t('sync.err_write'), $file_path, $err));
    }
    @FileHelper::writeFile($file_path . '.written', hash('sha256', (string) $content), 'asterisk', 'asterisk', 0664);
    return $file_path;
}

/**
 * A generated file that differs from what the portal last wrote was changed by hand. Regenerating
 * would lose that silently, so a copy is kept and the change goes in the audit log. The copy's name
 * ends in the date, so Asterisk's `pbx/*.conf` includes do not read it.
 */
function keepHandEditedConf(string $filename, string $file_path): void
{
    $written = @file_get_contents($file_path . '.written');
    $current = is_file($file_path) ? @file_get_contents($file_path) : false;
    if (!is_string($written) || !is_string($current) || hash('sha256', $current) === trim($written)) {
        return;
    }
    $copy = $file_path . '.edited-' . date('Ymd-His');
    if (@copy($file_path, $copy)) {
        @chmod($copy, 0640);
        $old = glob($file_path . '.edited-*') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 5)) as $stale) {
            @unlink($stale);
        }
    }
    writeAuditLog('system', 'config_file', 0, $filename, 'hand_edit', $_SESSION['user_id'] ?? null);
}

/** @return list<string> kept copies of generated files that were changed by hand, newest first */
function handEditedConfCopies(): array
{
    $copies = glob(ASTERISK_PBX_DIR . '/*.edited-*') ?: [];
    rsort($copies);
    return $copies;
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
    return writeConfsWithRollback([$filename => $content], $reloadFn, (string) $context);
}

/**
 * The same for files that belong together (parking: res_parking + its dialplan): all are
 * written, then reloaded; when the reload fails ALL of them go back to their previous
 * content, so Asterisk never runs a new half with an old half.
 *
 * @param array<string, string> $files file name in pbx/ => content
 */
function writeConfsWithRollback(array $files, callable $reloadFn, string $context): bool {
    $previous = [];
    foreach (array_keys($files) as $filename) {
        $path = ASTERISK_PBX_DIR . '/' . $filename;
        $previous[$filename] = is_file($path) ? file_get_contents($path) : null;
        if ($previous[$filename] === false) {
            throw new \RuntimeException(sprintf(t('sync.err_write'), $path, 'Cannot read the previous configuration'));
        }
    }

    $written = [];
    try {
        foreach ($files as $filename => $content) {
            writePBXConf($filename, $content);
            $written[] = $filename;
        }
        $reloadFn();
        // Success — keep the previous content as the "known good" version to go back
        // to on the next failure (the current file is exactly the new one already).
        foreach ($previous as $filename => $old) {
            if ($old !== null) {
                @FileHelper::writeFile(ASTERISK_PBX_DIR . '/' . $filename . '.prev', $old, 'asterisk', 'asterisk', 0640);
            }
        }
        return true;
    } catch (\Throwable $e) {
        if ($written === []) {
            throw $e;
        }
        try {
            foreach ($written as $filename) {
                $old = $previous[$filename];
                if ($old !== null) {
                    writePBXConf($filename, $old);
                    continue;
                }
                $path = ASTERISK_PBX_DIR . '/' . $filename;
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException(sprintf(t('sync.err_write'), $path, 'Cannot remove the new configuration'));
                }
                @unlink($path . '.written');
            }
            $reloadFn();
        } catch (\Throwable $e2) {
            throw new \Exception(sprintf(t('sync.err_rollback_failed'), $context, $e->getMessage(), $e2->getMessage()));
        }
        throw new \Exception(sprintf(t('sync.err_rolled_back'), $context, $e->getMessage()));
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
    // One directory for Apache (www-data), Asterisk's feature codes (asterisk)
    // and root: /tmp does not work (Apache has its own PrivateTmp, and a
    // directory root made with 0700 locked www-data out, which then ran
    // without a lock). Group asterisk, which www-data is in (install.sh);
    // closed to every other user, who could otherwise hold a lock forever.
    $lock_dir = is_dir('/var/lib/aipbx') ? '/var/lib/aipbx/sync-locks' : sys_get_temp_dir() . '/aipbx_sync_locks';
    if (!is_dir($lock_dir) && @mkdir($lock_dir, 0770, true)) {
        @chgrp($lock_dir, 'asterisk');
        @chmod($lock_dir, 02770);   // setgid: lock files get the group too
    }
    $lock_file = $lock_dir . '/' . preg_replace('/[^a-z0-9_]/', '', $lock_name) . '.lock';
    $created = !file_exists($lock_file);
    $lock_fp = @fopen($lock_file, 'c');
    if ($lock_fp !== false && $created) {
        @chmod($lock_file, 0660);
    }
    if ($lock_fp === false) {
        $lock_fp = @fopen($lock_file, 'r');   // flock() works on a read-only handle too
    }
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
