<?php
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../priv_helper.php';
require_once __DIR__ . '/NetworkSettingsService.php';

/**
 * Firewall (firewalld) management service
 * Runs `firewall-cmd` as root through PrivHelper (the aipbx-priv `fw`
 * subcommands) — no direct sudo call.
 */
class FirewallService {

    /**
     * These ports can NEVER be removed — closing SSH (22), the web panel
     * (80/443) or SIP signalling (5060) could lock the admin out of their own
     * access/the PBX. Protected regardless of protocol (both tcp and udp).
     */
    const PROTECTED_PORTS = ['22', '80', '443', '5060'];

    /**
     * Additionally protected ranges: the RTP audio ports — closing them cuts
     * the audio on every call (added in the 2026-08-31 audit).
     */
    const PROTECTED_RANGES = [[10000, 20000]];

    /**
     * Service-name equivalents of PROTECTED_PORTS — a rich rule can open a
     * port by firewalld service name too (`service name="ssh"`), not only by
     * number.
     */
    const PROTECTED_SERVICES = ['ssh', 'http', 'https', 'sip', 'sips'];

    /**
     * Does the given port or range ("22" or "20-30") cover a protected port/
     * range? Only exact matches used to be checked; a range rule such as
     * "20-30/tcp" could be removed although it covered SSH.
     */
    private static function coversProtectedPort(string $port): bool {
        $parts = explode('-', $port, 2);
        $from = (int) $parts[0];
        $to = isset($parts[1]) ? (int) $parts[1] : $from;
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        foreach (self::PROTECTED_PORTS as $p) {
            if ((int) $p >= $from && (int) $p <= $to) return true;
        }
        foreach (self::PROTECTED_RANGES as [$rf, $rt]) {
            if ($from <= $rt && $to >= $rf) return true; // ranges overlap
        }
        return false;
    }

    /**
     * Returns whether the given port number, range or rich rule is protected
     * (to show a lock badge in the UI).
     */
    public static function isProtected(string $portOrRule): bool {
        if (preg_match('/port="([0-9\-]+)"/', $portOrRule, $m)) {
            return self::coversProtectedPort($m[1]);
        }
        if (preg_match('/service name="([a-zA-Z0-9_-]+)"/', $portOrRule, $m)) {
            return in_array(strtolower($m[1]), self::PROTECTED_SERVICES, true);
        }
        return self::coversProtectedPort($portOrRule);
    }

    /**
     * The same `fw` operation live (effective at once) and with --permanent
     * (survives a reload): one for now, one for later.
     */
    private static function runLiveAndPermanent(array $args): array {
        $r1 = PrivHelper::run(array_merge(['fw'], $args));
        $r2 = PrivHelper::run(array_merge(['fw'], $args, ['--permanent']));
        return [
            'success' => $r1['success'] && $r2['success'],
            'output' => trim($r1['output'] . ' ' . $r2['output']),
        ];
    }

    /**
     * Turns the current zone state (active ports + rich rules) into a
     * structured array. Parses the text output of `firewall-cmd --list-all`
     * line by line (not an official/stable API, but the format has not changed
     * across firewalld versions for a long time).
     */
    public static function getStatus(): array {
        // NOTE: read commands MUST run as root too — firewall-cmd returns
        // "Authorization failed" for a non-root user; a direct call as the
        // web user yields an EMPTY list (found in the 2026-08-31 audit; it was
        // missed because the CLI tests run as root).
        $zone_out = PrivHelper::run(['fw', 'get-default-zone'])['output'];
        $zone = trim($zone_out) ?: 'public';

        $list = PrivHelper::run(['fw', 'list-all'])['output'];

        $ports = [];
        if (preg_match('/^\s*ports:\s*(.*)$/m', $list, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $p) {
                if ($p === '') continue;
                [$num, $proto] = array_pad(explode('/', $p), 2, 'tcp');
                $ports[] = ['port' => $num, 'protocol' => $proto];
            }
        }

        $rich_rules = [];
        if (preg_match('/rich rules:\s*\n(.*)$/s', $list, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $rich_rules[] = $line;
            }
        }

        return [
            'zone' => $zone,
            'active' => (bool) firewalld_is_active(),
            'ports' => $ports,
            'rich_rules' => $rich_rules,
        ];
    }

    /** Ports of the firewalld services a zone usually holds, to show them like any other rule. */
    const SERVICE_PORTS = [
        'ssh' => ['22', 'tcp'], 'http' => ['80', 'tcp'], 'https' => ['443', 'tcp'],
        'sip' => ['5060', ''], 'sips' => ['5061', ''], 'dhcp' => ['67', 'udp'],
        'dhcpv6-client' => ['546', 'udp'], 'tftp' => ['69', 'udp'], 'ntp' => ['123', 'udp'],
    ];

    /** What a port is for, in the words of the page. Key: port or range. */
    const PORT_USES = [
        '21' => 'FTP', '22' => 'SSH', '67' => 'DHCP', '69' => 'TFTP', '80' => 'Web', '123' => 'NTP',
        '443' => 'Web', '514' => 'Syslog', '546' => 'DHCPv6', '3478' => 'TURN', '5349' => 'TURN TLS',
        '4100-4999' => 'T.38', '5060' => 'SIP', '5061' => 'SIP TLS', '10000-20000' => 'RTP',
        '40000-40049' => 'FTP', '49152-65535' => 'TURN',
    ];

    /** Web and SSH: closing them on the card you are connected through locks you out. */
    const ACCESS_PORTS = ['22', '80', '443'];

    /** Parses one zone's `--list-all` text. */
    public static function parseZone(string $list): array {
        $target = preg_match('/^\s*target:\s*(\S+)/m', $list, $m) ? $m[1] : 'default';
        $services = preg_match('/^\s*services:[ \t]*(.*)$/m', $list, $m) ? preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) : [];
        $ports = preg_match('/^\s*ports:[ \t]*(.*)$/m', $list, $m) ? preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) : [];
        $rich = [];
        if (preg_match('/rich rules:[ \t]*\n(.*)$/s', $list, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (trim($line) !== '') {
                    $rich[] = trim($line);
                }
            }
        }
        $rules = [];
        foreach ($services as $name) {
            [$port, $proto] = self::SERVICE_PORTS[$name] ?? ['', ''];
            $rules[] = ['kind' => 'service', 'value' => $name, 'port' => $port, 'protocol' => $proto, 'source' => '', 'use' => self::PORT_USES[$port] ?? $name];
        }
        foreach ($ports as $spec) {
            [$port, $proto] = array_pad(explode('/', $spec, 2), 2, 'tcp');
            $rules[] = ['kind' => 'port', 'value' => $spec, 'port' => $port, 'protocol' => $proto, 'source' => '', 'use' => self::PORT_USES[$port] ?? ''];
        }
        foreach ($rich as $rule) {
            $plain = preg_match('/^rule family="ipv4" source address="([^"]+)" port port="([0-9-]+)" protocol="(tcp|udp)" accept$/', $rule, $m) === 1;
            $rules[] = [
                'kind' => 'rich', 'value' => $rule,
                'port' => $plain ? $m[2] : '', 'protocol' => $plain ? $m[3] : '', 'source' => $plain ? $m[1] : '',
                // Anything else (log, reject, a service...) is shown as written.
                'use' => $plain ? (self::PORT_USES[$m[2]] ?? '') : '', 'custom' => !$plain,
            ];
        }
        usort($rules, static fn(array $a, array $b): int => [(int) $a['port'], $a['protocol'], $a['source']] <=> [(int) $b['port'], $b['protocol'], $b['source']]);
        return ['target' => $target, 'rules' => $rules];
    }

    /**
     * Parses `fw overview`: the default zone, each interface's zone, each zone's rules.
     *
     * @return array{default: string, ifaces: array<string,string>, zones: array<string,array>}
     */
    public static function parseOverview(string $text): array {
        $default = 'public';
        $ifaces = [];
        $zones = [];
        $current = null;
        $body = '';
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^### zone (\S+)$/', $line, $m)) {
                if ($current !== null) {
                    $zones[$current] = self::parseZone($body);
                }
                $current = $m[1];
                $body = '';
            } elseif ($current !== null) {
                $body .= $line . "\n";
            } elseif (preg_match('/^default (\S+)$/', $line, $m)) {
                $default = $m[1];
            } elseif (preg_match('/^iface (\S+) (\S+)$/', $line, $m)) {
                $ifaces[$m[1]] = $m[2];
            }
        }
        if ($current !== null) {
            $zones[$current] = self::parseZone($body);
        }
        return ['default' => $default, 'ifaces' => $ifaces, 'zones' => $zones];
    }

    /**
     * Every interface with its rules, network cards first. Each rule says what removing it can do.
     *
     * @param array{default: string, ifaces: array<string,string>, zones: array<string,array>} $overview
     * @param array<string,list<string>> $ips interface => addresses
     */
    public static function buildCards(array $overview, array $ips, ?string $sessionIface): array {
        $real = [];
        $others = [];
        foreach ($overview['ifaces'] as $iface => $zone) {
            $z = $overview['zones'][$zone] ?? ['target' => 'default', 'rules' => []];
            $card = [
                'iface' => $iface, 'ips' => $ips[$iface] ?? [], 'zone' => $zone,
                'own' => $zone !== $overview['default'],
                'removable_zone' => preg_match('/^aipbx-card[0-9]+$/', $zone) === 1,
                // A zone that lets everything in (trusted) has no rules to manage.
                'open_all' => $z['target'] === 'ACCEPT',
                'rules' => $z['rules'],
            ];
            if (NetworkSettingsService::isVirtualInterface($iface)) {
                $card['virtual'] = true;
                $others[] = $card;
            } else {
                $real[] = $card;
            }
        }
        foreach ($real as $i => $card) {
            foreach ($card['rules'] as $j => $rule) {
                $real[$i]['rules'][$j]['warn'] = self::removalWarning($rule, $card, $real, $sessionIface);
            }
        }
        foreach ($others as $i => $card) {
            foreach ($card['rules'] as $j => $rule) {
                $others[$i]['rules'][$j]['warn'] = '';
            }
        }
        return ['cards' => array_merge($real, $others)];
    }

    /**
     * What removing this rule can do, to say before it is removed. Nothing here stops the admin.
     * 'session': this connection may be cut. 'last': no other card offers the port.
     *
     * @param list<array> $cards the network cards, without virtual interfaces
     */
    private static function removalWarning(array $rule, array $card, array $cards, ?string $sessionIface): string {
        $critical = $rule['kind'] === 'service' && in_array(strtolower($rule['value']), self::PROTECTED_SERVICES, true);
        if (!$critical && ($rule['port'] === '' || !self::coversProtectedPort($rule['port']))) {
            return '';
        }
        if (in_array($rule['port'], self::ACCESS_PORTS, true)
            && ($sessionIface === null || $sessionIface === $card['iface'])) {
            return 'session';
        }
        foreach ($cards as $other) {
            // A card without an address serves nobody, whatever its rules say.
            if ($other['iface'] === $card['iface'] || $other['ips'] === []) {
                continue;
            }
            if ($other['open_all']) {
                return '';
            }
            foreach ($other['rules'] as $r) {
                if ($r['port'] === $rule['port'] && $r['protocol'] === $rule['protocol'] && $r['source'] === '') {
                    return '';
                }
            }
        }
        return 'last';
    }

    /** The interface this session reaches the server through; null when it cannot be told. */
    private static function sessionIface(): ?string {
        $ip = function_exists('getClientIP') ? getClientIP() : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.')) {
            return null;
        }
        $out = (string) @shell_exec('ip -o route get ' . escapeshellarg($ip) . ' 2>/dev/null');
        return preg_match('/\bdev (\S+)/', $out, $m) ? $m[1] : null;
    }

    /** @return array<string,int> interface => phones registered through it right now */
    private static function phonesByIface(): array {
        $out = (string) AsteriskHelper::execCLI('pjsip show contacts')['output'];
        preg_match_all('/sip:[^@\s]*@(\d+\.\d+\.\d+\.\d+)/', $out, $m);
        $by = [];
        foreach (array_count_values($m[1]) as $ip => $n) {
            if (preg_match('/\bdev (\S+)/', (string) @shell_exec('ip -o route get ' . escapeshellarg((string) $ip) . ' 2>/dev/null'), $d)) {
                $by[$d[1]] = ($by[$d[1]] ?? 0) + $n;
            }
        }
        return $by;
    }

    /** Would closing this rule on a card cut phones off? For the confirmation. */
    public static function isCallPort(string $port): bool {
        return in_array($port, ['5060', '5061', '10000-20000'], true);
    }

    /** Everything the page shows. */
    public static function overview(): array {
        $res = PrivHelper::run(['fw', 'overview']);
        $overview = self::parseOverview($res['success'] ? (string) $res['output'] : '');
        $built = self::buildCards($overview, NetworkSettingsService::interfaceAddresses(), self::sessionIface());
        $phones = self::phonesByIface();
        foreach ($built['cards'] as $i => $card) {
            $built['cards'][$i]['phones'] = $phones[$card['iface']] ?? 0;
        }
        return $built + ['active' => (bool) firewalld_is_active(), 'default_zone' => $overview['default']];
    }

    /**
     * The zone to change for this card. While it shares a zone with others (or the default one)
     * it first gets its own copy, so the change reaches this card only. With one card there is
     * nothing to separate.
     */
    private static function zoneForChange(array $state, string $iface): ?string {
        foreach ($state['cards'] as $card) {
            if ($card['iface'] !== $iface) {
                continue;
            }
            $sharers = count(array_filter($state['cards'], static fn(array $c): bool => $c['zone'] === $card['zone']));
            if ($sharers === 1 || count(array_filter($state['cards'], static fn(array $c): bool => empty($c['virtual']))) < 2) {
                return $card['zone'];
            }
            $res = PrivHelper::run(['fw', 'split', $iface]);
            $zone = trim((string) $res['output']);
            return $res['success'] && preg_match('/^aipbx-card[0-9]+$/', $zone) ? $zone : null;
        }
        return null;
    }

    /**
     * Adds a rule on one card, or on all of them ($target 'all'). With a source only that source
     * may use the port; without one it is open to everyone on that card.
     */
    public static function addRule(string $port, string $protocol, string $source, string $target, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $port = trim($port);
        $protocol = in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp';
        $source = trim($source);
        if (!preg_match('/^[0-9]{1,5}(-[0-9]{1,5})?$/', $port)) {
            return ['success' => false, 'error' => t('srv_fw.err_port')];
        }
        if ($source !== '' && !preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', $source)) {
            return ['success' => false, 'error' => t('srv_fw.err_subnet')];
        }
        if ($source !== '' && !filter_var(explode('/', $source)[0], FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => t('srv_fw.err_ip')];
        }

        $state = self::overview();
        if ($target === 'all') {
            // The default zone too, so a card added later has the rule as well.
            $zones = [$state['default_zone']];
            foreach ($state['cards'] as $card) {
                // Not tunnels and bridges: a VPN zone opens only what the VPN needs.
                if (!$card['open_all'] && empty($card['virtual'])) {
                    $zones[] = $card['zone'];
                }
            }
            $zones = array_values(array_unique($zones));
        } else {
            $zone = self::zoneForChange($state, $target);
            if ($zone === null) {
                return ['success' => false, 'error' => t('srv_fw.err_card')];
            }
            $zones = [$zone];
        }
        $args = $source !== '' ? ['add-source-rule', $source, $port, $protocol] : ['add-port', $port, $protocol];
        foreach ($zones as $zone) {
            $res = self::runLiveAndPermanent(array_merge(['zone', $zone], $args));
            if (!$res['success']) {
                return ['success' => false, 'error' => sprintf(t('srv_fw.err_add'), $res['output'])];
            }
        }
        $what = "{$port}/{$protocol}" . ($source !== '' ? " ({$source})" : '');
        writeAuditLog(null, 'firewall', $port, "Firewall rule added on {$target}: {$what}", 'create', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => sprintf(t('srv_fw.added'), $what)];
    }

    /** Removes one rule from one card. The rule is looked up again, so the lock is the server's. */
    public static function removeRule(string $iface, string $kind, string $value, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $state = self::overview();
        $found = null;
        foreach ($state['cards'] as $card) {
            if ($card['iface'] !== $iface) {
                continue;
            }
            foreach ($card['rules'] as $rule) {
                if ($rule['kind'] === $kind && $rule['value'] === $value) {
                    $found = $rule;
                }
            }
        }
        if ($found === null) {
            return ['success' => false, 'error' => t('srv_fw.err_rule')];
        }
        $zone = self::zoneForChange($state, $iface);
        if ($zone === null) {
            return ['success' => false, 'error' => t('srv_fw.err_card')];
        }
        $args = match ($kind) {
            'service' => ['remove-service', $value],
            'port' => ['remove-port', $found['port'], $found['protocol']],
            default => ['remove-rich-rule', $value],
        };
        $res = self::runLiveAndPermanent(array_merge(['zone', $zone], $args));
        if (!$res['success']) {
            return ['success' => false, 'error' => sprintf(t('srv_fw.err_remove'), $res['output'])];
        }
        writeAuditLog(null, 'firewall', $iface, "Firewall rule removed on {$iface}: {$value}", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('srv_fw.removed')];
    }

    /** The card goes back to the general rules; its own rules are dropped. */
    public static function useGeneralRules(string $iface, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        foreach (self::overview()['cards'] as $card) {
            if ($card['iface'] === $iface && $card['removable_zone']) {
                $res = PrivHelper::run(['fw', 'merge', $iface]);
                if (!$res['success']) {
                    return ['success' => false, 'error' => sprintf(t('srv_fw.err_remove'), $res['output'])];
                }
                writeAuditLog(null, 'firewall', $iface, "Firewall: {$iface} back on the general rules", 'update', $_SESSION['user_id'] ?? null);
                return ['success' => true, 'message' => sprintf(t('firewall.general_done'), $iface)];
            }
        }
        return ['success' => false, 'error' => t('srv_fw.err_card')];
    }
}

/**
 * Is the firewalld service really active (read-only, no sudo needed).
 */
function firewalld_is_active(): bool {
    $out = shell_exec('systemctl is-active firewalld 2>&1');
    return trim((string) $out) === 'active';
}
