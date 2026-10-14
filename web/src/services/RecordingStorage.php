<?php
require_once __DIR__ . '/FileStorageService.php';

/**
 * Call recordings in the S3 bucket of Admin → File storage (#69, docs/recordings.md).
 *
 * With recordings_storage = "s3", bin/recordings_to_mp3.php uploads each
 * finished MP3 and removes the local copy; the CDR then points at
 * "s3:recordings/<path below the monitor folder>". Playing, downloading and
 * deleting go through the chat service's internal /api/internal/files (the
 * one S3 client of AiPBX), so the bucket stays private and the access checks
 * of the portal stay where they are.
 */
final class RecordingStorage
{
    public const PREFIX = 's3:';

    /** Upload recordings to the bucket (Admin → File storage). */
    public static function enabled(): bool
    {
        return getSystemSetting('recordings_storage', 'local') === 's3'
            && getSystemSetting('storage_backend', 'local') === 's3'
            && trim((string) getSystemSetting('storage_s3_bucket', '')) !== '';
    }

    public static function isRemote(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, self::PREFIX);
    }

    /** "s3:recordings/…" → "recordings/…" */
    public static function key(string $path): string
    {
        return substr($path, strlen(self::PREFIX));
    }

    /** Bucket key of a local recording: recordings/<path below the monitor folder>, or '' when outside it. */
    public static function keyFor(string $localPath): string
    {
        $root = rtrim((string) realpath(MONITOR_STORAGE_PATH), '/');
        $file = (string) realpath($localPath);
        if ($root === '' || $file === '' || !str_starts_with($file, $root . '/')) {
            return '';
        }
        $rel = substr($file, strlen($root) + 1);
        return preg_match('#^[A-Za-z0-9_@+-][A-Za-z0-9._@+/-]*$#', $rel) && !str_contains($rel, '..') ? 'recordings/' . $rel : '';
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
}
