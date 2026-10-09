<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../secret_box.php';
require_once __DIR__ . '/../../api/mobile/auth_helper.php';

/**
 * Admin → File storage: where the chat service keeps chat attachments,
 * thumbnails and group pictures (roadmap item 3).
 *
 * "local" (the default) is the server's disk, /var/lib/aipbx/chat_files.
 * "s3" is an S3-compatible bucket (AWS S3, MinIO, Wasabi, Backblaze B2 …).
 * The settings live in sys_settings (storage_*), the secret key encrypted
 * with SecretBox; the chat service reads them every few seconds
 * (chat/storage.go), so no restart is needed. Downloads keep going through
 * /chat/media with the same access check.
 *
 * The connection test and the one-time move of the existing files run in the
 * chat service, through its internal endpoints (chat/storage_admin.go).
 */
class FileStorageService
{
    public const BACKENDS = ['local', 's3'];

    /** sys_settings keys and their defaults. */
    public const DEFAULTS = [
        'storage_backend' => 'local',
        'storage_s3_endpoint' => '',
        'storage_s3_region' => 'us-east-1',
        'storage_s3_bucket' => '',
        'storage_s3_prefix' => '',
        'storage_s3_access_key' => '',
        'storage_s3_secret_key' => '',
        'storage_s3_path_style' => '1',
    ];

    /** @return array<string, string> stored values (the secret still encrypted) */
    public static function settings(): array
    {
        $out = self::DEFAULTS;
        $keys = array_keys(self::DEFAULTS);
        $stmt = getDB()->prepare('SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $stmt->execute($keys);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $out[$k] = (string) $v;
        }
        return $out;
    }

    /** What the page shows for the stored secret ("••••1234", or ''). */
    public static function maskedSecret(array $settings): string
    {
        return SecretBox::mask(SecretBox::decrypt($settings['storage_s3_secret_key'] ?? ''));
    }

    /**
     * Checks the form. An empty secret keeps the stored one.
     *
     * @return array<string, string> the values to store, the secret in plain text
     */
    public static function validate(array $data, string $storedSecretPlain): array
    {
        $backend = (string) ($data['storage_backend'] ?? 'local');
        if (!in_array($backend, self::BACKENDS, true)) {
            throw new \Exception(t('storage.err_backend'));
        }
        $v = ['storage_backend' => $backend];
        if ($backend === 'local') {
            return $v;
        }

        $endpoint = rtrim(trim((string) ($data['storage_s3_endpoint'] ?? '')), '/');
        if ($endpoint !== '' && !self::validEndpoint($endpoint)) {
            throw new \Exception(t('storage.err_endpoint'));
        }
        $region = strtolower(trim((string) ($data['storage_s3_region'] ?? '')));
        if ($region === '') {
            $region = 'us-east-1';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $region)) {
            throw new \Exception(t('storage.err_region'));
        }
        $bucket = trim((string) ($data['storage_s3_bucket'] ?? ''));
        if (!self::validBucket($bucket)) {
            throw new \Exception(t('storage.err_bucket'));
        }
        $prefix = trim(trim((string) ($data['storage_s3_prefix'] ?? '')), '/');
        if ($prefix !== '' && (!preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $prefix) || str_contains($prefix, '..') || str_contains($prefix, '//'))) {
            throw new \Exception(t('storage.err_prefix'));
        }
        $access = trim((string) ($data['storage_s3_access_key'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9/+=._-]{3,128}$#', $access)) {
            throw new \Exception(t('storage.err_access_key'));
        }
        $secret = (string) ($data['storage_s3_secret_key'] ?? '');
        if ($secret === '') {
            $secret = $storedSecretPlain;
        }
        if ($secret === '' || strlen($secret) > 256 || preg_match('/[\x00-\x1f\x7f]/', $secret)) {
            throw new \Exception(t('storage.err_secret_key'));
        }

        return $v + [
            'storage_s3_endpoint' => $endpoint,
            'storage_s3_region' => $region,
            'storage_s3_bucket' => $bucket,
            'storage_s3_prefix' => $prefix,
            'storage_s3_access_key' => $access,
            'storage_s3_secret_key' => $secret,
            'storage_s3_path_style' => !empty($data['storage_s3_path_style']) ? '1' : '0',
        ];
    }

    /** http(s)://host[:port] with no path, query or credentials. */
    public static function validEndpoint(string $url): bool
    {
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            return false;
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (($p['path'] ?? '') !== '' && $p['path'] !== '/')) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9.-]+$|^\[[0-9A-Fa-f:.]+\]$/', $p['host']);
    }

    /** S3 bucket naming rules: 3–63 of a-z 0-9 . -, starting and ending with a letter or digit. */
    public static function validBucket(string $bucket): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)
            && !str_contains($bucket, '..')
            && !filter_var($bucket, FILTER_VALIDATE_IP);
    }

    /** The JSON the chat service's connection test takes. */
    public static function testPayload(array $v): array
    {
        return [
            'endpoint' => $v['storage_s3_endpoint'],
            'region' => $v['storage_s3_region'],
            'bucket' => $v['storage_s3_bucket'],
            'prefix' => $v['storage_s3_prefix'],
            'access_key' => $v['storage_s3_access_key'],
            'secret_key' => $v['storage_s3_secret_key'],
            'path_style' => $v['storage_s3_path_style'] === '1',
        ];
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $stored = self::settings();
            $v = self::validate($data, SecretBox::decrypt($stored['storage_s3_secret_key']));
            if ($v['storage_backend'] === 's3') {
                // Never switch to a bucket the PBX cannot write to: chat uploads would fail.
                $res = self::callChat('POST', 'test', self::testPayload($v));
                if (empty($res['success'])) {
                    throw new \Exception(sprintf(t('storage.err_test'), $res['error'] ?? t('storage.err_chat_down')));
                }
                $v['storage_s3_secret_key'] = SecretBox::encrypt($v['storage_s3_secret_key']);
            }
            $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($v as $k => $val) {
                $stmt->execute([$k, $val]);
            }
            $label = $v['storage_backend'] === 's3' ? 'S3 bucket ' . $v['storage_s3_bucket'] : 'local disk';
            writeAuditLog('file_storage', 'settings', 0, 'File storage: ' . $label, 'update', $_SESSION['user_id'] ?? null);
            return t('storage.saved_' . $v['storage_backend']);
        });
    }

    /** Connection test with the values in the form (nothing is saved). */
    public static function test(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $stored = self::settings();
            $v = self::validate(['storage_backend' => 's3'] + $data, SecretBox::decrypt($stored['storage_s3_secret_key']));
            $res = self::callChat('POST', 'test', self::testPayload($v));
            if (empty($res['success'])) {
                throw new \Exception(sprintf(t('storage.err_test'), $res['error'] ?? t('storage.err_chat_down')));
            }
            return t('storage.test_ok');
        });
    }

    /** Starts moving the files on the disk into the bucket. */
    public static function migrate(string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () {
            $res = self::callChat('POST', 'migrate');
            if (empty($res['success'])) {
                throw new \Exception($res['error'] ?? t('storage.err_chat_down'));
            }
            writeAuditLog('file_storage', 'settings', 0, 'File storage: moving local chat files to S3', 'update', $_SESSION['user_id'] ?? null);
            return t('storage.migrate_started');
        });
    }

    /**
     * The chat service's view: backend in use, files left on the disk, the move.
     *
     * @return array{reachable: bool, backend?: string, local_files?: int, migration?: array}
     */
    public static function status(): array
    {
        $res = self::callChat('GET', 'status');
        return empty($res['success']) ? ['reachable' => false] : ['reachable' => true] + $res;
    }

    /** Calls /api/internal/storage/<action> on the chat service (direct, local). */
    private static function callChat(string $method, string $action, ?array $body = null): array
    {
        $port = (string) portalEnv('CHAT_PORT', '8086');
        if (!ctype_digit($port)) {
            $port = '8086';
        }
        // curl, not file_get_contents: a stopped chat service must not raise a PHP warning.
        $ch = curl_init('http://127.0.0.1:' . $port . '/api/internal/storage/' . $action);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['X-AiPBX-Internal: ' . self::internalToken(), 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        $resp = curl_exec($ch);
        $json = is_string($resp) ? json_decode($resp, true) : null;
        if (!is_array($json)) {
            return ['success' => false, 'error' => t('storage.err_chat_down')];
        }
        return $json;
    }

    /** Same as chat/storage_admin.go internalToken(): HMAC of the chat secret. */
    public static function internalToken(): string
    {
        return hash_hmac('sha256', 'chat-internal:storage', getMobileTokenSecret());
    }
}
