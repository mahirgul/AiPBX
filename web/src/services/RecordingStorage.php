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
}
