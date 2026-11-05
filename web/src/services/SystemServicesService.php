<?php
/**
 * The services of this server for the dashboard: their state, and which of them may be restarted.
 * A service that is not installed here is left out. aipbx-priv keeps the same list.
 */
class SystemServicesService
{
    /** Unit => what it does (key of web/lang/en.php). */
    const SERVICES = [
        'asterisk' => 'dashboard.svc_asterisk',
        'apache2' => 'dashboard.svc_apache',
        'nginx' => 'dashboard.svc_nginx',
        'mariadb' => 'dashboard.svc_mariadb',
        'postfix' => 'dashboard.svc_postfix',
        'coturn' => 'dashboard.svc_coturn',
        'firewalld' => 'dashboard.svc_firewalld',
        'fail2ban' => 'dashboard.svc_fail2ban',
        'chrony' => 'dashboard.svc_chrony',
        'ssh' => 'dashboard.svc_ssh',
        'aipbx-chat' => 'dashboard.svc_chat',
        'aipbx-signaling' => 'dashboard.svc_signaling',
        'aipbx-webhook-worker' => 'dashboard.svc_webhook',
        'aipbx-ai' => 'dashboard.svc_ai',
        'ollama' => 'dashboard.svc_ollama',
        'aipbx-mdns' => 'dashboard.svc_mdns',
        'aipbx-dnsmasq' => 'dashboard.svc_dnsmasq',
        'aipbx-syslog' => 'dashboard.svc_syslog',
        'aipbx-vsftpd' => 'dashboard.svc_ftp',
    ];

    public static function isAllowed(string $unit): bool
    {
        return isset(self::SERVICES[$unit]) || preg_match('/^aipbx-vpn@[A-Za-z0-9_-]{1,32}$/', $unit) === 1;
    }

    /** @return list<array{unit: string, label: string, state: string}> state: on, off or failed */
    public static function status(): array
    {
        $vpn = (string) @shell_exec("systemctl list-units 'aipbx-vpn@*' --all --plain --no-legend 2>/dev/null");
        preg_match_all('/^(aipbx-vpn@[A-Za-z0-9_-]{1,32})\.service/m', $vpn, $m);
        $units = array_merge(array_keys(self::SERVICES), $m[1]);
        $show = (string) @shell_exec('systemctl show --property=Id,LoadState,ActiveState ' . implode(' ', array_map('escapeshellarg', $units)) . ' 2>/dev/null');
        return self::parseShow($show, $units);
    }

    /**
     * `systemctl show` answers in the order asked, one block per unit.
     *
     * @param list<string> $units
     * @return list<array{unit: string, label: string, state: string}>
     */
    public static function parseShow(string $show, array $units): array
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($show)) ?: [] as $i => $block) {
            if (!isset($units[$i]) || !preg_match('/^LoadState=loaded$/m', $block)) {
                continue;
            }
            $active = preg_match('/^ActiveState=(\S+)/m', $block, $a) ? $a[1] : '';
            $unit = $units[$i];
            $out[] = [
                'unit' => $unit,
                'label' => isset(self::SERVICES[$unit]) ? t(self::SERVICES[$unit]) : sprintf(t('dashboard.svc_vpn'), substr($unit, 10)),
                'state' => match ($active) {
                    'active', 'reloading', 'activating' => 'on',
                    'failed' => 'failed',
                    default => 'off',
                },
            ];
        }
        return $out;
    }
}
