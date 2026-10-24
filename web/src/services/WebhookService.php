<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/secret_box.php';

/**
 * Outbound Webhook & Asynchronous Event Bus Service (`sys_webhooks`, `sys_webhook_queue`).
 */
class WebhookService
{
    public const SUPPORTED_EVENTS = [
        '*'                        => 'All Events (Wildcard)',
        'call.started'             => 'Call Started (Ringing)',
        'call.ended'               => 'Call Ended (CDR Ready)',
        'call.missed'              => 'Missed Inbound/Extension Call',
        'voicemail.created'        => 'New Voicemail Left',
        'voicemail.received'       => 'Voicemail Received & Processed',
        'voicemail.transcribed'    => 'Voicemail AI Transcription Ready',
        'fax.received'             => 'Incoming Fax Received',
        'fax.sent'                 => 'Outgoing Fax Delivered',
        'fax.failed'               => 'Outgoing Fax Failed',
        'queue.member_logged_in'   => 'Queue Agent Logged In',
        'queue.member_logged_out'  => 'Queue Agent Logged Out',
        'queue.member_paused'      => 'Queue Agent Paused / Unpaused',
        'queue.caller_abandoned'   => 'Queue Caller Abandoned',
        'config.pending_created'   => 'PBX Configuration Change Queued',
        'config.sync_applied'      => 'PBX Configuration Applied to Asterisk',
        'config.sync_failed'       => 'PBX Configuration Apply Failed (Rolled Back)',
        'security.login_failed'    => 'Failed Portal Login Attempt',
    ];

    /**
     * Validate a target webhook URL against SSRF (blocks loopback, link-local,
     * metadata IPs, and RFC1918 unless ALLOW_PRIVATE_WEBHOOKS=1 in /etc/ai-pbx.env).
     */
    public static function validateTargetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(t('webhooks.err_invalid_url', 'Enter a valid HTTP/HTTPS target URL.'));
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException(t('webhooks.err_invalid_url', 'Webhook URL must use http:// or https://.'));
        }

        $allowPrivate = portalEnv('ALLOW_PRIVATE_WEBHOOKS', '0') === '1';
        if (!$allowPrivate) {
            self::resolveAndValidatePublicIp($host);
        }
        return $url;
    }

    /**
     * Validates the URL and returns the CURLOPT_RESOLVE entry that pins the
     * checked address, so a DNS answer that changes between the check and the
     * request (rebinding) cannot reach an internal address. Throws like
     * validateTargetUrl(); empty when private targets are allowed or the host
     * is an IP literal.
     *
     * @return list<string>
     */
    public static function validatedResolve(string $url): array
    {
        self::validateTargetUrl($url);
        if (portalEnv('ALLOW_PRIVATE_WEBHOOKS', '0') === '1') {
            return [];
        }
        $parts = parse_url($url);
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = self::resolveAndValidatePublicIp($host);
        if ($ip === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return [];
        }
        $fmtIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        return ["{$host}:{$port}:{$fmtIp}"];
    }

    /**
     * Resolve a hostname or IP literal and verify that every resolved address is a
     * public, non-reserved IPv4/IPv6 address. Returns the first validated IP so
     * `deliverHttp()` can pin DNS resolution via `CURLOPT_RESOLVE`.
     */
    private static function resolveAndValidatePublicIp(string $host): string
    {
        $cleanHost = trim($host, '[]');
        if (in_array(strtolower($cleanHost), ['localhost', '127.0.0.1', '::1', '0.0.0.0', '169.254.169.254'], true)) {
            throw new \InvalidArgumentException(t('webhooks.err_private_ip', 'Loopback and cloud metadata addresses are blocked for security (set ALLOW_PRIVATE_WEBHOOKS=1 in /etc/ai-pbx.env to allow internal LAN webhooks).'));
        }
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            if (filter_var($cleanHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException(t('webhooks.err_private_ip', 'Private/reserved IP ranges are blocked by default (set ALLOW_PRIVATE_WEBHOOKS=1 in /etc/ai-pbx.env to allow internal LAN webhooks).'));
            }
            return $cleanHost;
        }

        $ips = [];
        $v4 = @gethostbynamel($cleanHost);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $v6 = @dns_get_record($cleanHost, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string)$rec['ipv6'];
                }
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException(t('webhooks.err_private_ip', 'Target hostname resolves to a private or reserved IP address.'));
            }
        }
        return $ips[0] ?? '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = DBHelper::fetchAll('SELECT * FROM sys_webhooks ORDER BY id DESC');
        foreach ($rows as &$r) {
            $rawSecret = !empty($r['secret_encrypted']) ? SecretBox::decrypt((string)$r['secret_encrypted']) : '';
            $r['has_secret'] = $rawSecret !== '';
            $r['secret_masked'] = $rawSecret !== '' ? SecretBox::mask($rawSecret) : '';
            unset($r['secret_encrypted']);
            $r['events'] = json_decode((string)($r['events_json'] ?? '[]'), true) ?: [];
            $r['headers'] = json_decode((string)($r['headers_json'] ?? '{}'), true) ?: [];
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentDeliveries(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return DBHelper::fetchAll(
            "SELECT q.*, w.name AS webhook_name, w.target_url
             FROM sys_webhook_queue q
             LEFT JOIN sys_webhooks w ON w.id = q.webhook_id
             ORDER BY q.id DESC
             LIMIT {$limit}"
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int)($data['webhook_id'] ?? $data['id'] ?? 0);
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new \Exception(t('webhooks.err_name_required', 'Webhook name is required.'));
            }
            $targetUrl = self::validateTargetUrl((string)($data['target_url'] ?? ''));

            $eventsIn = $data['events'] ?? [];
            if (is_string($eventsIn)) {
                $eventsIn = array_filter(array_map('trim', explode(',', $eventsIn)));
            }
            $validEventKeys = array_keys(self::SUPPORTED_EVENTS);
            $events = [];
            if (is_array($eventsIn)) {
                foreach ($eventsIn as $ev) {
                    $ev = trim((string)$ev);
                    if (in_array($ev, $validEventKeys, true)) {
                        $events[] = $ev;
                    }
                }
            }
            if (in_array('*', $events, true)) {
                $events = ['*'];
            }
            if (empty($events)) {
                throw new \Exception(t('webhooks.err_events_required', 'Select at least one event trigger.'));
            }

            $timeoutSec = max(2, min(15, (int)($data['timeout_sec'] ?? 5)));
            $maxRetries = max(1, min(10, (int)($data['max_retries'] ?? 5)));
            $isActive = PBXHelper::boolFlag($data, 'is_active', 1);

            $headers = [];
            if (!empty($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $hk => $hv) {
                    $hk = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$hk);
                    if ($hk !== '') {
                        $headers[$hk] = trim((string)$hv);
                    }
                }
            }

            $saveFields = [
                'id'          => $id,
                'name'        => mb_substr($name, 0, 120),
                'target_url'  => $targetUrl,
                'events_json' => json_encode(array_values(array_unique($events))),
                'headers_json'=> !empty($headers) ? json_encode($headers) : null,
                'timeout_sec' => $timeoutSec,
                'max_retries' => $maxRetries,
                'is_active'   => $isActive,
            ];

            $secretRaw = trim((string)($data['secret'] ?? ''));
            if ($secretRaw !== '') {
                $saveFields['secret_encrypted'] = SecretBox::encrypt($secretRaw);
            } elseif ($id <= 0) {
                $saveFields['secret_encrypted'] = SecretBox::encrypt('whsec_' . bin2hex(random_bytes(16)));
            }

            $savedId = DBHelper::save('sys_webhooks', $saveFields);
            writeAuditLog('webhooks', 'webhook', $savedId, "Webhook: {$name} ({$targetUrl})", $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            return sprintf(t('webhooks.msg_saved', 'Webhook "%s" saved.'), $name);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($id) {
            $row = DBHelper::fetchOne('SELECT id, name, target_url FROM sys_webhooks WHERE id = ?', [$id]);
            if (!$row) {
                throw new \Exception(t('common.not_found', 'Webhook not found.'));
            }
            getDB()->prepare('DELETE FROM sys_webhook_queue WHERE webhook_id = ?')->execute([$id]);
            DBHelper::delete('sys_webhooks', 'id', $id);
            writeAuditLog('webhooks', 'webhook', $id, "Webhook: {$row['name']} deleted", 'delete', $_SESSION['user_id'] ?? null);
            return t('webhooks.msg_deleted', 'Webhook deleted.');
        });
    }

    /**
     * Enqueue an event asynchronously (<2ms DB insert) for all matching active
     * webhooks and enabled event-subscribed plugins.
     *
     * @param array<string, mixed> $data
     */
    public static function emit(string $eventName, array $data): int
    {
        $queued = 0;
        try {
            $db = getDB();
            $eventId = self::uuidV4();
            $envelope = [
                'event_id'    => $eventId,
                'event'       => $eventName,
                'occurred_at' => date(DATE_ATOM),
                'pbx_version' => defined('AIPBX_VERSION') ? AIPBX_VERSION : 'dev',
                'data'        => $data,
            ];
            $payloadJson = (string)json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $webhooks = $db->query('SELECT id, events_json, max_retries FROM sys_webhooks WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare(
                "INSERT INTO sys_webhook_queue (webhook_id, plugin_slug, event_id, event_name, payload_json, attempts, max_attempts, next_attempt_at, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, NOW(), 'pending', NOW())"
            );

            foreach ($webhooks as $wh) {
                $evs = json_decode((string)($wh['events_json'] ?? '[]'), true) ?: [];
                if (in_array('*', $evs, true) || in_array($eventName, $evs, true)) {
                    $ins->execute([(int)$wh['id'], null, $eventId, $eventName, $payloadJson, max(1, (int)$wh['max_retries'])]);
                    $queued++;
                }
            }

            // Also enqueue for any enabled plugins subscribing to this event via hooks.events
            if (class_exists('PluginManager')) {
                foreach (PluginManager::pluginsSubscribedToEvent($eventName) as $pluginSlug) {
                    $ins->execute([null, $pluginSlug, $eventId, $eventName, $payloadJson, 3]);
                    $queued++;
                }
            }
        } catch (\Throwable $e) {
            // Event emission must never break a live call or portal request
        }
        return $queued;
    }

    /**
     * Send an immediate test ping to a configured webhook.
     *
     * @return array<string, mixed>
     */
    public static function sendTestPing(int $webhookId, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($webhookId) {
            $wh = DBHelper::fetchOne('SELECT * FROM sys_webhooks WHERE id = ?', [$webhookId]);
            if (!$wh) {
                throw new \Exception(t('common.not_found', 'Webhook not found.'));
            }
            $eventId = self::uuidV4();
            $payloadJson = (string)json_encode([
                'event_id'    => $eventId,
                'event'       => 'webhook.test',
                'occurred_at' => date(DATE_ATOM),
                'pbx_version' => defined('AIPBX_VERSION') ? AIPBX_VERSION : 'dev',
                'data'        => [
                    'webhook_id'   => (int)$wh['id'],
                    'webhook_name' => $wh['name'],
                    'message'      => 'Test webhook delivery from AiPBX.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $res = self::deliverHttp($wh, 'webhook.test', $eventId, $payloadJson);
            if (!$res['ok']) {
                throw new \Exception(sprintf('Delivery failed (HTTP %d): %s', $res['http_code'], $res['error']));
            }
            return sprintf(t('webhooks.msg_test_ok', 'Test webhook delivered successfully (HTTP %d).'), $res['http_code']);
        });
    }

    /**
     * Process a batch of pending webhook/plugin event jobs from `sys_webhook_queue`.
     *
     * @return array{processed: int, delivered: int, failed: int}
     */
    public static function processPendingBatch(int $limit = 25): array
    {
        $db = getDB();
        $limit = max(1, min(100, $limit));
        $jobs = $db->query(
            "SELECT * FROM sys_webhook_queue
             WHERE status = 'pending' AND next_attempt_at <= NOW()
             ORDER BY id ASC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['processed' => 0, 'delivered' => 0, 'failed' => 0];
        $backoffSchedule = [1 => 10, 2 => 30, 3 => 120, 4 => 600, 5 => 3600];

        foreach ($jobs as $job) {
            $stats['processed']++;
            $jobId = (int)$job['id'];
            $attempts = ((int)$job['attempts']) + 1;
            $maxAttempts = max(1, (int)$job['max_attempts']);

            if (!empty($job['plugin_slug'])) {
                $ok = false;
                $err = '';
                try {
                    if (!class_exists('PluginManager')) {
                        require_once dirname(__DIR__) . '/core/PluginManager.php';
                    }
                    $decoded = json_decode((string)$job['payload_json'], true) ?: [];
                    PluginManager::deliverEventToPlugin((string)$job['plugin_slug'], (string)$job['event_name'], $decoded);
                    $ok = true;
                } catch (\Throwable $e) {
                    $err = mb_substr($e->getMessage(), 0, 480);
                }

                if ($ok) {
                    $db->prepare("UPDATE sys_webhook_queue SET status = 'delivered', attempts = ?, last_http_code = 200, delivered_at = NOW() WHERE id = ?")
                        ->execute([$attempts, $jobId]);
                    $stats['delivered']++;
                } else {
                    $nextStatus = $attempts >= $maxAttempts ? 'failed' : 'pending';
                    $delay = $backoffSchedule[$attempts] ?? 3600;
                    $db->prepare("UPDATE sys_webhook_queue SET status = ?, attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                        ->execute([$nextStatus, $attempts, $err, $delay, $jobId]);
                    $stats['failed']++;
                }
                continue;
            }

            $wh = DBHelper::fetchOne('SELECT * FROM sys_webhooks WHERE id = ?', [(int)$job['webhook_id']]);
            if (!$wh || empty($wh['is_active'])) {
                $db->prepare("UPDATE sys_webhook_queue SET status = 'failed', last_error = 'Webhook disabled or deleted' WHERE id = ?")
                    ->execute([$jobId]);
                $stats['failed']++;
                continue;
            }

            $res = self::deliverHttp($wh, (string)$job['event_name'], (string)$job['event_id'], (string)$job['payload_json']);
            if ($res['ok']) {
                $db->prepare("UPDATE sys_webhook_queue SET status = 'delivered', attempts = ?, last_http_code = ?, last_error = NULL, delivered_at = NOW() WHERE id = ?")
                    ->execute([$attempts, $res['http_code'], $jobId]);
                $stats['delivered']++;
            } else {
                $nextStatus = $attempts >= $maxAttempts ? 'failed' : 'pending';
                $delay = $backoffSchedule[$attempts] ?? 3600;
                $db->prepare("UPDATE sys_webhook_queue SET status = ?, attempts = ?, last_http_code = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                    ->execute([$nextStatus, $attempts, $res['http_code'], mb_substr($res['error'], 0, 480), $delay, $jobId]);
                $stats['failed']++;
            }
        }

        // Prune delivered/failed queue history older than 14 days
        if ($stats['processed'] > 0) {
            try {
                $db->exec("DELETE FROM sys_webhook_queue WHERE status IN ('delivered', 'failed') AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
            } catch (\Throwable $e) {
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $wh
     * @return array{ok: bool, http_code: int, error: string}
     */
    private static function deliverHttp(array $wh, string $eventName, string $eventId, string $payloadJson): array
    {
        $url = (string)$wh['target_url'];
        $pinnedResolve = [];
        try {
            $pinnedResolve = self::validatedResolve($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'http_code' => 0, 'error' => $e->getMessage()];
        }

        $secret = !empty($wh['secret_encrypted']) ? SecretBox::decrypt((string)$wh['secret_encrypted']) : '';
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payloadJson, $secret);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: AiPBX-Webhook/' . (defined('AIPBX_VERSION') ? AIPBX_VERSION : '1.0'),
            'X-AiPBX-Event: ' . $eventName,
            'X-AiPBX-Delivery: ' . $eventId,
            'X-AiPBX-Timestamp: ' . $timestamp,
            'X-AiPBX-Signature: sha256=' . $signature,
            'X-AiPBX-Signature-256: sha256=' . $signature,
        ];

        $customHeaders = json_decode((string)($wh['headers_json'] ?? '{}'), true);
        if (is_array($customHeaders)) {
            foreach ($customHeaders as $hk => $hv) {
                $hk = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$hk);
                if ($hk !== '') {
                    $headers[] = $hk . ': ' . str_replace(["\r", "\n"], '', (string)$hv);
                }
            }
        }

        $timeout = max(2, min(15, (int)($wh['timeout_sec'] ?? 5)));
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if (!empty($pinnedResolve)) {
            $curlOpts[CURLOPT_RESOLVE] = $pinnedResolve;
        }
        curl_setopt_array($ch, $curlOpts);
        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $ok = ($respBody !== false && $httpCode >= 200 && $httpCode < 300);
        $errText = $ok ? '' : ($curlErr !== '' ? $curlErr : 'HTTP ' . $httpCode);

        $db = getDB();
        if ($ok) {
            $db->prepare('UPDATE sys_webhooks SET last_status_code = ?, last_triggered_at = NOW(), last_error = NULL, failure_streak = 0 WHERE id = ?')
                ->execute([$httpCode, $wh['id']]);
        } else {
            $db->prepare('UPDATE sys_webhooks SET last_status_code = ?, last_triggered_at = NOW(), last_error = ?, failure_streak = failure_streak + 1 WHERE id = ?')
                ->execute([$httpCode, mb_substr($errText, 0, 480), $wh['id']]);
        }

        return ['ok' => $ok, 'http_code' => $httpCode, 'error' => $errText];
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
