#!/usr/bin/env php
<?php
/**
 * Converts finished call recordings (WAV, ~1 MB/min) to mono 16 kbps MP3
 * (~0.12 MB/min) and points the CDR at the new file. Runs from cron.
 *
 * A recording is "finished" when it has not been written to for a few minutes;
 * MixMonitor keeps touching the file while the call lasts. Header-only WAVs
 * (calls that were never bridged) are removed together with their CDR link.
 */
if (PHP_SAPI !== 'cli') {   // cron/dialplan tool; never over HTTP
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/RecordingStorage.php';

const MIN_AGE_SECONDS = 180;
const EMPTY_WAV_BYTES = 1024;
// Recordings uploaded to the bucket per run (every 5 minutes).
const MAX_UPLOADS_PER_RUN = 200;

$lock = fopen('/run/aipbx-recordings-to-mp3.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
if (!is_executable('/usr/bin/lame')) {
    fwrite(STDERR, "recordings_to_mp3: /usr/bin/lame is not installed\n");
    exit(1);
}
proc_nice(19);

$root = rtrim(MONITOR_STORAGE_PATH, '/');
if (!is_dir($root)) {
    exit(0);
}

$db = getDB();
$updateCdr = $db->prepare('UPDATE asteriskcdr SET userfield = ? WHERE userfield = ?');
$cutoff = time() - MIN_AGE_SECONDS;
$converted = 0;
$failed = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->isLink() || strtolower($f->getExtension()) !== 'wav' || $f->getMTime() > $cutoff) {
        continue;
    }
    $wav = $f->getPathname();
    $base = substr($wav, 0, -4);
    $mp3 = $base . '.mp3';

    if ($f->getSize() <= EMPTY_WAV_BYTES) {
        $updateCdr->execute(['', $wav]);
        @unlink($wav);
        continue;
    }

    $tmp = $mp3 . '.part';
    $cmd = sprintf('/usr/bin/lame --quiet -m m -b 16 --resample 8 %s %s 2>&1', escapeshellarg($wav), escapeshellarg($tmp));
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        $failed++;
        fwrite(STDERR, "recordings_to_mp3: conversion failed: {$wav}\n");
        continue;
    }

    @chown($tmp, $f->getOwner());
    @chgrp($tmp, $f->getGroup());
    @chmod($tmp, $f->getPerms() & 0777);
    @touch($tmp, $f->getMTime());
    rename($tmp, $mp3);
    $updateCdr->execute([$mp3, $wav]);
    unlink($wav);
    $converted++;
}

// Recordings in the S3 bucket (Admin → File storage, #69): finished MP3s are
// uploaded and leave the disk; the CDR then points at "s3:recordings/…".
// Recordings from before the switch move a batch at a time.
$uploaded = 0;
if (RecordingStorage::enabled()) {
    $rows = $db->prepare("SELECT DISTINCT userfield FROM asteriskcdr WHERE userfield LIKE ? AND userfield LIKE '%.mp3' LIMIT " . MAX_UPLOADS_PER_RUN);
    $rows->execute([$root . '/%']);
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $mp3) {
        $key = is_file($mp3) && filemtime($mp3) <= $cutoff ? RecordingStorage::keyFor($mp3) : '';
        if ($key === '') {
            continue;
        }
        if (!RecordingStorage::upload($mp3, $key)) {
            $failed++;
            fwrite(STDERR, "recordings_to_mp3: upload to the bucket failed: {$mp3}\n");
            break; // bucket or chat service down: try again next run
        }
        $updateCdr->execute([RecordingStorage::PREFIX . $key, $mp3]);
        unlink($mp3);
        $uploaded++;
    }
}

if ($converted > 0 || $failed > 0 || $uploaded > 0) {
    echo "recordings_to_mp3: converted {$converted}, uploaded {$uploaded}, failed {$failed}\n";
}
