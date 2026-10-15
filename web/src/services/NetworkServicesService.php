<?php
/**
 * PBX → Network services (roadmap item 10): DHCP and TFTP for desk phones,
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
 *
 * FTP (netsvc_ftp, independent of the mode): vsftpd serves the same folder
 * read-only, from the same networks as TFTP, to anonymous logins, to the fixed
 * user NECSDT700 without a password (NEC DT700/DT800 phones log in with it)
 * and to extra FTP users added on the page (netsvc_ftp_users: name + SHA-512
 * hash; aipbx-priv gets only the hashes). None of them is a system account.
 *
 * Extra DHCP options (netsvc_dhcp_options, "code,value" per line) are added to
 * the ones AiPBX hands out; the same code replaces AiPBX's own (66, 160, 150,
 * 42, 3, 6). aipbx-priv checks them again: a dnsmasq file can run commands.
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
        'netsvc_ftp' => '0',
        'netsvc_ftp_users' => '[]',
        'netsvc_dhcp_options' => '',
    ];

    /** FTP user of NEC DT700/DT800 phones: always there while FTP is on, no password. */
    public const FTP_FIXED_USER = 'NECSDT700';

    public const MAX_FTP_USERS = 20;
    public const MAX_DHCP_OPTIONS = 20;

    /** Characters allowed in a DHCP option value (no quotes, #, backslash or line breaks). */
    private const DHCP_VALUE = '/^[A-Za-z0-9 .:\/_@,+=%~!$&()*;?-]{1,200}$/';

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
        return PhoneProvisionService::baseUrl() . '/provision/';
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
    public static function validate(array $data, array $interfaces, array $tftpNetworks, array $ftpUsers = []): array
    {
        // The page has two switches; netsvc_mode is still what is stored.
        if (!isset($data['netsvc_mode'])) {
            $data['netsvc_mode'] = !empty($data['netsvc_dhcp_on']) ? 'dhcp' : (!empty($data['netsvc_tftp']) ? 'tftp' : 'off');
        }
        $mode = (string) $data['netsvc_mode'];
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
        $v['netsvc_ftp'] = !empty($data['netsvc_ftp']) ? '1' : '0';
        $v['netsvc_ftp_users'] = json_encode(self::ftpUsersFromForm($data, $ftpUsers));
        $v['netsvc_dhcp_options'] = implode("\n", array_map(fn($o) => $o[0] . ',' . $o[1], self::dhcpOptions((string) ($data['netsvc_dhcp_options'] ?? ''))));
        if ($v['netsvc_ftp'] === '1' && $tftpNetworks === [] && $mode !== 'dhcp') {
            throw new \Exception(t('netsvc.err_tftp_networks'));
        }

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
        $nets = self::serviceNetworks($v, $tftpNetworks);
        if ($v['netsvc_mode'] === 'dhcp') {
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
            foreach (self::dhcpOptions($v['netsvc_dhcp_options']) as [$code, $value]) {
                $args[] = 'opt=' . $code . ',' . $value;
            }
        }
        $args[] = 'tftp=' . ($tftp ? '1' : '0');
        if ($tftp) {
            foreach ($nets as $n) {
                $args[] = 'net=' . $n;
            }
        }
        return $args;
    }

    /**
     * Networks TFTP and FTP answer: the provisioning allowed networks, plus the
     * phone network itself when this server hands out its addresses. IPv4 only
     * (what aipbx-priv takes).
     *
     * @param array<string, string> $v validated settings
     * @return string[]
     */
    public static function serviceNetworks(array $v, array $tftpNetworks): array
    {
        $nets = $tftpNetworks;
        if ($v['netsvc_mode'] === 'dhcp' && self::isIpv4($v['netsvc_range_start']) && self::isIpv4($v['netsvc_netmask'])) {
            $mask = (int) ip2long($v['netsvc_netmask']);
            $prefix = 32 - (int) round(log((~$mask & 0xFFFFFFFF) + 1, 2));
            $nets[] = long2ip((int) ip2long($v['netsvc_range_start']) & $mask) . '/' . $prefix;
        }
        return array_values(array_filter(array_unique($nets),
            fn($n) => (bool) preg_match('#^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$#', $n)));
    }

    /**
     * Arguments for `aipbx-priv netsvc ftp-on` / `ftp-off`: the extra users as
     * name:SHA-512-hash (NECSDT700 needs none: PAM lets it in without a password).
     *
     * @param array<string, string> $v validated settings
     * @return string[]
     */
    public static function ftpArgs(array $v, array $tftpNetworks): array
    {
        if ($v['netsvc_ftp'] !== '1') {
            return ['netsvc', 'ftp-off'];
        }
        $args = ['netsvc', 'ftp-on'];
        foreach (self::ftpUsers($v['netsvc_ftp_users']) as $u) {
            $args[] = 'user=' . $u['user'] . ':' . $u['hash'];
        }
        foreach (self::serviceNetworks($v, $tftpNetworks) as $n) {
            $args[] = 'net=' . $n;
        }
        return $args;
    }

    /** @return list<array{user: string, hash: string}> stored extra FTP users */
    public static function ftpUsers(string $json): array
    {
        $out = [];
        foreach ((array) json_decode($json, true) as $u) {
            if (is_array($u) && preg_match('/^[A-Za-z0-9._-]{1,32}$/', (string) ($u['user'] ?? ''))
                && preg_match('/^\$6\$[A-Za-z0-9.\/]{1,16}\$[A-Za-z0-9.\/]{86}$/', (string) ($u['hash'] ?? ''))) {
                $out[] = ['user' => (string) $u['user'], 'hash' => (string) $u['hash']];
            }
        }
        return $out;
    }

    /**
     * The extra FTP users after the form: ticked ones removed, a new one added
     * (or an existing one given a new password).
     *
     * @param list<array{user: string, hash: string}> $current
     * @return list<array{user: string, hash: string}>
     */
    private static function ftpUsersFromForm(array $data, array $current): array
    {
        $remove = array_map('strval', (array) ($data['netsvc_ftp_remove'] ?? []));
        $users = array_values(array_filter($current, fn($u) => !in_array($u['user'], $remove, true)));
        $name = trim((string) ($data['netsvc_ftp_new_user'] ?? ''));
        $password = (string) ($data['netsvc_ftp_new_password'] ?? '');
        if ($name === '' && $password === '') {
            return $users;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $name) || in_array(strtolower($name), ['anonymous', 'ftp', strtolower(self::FTP_FIXED_USER)], true)) {
            throw new \Exception(t('netsvc.err_ftp_user'));
        }
        if (!preg_match('/^[\x21-\x7E]{4,64}$/', $password)) {
            throw new \Exception(t('netsvc.err_ftp_password'));
        }
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
        $users = array_values(array_filter($users, fn($u) => $u['user'] !== $name));
        $users[] = ['user' => $name, 'hash' => crypt($password, '$6$' . $salt . '$')];
        if (count($users) > self::MAX_FTP_USERS) {
            throw new \Exception(sprintf(t('netsvc.err_ftp_too_many'), self::MAX_FTP_USERS));
        }
        return $users;
    }

    /**
     * Extra DHCP options from the text field: "code,value" per line (empty
     * lines and "# comments" skipped), code 1-254.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function dhcpOptions(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([0-9]{1,3})\s*,\s*(.+)$/', $line, $m) || (int) $m[1] < 1 || (int) $m[1] > 254
                || !preg_match(self::DHCP_VALUE, trim($m[2]))) {
                throw new \Exception(sprintf(t('netsvc.err_dhcp_option'), $n + 1));
            }
            $out[] = [(int) $m[1], trim($m[2])];
        }
        if (count($out) > self::MAX_DHCP_OPTIONS) {
            throw new \Exception(sprintf(t('netsvc.err_dhcp_option_count'), self::MAX_DHCP_OPTIONS));
        }
        return $out;
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $interfaces = self::interfaces();
            $tftpNets = self::tftpNetworks();
            $v = self::validate($data, $interfaces, $tftpNets, self::ftpUsers((string) getSystemSetting('netsvc_ftp_users', '[]')));

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
            if ($v['netsvc_ftp'] === '1' || getSystemSetting('netsvc_ftp', '0') === '1') {
                $res = PrivHelper::run(self::ftpArgs($v, $tftpNets));
                if (!$res['success']) {
                    throw new \Exception(sprintf(t('netsvc.err_apply'), $res['output']));
                }
            }

            $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($v as $k => $val) {
                $stmt->execute([$k, $val]);
            }
            writeAuditLog('network_services', 'settings', 0, 'Network services: ' . $v['netsvc_mode'] . ($v['netsvc_mode'] === 'dhcp' ? ' (TFTP ' . ($v['netsvc_tftp'] === '1' ? 'on' : 'off') . ')' : '') . ', FTP ' . ($v['netsvc_ftp'] === '1' ? 'on (' . count(self::ftpUsers($v['netsvc_ftp_users'])) . ' extra users)' : 'off'), 'update', $_SESSION['user_id'] ?? null);
            return t('netsvc.saved_' . $v['netsvc_mode'] . ($v['netsvc_mode'] === 'dhcp' && $v['netsvc_tftp'] === '1' ? '_tftp' : ''))
                . ($v['netsvc_ftp'] === '1' ? ' ' . sprintf(t('netsvc.saved_ftp'), self::FTP_FIXED_USER) : '');
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

    public static function ftpStatus(): string
    {
        $res = PrivHelper::run(['netsvc', 'ftp-status']);
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
