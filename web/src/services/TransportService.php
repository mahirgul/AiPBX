<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/asterisk_helper.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';
require_once __DIR__ . '/FirewallService.php';

/**
 * PBX → Transports: the addresses and ports Asterisk listens on for SIP.
 *
 * Built-in UDP, TCP and TLS live in pjsipsettings, as they always have. WS and WSS are shown but not
 * changed: web phones reach them through the web server. An added transport is a row of
 * pbx_transports. Asterisk keeps an open socket until it restarts (measured), so every change here
 * asks for a restart, which stays the administrator's choice because it ends calls.
 */
final class TransportService
{
    public const PROTOCOLS = ['udp', 'tcp', 'tls'];
    private const CHANGED_SETTING = 'transports_changed_at';

    /** Ports other services of AiPBX use. Ranges of settings are checked apart. */
    private const RESERVED = [
        21, 22, 25, 53, 67, 69, 80, 123, 443, 514, 3306, 3478, 5038, 5349, 8088, 8089,
    ];

    /** Protocol => the keywords of a built-in transport in pjsipsettings. */
    private const BUILTIN = [
        'udp' => ['bindaddr', 'bindport', null],
        'tcp' => ['tcp_bindaddr', 'tcp_bindport', 'tcp_enabled'],
        'tls' => ['tls_bindaddr', 'tls_bindport', 'tls_enabled'],
    ];

    /** @return array<string, string> */
    private static function pjsipSettings(): array
    {
        return getDB()->query('SELECT keyword, data FROM pjsipsettings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    private static function isOn(?string $value): bool
    {
        return !empty($value) && strtolower($value) !== 'no';
    }

    /** @return list<array{key: string, name: string, protocol: string, bind_addr: string, port: int, enabled: bool, editable: bool}> */
    public static function builtin(): array
    {
        $s = self::pjsipSettings();
        $rows = [];
        foreach (self::BUILTIN as $protocol => [$addr, $port, $switch]) {
            $rows[] = [
                'key' => $protocol, 'name' => 'transport-' . $protocol, 'protocol' => $protocol,
                'bind_addr' => (string) ($s[$addr] ?? ''), 'port' => (int) ($s[$port] ?? 0),
                'enabled' => $switch === null || self::isOn($s[$switch] ?? null), 'editable' => true,
            ];
        }
        $wssOn = !isset($s['wss_enabled']) || self::isOn($s['wss_enabled']);
        $rows[] = ['key' => 'wss', 'name' => 'transport-wss', 'protocol' => 'wss', 'bind_addr' => (string) ($s['wss_bindaddr'] ?? ''), 'port' => (int) ($s['wss_bindport'] ?? 0), 'enabled' => $wssOn, 'editable' => false];
        $rows[] = ['key' => 'ws', 'name' => 'transport-ws', 'protocol' => 'ws', 'bind_addr' => (string) ($s['ws_bindaddr'] ?? ''), 'port' => (int) ($s['ws_bindport'] ?? 0), 'enabled' => self::isOn($s['ws_enabled'] ?? null), 'editable' => false];
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function custom(): array
    {
        return getDB()->query('SELECT * FROM pbx_transports ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Whether a transport changed since Asterisk last started. */
    public static function restartNeeded(): bool
    {
        $changed = (int) getSystemSetting(self::CHANGED_SETTING, '0');
        if ($changed === 0) {
            return false;
        }
        $uptime = self::parseUptime((string) (AsteriskHelper::execCLI('core show uptime seconds')['output'] ?? ''));
        return $uptime !== null && time() - $uptime < $changed;
    }

    /** Seconds in "System uptime: 1234", or null when Asterisk did not answer. */
    public static function parseUptime(string $output): ?int
    {
        return preg_match('/System uptime:\s*(\d+)/', $output, $m) === 1 ? (int) $m[1] : null;
    }

    /** "tcp" for a transport that holds a TCP port, "udp" otherwise. */
    public static function socketKind(string $protocol): string
    {
        return $protocol === 'udp' ? 'udp' : 'tcp';
    }

    /**
     * Why a port cannot be used, or '' when it can.
     *
     * @param list<array{name: string, protocol: string, port: int}> $others the transports it must not meet
     * @param list<array{0: int, 1: int}> $ranges port ranges in use by media
     */
    public static function portProblem(int $port, string $protocol, array $others, array $ranges): string
    {
        if ($port < 1 || $port > 65535) {
            return t('transports.err_port');
        }
        if (in_array($port, self::RESERVED, true)) {
            return sprintf(t('transports.err_port_reserved'), $port);
        }
        foreach ($ranges as [$from, $to]) {
            if ($port >= $from && $port <= $to) {
                return sprintf(t('transports.err_port_range'), $port, $from, $to);
            }
        }
        foreach ($others as $other) {
            if ((int) $other['port'] === $port && self::socketKind((string) $other['protocol']) === self::socketKind($protocol)) {
                return sprintf(t('transports.err_port_used'), $port, $other['name']);
            }
        }
        return '';
    }

    /** @return list<array{0: int, 1: int}> */
    private static function mediaRanges(): array
    {
        return [
            [(int) getSystemSetting('rtp_start', '10000'), (int) getSystemSetting('rtp_end', '12999')],
            [4100, 4999],       // T.38 (udptl.conf)
            [13479, 14999],     // coturn
            [49152, 65535],     // coturn relay
        ];
    }

    /** @return list<array{name: string, protocol: string, port: int}> every transport but the one being saved */
    private static function othersThan(string $key, int $customId): array
    {
        $others = [];
        foreach (self::builtin() as $b) {
            if ($b['key'] !== $key && $b['enabled']) {
                $others[] = ['name' => $b['name'], 'protocol' => $b['protocol'], 'port' => $b['port']];
            }
        }
        foreach (self::custom() as $c) {
            if ((int) $c['id'] !== $customId) {
                $others[] = ['name' => (string) $c['name'], 'protocol' => (string) $c['protocol'], 'port' => (int) $c['port']];
            }
        }
        return $others;
    }

    private static function checkBind(string $addr): string
    {
        $addr = trim($addr);
        if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
            throw new \Exception(t('transports.err_addr'));
        }
        return $addr;
    }

    /** Changes a built-in UDP, TCP or TLS transport. */
    public static function saveBuiltin(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $key = (string) ($data['key'] ?? '');
            if (!isset(self::BUILTIN[$key])) {
                throw new \Exception(t('transports.err_missing'));
            }
            [$addrKey, $portKey, $switchKey] = self::BUILTIN[$key];
            $addr = self::checkBind((string) ($data['bind_addr'] ?? ''));
            $port = (int) ($data['port'] ?? 0);
            $enabled = $switchKey === null || !empty($data['enabled']);
            $problem = $enabled ? self::portProblem($port, $key, self::othersThan($key, 0), self::mediaRanges()) : '';
            if ($problem !== '') {
                throw new \Exception($problem);
            }
            $db = getDB();
            $set = $db->prepare('INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES (?, ?, 1, 0) ON DUPLICATE KEY UPDATE data = VALUES(data)');
            $set->execute([$addrKey, $addr]);
            $set->execute([$portKey, (string) $port]);
            if ($switchKey !== null) {
                $set->execute([$switchKey, $enabled ? '1' : '0']);
            }
            // The Asterisk settings page and the API read the port from here.
            if ($key !== 'tcp') {
                setSystemSetting("pjsip_{$key}_port", (string) $port);
            }
            return self::afterChange("transport-{$key}", $enabled ? $port : 0, $key, 'update', (string) ($data['csrf_token'] ?? ''));
        });
    }

    /** Adds or changes a transport of the administrator's own. */
    public static function saveCustom(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['id'] ?? 0);
            $name = trim((string) ($data['name'] ?? ''));
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $name) !== 1) {
                throw new \Exception(t('transports.err_name'));
            }
            $protocol = (string) ($data['protocol'] ?? '');
            if (!in_array($protocol, self::PROTOCOLS, true)) {
                throw new \Exception(t('transports.err_protocol'));
            }
            $addr = self::checkBind((string) ($data['bind_addr'] ?? ''));
            $port = (int) ($data['port'] ?? 0);
            $problem = self::portProblem($port, $protocol, self::othersThan('', $id), self::mediaRanges());
            if ($problem !== '') {
                throw new \Exception($problem);
            }
            $external = trim((string) ($data['external_address'] ?? ''));
            if ($external !== '' && preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,253}[A-Za-z0-9])?$/D', $external) !== 1) {
                throw new \Exception(t('transports.err_external'));
            }
            $active = empty($data['is_active']) ? 0 : 1;
            $verifyServer = !empty($data['verify_server']) ? 1 : 0;
            $taken = in_array($name, ['transport-udp', 'transport-tcp', 'transport-tls', 'transport-ws', 'transport-wss'], true);
            $db = getDB();
            $same = $db->prepare('SELECT id FROM pbx_transports WHERE name = ? AND id <> ?');
            $same->execute([$name, $id]);
            if ($taken || $same->fetchColumn() !== false) {
                throw new \Exception(t('transports.err_name_taken'));
            }
            if ($id > 0) {
                $db->prepare('UPDATE pbx_transports SET name = ?, protocol = ?, bind_addr = ?, port = ?, external_address = ?, is_active = ?, verify_server = ? WHERE id = ?')
                    ->execute([$name, $protocol, $addr, $port, $external, $active, $verifyServer, $id]);
            } else {
                $db->prepare('INSERT INTO pbx_transports (name, protocol, bind_addr, port, external_address, is_active, verify_server) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $protocol, $addr, $port, $external, $active, $verifyServer]);
            }
            return self::afterChange($name, $active ? $port : 0, $protocol, $id > 0 ? 'update' : 'create', (string) ($data['csrf_token'] ?? ''));
        });
    }

    public static function deleteCustom(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $stmt = $db->prepare('SELECT name, port FROM pbx_transports WHERE id = ?');
            $stmt->execute([(int) ($data['id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \Exception(t('transports.err_missing'));
            }
            $db->prepare('DELETE FROM pbx_transports WHERE id = ?')->execute([(int) $data['id']]);
            self::markChanged((string) $row['name'], 'delete');
            return sprintf(t('transports.deleted'), (int) $row['port']);
        });
    }

    /** The new port opens in the firewall now; an old one stays open until somebody closes it. */
    private static function afterChange(string $name, int $port, string $protocol, string $action, string $csrf): string
    {
        self::markChanged($name, $action);
        $message = t('transports.saved');
        if ($port > 0 && firewalld_is_active()) {
            $open = FirewallService::addRule((string) $port, self::socketKind($protocol), '', 'all', $csrf);
            $message .= ' ' . (!empty($open['success']) ? sprintf(t('transports.firewall_opened'), "{$port}/" . self::socketKind($protocol)) : sprintf(t('transports.firewall_failed'), "{$port}/" . self::socketKind($protocol)));
        }
        return $message;
    }

    private static function markChanged(string $name, string $action): void
    {
        setSystemSetting(self::CHANGED_SETTING, (string) time());
        $uid = $_SESSION['user_id'] ?? null;
        markPendingSync('transports', 'transports', 'all', "Transport: {$name}", $action, $uid);
        writeAuditLog('transports', 'transport', 0, $name, $action, $uid);
    }
}
