<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * Troubleshooting → Registrations: which phone, browser and app of each
 * extension is registered (address, user agent, expiry, round trip), and the
 * state of every trunk. Read from Asterisk at the moment the page opens:
 * "database show registrar" (the contacts with their details), "pjsip show
 * contacts" (reachability), "pjsip show registrations" and "pjsip show endpoints".
 */
final class RegistrationService
{
    /** Endpoint suffix => device kind shown on the page. */
    public const KINDS = ['sip' => 'desk', 'webrtc' => 'web', 'mob-webrtc' => 'mobile'];

    /**
     * Contacts from "database show registrar".
     *
     * @return array<string, list<array{endpoint: string, hash: string, uri: string, address: string, user_agent: string, expires: int}>> endpoint => contacts
     */
    public static function parseRegistrar(string $out): array
    {
        $contacts = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('#^/registrar/contact/([^;:\s]+);@([0-9a-f]+)\s*:\s*(\{.*\})\s*$#', trim($line), $m)) {
                continue;
            }
            $d = json_decode($m[3], true);
            if (!is_array($d)) {
                continue;
            }
            $endpoint = (string) ($d['endpoint'] ?? $m[1]);
            $contacts[$endpoint][] = [
                'endpoint' => $endpoint,
                'hash' => substr($m[2], 0, 10),
                'uri' => (string) ($d['uri'] ?? ''),
                'address' => self::address((string) ($d['uri'] ?? '')),
                'user_agent' => (string) ($d['user_agent'] ?? ''),
                'expires' => (int) ($d['expiration_time'] ?? 0),
            ];
        }
        return $contacts;
    }

    /** "1.2.3.4:5060 (UDP)"; a browser or app comes through the portal's WebSocket. */
    public static function address(string $uri): string
    {
        if (str_contains($uri, 'transport=WS')) {
            return 'WebSocket';
        }
        if (!preg_match('/@(\[[0-9a-fA-F:]+\]|[^:;>]+)(?::(\d+))?/', $uri, $m)) {
            return '';
        }
        $transport = preg_match('/transport=([a-z]+)/i', $uri, $t) ? strtoupper($t[1]) : 'UDP';
        return $m[1] . (isset($m[2]) ? ':' . $m[2] : '') . ' (' . $transport . ')';
    }

    /**
     * "pjsip show contacts": state and round trip by "aor/hash".
     *
     * @return array<string, array{status: string, rtt: ?float}>
     */
    public static function parseContactStates(string $out): array
    {
        $states = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('#^\s*Contact:\s+([^/\s]+)/\S+\s+([0-9a-f]{10})\s+(\S+)\s+(\S+)\s*$#', $line, $m)) {
                $states[$m[1] . '/' . $m[2]] = ['status' => $m[3], 'rtt' => is_numeric($m[4]) ? round((float) $m[4], 1) : null];
            }
        }
        return $states;
    }

    /**
     * "pjsip show registrations": outbound registrations of trunks.
     *
     * @return array<string, array{server: string, status: string}> registration name => state
     */
    public static function parseRegistrations(string $out): array
    {
        $regs = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('#^\s*([A-Za-z0-9_.-]+)/(sips?:\S+)\s+(?:\S+\s+)?(Registered|Unregistered|Rejected|Stopped|Retrying|Unknown)\b(.*)$#', $line, $m)) {
                $regs[$m[1]] = ['server' => $m[2], 'status' => $m[3] . rtrim($m[4])];
            }
        }
        return $regs;
    }

    /** A trunk's qualify state: its contact is static, so it is found by the AOR name alone. */
    private static function firstState(array $states, string $aor): ?array
    {
        foreach ($states as $key => $st) {
            if (str_starts_with($key, $aor . '/')) {
                return $st;
            }
        }
        return null;
    }

    /**
     * Everything the page shows.
     *
     * @return array{extensions: list<array<string, mixed>>, trunks: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function overview(): array
    {
        $contacts = self::parseRegistrar((string) (AsteriskHelper::execCLI('database show registrar')['output'] ?? ''));
        $states = self::parseContactStates((string) (AsteriskHelper::execCLI('pjsip show contacts')['output'] ?? ''));
        $endpoints = AsteriskHelper::getPJSIPStatuses();
        $regs = self::parseRegistrations((string) (AsteriskHelper::execCLI('pjsip show registrations')['output'] ?? ''));
        $now = time();

        $withState = static function (array $list, string $aor) use ($states, $now): array {
            foreach ($list as &$c) {
                $st = $states[$aor . '/' . $c['hash']] ?? null;
                $c['status'] = $st['status'] ?? '';
                $c['rtt'] = $st['rtt'] ?? null;
                $c['expires_in'] = $c['expires'] > 0 ? max(0, $c['expires'] - $now) : null;
            }
            return $list;
        };

        $extensions = [];
        $counts = ['desk' => 0, 'web' => 0, 'mobile' => 0, 'none' => 0];
        $users = getDB()->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != ''
            AND is_active = 1 AND (extension_type IS NULL OR extension_type != 'fax') ORDER BY extension + 0, extension")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($users as $u) {
            $row = ['extension' => (string) $u['extension'], 'name' => (string) $u['full_name'], 'devices' => []];
            foreach (self::KINDS as $suffix => $kind) {
                $ep = $u['extension'] . '-' . $suffix;
                if (!isset($endpoints[$ep]) && empty($contacts[$ep])) {
                    continue;
                }
                $list = $withState($contacts[$ep] ?? [], $ep);
                $row['devices'][$kind] = $list;
                if ($list) {
                    $counts[$kind]++;
                }
            }
            if (!array_filter($row['devices'])) {
                $counts['none']++;
            }
            $extensions[] = $row;
        }

        $trunks = [];
        foreach (getDB()->query("SELECT trunk_name, title, ip_address, port, registration_enabled, is_active FROM pbx_trunks ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
            $name = (string) $t['trunk_name'];
            $reg = null;
            foreach ($regs as $regName => $r) {
                if ($regName === $name || str_starts_with($regName, $name . '-') || str_starts_with($regName, $name . '_')) {
                    $reg = $r;
                    break;
                }
            }
            $trunks[] = [
                'name' => $name,
                'title' => (string) $t['title'],
                'host' => trim((string) $t['ip_address']) . (!empty($t['port']) ? ':' . (int) $t['port'] : ''),
                'active' => (bool) $t['is_active'],
                'registers' => (bool) $t['registration_enabled'],
                'registration' => $reg,
                'state' => (string) ($endpoints[$name] ?? ''),
                'reach' => self::firstState($states, $name),
            ];
        }
        return ['extensions' => $extensions, 'trunks' => $trunks, 'counts' => $counts];
    }
}
