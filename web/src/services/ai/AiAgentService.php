<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 3) . '/modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

/**
 * AI → External agents: a voice agent of another service at a SIP address as
 * a call destination (SyncAiAgents.php writes the endpoint and the dialplan).
 * The caller stays on this PBX: call records, recording and the limits here.
 * The agent may hand the call over with a SIP REFER (blind transfer); whatever
 * number it names, the call goes to the agent's hand-over destination.
 */
final class AiAgentService
{
    public const TRANSPORTS = ['udp', 'tcp', 'tls'];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return getDB()->query('SELECT * FROM pbx_ai_agents ORDER BY title ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function get(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM pbx_ai_agents WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * "sip:agent@voice.example.com:5060" -> user, host, port (0 = default).
     *
     * @return array{scheme: string, user: string, host: string, port: int}|null
     */
    public static function parseUri(string $uri): ?array
    {
        if (!preg_match('/^(sips?):(?:([A-Za-z0-9._~+*#-]{1,64})@)?(\[[0-9A-Fa-f:]+\]|[A-Za-z0-9.-]{1,253})(?::(\d{1,5}))?$/', trim($uri), $m)) {
            return null;
        }
        $port = (int) ($m[4] ?? 0);
        if ($port > 65535 || (isset($m[4]) && $port < 1)) {
            return null;
        }
        return ['scheme' => $m[1], 'user' => $m[2], 'host' => $m[3], 'port' => $port];
    }

    /** @return array<string, mixed> the row to store */
    public static function validate(array $d, int $id = 0): array
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100) {
            throw new \InvalidArgumentException(t('ai_agents.err_title'));
        }
        $uri = trim((string) ($d['sip_uri'] ?? ''));
        $parts = self::parseUri($uri);
        if ($parts === null) {
            throw new \InvalidArgumentException(t('ai_agents.err_uri'));
        }
        $transport = $parts['scheme'] === 'sips' ? 'tls' : (string) ($d['transport'] ?? 'udp');
        if (!in_array($transport, self::TRANSPORTS, true)) {
            throw new \InvalidArgumentException(t('ai_agents.err_uri'));
        }
        $user = trim((string) ($d['auth_username'] ?? ''));
        if ($user !== '' && !preg_match('/^[A-Za-z0-9._@+-]{1,100}$/', $user)) {
            throw new \InvalidArgumentException(t('ai_agents.err_auth'));
        }
        // An empty password field keeps the saved one (the form never shows it).
        $old = $id > 0 ? self::get($id) : null;
        $password = (string) ($d['auth_password'] ?? '');
        if (preg_match('/[\x00-\x1F\x7F]/', $password) || strlen($password) > 200) {
            throw new \InvalidArgumentException(t('ai_agents.err_auth'));
        }
        $stored = $password !== '' ? SecretBox::encrypt($password) : (string) ($old['auth_password'] ?? '');
        if ($user === '') {
            $stored = '';
        }
        $row = [
            'title' => $title, 'sip_uri' => $uri, 'transport' => $transport,
            'auth_username' => $user, 'auth_password' => $stored,
            'max_concurrent' => max(1, min(100, (int) ($d['max_concurrent'] ?? 4))),
            'max_minutes' => max(1, min(240, (int) ($d['max_minutes'] ?? 15))),
            'ring_seconds' => max(5, min(120, (int) ($d['ring_seconds'] ?? 20))),
            'is_active' => !empty($d['is_active']) ? 1 : 0,
        ];
        foreach (['handover_dest', 'fallback_dest'] as $p) {
            $type = (string) ($d[$p . '_type'] ?? '');
            $destId = (string) ($d[$p . '_id'] ?? '');
            if ($type === '' && $p === 'handover_dest') {
                $destId = '';
            } elseif (!preg_match('/^[a-z_]{2,30}$/', $type) || mb_strlen($destId) > 64) {
                throw new \InvalidArgumentException(t('ai_agents.err_dest'));
            }
            if ($type === 'ai_agent' && $id > 0 && (int) $destId === $id) {
                throw new \InvalidArgumentException(t('ai_agents.err_dest_self'));
            }
            $row[$p . '_type'] = $type;
            $row[$p . '_id'] = $destId;
        }
        return $row;
    }

    public static function save(array $d): int
    {
        $id = (int) ($d['id'] ?? 0);
        $old = $id > 0 ? self::get($id) : null;
        if ($id > 0 && $old === null) {
            throw new \InvalidArgumentException(t('ai_agents.err_not_found'));
        }
        $row = self::validate($d, $id);
        $db = getDB();
        $cols = array_keys($row);
        if ($id > 0) {
            $db->prepare('UPDATE pbx_ai_agents SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
               ->execute([...array_values($row), $id]);
        } else {
            $db->prepare('INSERT INTO pbx_ai_agents (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
               ->execute(array_values($row));
            $id = (int) $db->lastInsertId();
        }
        markPendingSync('ai_agents', 'ai_agent', $id, 'AI agent: ' . $row['title'], $old ? 'update' : 'create', $_SESSION['user_id'] ?? null);
        return $id;
    }

    public static function delete(int $id): void
    {
        $agent = self::get($id);
        if ($agent === null) {
            return;
        }
        if (DestinationRegistry::usageCount('ai_agent', (string) $id) > 0) {
            throw new \InvalidArgumentException(t('srv_tc.err_in_use'));
        }
        getDB()->prepare('DELETE FROM pbx_ai_agents WHERE id = ?')->execute([$id]);
        markPendingSync('ai_agents', 'ai_agent', $id, 'AI agent: ' . $agent['title'], 'delete', $_SESSION['user_id'] ?? null);
    }

    /** The saved password in clear text, for the endpoint's auth section only. */
    public static function password(array $agent): string
    {
        $stored = (string) ($agent['auth_password'] ?? '');
        if ($stored === '') {
            return '';
        }
        try {
            return (string) SecretBox::decrypt($stored);
        } catch (\Throwable $e) {
            error_log('AI agent ' . (int) $agent['id'] . ': password cannot be read: ' . $e->getMessage());
            return '';
        }
    }
}
