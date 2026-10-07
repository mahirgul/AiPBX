<?php
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../priv_helper.php';

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
     * Applies the same `fw` operation first live (effective at once without a
     * reload), then with --permanent — both are needed (one for now, one for
     * persistence).
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

    /**
     * Adds a new port rule. With $sourceSubnet set (e.g. "192.0.2.0/24") it
     * generates a rich rule that opens traffic ONLY from that source (safer,
     * closed outside the campus); without it, a general --add-port rule open
     * to everyone. Applied first live (effective at once without a reload),
     * then with --permanent — both are needed (one for now, one for
     * persistence).
     */
    public static function addPortRule(string $port, string $protocol, string $sourceSubnet, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $port = preg_replace('/[^0-9\-]/', '', trim($port));
        $protocol = in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp';
        $sourceSubnet = trim($sourceSubnet);

        if ($port === '' || !preg_match('/^[0-9]+(-[0-9]+)?$/', $port)) {
            return ['success' => false, 'error' => t('srv_fw.err_port')];
        }
        // Validate the full format: only the IP before "/" used to be checked,
        // the CIDR suffix and characters such as quotes were free — e.g.
        // `1.2.3.4/32" accept` passed validation and could break the rich-rule
        // syntax (found in the 2026-08-31 audit).
        if ($sourceSubnet !== '' && !preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', $sourceSubnet)) {
            return ['success' => false, 'error' => t('srv_fw.err_subnet')];
        }
        if ($sourceSubnet !== '' && !filter_var(explode('/', $sourceSubnet)[0], FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => t('srv_fw.err_ip')];
        }

        // The source-restricted rule is generated as a rich rule by aipbx-priv:
        // `rule family="ipv4" source address="…" port port="…"
        // protocol="…" accept`.
        $res = $sourceSubnet !== ''
            ? self::runLiveAndPermanent(['add-source-rule', $sourceSubnet, $port, $protocol])
            : self::runLiveAndPermanent(['add-port', $port, $protocol]);

        if (!$res['success']) {
            return ['success' => false, 'error' => sprintf(t('srv_fw.err_add'), $res['output'])];
        }
        writeAuditLog(null, 'firewall', $port, "Firewall rule added: {$port}/{$protocol}" . ($sourceSubnet !== '' ? " (source: {$sourceSubnet})" : ' (any)'), 'create', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => sprintf(t('srv_fw.added'), "{$port}/{$protocol}" . ($sourceSubnet !== '' ? " ({$sourceSubnet})" : ''))];
    }

    /**
     * Removes a general (non-rich-rule) --port entry. Protected ports
     * (PROTECTED_PORTS) can never be removed.
     */
    public static function removePortRule(string $port, string $protocol, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $port = preg_replace('/[^0-9\-]/', '', trim($port));
        $protocol = in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp';

        if (self::coversProtectedPort($port)) {
            return ['success' => false, 'error' => t('srv_fw.err_critical_port')];
        }

        $res = self::runLiveAndPermanent(['remove-port', $port, $protocol]);

        if (!$res['success']) {
            return ['success' => false, 'error' => sprintf(t('srv_fw.err_remove'), $res['output'])];
        }
        writeAuditLog(null, 'firewall', $port, "Firewall rule removed: {$port}/{$protocol}", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => sprintf(t('srv_fw.removed_port'), "{$port}/{$protocol}")];
    }

    /**
     * Removes a rich rule (by exact text match). Rich rules covering a
     * protected port (22/80/443/5060) are blocked ON PURPOSE as well.
     */
    public static function removeRichRule(string $rule, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $rule = trim($rule);
        if ($rule === '') {
            return ['success' => false, 'error' => t('srv_fw.err_rule')];
        }
        // The port value inside a rich rule can be a range too ("10000-20000")
        // — coversProtectedPort() also checks range overlap (2026-08-31 audit:
        // only exact matches used to be checked, so a rule removing the RTP
        // range was not blocked).
        if (preg_match('/port="([0-9\-]+)"/', $rule, $m) && self::coversProtectedPort($m[1])) {
            return ['success' => false, 'error' => t('srv_fw.err_critical_port')];
        }
        // A rich rule can also name the port BY SERVICE NAME
        // (`service name="ssh"`) — then the port check above never matches and
        // a critical access rule could be removed. No such rule exists live
        // right now; forward-looking defence (2026-08-31).
        if (preg_match('/service name="([a-zA-Z0-9_-]+)"/', $rule, $m)
            && in_array(strtolower($m[1]), self::PROTECTED_SERVICES, true)) {
            return ['success' => false, 'error' => sprintf(t('srv_fw.err_critical_service'), $m[1])];
        }

        $res = self::runLiveAndPermanent(['remove-rich-rule', $rule]);

        if (!$res['success']) {
            return ['success' => false, 'error' => sprintf(t('srv_fw.err_remove_rule'), $res['output'])];
        }
        writeAuditLog(null, 'firewall', 'rich-rule', "Firewall rich rule removed: {$rule}", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('srv_fw.removed')];
    }
}

/**
 * Is the firewalld service really active (read-only, no sudo needed).
 */
function firewalld_is_active(): bool {
    $out = shell_exec('systemctl is-active firewalld 2>&1');
    return trim((string) $out) === 'active';
}
