<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/priv_helper.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';
require_once __DIR__ . '/NetworkServicesService.php';
require_once __DIR__ . '/PhoneProvisionService.php';

/**
 * Network → Network settings (roadmap 13 and 12): the server's addresses in
 * one place, and one step to move them all when the server's IP changes.
 *
 * Why: the address was written into many places, at install time or by hand
 * (RTP, NAT, what desk phones are told, coturn, the mDNS alias, a self-signed
 * certificate), on three different pages, and nothing noticed when it changed
 * (new DHCP lease, a cloned VM, a move to another network). TURN then had no
 * audio and phones registered to the old address.
 *
 * Settings kept here (sys_settings):
 *   server_ip            this server's address on the local network: the RTP
 *                        ICE host candidate (SyncRtpSettings); '' = detected
 *   pjsip_external_ip    the public address behind NAT (also pjsipsettings
 *                        externip_val, read by SyncTransports)
 *   pjsip_local_net      the networks Asterisk treats as local (pjsipsettings
 *                        localnet_N / netmask_N)
 *   provision_*          what desk phones are told (PhoneProvisionService::ADDRESS_KEYS)
 * Files (root, through `aipbx-priv net`): coturn relay-ip / allowed-peer-ip,
 * the mDNS alias unit, a self-signed certificate naming the IP.
 */
final class NetworkSettingsService
{
    /** Settings of the "server addresses" form. */
    public const SERVER_KEYS = ['server_ip', 'pjsip_external_ip', 'pjsip_local_net'];

    private const IPV4 = '/^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$/';

    // ------------------------------------------------------------ status

    /**
     * What the server itself reports: host name, addresses, gateway, DNS.
     *
     * @return array{hostname: string, fqdn: string, interfaces: list<array{name: string, addresses: list<string>, mac: string, up: bool}>,
     *               gateway: string, gateway_dev: string, dns: list<string>, primary: string}
     */
    public static function system(): array
    {
        $interfaces = [];
        $json = @shell_exec('ip -j addr show 2>/dev/null');
        foreach ((array) (is_string($json) ? json_decode($json, true) : []) as $if) {
            $name = (string) ($if['ifname'] ?? '');
            if ($name === '' || $name === 'lo') {
                continue;
            }
            $addresses = [];
            foreach ((array) ($if['addr_info'] ?? []) as $a) {
                if (in_array($a['family'] ?? '', ['inet', 'inet6'], true) && ($a['scope'] ?? '') !== 'link') {
                    $addresses[] = $a['local'] . '/' . (int) ($a['prefixlen'] ?? 0);
                }
            }
            $interfaces[] = ['name' => $name, 'addresses' => $addresses, 'mac' => (string) ($if['address'] ?? ''),
                'up' => in_array('UP', (array) ($if['flags'] ?? []), true) && ($if['operstate'] ?? '') !== 'DOWN'];
        }
        $gateway = '';
        $gatewayDev = '';
        $route = @shell_exec('ip -j -4 route show default 2>/dev/null');
        foreach ((array) (is_string($route) ? json_decode($route, true) : []) as $r) {
            $gateway = (string) ($r['gateway'] ?? '');
            $gatewayDev = (string) ($r['dev'] ?? '');
            break;
        }
        return [
            'hostname' => trim((string) @shell_exec('hostname 2>/dev/null')),
            'fqdn' => trim((string) @shell_exec('hostname -f 2>/dev/null')),
            'interfaces' => $interfaces,
            'gateway' => $gateway,
            'gateway_dev' => $gatewayDev,
            'dns' => self::dnsServers(),
            'primary' => function_exists('rtpPrimaryIp') ? rtpPrimaryIp() : '',
        ];
    }

    /** DNS servers the system uses (resolvectl, else resolv.conf without the 127.0.0.53 stub). */
    private static function dnsServers(): array
    {
        $out = [];
        foreach (explode("\n", (string) @shell_exec('resolvectl dns 2>/dev/null')) as $line) {
            if (preg_match('/:\s*(.+)$/', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $out[] = $ip;
                    }
                }
            }
        }
        if ($out === []) {
            foreach ((array) @file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^nameserver\s+(\S+)/', (string) $line, $m) && $m[1] !== '127.0.0.53') {
                    $out[] = $m[1];
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** IPv4 addresses of this server (no loopback). */
    public static function localIps(): array
    {
        $ips = [];
        foreach (NetworkServicesService::interfaces() as $if) {
            $ips[] = $if['ip'];
        }
        foreach (preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) ?: [] as $ip) {
            if (preg_match(self::IPV4, $ip)) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }

    /** `aipbx-priv net status` as key => value (coturn, mDNS, certificate). */
    public static function fileStatus(): array
    {
        $res = PrivHelper::run(['net', 'status']);
        $out = [];
        foreach (explode("\n", $res['output']) as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', trim($line), $m)) {
                $out[$m[1]] = trim($m[2]);
            }
        }
        return $out;
    }

    /** The stored settings of this page. */
    public static function settings(): array
    {
        $out = [];
        foreach (self::SERVER_KEYS as $k) {
            $out[$k] = (string) getSystemSetting($k, $k === 'pjsip_local_net' ? '192.168.1.0/24' : '');
        }
        return $out + PhoneProvisionService::addressSettings();
    }

    /**
     * Stored addresses that are none of this server's addresses: the sign that
     * the IP changed. Only addresses AiPBX itself uses as "this server" count
     * (not the public NAT address, not a phone's NTP server elsewhere).
     *
     * @param list<string>|null $local this server's addresses (tests)
     * @param array<string, string>|null $files `net status` (tests)
     * @return list<array{where: string, value: string}>
     */
    public static function staleAddresses(?array $local = null, ?array $settings = null, ?array $files = null): array
    {
        $local = $local ?? self::localIps();
        $settings = $settings ?? self::settings();
        $files = $files ?? self::fileStatus();
        $stale = [];
        $check = function (string $where, string $value) use (&$stale, $local) {
            $host = self::hostOf($value);
            if ($host !== '' && preg_match(self::IPV4, $host) && !in_array($host, $local, true)) {
                $stale[] = ['where' => $where, 'value' => $host];
            }
        };
        $check('server_ip', $settings['server_ip'] ?? '');
        $check('provision_sip_server', $settings['provision_sip_server'] ?? '');
        $check('provision_base_url', $settings['provision_base_url'] ?? '');
        $check('provision_syslog', $settings['provision_syslog'] ?? '');
        $check('coturn_relay', $files['coturn_relay'] ?? '');
        $check('mdns', $files['mdns'] ?? '');
        return $stale;
    }

    /** The host of "1.2.3.4", "https://1.2.3.4:8443" or "pbx.example.com". */
    private static function hostOf(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }
        $host = parse_url(str_contains($v, '://') ? $v : 'x://' . $v, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    // ------------------------------------------------------------ save

    /**
     * The "server addresses" form: this server's address, the public address
     * and the local networks. Asterisk waits for Apply (transports, RTP).
     */
    public static function saveServer(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $v = self::validateServer($data);
            self::storeServer($v);
            writeAuditLog('network_settings', 'settings', 0, 'Server addresses: ' . ($v['server_ip'] ?: 'auto') . ', public ' . ($v['pjsip_external_ip'] ?: '-'), 'update', $_SESSION['user_id'] ?? null);
            return t('netset.saved_server');
        });
    }

    /** The "addresses for phones" form (PhoneProvisionService keeps them). */
    public static function savePhones(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            PhoneProvisionService::saveAddressSettings($data);
            return t('netset.saved_phones');
        });
    }

    /**
     * @return array{server_ip: string, pjsip_external_ip: string, pjsip_local_net: string}
     */
    public static function validateServer(array $data): array
    {
        $server = trim((string) ($data['server_ip'] ?? ''));
        if ($server !== '' && !preg_match(self::IPV4, $server)) {
            throw new \Exception(t('netset.err_server_ip'));
        }
        $public = trim((string) ($data['pjsip_external_ip'] ?? ''));
        if ($public !== '' && !preg_match(self::IPV4, $public)) {
            throw new \Exception(t('netset.err_public_ip'));
        }
        $nets = [];
        foreach (preg_split('/[\s,]+/', trim((string) ($data['pjsip_local_net'] ?? ''))) ?: [] as $n) {
            if ($n === '') {
                continue;
            }
            if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $n, $m) || !preg_match(self::IPV4, $m[1]) || (int) $m[2] > 32) {
                throw new \Exception(sprintf(t('netset.err_local_net'), $n));
            }
            $nets[] = $m[1] . '/' . (int) $m[2];
        }
        if (count($nets) > 32) {
            throw new \Exception(sprintf(t('netset.err_local_net'), '…'));
        }
        return ['server_ip' => $server, 'pjsip_external_ip' => $public, 'pjsip_local_net' => implode(',', array_values(array_unique($nets)))];
    }

    /** Stores the server addresses and marks transports and RTP for Apply. */
    private static function storeServer(array $v): void
    {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($v as $k => $val) {
            $stmt->execute([$k, $val]);
        }
        // The transports read these rows (SyncTransports).
        $pj = $db->prepare('INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES (?, ?, 1, 0) ON DUPLICATE KEY UPDATE data = VALUES(data)');
        $pj->execute(['externip_val', $v['pjsip_external_ip']]);
        $i = 0;
        foreach (array_filter(explode(',', $v['pjsip_local_net'])) as $net) {
            [$ip, $cidr] = explode('/', $net, 2);
            $pj->execute(["localnet_{$i}", $ip]);
            $pj->execute(["netmask_{$i}", long2ip(-1 << (32 - (int) $cidr))]);
            $i++;
        }
        $db->prepare("DELETE FROM pjsipsettings WHERE keyword REGEXP '^(localnet|netmask)_[0-9]+$' AND CAST(SUBSTRING_INDEX(keyword, '_', -1) AS UNSIGNED) >= ?")->execute([$i]);
        $uid = $_SESSION['user_id'] ?? null;
        markPendingSync('transports', 'system_setting', 'network', 'Network settings: server addresses', 'update', $uid);
        markPendingSync('rtp', 'system_setting', 'network', 'Network settings: server addresses', 'update', $uid);
    }

    // ------------------------------------------------------------ readdress

    /**
     * Everything that holds $old and what it becomes (roadmap 12). Settings
     * that hold it as a host get $new; when $new lies in none of the local
     * networks, its network (prefix of the interface that has it, else /24)
     * is added.
     *
     * @return list<array{where: string, from: string, to: string}>
     */
    public static function readdressPreview(string $old, string $new, ?array $settings = null, ?array $files = null, ?int $newPrefix = null): array
    {
        if (!preg_match(self::IPV4, $old) || !preg_match(self::IPV4, $new) || $old === $new) {
            throw new \Exception(t('netset.err_readdress'));
        }
        $settings = $settings ?? self::settings();
        $files = $files ?? self::fileStatus();
        $changes = [];
        foreach (['server_ip', 'pjsip_external_ip', 'provision_sip_server', 'provision_sip_proxy', 'provision_base_url', 'provision_syslog', 'provision_ntp'] as $k) {
            $val = (string) ($settings[$k] ?? '');
            $to = self::replaceHost($val, $old, $new);
            if ($to !== $val) {
                $changes[] = ['where' => $k, 'from' => $val, 'to' => $to];
            }
        }
        $nets = array_values(array_filter(explode(',', (string) ($settings['pjsip_local_net'] ?? ''))));
        if (!self::inAny($new, $nets)) {
            $prefix = $newPrefix ?? self::prefixOf($new) ?? 24;
            $net = long2ip(ip2long($new) & (-1 << (32 - $prefix))) . '/' . $prefix;
            $changes[] = ['where' => 'pjsip_local_net', 'from' => implode(',', $nets), 'to' => implode(',', array_merge($nets, [$net]))];
        }
        $allowed = (string) ($settings['provision_allowed_networks'] ?? '');
        $allowedList = PhoneProvisionService::parseNetworks($allowed);
        if ($allowedList !== [] && PhoneProvisionService::ipInNetworks($old, $allowedList) && !PhoneProvisionService::ipInNetworks($new, $allowedList)) {
            $changes[] = ['where' => 'provision_allowed_networks', 'from' => implode(', ', $allowedList), 'to' => implode(', ', $allowedList) . ' ' . t('netset.check_by_hand')];
        }
        if (($files['coturn_relay'] ?? '') === $old) {
            $changes[] = ['where' => 'coturn_relay', 'from' => $old, 'to' => $new];
        }
        if (($files['mdns'] ?? '') === $old) {
            $changes[] = ['where' => 'mdns', 'from' => $old, 'to' => $new];
        }
        if (in_array($old, preg_split('/\s+/', (string) ($files['cert_ips'] ?? '')) ?: [], true)) {
            $changes[] = ['where' => 'certificate', 'from' => $old, 'to' => $new];
        }
        return $changes;
    }

    /** Applies readdressPreview(): settings now, files through aipbx-priv, Asterisk and phone files at once. */
    public static function readdress(string $old, string $new, ?int $userId = null): array
    {
        $changes = self::readdressPreview($old, $new);
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $server = self::settings();
        foreach ($changes as $c) {
            if (in_array($c['where'], ['server_ip', 'pjsip_external_ip', 'pjsip_local_net'], true)) {
                $server[$c['where']] = $c['to'];
            } elseif (str_starts_with($c['where'], 'provision_') && $c['where'] !== 'provision_allowed_networks') {
                $stmt->execute([$c['where'], $c['to']]);
            }
        }
        self::storeServer(['server_ip' => $server['server_ip'], 'pjsip_external_ip' => $server['pjsip_external_ip'], 'pjsip_local_net' => $server['pjsip_local_net']]);
        $files = PrivHelper::run(['net', 'readdress', 'old=' . $old, 'new=' . $new]);
        // Calls keep running through a PJSIP / RTP reload.
        $synced = true;
        try {
            syncTransports();
            syncRtpSettings();
        } catch (\Throwable $e) {
            $synced = false;
        }
        PhoneProvisionService::exportTftpQuietly();
        writeAuditLog('network_settings', 'readdress', 0, "Server address {$old} -> {$new} (" . count($changes) . ' changes)', 'update', $userId);
        return ['changes' => $changes, 'files' => trim((string) $files['output']), 'files_ok' => (bool) $files['success'], 'synced' => $synced];
    }

    /** Portal action: preview (step 1) or apply (step 2, with confirm=1). */
    public static function readdressAction(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $old = trim((string) ($data['old_ip'] ?? ''));
            $new = trim((string) ($data['new_ip'] ?? ''));
            $r = self::readdress($old, $new, $_SESSION['user_id'] ?? null);
            return sprintf(t('netset.readdress_done'), $old, $new, count($r['changes']))
                . ($r['synced'] ? '' : ' ' . t('netset.readdress_sync_failed'))
                . ($r['files_ok'] ? '' : ' ' . t('netset.readdress_files_failed') . ' ' . $r['files']);
        });
    }

    /** $value with the host $old replaced by $new (exact host only, never part of another address). */
    public static function replaceHost(string $value, string $old, string $new): string
    {
        return (string) preg_replace('/(?<![\d.])' . preg_quote($old, '/') . '(?![\d.])/', $new, $value);
    }

    private static function inAny(string $ip, array $nets): bool
    {
        return PhoneProvisionService::ipInNetworks($ip, $nets);
    }

    /** Prefix length of the interface that has $ip, or null. */
    private static function prefixOf(string $ip): ?int
    {
        foreach (NetworkServicesService::interfaces() as $if) {
            if ($if['ip'] === $ip) {
                return (int) $if['prefix'];
            }
        }
        return null;
    }
}
