<?php
declare(strict_types=1);

/**
 * Read-only Asterisk log viewer service with tailing, filtering, and secret masking.
 */
final class AsteriskLogService
{
    public const DEFAULT_DIR = '/var/log/asterisk';

    /** @var array<string, string> */
    public const LOG_FILES = [
        'messages' => 'messages.log',
        'security' => 'security.log',
        'queue_log' => 'queue_log',
        'full' => 'full.log',
    ];

    /** @return array<string, string> key => filename */
    public static function availableLogs(?string $baseDir = null): array
    {
        $dir = rtrim($baseDir ?? self::DEFAULT_DIR, '/');
        $out = [];
        foreach (self::LOG_FILES as $key => $file) {
            $path = $dir . '/' . $file;
            if (is_file($path) && is_readable($path)) {
                $out[$key] = $file;
            }
        }
        return $out;
    }

    /**
     * Reads the last `$maxLines` lines from an allowlisted log file, applies optional
     * level and search filters, and masks sensitive credentials/headers.
     *
     * @return array{ok: bool, file: string, lines: list<string>, error?: string}
     */
    public static function readTail(
        string $logKey,
        int $maxLines = 200,
        string $level = '',
        string $search = '',
        ?string $baseDir = null
    ): array {
        if (!isset(self::LOG_FILES[$logKey])) {
            return ['ok' => false, 'file' => '', 'lines' => [], 'error' => 'Unknown log file.'];
        }
        $maxLines = max(20, min(1000, $maxLines));
        $dir = rtrim($baseDir ?? self::DEFAULT_DIR, '/');
        $file = self::LOG_FILES[$logKey];
        $path = $dir . '/' . $file;

        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'file' => $file, 'lines' => [], 'error' => 'Log file not found or not readable.'];
        }

        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return ['ok' => false, 'file' => $file, 'lines' => [], 'error' => 'Failed to open log file.'];
        }

        $chunkBytes = 512 * 1024;
        $size = filesize($path);
        if ($size !== false && $size > $chunkBytes) {
            fseek($fp, -$chunkBytes, SEEK_END);
            fgets($fp); // discard partial first line
        }
        $raw = stream_get_contents($fp);
        fclose($fp);

        if ($raw === false || $raw === '') {
            return ['ok' => true, 'file' => $file, 'lines' => []];
        }

        $allLines = preg_split('/\r\n|\n|\r/', rtrim($raw, "\r\n")) ?: [];
        $levelUpper = strtoupper(trim($level));
        $allowedLevels = ['ERROR', 'WARNING', 'NOTICE', 'VERBOSE'];
        if (!in_array($levelUpper, $allowedLevels, true)) {
            $levelUpper = '';
        }
        $search = trim($search);

        $filtered = [];
        foreach ($allLines as $line) {
            if ($levelUpper !== '' && stripos($line, $levelUpper) === false) {
                continue;
            }
            $masked = self::maskSecrets($line);
            if ($search !== '' && stripos($masked, $search) === false) {
                continue;
            }
            $filtered[] = $masked;
        }

        if (count($filtered) > $maxLines) {
            $filtered = array_slice($filtered, -$maxLines);
        }

        return ['ok' => true, 'file' => $file, 'lines' => $filtered];
    }

    public static function maskSecrets(string $line): string
    {
        $line = (string) preg_replace('/(Authorization:\s*\S+\s+)([^\r\n]+)/i', '$1***', $line);
        $line = (string) preg_replace('/\b(Bearer\s+)([^\s,;]+)/i', '$1***', $line);
        $line = (string) preg_replace('/\b(secret|password|passwd|sip_password|sip_auth_digest|token|api_key|md5_cred|auth_pass|client_secret)\s*=\s*([^\s,;]+)/i', '$1=***', $line);
        $line = (string) preg_replace('/("(?:secret|password|passwd|sip_password|sip_auth_digest|token|api_key|md5_cred|auth_pass|client_secret)"\s*:\s*")([^"]+)(")/i', '$1***$3', $line);
        return $line;
    }
}
