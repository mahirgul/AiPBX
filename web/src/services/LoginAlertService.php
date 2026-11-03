<?php
/**
 * Mail to a user when their account signs in from a network it has not used
 * in the last 90 days. Fail2ban and the lockouts only see wrong passwords; a
 * stolen password that works looks like a normal sign-in, and this is what
 * tells the owner.
 *
 * Called just before the successful sign-in is logged, so the log still holds
 * only the earlier ones. Mail goes to the local mail queue, so a slow relay
 * does not hold up the sign-in, and a failure here never stops one.
 */
class LoginAlertService
{
    const SETTING = 'login_new_ip_alert';
    const LOOKBACK_DAYS = 90;

    public static function enabled(): bool
    {
        return (string) getSystemSetting(self::SETTING, '1') === '1';
    }

    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $on = !empty($post[self::SETTING]);
        setSystemSetting(self::SETTING, $on ? '1' : '0');
        writeAuditLog('security', 'sys_settings', self::SETTING, self::SETTING . ' = ' . ($on ? '1' : '0'), 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('login_alert.saved')];
    }

    /** Only addresses on the internet count: a site's own LAN or VPN is not news. */
    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * The same network: the same /24 (IPv4) or /64 (IPv6). A whole address would
     * mail people each time their provider or office NAT hands them a new one.
     */
    public static function sameNetwork(string $a, string $b): bool
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);
        if ($pa === false || $pb === false || strlen($pa) !== strlen($pb)) {
            return false;
        }
        $bytes = strlen($pa) === 4 ? 3 : 8;
        return substr($pa, 0, $bytes) === substr($pb, 0, $bytes);
    }

    /**
     * @param list<string> $earlier addresses of the account's earlier sign-ins
     * An account with no history gets no mail: its first sign-in is expected.
     */
    public static function isNewNetwork(string $ip, array $earlier): bool
    {
        if ($earlier === [] || !self::isPublic($ip)) {
            return false;
        }
        foreach ($earlier as $known) {
            if (self::sameNetwork($ip, $known)) {
                return false;
            }
        }
        return true;
    }

    public static function beforeSuccessfulLogin(string $ip, string $username): void
    {
        try {
            if (!self::enabled() || !self::isPublic($ip)) {
                return;
            }
            $db = getDB();
            $st = $db->prepare("SELECT DISTINCT ip_address FROM sys_login_logs
                WHERE username = ? AND status = 'SUCCESS' AND created_at > DATE_SUB(NOW(), INTERVAL " . self::LOOKBACK_DAYS . ' DAY)');
            $st->execute([$username]);
            if (!self::isNewNetwork($ip, array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)))) {
                return;
            }
            $st = $db->prepare('SELECT id, username, full_name, email FROM sys_users WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user || !filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $res = MailTemplateService::send('login_new_ip', (string) $user['email'], MailTemplateService::languageFor(null, (int) $user['id']), [
                'name' => (string) (($user['full_name'] ?? '') ?: $user['username']),
                'username' => (string) $user['username'],
                'ip' => $ip,
                'date' => date('d.m.Y H:i'),
                'browser' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                'portal_link' => rtrim(ForgotPasswordService::portalUrl(), '/'),
            ]);
            writeAuditLog('security', 'sys_users', (string) $user['id'],
                'New network sign-in mail: ' . $user['username'] . ' from ' . $ip . ($res['success'] ? '' : ' (not sent)'),
                'login_new_ip_alert', null);
        } catch (Throwable $e) {
            error_log('LoginAlertService: ' . $e->getMessage());
        }
    }
}
