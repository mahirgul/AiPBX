<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/asterisk_helper.php';
require_once __DIR__ . '/FirewallService.php';
require_once __DIR__ . '/ReverseProxyService.php';

/**
 * Network → Network settings: why a call may have no audio or only one-way audio.
 *
 * Runs when an administrator asks for it, because asking a public STUN server sends a packet to a
 * third party. evaluate() holds the decisions and only looks at what it is given.
 */
final class NatDiagnosticsService
{
    private const STUN_HOST = 'stun.l.google.com';
    private const STUN_PORT = 19302;

    /**
     * @return array<string, mixed> what evaluate() looks at
     */
    public static function gather(): array
    {
        $transport = (string) AsteriskHelper::execCLI('pjsip show transport transport-udp')['output'];
        $firewall = FirewallService::getStatus();
        return [
            'local_ips' => NetworkSettingsService::localIps(),
            'public' => trim((string) getSystemSetting('pjsip_external_ip', '')),
            'media' => trim((string) getSystemSetting('pjsip_external_media_ip', '')),
            'local_nets' => array_merge(
                $configuredNets = array_values(array_filter(explode(',', (string) getSystemSetting('pjsip_local_net', '')))),
                NetworkSettingsService::connectedNetworks($configuredNets, NetworkSettingsService::addressList())
            ),
            'running_signaling' => self::transportValue($transport, 'external_signaling_address'),
            'running_media' => self::transportValue($transport, 'external_media_address'),
            'rtp_start' => (int) getSystemSetting('rtp_start', '10000'),
            'rtp_end' => (int) getSystemSetting('rtp_end', '12999'),
            'firewall_active' => !empty($firewall['active']),
            'firewall_ports' => $firewall['ports'],
            'observed' => self::observedPublicIp(),
            'resolve' => static fn(string $host): string => filter_var($host, FILTER_VALIDATE_IP) ? $host : (string) gethostbyname($host),
            'channels' => self::parseChannelStats((string) AsteriskHelper::execCLI('pjsip show channelstats')['output']),
        ];
    }

    public static function transportValue(string $output, string $key): string
    {
        return preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*(\S*)\s*$/m', $output, $m) ? $m[1] : '';
    }

    /** The address this server appears from on the internet; '' when it cannot be learned. */
    private static function observedPublicIp(): string
    {
        $out = (string) @shell_exec('timeout 5 turnutils_stunclient -p ' . self::STUN_PORT . ' ' . escapeshellarg(self::STUN_HOST) . ' 2>/dev/null');
        return self::parseStunOutput($out);
    }

    public static function parseStunOutput(string $output): string
    {
        return preg_match('/UDP reflexive addr:\s*([0-9.]+):\d+/', $output, $m) ? $m[1] : '';
    }

    /**
     * Channels that are up, with what they received. A line of `pjsip show channelstats` is: bridge
     * (absent when not bridged), channel, up time, codec, then receive count, lost, percent, jitter
     * and transmit count. Asterisk cuts the channel name short.
     *
     * @return list<array{channel: string, up: int, received: int, sent: int}>
     */
    public static function parseChannelStats(string $output): array
    {
        $calls = [];
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^\s*(?:\S+\s+)?(\S+)\s+(\d\d):(\d\d):(\d\d)\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+(\d+)/', $line, $m)) {
                continue;
            }
            $calls[] = [
                'channel' => $m[1],
                'up' => (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4],
                'received' => (int) $m[5],
                'sent' => (int) $m[6],
            ];
        }
        return $calls;
    }

    /**
     * @param array<string, mixed> $in
     * @return list<array{status: string, text: string}> status is ok, warn, fail or info
     */
    public static function evaluate(array $in): array
    {
        $out = [];
        $add = static function (string $status, string $text) use (&$out): void {
            $out[] = ['status' => $status, 'text' => $text];
        };
        $public = (string) $in['public'];
        $media = (string) $in['media'] !== '' ? (string) $in['media'] : $public;
        $privateIps = array_values(array_filter((array) $in['local_ips'], [self::class, 'isPrivate']));
        $observed = (string) $in['observed'];

        // Behind NAT?
        if ($public === '' && $observed === '' && $privateIps !== [] && count($privateIps) === count((array) $in['local_ips'])) {
            $add('warn', t('nat_check.no_public'));
        } elseif ($public !== '' && self::isPrivate($public)) {
            $add('fail', sprintf(t('nat_check.public_is_private'), $public));
        } elseif ($public !== '') {
            $add('ok', sprintf(t('nat_check.public_set'), $public));
        }

        // What the internet sees.
        if ($observed === '') {
            $add('info', t('nat_check.stun_failed'));
        } else {
            $resolve = $in['resolve'];
            if ($public !== '' && $resolve($public) !== $observed) {
                $add('fail', sprintf(t('nat_check.public_differs'), $public, $observed));
            } elseif ($media !== '' && $media !== $public && $resolve($media) !== $observed) {
                $add('warn', sprintf(t('nat_check.media_differs'), $media, $observed));
            } elseif ($public === '' && !in_array($observed, (array) $in['local_ips'], true)) {
                // Fine for a PBX used only inside this network, so not a failure.
                $add('warn', sprintf(t('nat_check.observed_not_set'), $observed));
            } else {
                $add('ok', sprintf(t('nat_check.observed'), $observed));
            }
        }

        // Phones on the LAN must not be told the public address.
        foreach ($privateIps as $ip) {
            $inside = false;
            foreach ((array) $in['local_nets'] as $net) {
                $inside = $inside || ReverseProxyService::ipInCidr($ip, (string) $net);
            }
            if (!$inside) {
                $add('warn', sprintf(t('nat_check.not_local'), $ip));
            }
        }

        // Saved but not applied.
        if ($public !== (string) $in['running_signaling'] || ($public === '' ? '' : $media) !== (string) $in['running_media']) {
            $add('warn', t('nat_check.not_applied'));
        }

        // Audio ports.
        $start = (int) $in['rtp_start'];
        $end = (int) $in['rtp_end'];
        if (!empty($in['firewall_active'])) {
            $add(self::firewallCovers((array) $in['firewall_ports'], $start, $end) ? 'ok' : 'fail', sprintf(t(self::firewallCovers((array) $in['firewall_ports'], $start, $end) ? 'nat_check.rtp_open' : 'nat_check.rtp_closed'), $start, $end));
        }
        $add('info', sprintf(t('nat_check.ports_needed'), $start, $end));

        // Calls in progress.
        $silent = array_values(array_filter((array) $in['channels'], static fn(array $c): bool => $c['up'] >= 5 && $c['received'] === 0));
        foreach ($silent as $c) {
            $add('fail', sprintf(t('nat_check.call_no_audio'), $c['channel'], $c['up'], $c['sent']));
        }
        if ((array) $in['channels'] !== [] && $silent === []) {
            $add('ok', sprintf(t('nat_check.calls_ok'), count((array) $in['channels'])));
        }
        return $out;
    }

    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** @param list<array{port: string, protocol: string}> $ports */
    public static function firewallCovers(array $ports, int $start, int $end): bool
    {
        foreach ($ports as $p) {
            if ($p['protocol'] !== 'udp') {
                continue;
            }
            $range = array_map('intval', explode('-', (string) $p['port']));
            if ($range[0] <= $start && ($range[1] ?? $range[0]) >= $end) {
                return true;
            }
        }
        return false;
    }
}
