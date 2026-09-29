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
require_once dirname(__DIR__) . '/config.php';

const MIN_AGE_SECONDS = 180;
const EMPTY_WAV_BYTES = 1024;

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

if ($converted > 0 || $failed > 0) {
    echo "recordings_to_mp3: converted {$converted}, failed {$failed}\n";
}
