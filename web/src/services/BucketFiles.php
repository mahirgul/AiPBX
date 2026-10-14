<?php
require_once __DIR__ . '/FileStorageService.php';

/**
 * PBX files (call recordings, faxes) in the S3 bucket of Admin → File storage
 * (#69). Everything goes through the chat service's internal
 * /api/internal/files — the one S3 client of AiPBX — so the bucket stays
 * private and the portal keeps its own access checks. A file in the bucket is
 * stored in the database as "s3:<key>".
 */
final class BucketFiles
{
    public const PREFIX = 's3:';

    public static function isRemote(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, self::PREFIX);
    }

    /** "s3:recordings/…" → "recordings/…" */
    public static function key(string $path): string
    {
        return substr($path, strlen(self::PREFIX));
    }

    /**
     * Bucket key of a file below $root: <dir>/<path below root>, or '' when the
     * file is outside it or its name has characters keys do not allow.
     */
    public static function keyBelow(string $root, string $localPath, string $dir): string
    {
        $root = rtrim((string) realpath($root), '/');
        $file = (string) realpath($localPath);
        if ($root === '' || $file === '' || !str_starts_with($file, $root . '/')) {
            return '';
        }
        $rel = substr($file, strlen($root) + 1);
        return preg_match('#^[A-Za-z0-9_@+-][A-Za-z0-9._@+/-]*$#', $rel) && !str_contains($rel, '..') ? $dir . '/' . $rel : '';
    }

    /** The bucket of File storage is in use (chat files there too). */
    public static function bucketInUse(): bool
    {
        return getSystemSetting('storage_backend', 'local') === 's3'
            && trim((string) getSystemSetting('storage_s3_bucket', '')) !== '';
    }

    /** Uploads a local file; true when the bucket has it. */
    public static function upload(string $localPath, string $key, string $contentType = 'audio/mpeg'): bool
    {
        $fh = @fopen($localPath, 'rb');
        if (!$fh) {
            return false;
        }
        $ch = self::curl('PUT', $key);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => (int) filesize($localPath),
            CURLOPT_HTTPHEADER => array_merge(self::headers(), ['Content-Type: ' . $contentType]),
            CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fh);
        $json = is_string($resp) ? json_decode($resp, true) : null;
        return $code === 200 && !empty($json['success']);
    }

    /**
     * Sends the file to the browser (headers first, then the content as it
     * arrives). False when it cannot be read; nothing is sent then.
     */
    public static function passthru(string $key, array $headers): bool
    {
        $ch = self::curl('GET', $key);
        $started = false;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers) {
                if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
                    $headers['Content-Length'] = $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$started, &$headers) {
                if (!$started) {
                    if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
                        return 0; // abort: an error body is not the recording
                    }
                    foreach ($headers as $k => $v) {
                        header($k . ': ' . $v);
                    }
                    $started = true;
                }
                echo $data;
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        return $started;
    }

    public static function delete(string $key): bool
    {
        $ch = self::curl('DELETE', $key);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    }

    private static function headers(): array
    {
        return ['X-AiPBX-Internal: ' . FileStorageService::internalToken()];
    }

    /** @return \CurlHandle */
    private static function curl(string $method, string $key)
    {
        $port = (string) portalEnv('CHAT_PORT', '8086');
        $ch = curl_init('http://127.0.0.1:' . (ctype_digit($port) ? $port : '8086') . '/api/internal/files?key=' . rawurlencode($key));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => self::headers(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROXY => '',
        ]);
        return $ch;
    }

    /** Downloads a bucket file to a local path (e.g. to send a fax again); true on success. */
    public static function download(string $key, string $target): bool
    {
        $tmp = $target . '.part';
        $fh = @fopen($tmp, 'wb');
        if (!$fh) {
            return false;
        }
        $ch = self::curl('GET', $key);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300]);
        curl_exec($ch);
        $ok = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        fclose($fh);
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
