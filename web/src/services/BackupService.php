<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../priv_helper.php';
require_once __DIR__ . '/SsoOnlyService.php';

/**
 * Backup page. The backups are root's (they hold every secret of the system), so the
 * portal lists, fetches and removes them through aipbx-priv. A restore is done by
 * /usr/local/sbin/aipbx-restore in its own systemd unit: Apache restarts during it, and
 * the database under this very portal is replaced.
 */
class BackupService
{
    public const STATUS_FILE = '/var/lib/aipbx/restore-status.json';
    public const LOG_FILE = '/var/log/aipbx/restore.log';
    public const UPLOAD_DIR = '/var/lib/aipbx/backup-upload';
    public const KEEP_FILE = '/var/lib/aipbx/backup-keep-days';
    public const CHUNK_BYTES = 1048576;
    public const MAX_UPLOAD_GB = 5;
    private const RE_NAME = '/^aipbx-[0-9]{8}-[0-9]{4}(-[a-z-]{1,20})?$/D';

    public static function validName(string $name): bool
    {
        return preg_match(self::RE_NAME, $name) === 1;
    }

    /** @return list<array{name:string,bytes:int,time:int,version:string,uploaded:bool,safety:bool}> */
    public static function parseList(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $f = explode("\t", $line);
            if (count($f) < 5 || !self::validName($f[0])) {
                continue;
            }
            $rows[] = [
                'name' => $f[0],
                'bytes' => (int) $f[1],
                'time' => (int) $f[2],
                'version' => $f[3],
                'uploaded' => $f[4] === '1',
                'safety' => str_ends_with($f[0], '-before-restore'),
            ];
        }
        usort($rows, static fn($a, $b) => $b['time'] <=> $a['time']);
        return $rows;
    }

    /** @return list<array{name:string,bytes:int,time:int,version:string,uploaded:bool,safety:bool}> */
    public static function all(): array
    {
        $res = PrivHelper::run(['backup', 'list']);
        return $res['success'] ? self::parseList($res['output']) : [];
    }

    public static function creating(): bool
    {
        return trim(PrivHelper::run(['backup', 'creating'])['output']) === 'yes';
    }

    /** @return array{success:bool,error?:string,message?:string} */
    public static function create(): array
    {
        $res = PrivHelper::run(['backup', 'create']);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output']];
        }
        writeAuditLog('backup', 'backup', '', 'Backup started by hand', 'create');
        return ['success' => true, 'message' => t('backup.created')];
    }

    /** @return array{success:bool,error?:string,message?:string} */
    public static function delete(string $name): array
    {
        if (!self::validName($name)) {
            return ['success' => false, 'error' => t('backup.err_name')];
        }
        $res = PrivHelper::run(['backup', 'delete', $name]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output']];
        }
        writeAuditLog('backup', 'backup', $name, "Backup deleted: {$name}", 'delete');
        return ['success' => true, 'message' => t('backup.deleted')];
    }

    public static function keepDays(): int
    {
        $v = is_readable(self::KEEP_FILE) ? (int) trim((string) file_get_contents(self::KEEP_FILE)) : 0;
        return $v > 0 ? $v : (int) (portalEnv('AIPBX_BACKUP_KEEP_DAYS', '14') ?: 14);
    }

    /** @return array{success:bool,error?:string,message?:string} */
    public static function setKeepDays(int $days): array
    {
        if ($days < 1 || $days > 999) {
            return ['success' => false, 'error' => t('backup.keep_invalid')];
        }
        $res = PrivHelper::run(['backup', 'keep', (string) $days]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output']];
        }
        writeAuditLog('backup', 'backup', '', "Backups are kept for {$days} days", 'update');
        return ['success' => true, 'message' => t('backup.keep_saved')];
    }

    /** Sends the backup to the browser. It holds every secret, so the download is on record. */
    public static function download(string $name): void
    {
        if (!self::validName($name)) {
            http_response_code(400);
            return;
        }
        writeAuditLog('backup', 'backup', $name, "Backup downloaded: {$name}", 'download');
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '.tar.gz"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        passthru('sudo -n ' . PrivHelper::BIN . ' backup fetch ' . escapeshellarg($name));
    }

    // ------------------------------------------------------------ upload

    /** PHP takes 2 MB at a time by default, and a backup is larger: the page sends pieces. */
    public static function uploadStart(): array
    {
        foreach (glob(self::UPLOAD_DIR . '/*.part') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        if (file_put_contents(self::UPLOAD_DIR . "/{$token}.part", '') === false) {
            return ['success' => false, 'error' => t('backup.upload_failed')];
        }
        return ['success' => true, 'token' => $token, 'chunk_bytes' => self::CHUNK_BYTES];
    }

    /** @param array<string,mixed> $file one entry of $_FILES */
    public static function uploadChunk(string $token, int $offset, array $file): array
    {
        $path = self::partPath($token);
        if ($path === null || !is_file($path) || is_link($path)) {
            return ['success' => false, 'error' => t('backup.err_upload_state')];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return ['success' => false, 'error' => t('backup.upload_failed')];
        }
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($offset !== $size) {
            return ['success' => false, 'error' => t('backup.err_upload_state')];
        }
        $piece = (int) $file['size'];
        if ($piece > self::CHUNK_BYTES * 2 || $size + $piece > self::MAX_UPLOAD_GB * 1024 ** 3) {
            @unlink($path);
            return ['success' => false, 'error' => sprintf(t('backup.err_upload_size'), self::MAX_UPLOAD_GB)];
        }
        $data = file_get_contents((string) $file['tmp_name']);
        if ($data === false || file_put_contents($path, $data, FILE_APPEND | LOCK_EX) === false) {
            return ['success' => false, 'error' => t('backup.upload_failed')];
        }
        return ['success' => true, 'received' => $size + $piece];
    }

    public static function uploadFinish(string $token): array
    {
        $path = self::partPath($token);
        if ($path === null || !is_file($path)) {
            return ['success' => false, 'error' => t('backup.err_upload_state')];
        }
        $res = PrivHelper::run(['backup', 'import', "{$token}.part"]);
        @unlink($path);
        if (!$res['success']) {
            $lines = preg_split('/\r?\n/', trim($res['output'])) ?: [];
            $why = (string) end($lines);
            writeAuditLog('backup', 'backup', '', 'Uploaded backup refused: ' . $why, 'security');
            return ['success' => false, 'error' => sprintf(t('backup.err_refused'), $why)];
        }
        $lines = preg_split('/\r?\n/', trim($res['output'])) ?: [];
        $name = (string) end($lines);
        writeAuditLog('backup', 'backup', $name, "Backup uploaded: {$name}", 'upload');
        return ['success' => true, 'message' => sprintf(t('backup.upload_done'), $name), 'name' => $name];
    }

    private static function partPath(string $token): ?string
    {
        return preg_match('/^[0-9a-f]{32}$/D', $token) === 1 ? self::UPLOAD_DIR . "/{$token}.part" : null;
    }

    // ------------------------------------------------------------ restore

    /**
     * Starts a restore. It replaces the whole system, so the administrator types the name
     * of the backup and their password (an SSO-only system has no password to ask for).
     * The token lets the page follow the progress when this session and the database under
     * it are gone: only its hash is stored.
     *
     * @return array{success:bool,error?:string,token?:string,relogin?:bool}
     */
    public static function restore(array $post, int $userId): array
    {
        $name = (string) ($post['name'] ?? '');
        if (!self::validName($name)) {
            return ['success' => false, 'error' => t('backup.err_name')];
        }
        if (!hash_equals($name, trim((string) ($post['confirm_name'] ?? '')))) {
            return ['success' => false, 'error' => t('backup.err_type')];
        }
        if (!SsoOnlyService::isActive()) {
            $hash = (string) DBHelper::fetchColumn('SELECT password_hash FROM sys_users WHERE id = ?', [$userId]);
            if ($hash === '' || !password_verify((string) ($post['password'] ?? ''), $hash)) {
                $_SESSION['backup_password_failures'] = (int) ($_SESSION['backup_password_failures'] ?? 0) + 1;
                writeAuditLog('backup', 'backup', $name, "Restore of {$name} refused: wrong password", 'security');
                if ($_SESSION['backup_password_failures'] >= 5) {
                    $_SESSION = [];
                    session_destroy();
                    return ['success' => false, 'error' => t('backup.err_password'), 'relogin' => true];
                }
                return ['success' => false, 'error' => t('backup.err_password')];
            }
        }
        unset($_SESSION['backup_password_failures']);
        if ((self::status()['state'] ?? '') === 'running') {
            return ['success' => false, 'error' => t('backup.err_running')];
        }

        $token = bin2hex(random_bytes(32));
        $args = ['backup', 'restore', $name, hash('sha256', $token)];
        if (!empty($post['db_only'])) {
            $args[] = '--db-only';
        }
        if (!empty($post['allow_calls'])) {
            $args[] = '--allow-calls';
        }
        // Written first: once the restore runs, the audit table itself is being replaced.
        writeAuditLog('backup', 'backup', $name, "Restore of {$name} started", 'restore');
        $res = PrivHelper::run($args);
        if (!$res['success']) {
            return ['success' => false, 'error' => sprintf(t('backup.err_start'), $res['output'])];
        }
        return ['success' => true, 'token' => $token];
    }

    /** @return array<string,mixed>|null */
    public static function status(): ?array
    {
        $raw = is_readable(self::STATUS_FILE) ? file_get_contents(self::STATUS_FILE) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        unset($data['token_hash']);
        return $data;
    }

    public static function logTail(int $lines = 40): string
    {
        if (!is_readable(self::LOG_FILE)) {
            return '';
        }
        $fh = fopen(self::LOG_FILE, 'rb');
        if (!$fh) {
            return '';
        }
        fseek($fh, max(0, (int) filesize(self::LOG_FILE) - 32 * 1024));
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        $data = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $data);
        return implode("\n", array_slice(preg_split('/\r?\n/', rtrim($data)) ?: [], -$lines));
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024, 0) . ' KB';
    }
}
