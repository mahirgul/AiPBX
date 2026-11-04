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
        $args = ['netcfg', 'apply', "iface={$iface}", "mode={$mode}"];
        if ($mode === 'static') {
            $args[] = 'address=' . trim((string) ($post['address'] ?? ''));
            $args[] = 'gateway=' . trim((string) ($post['gateway'] ?? ''));
            $args[] = 'dns=' . trim((string) ($post['dns'] ?? ''));
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
        return self::finish($post, 'confirm', t('netcfg.confirmed'));
    }

    public static function revert(array $post): array
    {
        return self::finish($post, 'revert', t('netcfg.reverted'));
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
        return ['success' => true, 'message' => $message];
    }
}
