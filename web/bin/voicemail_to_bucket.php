#!/usr/bin/env php
<?php
/**
 * Archives heard voicemail to the S3 bucket (Admin → File storage, #69). Cron.
 *
 * Messages in a mailbox's Old folder (already heard) whose recording is older
 * than voicemail_archive_days are uploaded, listed in voicemail_archive and
 * removed from Asterisk's spool. New messages are never touched (the phones'
 * voicemail lamp and listening from a desk phone stay as they are); archived
 * ones are played and deleted in the portal and the apps only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/BucketFiles.php';

const MAX_PER_RUN = 200;
// A mailbox Asterisk changed this recently may be in use (someone listening): next run.
const BUSY_SECONDS = 120;

$lock = fopen('/run/aipbx-voicemail-to-bucket.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$days = (int) getSystemSetting('voicemail_archive_days', '0');
if ($days <= 0 || !BucketFiles::bucketInUse()) {
    exit(0);
}

$db = getDB();
$insert = $db->prepare('INSERT INTO voicemail_archive (mailbox, callerid, origtime, duration, object_key) VALUES (?, ?, ?, ?, ?)');
$cutoff = time() - $days * 86400;
$archived = 0;
$failed = 0;

foreach (glob('/var/spool/asterisk/voicemail/default/*/Old', GLOB_ONLYDIR) ?: [] as $old) {
    $ext = basename(dirname($old));
    if (!preg_match('/^[0-9]{1,20}$/', $ext)) {
        continue;
    }
    $recent = false;
    foreach (glob(dirname($old) . '/{INBOX,Old}/*', GLOB_BRACE) ?: [] as $f) {
        if (time() - (int) @filemtime($f) < BUSY_SECONDS) {
            $recent = true;
            break;
        }
    }
    if ($recent) {
        continue;
    }
    foreach (glob($old . '/msg[0-9][0-9][0-9][0-9].txt') ?: [] as $txt) {
        if ($archived >= MAX_PER_RUN) {
            break 2;
        }
        $info = @parse_ini_string((string) @file_get_contents($txt), false, INI_SCANNER_RAW) ?: [];
        $origtime = (int) ($info['origtime'] ?? 0);
        $base = substr($txt, 0, -4);
        $wav = is_file($base . '.wav') ? $base . '.wav' : (is_file($base . '.WAV') ? $base . '.WAV' : '');
        if ($origtime <= 0 || $origtime > $cutoff || $wav === '') {
            continue;
        }
        $key = 'voicemail/' . $ext . '/' . $origtime . '-' . basename($base) . '.wav';
        if (!BucketFiles::upload($wav, $key, 'audio/wav')) {
            $failed++;
            fwrite(STDERR, "voicemail_to_bucket: upload failed: {$wav}\n");
            break 2; // bucket or chat service down: next run
        }
        $insert->execute([$ext, (string) ($info['callerid'] ?? ''), $origtime, (int) ($info['duration'] ?? 0), $key]);
        foreach (glob($base . '.*') ?: [] as $f) {
            @unlink($f);
        }
        $archived++;
    }
}
if ($archived > 0 || $failed > 0) {
    echo "voicemail_to_bucket: archived {$archived}, failed {$failed}\n";
}
