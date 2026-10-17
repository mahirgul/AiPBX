#!/usr/bin/env php
<?php
/**
 * Faxes into the S3 bucket (Admin → File storage, #69). Runs from cron.
 *
 *   fax_to_bucket.php                 upload the files of faxes finished more than an hour ago
 *   fax_to_bucket.php --delete KEY…   delete bucket files (fax_cleanup.sh, retention)
 *
 * A file is moved only when no fax that uses it is still pending or younger
 * than an hour (a fax sent again reuses the same TIFF and PDF).
 */
if (PHP_SAPI !== 'cli') {   // cron/dialplan tool; never over HTTP
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/FaxStorage.php';

const MAX_UPLOADS_PER_RUN = 200;

$args = $_SERVER['argv'] ?? [];
if (($args[1] ?? '') === '--delete') {
    foreach (array_slice($args, 2) as $key) {
        $key = BucketFiles::isRemote($key) ? BucketFiles::key($key) : $key;
        if (str_starts_with($key, 'faxes/')) {
            BucketFiles::delete($key);
        }
    }
    exit(0);
}

$lock = fopen('/run/aipbx-fax-to-bucket.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB) || !FaxStorage::enabled()) {
    exit(0);
}

$db = getDB();
$root = rtrim(FAX_STORAGE_PATH, '/');
$age = FaxStorage::MIN_AGE_SECONDS;

// Local files of finished faxes, oldest first.
$candidates = [];
$queries = [
    "SELECT pdf_path AS p FROM fax_received WHERE pdf_path LIKE :r1 AND received_at < NOW() - INTERVAL $age SECOND
     UNION SELECT tif_path FROM fax_received WHERE tif_path LIKE :r2 AND received_at < NOW() - INTERVAL $age SECOND",
    "SELECT pdf_path AS p FROM fax_sent WHERE pdf_path LIKE :r1 AND status <> 'PENDING' AND COALESCE(completed_at, created_at) < NOW() - INTERVAL $age SECOND
     UNION SELECT tif_path FROM fax_sent WHERE tif_path LIKE :r2 AND status <> 'PENDING' AND COALESCE(completed_at, created_at) < NOW() - INTERVAL $age SECOND",
];
foreach ($queries as $sql) {
    $stmt = $db->prepare($sql);
    $stmt->execute(['r1' => $root . '/%', 'r2' => $root . '/%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $candidates[$p] = true;
    }
}

// Still in use: a pending fax or one that finished less than an hour ago uses it.
$busy = $db->prepare("SELECT COUNT(*) FROM fax_sent WHERE (pdf_path = :p1 OR tif_path = :p2)
    AND (status = 'PENDING' OR COALESCE(completed_at, created_at) >= NOW() - INTERVAL $age SECOND)");
$setReceived = [$db->prepare('UPDATE fax_received SET pdf_path = ? WHERE pdf_path = ?'), $db->prepare('UPDATE fax_received SET tif_path = ? WHERE tif_path = ?')];
$setSent = [$db->prepare('UPDATE fax_sent SET pdf_path = ? WHERE pdf_path = ?'), $db->prepare('UPDATE fax_sent SET tif_path = ? WHERE tif_path = ?')];

$uploaded = 0;
$failed = 0;
foreach (array_slice(array_keys($candidates), 0, MAX_UPLOADS_PER_RUN) as $path) {
    $busy->execute(['p1' => $path, 'p2' => $path]);
    if ((int) $busy->fetchColumn() > 0 || !is_file($path)) {
        continue;
    }
    $key = FaxStorage::keyFor($path);
    if ($key === '') {
        continue;
    }
    if (!BucketFiles::upload($path, $key, FaxStorage::contentType($path))) {
        $failed++;
        fwrite(STDERR, "fax_to_bucket: upload failed: {$path}\n");
        break; // bucket or chat service down: next run
    }
    foreach (array_merge($setReceived, $setSent) as $stmt) {
        $stmt->execute([BucketFiles::PREFIX . $key, $path]);
    }
    unlink($path);
    $uploaded++;
}
if ($uploaded > 0 || $failed > 0) {
    echo "fax_to_bucket: uploaded {$uploaded}, failed {$failed}\n";
}
