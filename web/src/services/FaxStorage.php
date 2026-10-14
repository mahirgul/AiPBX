<?php
require_once __DIR__ . '/BucketFiles.php';

/**
 * Faxes (PDF and TIFF) in the S3 bucket (#69, docs/fax.md).
 *
 * With faxes_storage = "s3", bin/fax_to_bucket.php uploads the files of
 * finished faxes an hour after they finished (the e-mail with the PDF and a
 * quick "send again" still use the local files) and removes the local copies;
 * fax_received / fax_sent then hold "s3:faxes/<path below the fax folder>".
 */
final class FaxStorage
{
    /** Finished faxes wait this long before they move to the bucket. */
    public const MIN_AGE_SECONDS = 3600;

    public static function enabled(): bool
    {
        return getSystemSetting('faxes_storage', 'local') === 's3' && BucketFiles::bucketInUse();
    }

    public static function keyFor(string $localPath): string
    {
        return BucketFiles::keyBelow(FAX_STORAGE_PATH, $localPath, 'faxes');
    }

    public static function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'tif', 'tiff' => 'image/tiff',
            default => 'application/octet-stream',
        };
    }

    /** Deletes a fax file wherever it is (local or bucket). */
    public static function remove(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        if (BucketFiles::isRemote($path)) {
            BucketFiles::delete(BucketFiles::key($path));
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * A local copy of a fax file (sending a fax again needs the TIFF on the
     * disk): the file itself, or the bucket file downloaded to its old place.
     */
    public static function local(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (!BucketFiles::isRemote($path)) {
            return file_exists($path) ? $path : null;
        }
        $key = BucketFiles::key($path);
        if (!str_starts_with($key, 'faxes/')) {
            return null;
        }
        $target = rtrim(FAX_STORAGE_PATH, '/') . '/' . substr($key, strlen('faxes/'));
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0775, true);
        }
        return BucketFiles::download($key, $target) ? $target : null;
    }
}
