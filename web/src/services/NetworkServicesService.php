<?php
/**
 * Admin → Network services (roadmap item 10): DHCP and TFTP for desk phones,
 * so they find the provisioning server by themselves.
 *
 * Modes (sys_settings netsvc_mode):
 *  - off:  nothing runs (the default).
 *  - tftp: the company's DHCP server keeps handing out addresses; the page
 *          shows which options to set there, and TFTP serves the files in
 *          TFTP_DIR to the provisioning allowed networks.
 *  - dhcp: dnsmasq hands out addresses on one interface (a phone network or
 *          VLAN) with option 66/160 = the provisioning URL, 42 = NTP, and
 *          optionally TFTP (option 150 = this server).
 *
 * dnsmasq runs as its own unit (aipbx-dnsmasq). Its configuration is written
 * by `aipbx-priv netsvc apply` from validated KEY=VALUE arguments, never by
 * the portal: a dnsmasq file can run commands as root.
 *
 * TFTP never serves the per-phone configuration (it holds the SIP password):
 * that stays on HTTPS (/provision/). TFTP_DIR holds only what an
 * administrator uploads (boot files, firmware).
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/priv_helper.php';
require_once dirname(__DIR__) . '/audit_log.php';
require_once __DIR__ . '/ForgotPasswordService.php';
require_once __DIR__ . '/PhoneProvisionService.php';

class NetworkServicesService
{
    public const MODES = ['off', 'tftp', 'dhcp'];

    /** sys_settings keys and their defaults. */
    public const SETTINGS = [
        'netsvc_mode' => 'off',
        'netsvc_interface' => '',
        'netsvc_range_start' => '',
        'netsvc_range_end' => '',
        'netsvc_netmask' => '255.255.255.0',
        'netsvc_gateway' => '',
        'netsvc_dns' => '',
        'netsvc_ntp' => '',
        'netsvc_lease_hours' => '12',
        'netsvc_tftp' => '1',
    ];

    public const MAX_TFTP_FILE = 64 * 1024 * 1024;

    public static function tftpDir(): string
    {
        return getenv('AIPBX_TFTP_DIR') ?: '/var/lib/aipbx/tftp';
    }

    public static function leaseFile(): string
    {
        return getenv('AIPBX_DHCP_LEASE_FILE') ?: '/var/lib/aipbx-netsvc/dnsmasq.leases';
    }

    // ------------------------------------------------------------ settings

    public static function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $key => $default) {
            $out[$key] = (string) getSystemSetting($key, $default);
        }
        if (!in_array($out['netsvc_mode'], self::MODES, true)) {
            $out['netsvc_mode'] = 'off';
        }
        return $out;
    }

    /** URL handed out as option 66/160: the MAC-based provisioning address. */
    public static function provisioningUrl(): string
    {
        return ForgotPasswordService::portalUrl() . '/provision/';
    }

    /** Networks TFTP answers: the provisioning allowed networks (Phones → settings). */
    public static function tftpNetworks(): array
    {
        return PhoneProvisionService::parseNetworks((string) getSystemSetting('provision_allowed_networks', ''));
    }

    /**
     * IPv4 interfaces of this server (loopback left out).
     *
     * @return array<string, array{ip: string, prefix: int}>
     */
    public static function interfaces(): array
    {
        $out = [];
        $json = @shell_exec('ip -j -4 addr show 2>/dev/null');
        $list = is_string($json) ? json_decode($json, true) : null;
        foreach (is_array($list) ? $list : [] as $if) {
            $name = (string) ($if['ifname'] ?? '');
            if ($name === '' || $name === 'lo' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', $name)) {
                continue;
            }
            foreach ((array) ($if['addr_info'] ?? []) as $a) {
                if (($a['family'] ?? '') === 'inet' && filter_var($a['local'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $out[$name] = ['ip' => (string) $a['local'], 'prefix' => (int) ($a['prefixlen'] ?? 24)];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Checks the form and returns the values to store.
     *
     * @param array<string, array{ip: string, prefix: int}> $interfaces
     * @return array<string, string>
     */
    public static function validate(array $data, array $interfaces, array $tftpNetworks): array
    {
        $mode = (string) ($data['netsvc_mode'] ?? 'off');
        if (!in_array($mode, self::MODES, true)) {
            throw new \Exception(t('netsvc.err_mode'));
        }
        $v = self::SETTINGS;
        $v['netsvc_mode'] = $mode;
        foreach (['netsvc_interface', 'netsvc_range_start', 'netsvc_range_end', 'netsvc_netmask', 'netsvc_gateway', 'netsvc_dns', 'netsvc_ntp'] as $k) {
            $v[$k] = trim((string) ($data[$k] ?? ''));
        }
        $v['netsvc_lease_hours'] = (string) max(1, min(168, (int) ($data['netsvc_lease_hours'] ?? 12)));
        $v['netsvc_tftp'] = !empty($data['netsvc_tftp']) ? '1' : '0';

        if ($mode === 'off') {
            return $v;
        }
        if ($mode === 'tftp') {
            $v['netsvc_tftp'] = '1';
        }
        if ($v['netsvc_tftp'] === '1' && $mode === 'tftp' && $tftpNetworks === []) {
            throw new \Exception(t('netsvc.err_tftp_networks'));
        }
        if ($mode !== 'dhcp') {
            return $v;
        }

        if (empty($data['netsvc_confirm'])) {
            throw new \Exception(t('netsvc.err_confirm'));
        }
        $iface = $v['netsvc_interface'];
        if (!isset($interfaces[$iface])) {
            throw new \Exception(t('netsvc.err_interface'));
        }
        foreach (['netsvc_range_start', 'netsvc_range_end', 'netsvc_netmask'] as $k) {
            if (!self::isIpv4($v[$k])) {
                throw new \Exception(sprintf(t('netsvc.err_address'), $v[$k] === '' ? t('netsvc.' . substr($k, 7)) : $v[$k]));
            }
        }
        $mask = (int) ip2long($v['netsvc_netmask']);
        $inverted = ~$mask & 0xFFFFFFFF;
        if ($mask === 0 || (($inverted + 1) & $inverted) !== 0) {
            throw new \Exception(sprintf(t('netsvc.err_address'), $v['netsvc_netmask']));
        }
        $server = (int) ip2long($interfaces[$iface]['ip']);
        $start = (int) ip2long($v['netsvc_range_start']);
        $end = (int) ip2long($v['netsvc_range_end']);
        $net = $server & $mask;
        if (($start & $mask) !== $net || ($end & $mask) !== $net) {
            throw new \Exception(sprintf(t('netsvc.err_range_subnet'), $iface, $interfaces[$iface]['ip']));
        }
        if ($start > $end) {
            throw new \Exception(t('netsvc.err_range_order'));
        }
        if ($server >= $start && $server <= $end) {
            throw new \Exception(sprintf(t('netsvc.err_range_server'), $interfaces[$iface]['ip']));
        }
        if ($v['netsvc_gateway'] !== '' && (!self::isIpv4($v['netsvc_gateway']) || ((int) ip2long($v['netsvc_gateway']) & $mask) !== $net)) {
            throw new \Exception(sprintf(t('netsvc.err_address'), $v['netsvc_gateway']));
        }
        foreach (['netsvc_dns', 'netsvc_ntp'] as $k) {
            $v[$k] = implode(',', self::ipList($v[$k]));
        }
        return $v;
    }

    /**
     * Arguments for `aipbx-priv netsvc apply` (empty for mode off).
     *
     * @param array<string, string> $v validated settings
     * @return string[]
     */
    public static function privArgs(array $v, array $tftpNetworks, string $url): array
    {
        if ($v['netsvc_mode'] === 'off') {
            return [];
        }
        $args = ['netsvc', 'apply', 'mode=' . $v['netsvc_mode']];
        $tftp = $v['netsvc_mode'] === 'tftp' || $v['netsvc_tftp'] === '1';
        $nets = $tftpNetworks;
        if ($v['netsvc_mode'] === 'dhcp') {
            $mask = (int) ip2long($v['netsvc_netmask']);
            $prefix = 32 - (int) round(log((~$mask & 0xFFFFFFFF) + 1, 2));
            // The phone network itself always gets TFTP.
            $nets[] = long2ip((int) ip2long($v['netsvc_range_start']) & $mask) . '/' . $prefix;
            array_push($args,
                'iface=' . $v['netsvc_interface'],
                'start=' . $v['netsvc_range_start'],
                'end=' . $v['netsvc_range_end'],
                'mask=' . $v['netsvc_netmask'],
                'lease=' . $v['netsvc_lease_hours']
            );
            foreach (['gw' => 'netsvc_gateway', 'dns' => 'netsvc_dns', 'ntp' => 'netsvc_ntp'] as $key => $setting) {
                if ($v[$setting] !== '') {
                    $args[] = $key . '=' . $v[$setting];
                }
            }
            if (preg_match('#^https://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?/provision/$#', $url)) {
                $args[] = 'url=' . $url;
            }
        }
        $args[] = 'tftp=' . ($tftp ? '1' : '0');
        if ($tftp) {
            foreach (array_values(array_unique($nets)) as $n) {
                // aipbx-priv takes IPv4 networks only.
                if (preg_match('#^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$#', $n)) {
                    $args[] = 'net=' . $n;
                }
            }
        }
        return $args;
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $interfaces = self::interfaces();
            $tftpNets = self::tftpNetworks();
            $v = self::validate($data, $interfaces, $tftpNets);

            if ($v['netsvc_mode'] === 'dhcp' && empty($data['netsvc_force'])) {
                $others = self::otherDhcpServers($v['netsvc_interface'], $interfaces);
                if ($others !== []) {
                    throw new \Exception(sprintf(t('netsvc.err_other_dhcp'), implode(', ', $others)));
                }
            }

            $res = $v['netsvc_mode'] === 'off'
                ? PrivHelper::run(['netsvc', 'off'])
                : PrivHelper::run(self::privArgs($v, $tftpNets, self::provisioningUrl()));
            if (!$res['success']) {
                throw new \Exception(sprintf(t('netsvc.err_apply'), $res['output']));
            }

            $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($v as $k => $val) {
                $stmt->execute([$k, $val]);
            }
            writeAuditLog('network_services', 'settings', 0, 'Network services: ' . $v['netsvc_mode'], 'update', $_SESSION['user_id'] ?? null);
            return t('netsvc.saved_' . $v['netsvc_mode']);
        });
    }

    // ------------------------------------------------------------ DHCP check

    /**
     * DHCP servers answering on $iface: "offer server=… address=…" lines of
     * aipbx-dhcp-probe.
     *
     * @return array{success: bool, servers: string[], error: string}
     */
    public static function probe(string $iface): array
    {
        $res = PrivHelper::run(['netsvc', 'probe', $iface]);
        if (!$res['success']) {
            return ['success' => false, 'servers' => [], 'error' => $res['output']];
        }
        return ['success' => true, 'servers' => self::parseProbe($res['output']), 'error' => ''];
    }

    /** @return string[] server addresses */
    public static function parseProbe(string $output): array
    {
        preg_match_all('/^offer server=([0-9.]{7,15}) address=[0-9.]{7,15}$/m', $output, $m);
        return array_values(array_unique(array_filter($m[1], fn($ip) => self::isIpv4($ip))));
    }

    /**
     * Other DHCP servers on $iface (this server's own answer left out).
     *
     * @return string[]
     */
    private static function otherDhcpServers(string $iface, array $interfaces): array
    {
        $res = self::probe($iface);
        if (!$res['success']) {
            throw new \Exception(sprintf(t('netsvc.err_probe'), $res['error']));
        }
        $own = array_column($interfaces, 'ip');
        return array_values(array_diff($res['servers'], $own));
    }

    public static function probeAction(string $iface, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($iface) {
            $interfaces = self::interfaces();
            if (!isset($interfaces[$iface])) {
                throw new \Exception(t('netsvc.err_interface'));
            }
            $res = self::probe($iface);
            if (!$res['success']) {
                throw new \Exception(sprintf(t('netsvc.err_probe'), $res['error']));
            }
            $own = array_column($interfaces, 'ip');
            $others = array_values(array_diff($res['servers'], $own));
            if ($others !== []) {
                throw new \Exception(sprintf(t('netsvc.probe_found'), $iface, implode(', ', $others)));
            }
            return sprintf(t('netsvc.probe_none'), $iface);
        });
    }

    public static function status(): string
    {
        $res = PrivHelper::run(['netsvc', 'status']);
        return trim($res['output']) === 'active' ? 'active' : 'inactive';
    }

    // ------------------------------------------------------------ leases

    /**
     * dnsmasq leases ("expiry mac ip hostname client-id" per line), newest first.
     *
     * @return list<array{mac: string, ip: string, hostname: string, vendor: string, expires: int}>
     */
    public static function leases(?string $file = null): array
    {
        $file ??= self::leaseFile();
        $text = is_readable($file) ? (string) @file_get_contents($file) : '';
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $f = preg_split('/\s+/', trim($line));
            if ($f === false || count($f) < 4) {
                continue;
            }
            $mac = PhoneModels::normalizeMac($f[1]);
            if ($mac === '' || !self::isIpv4($f[2])) {
                continue;
            }
            $out[] = [
                'mac' => $mac,
                'ip' => $f[2],
                'hostname' => $f[3] === '*' ? '' : mb_substr($f[3], 0, 64),
                'vendor' => PhoneModels::guessVendor($mac, ''),
                'expires' => (int) $f[0],
            ];
        }
        usort($out, fn($a, $b) => $b['expires'] <=> $a['expires']);
        return $out;
    }

    /**
     * Phones among the leases that are not known yet become waiting phones
     * (PBX → Phones), so they can be assigned before they ask for a file.
     */
    public static function syncLeasesToWaiting(?array $leases = null): int
    {
        $leases ??= self::leases();
        $db = getDB();
        $known = $db->prepare('SELECT 1 FROM pbx_phones WHERE mac = ?');
        $ins = $db->prepare(
            "INSERT INTO pbx_phones_waiting (mac, vendor, ip, user_agent, request_count) VALUES (?, ?, ?, 'DHCP', 0)
             ON DUPLICATE KEY UPDATE ip = VALUES(ip), vendor = IF(vendor = '', VALUES(vendor), vendor)"
        );
        $n = 0;
        foreach ($leases as $l) {
            if ($l['vendor'] === '') {
                continue;
            }
            $known->execute([$l['mac']]);
            if ($known->fetchColumn()) {
                continue;
            }
            $ins->execute([$l['mac'], $l['vendor'], $l['ip']]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------ DHCP options for an existing server

    /**
     * What to set on the company's own DHCP server (TFTP-only mode).
     *
     * @return list<array{option: string, value: string, for: string}>
     */
    public static function dhcpOptionHints(string $url, string $serverIp): array
    {
        return [
            ['option' => '66', 'value' => $url, 'for' => 'Yealink, Grandstream, Fanvil, Snom'],
            ['option' => '160', 'value' => $url, 'for' => 'Poly'],
            ['option' => '150', 'value' => $serverIp, 'for' => 'Cisco SPA (TFTP)'],
            ['option' => '42', 'value' => t('netsvc.hint_ntp'), 'for' => t('netsvc.hint_all_phones')],
        ];
    }

    // ------------------------------------------------------------ TFTP files

    /** @return list<array{name: string, size: int, mtime: int}> */
    public static function tftpFiles(): array
    {
        $out = [];
        foreach (glob(self::tftpDir() . '/*') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                $out[] = ['name' => basename($path), 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Allowed TFTP file name. Names that carry a MAC address are refused: those
     * are per-phone configuration files, which hold the SIP password and are
     * served over HTTPS only.
     */
    public static function validTftpName(string $name): bool
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name)) {
            return false;
        }
        // Shared files with a 12-digit name that starts with zeros stay allowed
        // (Yealink y000000000108.cfg, Poly 000000000000.cfg): no MAC looks like that.
        preg_match_all('/[0-9a-f]{12}/i', str_replace([':', '-'], '', $name), $m);
        foreach ($m[0] as $hex) {
            if (!str_starts_with($hex, '00000000')) {
                return false;
            }
        }
        return true;
    }

    public static function uploadTftp(?array $file, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($file) {
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new \Exception(t('netsvc.err_upload'));
            }
            $name = basename((string) ($file['name'] ?? ''));
            if (!self::validTftpName($name)) {
                throw new \Exception(t('netsvc.err_tftp_name'));
            }
            if ((int) ($file['size'] ?? 0) > self::MAX_TFTP_FILE) {
                throw new \Exception(t('netsvc.err_tftp_size'));
            }
            $dir = self::tftpDir();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new \Exception(t('netsvc.err_upload'));
            }
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                throw new \Exception(t('netsvc.err_upload'));
            }
            @chmod($dir . '/' . $name, 0644);
            writeAuditLog('network_services', 'tftp_file', 0, 'TFTP file: ' . $name, 'create', $_SESSION['user_id'] ?? null);
            return sprintf(t('netsvc.tftp_uploaded'), $name);
        });
    }

    public static function deleteTftp(string $name, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($name) {
            $path = self::tftpDir() . '/' . $name;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name) || !is_file($path) || is_link($path) || !@unlink($path)) {
                throw new \Exception(t('netsvc.err_tftp_delete'));
            }
            writeAuditLog('network_services', 'tftp_file', 0, 'TFTP file: ' . $name, 'delete', $_SESSION['user_id'] ?? null);
            return sprintf(t('netsvc.tftp_deleted'), $name);
        });
    }

    // ------------------------------------------------------------ helpers

    private static function isIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /** @return string[] up to 3 IPv4 addresses from a comma/space separated text */
    private static function ipList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($text)) ?: [] as $ip) {
            if ($ip === '') {
                continue;
            }
            if (!self::isIpv4($ip)) {
                throw new \Exception(sprintf(t('netsvc.err_address'), $ip));
            }
            $out[] = $ip;
        }
        if (count($out) > 3) {
            throw new \Exception(t('netsvc.err_too_many'));
        }
        return array_values(array_unique($out));
    }
}
