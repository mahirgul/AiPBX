<?php
require_once __DIR__ . '/BucketFiles.php';

/**
 * Call recordings in the S3 bucket (#69, docs/recordings.md).
 *
 * With recordings_storage = "s3", bin/recordings_to_mp3.php uploads each
 * finished MP3 and removes the local copy; the CDR then points at
 * "s3:recordings/<path below the monitor folder>" (BucketFiles).
 */
final class RecordingStorage
{
    public const PREFIX = BucketFiles::PREFIX;

    /** Upload recordings to the bucket (Admin → File storage). */
    public static function enabled(): bool
    {
        return getSystemSetting('recordings_storage', 'local') === 's3' && BucketFiles::bucketInUse();
    }

    public static function isRemote(?string $path): bool
    {
        return BucketFiles::isRemote($path);
    }

    public static function key(string $path): string
    {
        return BucketFiles::key($path);
    }

    /** Bucket key of a local recording: recordings/<path below the monitor folder>, or ''. */
    public static function keyFor(string $localPath): string
    {
        return BucketFiles::keyBelow(MONITOR_STORAGE_PATH, $localPath, 'recordings');
    }

    public static function upload(string $localPath, string $key, string $contentType = 'audio/mpeg'): bool
    {
        return BucketFiles::upload($localPath, $key, $contentType);
    }

    public static function passthru(string $key, array $headers): bool
    {
        return BucketFiles::passthru($key, $headers);
    }

    public static function delete(string $key): bool
    {
        return BucketFiles::delete($key);
    }

    private const MIME = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'gsm' => 'audio/x-gsm', 'ogg' => 'audio/ogg'];

    private static function sanitizeDownloadName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $clean = trim($clean, '._-');
        return $clean !== '' ? mb_substr($clean, 0, 120) : 'recording';
    }

    /**
     * Send a recording the database points at: from the bucket, or a file inside the
     * monitor folder (nothing else: a path that leads out of it is refused). The caller
     * checks who may hear it first. $downloadName without extension; '' plays inline.
     *
     * @return bool false when there is no such file (nothing was sent)
     */
    public static function send(?string $path, string $downloadName = ''): bool
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }
        if (self::isRemote($path)) {
            $key = self::key($path);
            if (!str_starts_with($key, 'recordings/') || str_contains($key, '..') || str_contains($key, '\\') || str_contains($key, "\0")) {
                return false;
            }
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($key, PATHINFO_EXTENSION))) ?? '';
            $headers = [
                'Content-Type' => self::MIME[$ext] ?? 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($downloadName !== '') {
                $safeName = self::sanitizeDownloadName($downloadName);
                $headers['Content-Disposition'] = 'attachment; filename="' . $safeName . '.' . ($ext !== '' ? $ext : 'mp3') . '"';
            }
            return self::passthru($key, $headers);
        }
        $file = realpath($path);
        $allowed = realpath(MONITOR_STORAGE_PATH);
        // Trailing '/': a plain prefix would also accept a sibling such as "/monitor2/...".
        if (!$file || !$allowed || !str_starts_with($file, rtrim($allowed, '/') . '/') || !is_file($file)) {
            return false;
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($file, PATHINFO_EXTENSION))) ?? '';
        header('Content-Type: ' . (self::MIME[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if ($downloadName !== '') {
            $safeName = self::sanitizeDownloadName($downloadName);
            header('Content-Disposition: attachment; filename="' . $safeName . '.' . ($ext !== '' ? $ext : 'wav') . '"');
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        readfile($file);
        return true;
    }
}
