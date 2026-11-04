<?php
require_once __DIR__ . '/../priv_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * Network settings → the server's own address: DHCP or static. The change goes back by
 * itself unless it is confirmed in time (conf/sbin/aipbx-netcfg).
 */
class NetworkConfigService
{
    /** @return array{interfaces: list<array<string, mixed>>, change: array<string, mixed>} */
    public static function status(): array
    {
        $res = PrivHelper::run(['netcfg', 'status']);
        $data = $res['success'] ? json_decode($res['output'], true) : null;
        return is_array($data) ? $data + ['interfaces' => [], 'change' => []] : ['interfaces' => [], 'change' => []];
    }

    public static function apply(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $iface = trim((string) ($post['iface'] ?? ''));
        $mode = ($post['mode'] ?? '') === 'dhcp' ? 'dhcp' : 'static';
        $calls = (int) AsteriskHelper::activeCallCount();
        if ($calls > 0 && empty($post['end_calls'])) {
            return ['success' => false, 'error' => sprintf(t('netcfg.err_calls'), $calls)];
        }
        $args = ['netcfg', 'apply', "iface={$iface}", "mode={$mode}", 'default=' . (empty($post['is_default']) ? '0' : '1')];
        if ($mode === 'static') {
            $prefix = self::prefixOf(trim((string) ($post['netmask'] ?? '')));
            if ($prefix === null) {
                return ['success' => false, 'error' => t('netcfg.err_netmask')];
            }
            $args[] = 'address=' . trim((string) ($post['address'] ?? '')) . '/' . $prefix;
            $args[] = 'gateway=' . trim((string) ($post['gateway'] ?? ''));
            $args[] = 'dns=' . trim((string) ($post['dns'] ?? ''));
            $args[] = 'nets=' . trim((string) ($post['nets'] ?? ''));
        }
        $res = PrivHelper::run($args);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output']];
        }
        writeAuditLog('network', 'interface', $iface, "Address of {$iface}: {$mode} " . trim((string) ($post['address'] ?? '')), 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('netcfg.applied')];
    }

    public static function confirm(array $post): array
    {
        $change = self::status()['change'] ?? [];
        $res = self::finish($post, 'confirm', t('netcfg.confirmed'));
        // What still holds the old address follows: phone files, settings, certificate, local name.
        $old = (string) ($change['old_ip'] ?? '');
        $new = (string) ($change['new_ip'] ?? '');
        if ($res['success'] && ($change['state'] ?? '') === 'pending' && filter_var($old, FILTER_VALIDATE_IP) && filter_var($new, FILTER_VALIDATE_IP) && $old !== $new) {
            try {
                NetworkSettingsService::readdress($old, $new, $_SESSION['user_id'] ?? null);
                $res['message'] = sprintf(t('netcfg.confirmed_moved'), $old, $new);
            } catch (\Throwable $e) {
                $res['message'] = t('netcfg.confirmed') . ' ' . $e->getMessage();
            }
        }
        return $res;
    }

    public static function revert(array $post): array
    {
        return self::finish($post, 'revert', t('netcfg.reverted'));
    }

    /** "255.255.255.0", "24" or "/24" as a prefix length; null when it is neither. */
    public static function prefixOf(string $mask): ?int
    {
        $mask = ltrim($mask, '/');
        if (ctype_digit($mask)) {
            return (int) $mask <= 32 ? (int) $mask : null;
        }
        $long = filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($mask) : false;
        if ($long === false || (($long ^ 0xFFFFFFFF) & (($long ^ 0xFFFFFFFF) + 1)) !== 0) {
            return null;
        }
        return substr_count(decbin($long), '1');
    }

    /** "24" as "255.255.255.0". */
    public static function maskOf(int $prefix): string
    {
        return (string) long2ip($prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF);
    }

    private static function finish(array $post, string $what, string $message): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $res = PrivHelper::run(['netcfg', $what]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output']];
        }
        writeAuditLog('network', 'interface', '', "Address change: {$what}", 'update', $_SESSION['user_id'] ?? null);
        if ($what === 'confirm') {
            // The card's network may be another one now: it counts as local (SyncTransports).
            markPendingSync('transports', 'system_setting', 'network', 'Network card address changed');
        }
        return ['success' => true, 'message' => $message];
    }
}
