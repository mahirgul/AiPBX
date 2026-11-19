<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';

/**
 * Service for managing REST API (/api/v1/*) Bearer tokens (`sys_api_tokens`).
 */
class ApiTokenService
{
    public const AVAILABLE_SCOPES = [
        '*'             => 'Full Access (All Domains Read & Write)',
        'users:read'    => 'Users & Roles (Read)',
        'users:write'   => 'Users & Roles (Write)',
        'pbx:read'      => 'Extensions, Trunks, Routes, IVRs, Time Conditions, Ring Groups, Conferences (Read)',
        'pbx:write'     => 'Extensions, Trunks, Routes, IVRs, Time Conditions, Ring Groups, Conferences (Write)',
        'queues:read'   => 'Queues, Agents, Wallboard & Queue Reports (Read)',
        'queues:write'  => 'Queues & Live Agent Control (Write)',
        'calls:read'    => 'Active Calls, CDRs, Recordings & Voicemail (Read)',
        'calls:write'   => 'Click-to-Call Originate, Hangup, Transfer, Spy & Voicemail (Write)',
        'contacts:read' => 'Address Book Contacts & Caller Lookup (Read)',
        'contacts:write'=> 'Address Book Contacts (Write)',
        'fax:read'      => 'Fax Inbox, Sent & Units (Read)',
        'fax:write'     => 'Send Fax & Manage Fax Units (Write)',
        'sounds:read'   => 'Sounds, Announcements & Music on Hold (Read)',
        'sounds:write'  => 'Upload/Delete Sounds & Announcements (Write)',
        'devices:read'  => 'Desk Phones & Network Provisioning (Read)',
        'devices:write' => 'Manage Desk Phones & BLF Keys (Write)',
        'system:read'   => 'System Health, Audit Log, Pending Sync & Settings (Read)',
        'system:write'  => 'Apply Pending Sync, Firewall, Fail2ban, Webhooks & Plugins (Write)',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = DBHelper::fetchAll(
            "SELECT t.*, u.username AS created_by_username, u.full_name AS created_by_name
             FROM sys_api_tokens t
             LEFT JOIN sys_users u ON u.id = t.created_by
             ORDER BY t.id DESC"
        );
        foreach ($rows as &$r) {
            unset($r['token_hash']);
            $r['scopes'] = json_decode((string)($r['scopes_json'] ?? '[]'), true) ?: [];
        }
        return $rows;
    }

    public static function validateAllowedIps(string $allowedIps): ?string
    {
        $raw = trim($allowedIps);
        if ($raw === '') {
            return null;
        }
        $valid = [];
        foreach (explode(',', $raw) as $part) {
            $entry = trim($part);
            if ($entry === '') {
                continue;
            }
            if (!self::isValidIpOrCidr($entry)) {
                throw new \InvalidArgumentException(sprintf(t('api_keys.err_invalid_ip_entry', 'Invalid IP or CIDR entry: %s'), $entry));
            }
            $valid[] = $entry;
        }
        if ($valid === []) {
            throw new \InvalidArgumentException(t('api_keys.err_invalid_ip', 'Allowed IPs must contain at least one valid IP or CIDR entry.'));
        }
        return implode(', ', $valid);
    }

    public static function isValidIpOrCidr(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (!str_contains($entry, '/')) {
            return false;
        }
        $parts = explode('/', $entry);
        if (count($parts) !== 2 || $parts[1] === '' || !ctype_digit($parts[1])) {
            return false;
        }
        $subBin = @inet_pton($parts[0]);
        if ($subBin === false) {
            return false;
        }
        $maxBits = strlen($subBin) * 8;
        $bits = (int)$parts[1];
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            $canon = inet_ntop($subBin);
            return ($maxBits === 32 && $canon === '0.0.0.0') || ($maxBits === 128 && $canon === '::');
        }
        return true;
    }

    public static function generateRawToken(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $random = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 40; $i++) {
            $random .= $alphabet[random_int(0, $max)];
        }
        return 'apbx_' . $random;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function create(array $data): array
    {
        $rawToken = '';
        $tokenId = 0;
        $res = PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data, &$rawToken, &$tokenId) {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new \Exception(t('api_keys.err_name_required', 'Token name is required.'));
            }

            $scopesIn = $data['scopes'] ?? [];
            if (is_string($scopesIn)) {
                $scopesIn = array_filter(array_map('trim', explode(',', $scopesIn)));
            }
            if (!is_array($scopesIn) || empty($scopesIn)) {
                throw new \Exception(t('api_keys.err_scopes_required', 'Select at least one permission scope.'));
            }

            $validScopeKeys = array_keys(self::AVAILABLE_SCOPES);
            $scopes = [];
            foreach ($scopesIn as $s) {
                $s = trim((string)$s);
                if (in_array($s, $validScopeKeys, true) || preg_match('/^plugin:[a-z0-9-]+$/', $s)) {
                    $scopes[] = $s;
                }
            }
            if (in_array('*', $scopes, true)) {
                $scopes = ['*'];
            }
            if (empty($scopes)) {
                throw new \Exception(t('api_keys.err_scopes_required', 'Select at least one valid permission scope.'));
            }

            $allowedIps = self::validateAllowedIps((string)($data['allowed_ips'] ?? ''));
            $rateLimit = max(10, min(10000, (int)($data['rate_limit_per_min'] ?? 120)));
            $expiresAt = null;
            if (!empty($data['expires_at'])) {
                $ts = strtotime(trim((string)$data['expires_at']));
                if ($ts !== false && $ts > time()) {
                    $expiresAt = date('Y-m-d H:i:s', $ts);
                }
            }

            $rawToken = self::generateRawToken();
            $prefix = substr($rawToken, 0, 13) . '…';
            $hash = hash('sha256', $rawToken);

            $tokenId = (int)DBHelper::insert('sys_api_tokens', [
                'name'               => mb_substr($name, 0, 120),
                'token_prefix'       => $prefix,
                'token_hash'         => $hash,
                'scopes_json'        => json_encode(array_values(array_unique($scopes))),
                'allowed_ips'        => $allowedIps,
                'rate_limit_per_min' => $rateLimit,
                'expires_at'         => $expiresAt,
                'created_by'         => $_SESSION['user_id'] ?? null,
                'plugin_slug'        => null,
                'is_active'          => 1,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);

            writeAuditLog('api_keys', 'api_token', $tokenId, "API Token: {$name} ({$prefix})", 'create', $_SESSION['user_id'] ?? null);
            return sprintf(t('api_keys.msg_created', 'API token "%s" created. Copy the secret token now; it will not be shown again.'), $name);
        });

        if (!empty($res['success'])) {
            $res['raw_token'] = $rawToken;
            $res['id'] = $tokenId;
        }
        return $res;
    }

    /**
     * Issue or rotate a loopback-only (`127.0.0.1`) API token for an isolated plugin worker.
     *
     * @param list<string> $scopes
     */
    public static function issuePluginToken(string $pluginSlug, string $pluginName, array $scopes = []): string
    {
        $db = getDB();
        $db->prepare('DELETE FROM sys_api_tokens WHERE plugin_slug = ?')->execute([$pluginSlug]);

        $finalScopes = array_values(array_unique(array_merge(['plugin:' . $pluginSlug], $scopes)));
        $rawToken = self::generateRawToken();
        $prefix = substr($rawToken, 0, 13) . '…';
        $hash = hash('sha256', $rawToken);

        DBHelper::insert('sys_api_tokens', [
            'name'               => 'Plugin Worker: ' . mb_substr($pluginName, 0, 90),
            'token_prefix'       => $prefix,
            'token_hash'         => $hash,
            'scopes_json'        => json_encode($finalScopes),
            'allowed_ips'        => '127.0.0.1, ::1',
            'rate_limit_per_min' => 600,
            'expires_at'         => null,
            'created_by'         => $_SESSION['user_id'] ?? null,
            'plugin_slug'        => $pluginSlug,
            'is_active'          => 1,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        return $rawToken;
    }

    public static function revokePluginTokens(string $pluginSlug): void
    {
        getDB()->prepare('DELETE FROM sys_api_tokens WHERE plugin_slug = ?')->execute([$pluginSlug]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toggle(int $id, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($id) {
            $row = DBHelper::fetchOne('SELECT id, name, token_prefix, is_active FROM sys_api_tokens WHERE id = ?', [$id]);
            if (!$row) {
                throw new \Exception(t('common.not_found', 'Record not found.'));
            }
            $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;
            DBHelper::update('sys_api_tokens', ['is_active' => $newStatus], 'id', $id);
            $statusLabel = $newStatus === 1 ? 'enabled' : 'disabled';
            writeAuditLog('api_keys', 'api_token', $id, "API Token: {$row['name']} ({$statusLabel})", 'update', $_SESSION['user_id'] ?? null);
            return t('api_keys.msg_status_updated', 'API token status updated.');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($id) {
            $row = DBHelper::fetchOne('SELECT id, name, token_prefix FROM sys_api_tokens WHERE id = ?', [$id]);
            if (!$row) {
                throw new \Exception(t('common.not_found', 'Record not found.'));
            }
            DBHelper::delete('sys_api_tokens', 'id', $id);
            writeAuditLog('api_keys', 'api_token', $id, "API Token: {$row['name']} ({$row['token_prefix']}) revoked", 'delete', $_SESSION['user_id'] ?? null);
            return t('api_keys.msg_deleted', 'API token revoked and deleted.');
        });
    }
}
