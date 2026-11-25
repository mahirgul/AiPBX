<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../secret_box.php';
require_once __DIR__ . '/ApiTokenService.php';
require_once __DIR__ . '/WebhookService.php';

/**
 * Integration -> Connections: one outside system (Odoo, a CRM, n8n, a script) gets its own
 * API token and its own signed webhook for the call events, handed over once as a
 * connection pack (JSON) the other side pastes. The connection owns both, so rotating or
 * removing it touches nothing else. Kinds: "odoo" (the AiPBX Odoo app: the webhook goes to
 * <Odoo URL>/aipbx/webhook/<connection id>) and "generic" (the URL entered is the webhook).
 */
final class ConnectionService
{
    public const KINDS = ['odoo', 'erpnext', 'generic'];
    public const PACK_VERSION = 1;
    /** What a CRM needs: call history and recordings, click-to-call, the address book, the extensions. */
    public const SCOPES = ['calls:read', 'calls:write', 'contacts:read', 'contacts:write', 'pbx:read'];
    public const EVENTS = ['call.ringing', 'call.offered', 'call.answered', 'call.completed', 'recording.ready', 'call.ai_completed'];
    public const GENERIC_EVENTS = ['call.ringing', 'call.offered', 'call.answered', 'call.completed', 'recording.ready', 'call.ai_completed', 'autodial.result'];

    /** @return list<string> */
    public static function eventsFor(string $kind): array
    {
        return $kind === 'generic' ? self::GENERIC_EVENTS : self::EVENTS;
    }

    /** @return list<array<string, mixed>> connections with their token and webhook state */
    public static function all(): array
    {
        return getDB()->query(
            'SELECT c.*, t.token_prefix, t.last_used_at, t.last_used_ip, t.allowed_ips,
                    COALESCE(w.is_active, 0) AS is_active, w.target_url, w.last_status_code, w.last_triggered_at, w.last_error, w.failure_streak
             FROM sys_connections c
             LEFT JOIN sys_api_tokens t ON t.id = c.api_token_id
             LEFT JOIN sys_webhooks w ON w.id = c.webhook_id
             ORDER BY c.name, c.id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function find(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM sys_connections WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The webhook URL of a connection: the Odoo app's fixed route, or the URL as entered.
     */
    public static function webhookUrl(string $kind, string $baseUrl, string $uuid): string
    {
        return match ($kind) {
            'odoo' => rtrim($baseUrl, '/') . '/aipbx/webhook/' . $uuid,
            // ERPNext / Frappe: the app's whitelisted method (aipbx_connector, AiPBX-Frappe).
            'erpnext' => rtrim($baseUrl, '/') . '/api/method/aipbx_connector.api.webhook?connection=' . $uuid,
            default => $baseUrl,
        };
    }

    /**
     * Create a connection. @return array{success: bool, message: string, pack?: string, id?: int}
     */
    public static function create(array $in, string $csrf): array
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        $kind = in_array($in['kind'] ?? '', self::KINDS, true) ? (string) $in['kind'] : 'generic';
        $url = trim((string) ($in['base_url'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => t('connections.err_name')];
        }
        $uuid = self::uuid();
        try {
            // The same SSRF rules as every webhook: https or a private address only when allowed.
            WebhookService::validateTargetUrl(self::webhookUrl($kind, $url, $uuid));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            [$tokenId, $raw] = self::newToken($name, (string) ($in['allowed_ips'] ?? ''), $csrf);
            $secret = 'whsec_' . bin2hex(random_bytes(24));
            $wh = WebhookService::save(['csrf_token' => $csrf, 'name' => 'Connection: ' . $name,
                'target_url' => self::webhookUrl($kind, $url, $uuid), 'events' => self::eventsFor($kind),
                'secret' => $secret, 'is_active' => 1, 'max_retries' => 8, 'timeout_sec' => 8]);
            if (empty($wh['success'])) {
                throw new \RuntimeException((string) ($wh['message'] ?? $wh['error'] ?? 'webhook'));
            }
            $db->prepare('INSERT INTO sys_connections (uuid, name, kind, base_url, api_token_id, webhook_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uuid, $name, $kind, $url, $tokenId, (int) $wh['id'], $_SESSION['user_id'] ?? null]);
            $id = (int) $db->lastInsertId();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack(); // nothing half made stays behind
            return ['success' => false, 'message' => $e->getMessage()];
        }
        writeAuditLog('integrations', 'connection', $id, "Connection {$name} ({$kind}) created", 'create', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'id' => $id, 'message' => t('connections.msg_created'),
            'pack' => self::pack(self::find($id) ?? [], $raw, $secret)];
    }

    /**
     * New token and webhook secret; the old ones stop at once, so the other side needs the
     * new pack. @return array{success: bool, message: string, pack?: string}
     */
    /**
     * On or off: off, the connection's webhook sends nothing and its API key is refused, so the
     * CRM neither hears about calls nor places them. Nothing is deleted; on brings it back as it was.
     */
    public static function setActive(int $id, bool $on, string $csrf): array
    {
        if (!verifyCSRFToken($csrf)) {
            return ['success' => false, 'message' => t('common.invalid_csrf')];
        }
        $c = self::find($id);
        if (!$c) {
            return ['success' => false, 'message' => t('common.not_found')];
        }
        $db = getDB();
        $db->prepare(
            'UPDATE sys_connections c
             INNER JOIN sys_api_tokens t ON t.id = c.api_token_id
             INNER JOIN sys_webhooks w ON w.id = c.webhook_id
             SET t.is_active = ?, w.is_active = ?
             WHERE c.id = ?'
        )->execute([(int) $on, (int) $on, $id]);
        writeAuditLog('integrations', 'connection', $id, "Connection {$c['name']} " . ($on ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t($on ? 'connections.msg_enabled' : 'connections.msg_disabled')];
    }

    public static function rotate(int $id, string $csrf): array
    {
        if (!verifyCSRFToken($csrf)) {
            return ['success' => false, 'message' => t('common.invalid_csrf')];
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM sys_connections WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) {
                $db->rollBack();
                return ['success' => false, 'message' => t('common.not_found')];
            }
            $tokSt = $db->prepare('SELECT id, allowed_ips FROM sys_api_tokens WHERE id = ? FOR UPDATE');
            $tokSt->execute([(int) $c['api_token_id']]);
            $old = $tokSt->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new \RuntimeException(t('common.not_found', 'Owned API token not found.'));
            }
            $whSt = $db->prepare('SELECT id, is_active FROM sys_webhooks WHERE id = ? FOR UPDATE');
            $whSt->execute([(int) $c['webhook_id']]);
            $wh = $whSt->fetch(PDO::FETCH_ASSOC);
            if (!$wh || empty($wh['is_active'])) {
                throw new \RuntimeException(t('common.not_found', 'Owned webhook not found or disabled.'));
            }
            $oldIps = (($old['allowed_ips'] ?? '') === '*') ? '' : (string) ($old['allowed_ips'] ?? '');
            [$tokenId, $raw] = self::newToken((string) $c['name'], $oldIps, $csrf);
            $delOld = $db->prepare('DELETE FROM sys_api_tokens WHERE id = ?');
            $delOld->execute([(int) $c['api_token_id']]);
            if ($delOld->rowCount() !== 1) {
                throw new \RuntimeException(t('common.not_found', 'Owned API token not found.'));
            }
            $secret = 'whsec_' . bin2hex(random_bytes(24));
            // The events of today's version too: a connection made before a new event gets it.
            $updWh = $db->prepare('UPDATE sys_webhooks SET secret_encrypted = ?, events_json = ? WHERE id = ?');
            $updWh->execute([SecretBox::encrypt($secret), json_encode(self::eventsFor((string) ($c['kind'] ?? 'generic'))), (int) $c['webhook_id']]);
            if ($updWh->rowCount() !== 1) {
                throw new \RuntimeException(t('common.not_found', 'Owned webhook not found.'));
            }
            $db->prepare('UPDATE sys_connections SET api_token_id = ?, rotated_at = NOW() WHERE id = ?')->execute([$tokenId, $id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
        writeAuditLog('integrations', 'connection', $id, "Connection {$c['name']}: new key and secret", 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('connections.msg_rotated'), 'pack' => self::pack(self::find($id) ?? [], $raw, $secret)];
    }

    /** Remove the connection with its token, webhook and undelivered events; nothing else. */
    public static function delete(int $id): bool
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM sys_connections WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) {
                $db->rollBack();
                return false;
            }
            $db->prepare('DELETE FROM sys_api_tokens WHERE id = ?')->execute([(int) $c['api_token_id']]);
            $db->prepare('DELETE FROM sys_webhook_queue WHERE webhook_id = ?')->execute([(int) $c['webhook_id']]);
            $db->prepare('DELETE FROM sys_webhooks WHERE id = ?')->execute([(int) $c['webhook_id']]);
            $db->prepare('DELETE FROM sys_connections WHERE id = ?')->execute([$id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        writeAuditLog('integrations', 'connection', $id, "Connection {$c['name']} removed", 'delete', $_SESSION['user_id'] ?? null);
        return true;
    }

    /** A signed webhook.test to the other side now. */
    public static function test(int $id, string $csrf): array
    {
        $c = self::find($id);
        return $c ? WebhookService::sendTestPing((int) $c['webhook_id'], $csrf) : ['success' => false, 'message' => t('common.not_found')];
    }

    /** The one-time JSON the other side pastes; the token and the secret are not stored readable. */
    public static function pack(array $c, string $token, string $secret): string
    {
        $domain = trim((string) portalEnv('PORTAL_DOMAIN', ''));
        $host = $domain !== ''
            ? (self::sanitizePackHost($domain) ?? 'localhost')
            : (self::sanitizePackHost((string) ($_SERVER['HTTP_HOST'] ?? '')) ?? 'localhost');
        return (string) json_encode([
            'aipbx_connection' => self::PACK_VERSION,
            'pbx_url' => 'https://' . $host,
            'instance_id' => WebhookService::instanceId(),
            'connection_id' => (string) ($c['uuid'] ?? ''),
            'name' => (string) ($c['name'] ?? ''),
            'api_token' => $token,
            'webhook_secret' => $secret,
            'events' => self::EVENTS,
            'scopes' => self::SCOPES,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Validate a host[:port] value (preserving bracketed IPv6 and custom non-443 ports). */
    private static function sanitizePackHost(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\[([0-9a-fA-F:]+)\](?::(\d{1,5}))?$/', $raw, $m)) {
            if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $port = isset($m[2]) ? (int) $m[2] : null;
            if ($port !== null && ($port < 1 || $port > 65535)) {
                return null;
            }
            return ($port === null || $port === 443) ? "[{$m[1]}]" : "[{$m[1]}]:{$port}";
        }
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return "[{$raw}]";
        }
        if (preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)(?::(\d{1,5}))?$/', $raw, $m)) {
            $port = isset($m[2]) ? (int) $m[2] : null;
            if ($port !== null && ($port < 1 || $port > 65535)) {
                return null;
            }
            return ($port === null || $port === 443) ? $m[1] : "{$m[1]}:{$port}";
        }
        return null;
    }

    /** @return array{0: int, 1: string} token id and the raw token */
    private static function newToken(string $name, string $allowedIps, string $csrf): array
    {
        $ips = ApiTokenService::validateAllowedIps($allowedIps) ?? '';
        $res = ApiTokenService::create(['csrf_token' => $csrf, 'name' => 'Connection: ' . mb_substr($name, 0, 100),
            'scopes' => self::SCOPES, 'allowed_ips' => $ips, 'rate_limit_per_min' => 600]);
        if (empty($res['success'])) {
            throw new \RuntimeException((string) ($res['message'] ?? $res['error'] ?? 'token'));
        }
        return [(int) $res['id'], (string) $res['raw_token']];
    }

    /** @var callable|null Optional HTTP transport hook for tests: fn(string $url, array $headers, string $body): ?string */
    public static $lookupTransport = null;

    /**
     * On-demand caller lookup against the active CRM/ERP connection (1 s timeout, fail-open, 60 s cache).
     *
     * @return array{display_name: string, company: string, email: string, external_url: string, source: string, customer_id: string, external_id: string}|null
     */
    public static function lookupCaller(string $number, bool $useCache = true): ?array
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if (strlen($digits) < 5) {
            return null;
        }
        try {
            $conn = getDB()->query(
                "SELECT c.uuid, c.kind, c.base_url, w.secret_encrypted
                 FROM sys_connections c
                 INNER JOIN sys_webhooks w ON w.id = c.webhook_id
                 WHERE w.is_active = 1 AND c.kind = 'odoo'
                 ORDER BY c.id ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$conn) {
            return null;
        }

        $key = substr($digits, -10);
        $cacheFile = sys_get_temp_dir() . '/aipbx-conn-lookup-' . hash('sha256', implode('|', [$conn['uuid'], $conn['base_url'], $conn['secret_encrypted'], $key])) . '.json';
        if ($useCache && is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 60) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                if (empty($cached['found']) || !is_array($cached['data'] ?? null)) {
                    return null;
                }
                return $cached['data'] + [
                    'display_name' => '',
                    'company' => '',
                    'email' => '',
                    'external_url' => '',
                    'source' => 'odoo',
                    'customer_id' => '',
                    'external_id' => '',
                ];
            }
        }

        $baseUrl = rtrim((string) $conn['base_url'], '/');
        $url = $baseUrl . '/aipbx/lookup/' . $conn['uuid'];
        try {
            $pinnedResolve = WebhookService::validatedResolve($url);
            $secret = SecretBox::decrypt((string) $conn['secret_encrypted']);
        } catch (\Throwable $e) {
            return null;
        }
        if ($secret === '') {
            return null;
        }

        $body = json_encode(['number' => $number], JSON_UNESCAPED_UNICODE) ?: '{}';
        $ts = (string) time();
        $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
        $headers = [
            'Content-Type: application/json',
            'X-AiPBX-Timestamp: ' . $ts,
            'X-AiPBX-Signature-256: ' . $sig,
        ];

        $raw = null;
        if (self::$lookupTransport !== null) {
            $raw = (self::$lookupTransport)($url, $headers, $body);
        } else {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 1000,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($pinnedResolve !== []) {
                curl_setopt($ch, CURLOPT_RESOLVE, $pinnedResolve);
            }
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (is_string($res) && $code >= 200 && $code < 300) {
                $raw = $res;
            }
        }

        $parsed = is_string($raw) ? json_decode($raw, true) : null;
        $result = null;
        if (is_array($parsed) && !empty($parsed['found'])) {
            $relUrl = (string) ($parsed['url'] ?? '');
            $extUrl = (str_starts_with($relUrl, '/') && preg_match('#^https?://#i', $baseUrl)) ? $baseUrl . $relUrl : '';
            $custId = mb_substr(trim((string) ($parsed['customer_id'] ?? $parsed['external_id'] ?? $parsed['partner_id'] ?? $parsed['id'] ?? '')), 0, 191);
            $result = [
                'display_name' => mb_substr(trim((string) ($parsed['name'] ?? '')), 0, 80),
                'company' => mb_substr(trim((string) ($parsed['company'] ?? '')), 0, 80),
                'email' => mb_substr(trim((string) ($parsed['email'] ?? '')), 0, 120),
                'external_url' => $extUrl,
                'source' => (string) ($conn['kind'] ?? 'odoo'),
                'customer_id' => $custId,
                'external_id' => $custId,
            ];
        }

        if ($useCache && ($result !== null || ($parsed['found'] ?? null) === false)) {
            @file_put_contents($cacheFile, json_encode(['found' => $result !== null, 'data' => $result], JSON_UNESCAPED_UNICODE));
        }
        return $result;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
