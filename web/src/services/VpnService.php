<?php
require_once __DIR__ . '/../priv_helper.php';
require_once __DIR__ . '/NetworkSettingsService.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;

/**
 * Network → VPN Server and VPN Client.
 *
 * Everything lives in the root helper conf/sbin/aipbx-vpn (keys, state, profiles,
 * services); nothing here is stored in the database and the portal never writes a
 * VPN configuration: such a file can run commands as root. What is added here is
 * what the PBX itself needs: the VPN networks count as local for Asterisk, so a
 * phone on the VPN is not sent the server's public address.
 */
class VpnService
{
    public const TYPES = ['wg', 'ovpn'];
    public const DEFAULT_NET = '10.99.0.0/24';
    public const DEFAULT_PORT = ['wg' => 51820, 'ovpn' => 1194];
    /** Which of the local networks this page added, so it can take back only those. */
    public const LOCAL_NETS_KEY = 'vpn_local_nets';

    /** @return array{caps: array, server: array, tunnels: array, unavailable?: bool} */
    public static function status(): array
    {
        $res = PrivHelper::run(['vpn', 'status']);
        $data = $res['success'] ? json_decode($res['output'], true) : null;
        if (!is_array($data)) {
            return [
                'caps' => ['wg' => false, 'ovpn' => false],
                'server' => ['type' => 'off', 'port' => self::DEFAULT_PORT['wg'], 'proto' => 'udp', 'net' => self::DEFAULT_NET,
                    'endpoint' => '', 'active' => false, 'address' => '10.99.0.1', 'peers' => [], 'counts' => [], 'public_key' => ''],
                'tunnels' => [],
                // The helper did not answer: this says nothing about what is really set up.
                'unavailable' => true,
            ];
        }
        return $data;
    }

    /** The address devices are most likely to reach: the public one, else this server's. */
    public static function suggestedEndpoint(): string
    {
        foreach (['pjsip_external_ip', 'server_ip'] as $key) {
            $value = trim((string) getSystemSetting($key, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return (string) ($_SERVER['SERVER_NAME'] ?? '');
    }

    /** Runs a helper command; its refusal is what the admin reads. */
    private static function helper(array $args, ?string $input = null): string
    {
        $res = PrivHelper::run(array_merge(['vpn'], $args), $input);
        if (!$res['success']) {
            throw new \Exception($res['output'] !== '' ? $res['output'] : t('vpn.err_helper'));
        }
        return $res['output'];
    }

    private static function audit(string $entity, int $id, string $label, string $action): void
    {
        writeAuditLog('vpn', $entity, $id, $label, $action, $_SESSION['user_id'] ?? null);
    }

    /**
     * The form of the server card as helper arguments. The helper checks them again:
     * it is the one that must hold, this is for the message.
     *
     * @return string[]
     */
    public static function serverArgs(array $data): array
    {
        $type = (string) ($data['vpn_type'] ?? 'off');
        if ($type === 'off') {
            return ['type=off'];
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \Exception(t('vpn.err_type'));
        }
        $proto = $type === 'ovpn' && ($data['vpn_proto'] ?? '') === 'tcp' ? 'tcp' : 'udp';
        $args = ['type=' . $type, 'port=' . (int) ($data['vpn_port'] ?? 0), 'proto=' . $proto,
            'net=' . trim((string) ($data['vpn_net'] ?? '')), 'endpoint=' . trim((string) ($data['vpn_endpoint'] ?? ''))];
        foreach ($args as $arg) {
            if (preg_match('/[\x00-\x1f]/', $arg)) {
                throw new \Exception(t('vpn.err_value'));
            }
        }
        return $args;
    }

    public static function saveServer(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $args = self::serverArgs($data);
            self::helper(array_merge(['server-set'], $args));
            self::syncLocalNets();
            self::audit('server', 0, implode(' ', array_slice($args, 0, 3)), 'update');
            return t('vpn.saved');
        });
    }

    public static function addPeer(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $name = trim((string) ($data['peer_name'] ?? ''));
            $id = (int) self::helper(['peer-add', 'name=' . $name]);
            self::audit('device', $id, $name, 'create');
            return sprintf(t('vpn.peer_added'), $name);
        });
    }

    public static function deletePeer(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['peer_id'] ?? 0);
            self::helper(['peer-del', 'id=' . $id]);
            self::audit('device', $id, 'VPN device ' . $id, 'delete');
            return t('vpn.peer_deleted');
        });
    }

    /**
     * A device's profile: it holds the device's private key, so every read is in the audit log.
     *
     * @return array{name: string, content: string}
     */
    public static function peerProfile(int $id): array
    {
        $status = self::status();
        foreach ($status['server']['peers'] as $peer) {
            if ((int) $peer['id'] === $id) {
                $content = self::helper(['peer-profile', 'id=' . $id]) . "\n";
                self::audit('device', $id, (string) $peer['name'], 'download');
                $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $peer['name']);
                return ['name' => $base . ($status['server']['type'] === 'wg' ? '.conf' : '.ovpn'), 'content' => $content];
            }
        }
        throw new \Exception(t('vpn.err_no_peer'));
    }

    public static function profileQr(string $content): string
    {
        $options = new QROptions(['outputInterface' => QRMarkupSVG::class, 'outputBase64' => true, 'eccLevel' => EccLevel::L, 'addQuietzone' => true, 'scale' => 5]);
        return (new QRCode($options))->render($content);
    }

    /**
     * The profile out of the form: a file or pasted text.
     */
    public static function profileText(array $data, ?array $file): string
    {
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name'])) {
            if ((int) ($file['size'] ?? 0) > 256 * 1024) {
                throw new \Exception(t('vpn.err_profile_size'));
            }
            return (string) file_get_contents((string) $file['tmp_name']);
        }
        return (string) ($data['tunnel_config'] ?? '');
    }

    /** WireGuard and OpenVPN files are told apart by their content, whatever the file is called. */
    public static function detectType(string $text): string
    {
        return preg_match('/^\s*\[Interface\]/mi', $text) ? 'wg' : 'ovpn';
    }

    public static function addTunnel(array $data, ?array $file): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data, $file) {
            $text = self::profileText($data, $file);
            if (trim($text) === '') {
                throw new \Exception(t('vpn.err_profile_empty'));
            }
            $type = (string) ($data['tunnel_type'] ?? 'auto');
            if ($type === 'auto') {
                $type = self::detectType($text);
            }
            if (!in_array($type, self::TYPES, true)) {
                throw new \Exception(t('vpn.err_type'));
            }
            $name = trim((string) ($data['tunnel_name'] ?? ''));
            $input = json_encode(['config' => $text, 'username' => (string) ($data['tunnel_username'] ?? ''), 'password' => (string) ($data['tunnel_password'] ?? '')]);
            if ($input === false) {
                throw new \Exception(t('vpn.err_profile_empty'));
            }
            $id = (int) self::helper(['tunnel-add', 'name=' . $name, 'type=' . $type, 'routes=' . self::oneLine((string) ($data['tunnel_routes'] ?? '')), 'manage=' . (!empty($data['tunnel_manage']) ? '1' : '0')], $input);
            self::syncLocalNets();
            self::audit('connection', $id, $name, 'create');
            return sprintf(t('vpn.tunnel_added'), $name);
        });
    }

    public static function setTunnel(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['tunnel_id'] ?? 0);
            $args = ['tunnel-set', 'id=' . $id];
            if (isset($data['tunnel_routes'])) {
                $args[] = 'routes=' . self::oneLine((string) $data['tunnel_routes']);
            }
            if (isset($data['tunnel_enabled'])) {
                $args[] = 'enabled=' . ($data['tunnel_enabled'] === '1' ? '1' : '0');
            }
            if (isset($data['tunnel_manage'])) {
                $args[] = 'manage=' . ($data['tunnel_manage'] === '1' ? '1' : '0');
            }
            self::helper($args);
            self::syncLocalNets();
            self::audit('connection', $id, 'VPN connection ' . $id, 'update');
            return t('vpn.saved');
        });
    }

    public static function deleteTunnel(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['tunnel_id'] ?? 0);
            self::helper(['tunnel-del', 'id=' . $id]);
            self::syncLocalNets();
            self::audit('connection', $id, 'VPN connection ' . $id, 'delete');
            return t('vpn.tunnel_deleted');
        });
    }

    /** The newest lines of the log of 'server' or 't<id>'. */
    public static function log(string $unit): string
    {
        return preg_match('/^(server|t[1-9][0-9]{0,4})$/', $unit) ? self::helper(['log', $unit]) : '';
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\s,]+/', ' ', $value));
    }

    /**
     * Networks the status says are on the VPN: the server's and what each connection carries.
     *
     * @return string[]
     */
    public static function wantedLocalNets(array $status): array
    {
        $nets = [];
        if (($status['server']['type'] ?? 'off') !== 'off') {
            $nets[] = (string) $status['server']['net'];
        }
        foreach ($status['tunnels'] ?? [] as $tunnel) {
            if (!empty($tunnel['enabled'])) {
                foreach ($tunnel['routes'] ?? [] as $route) {
                    $nets[] = (string) $route;
                }
            }
        }
        return array_values(array_unique($nets));
    }

    /**
     * Asterisk's local networks, with what this page added before taken out and what is
     * wanted now put in. A network the admin already covers is not added, and so never
     * taken away either.
     *
     * @param string[] $current
     * @param string[] $before  what this page added earlier
     * @param string[] $wanted
     * @return array{nets: string[], ours: string[]}
     */
    public static function mergeLocalNets(array $current, array $before, array $wanted): array
    {
        $nets = array_values(array_filter($current, fn($n) => !in_array($n, $before, true)));
        $ours = [];
        foreach ($wanted as $net) {
            if (!self::isCovered($net, $nets)) {
                $nets[] = $net;
                $ours[] = $net;
            }
        }
        return ['nets' => $nets, 'ours' => $ours];
    }

    /** @param string[] $nets */
    public static function isCovered(string $net, array $nets): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $net, 2), 2, '32');
        foreach ($nets as $other) {
            [$oip, $oprefix] = array_pad(explode('/', $other, 2), 2, '32');
            if ((int) $oprefix > (int) $prefix) {
                continue;
            }
            $mask = (int) $oprefix === 0 ? 0 : (-1 << (32 - (int) $oprefix));
            if ((ip2long($ip) & $mask) === (ip2long($oip) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** Puts the VPN networks into Asterisk's local networks (applied with the page's Apply). */
    public static function syncLocalNets(): void
    {
        self::syncLocalNetsFrom(self::status());
    }

    /** A status the helper did not give changes nothing: the VPN may well be up. */
    public static function syncLocalNetsFrom(array $status): void
    {
        if (!empty($status['unavailable'])) {
            return;
        }
        $settings = NetworkSettingsService::settings();
        $current = array_values(array_filter(explode(',', (string) $settings['pjsip_local_net'])));
        $before = json_decode((string) getSystemSetting(self::LOCAL_NETS_KEY, '[]'), true);
        $merged = self::mergeLocalNets($current, is_array($before) ? $before : [], self::wantedLocalNets($status));
        $db = getDB();
        $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::LOCAL_NETS_KEY, json_encode($merged['ours'])]);
        if ($merged['nets'] !== $current) {
            NetworkSettingsService::storeLocalNets($merged['nets'], $settings);
        }
    }
}
