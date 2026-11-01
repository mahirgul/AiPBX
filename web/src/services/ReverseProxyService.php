<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/audit_log.php';

/**
 * Service for Reverse Proxy management, client IP resolution behind trusted proxies,
 * live reverse proxy configuration generation (Nginx, Caddy, Traefik, HAProxy, Apache),
 * and network ports & firewall rule generation.
 */
final class ReverseProxyService
{
    public const DEFAULT_TRUSTED_PROXIES = '127.0.0.1, ::1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16';

    /**
     * Settings keys stored in sys_settings.
     */
    public const SETTING_ENABLED = 'proxy_enabled';
    public const SETTING_TRUSTED_PROXIES = 'proxy_trusted_proxies';
    public const SETTING_TLS_TERMINATION = 'proxy_tls_termination';
    public const SETTING_EXTERNAL_FQDN = 'proxy_external_fqdn';
    public const SETTING_EXTERNAL_PORT = 'proxy_external_port';
    public const SETTING_PROXY_TYPE = 'proxy_type';

    /**
     * Get all reverse proxy settings with defaults.
     *
     * @return array{
     *   enabled: bool,
     *   trusted_proxies: string,
     *   tls_termination: string,
     *   external_fqdn: string,
     *   external_port: int,
     *   proxy_type: string
     * }
     */
    public static function getSettings(): array
    {
        $db = getDB();
        $keys = [
            self::SETTING_ENABLED,
            self::SETTING_TRUSTED_PROXIES,
            self::SETTING_TLS_TERMINATION,
            self::SETTING_EXTERNAL_FQDN,
            self::SETTING_EXTERNAL_PORT,
            self::SETTING_PROXY_TYPE,
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        /** @var array<string, string> $pairs */
        $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $defaultFqdn = strtolower(trim((string) portalEnv('PORTAL_DOMAIN', '')));

        return [
            'enabled' => ($pairs[self::SETTING_ENABLED] ?? '0') === '1',
            'trusted_proxies' => $pairs[self::SETTING_TRUSTED_PROXIES] ?? self::DEFAULT_TRUSTED_PROXIES,
            'tls_termination' => in_array($pairs[self::SETTING_TLS_TERMINATION] ?? '', ['edge', 're_encrypt', 'none'], true)
                ? $pairs[self::SETTING_TLS_TERMINATION]
                : 'edge',
            'external_fqdn' => $pairs[self::SETTING_EXTERNAL_FQDN] ?? $defaultFqdn,
            'external_port' => max(1, min(65535, (int) ($pairs[self::SETTING_EXTERNAL_PORT] ?? 443))),
            'proxy_type' => in_array($pairs[self::SETTING_PROXY_TYPE] ?? '', ['nginx', 'caddy', 'traefik', 'haproxy', 'apache'], true)
                ? $pairs[self::SETTING_PROXY_TYPE]
                : 'nginx',
        ];
    }

    /**
     * Save reverse proxy settings.
     *
     * @param array<string, mixed> $input
     * @param int|string|null $userId
     * @return array{success: bool, error?: string}
     */
    public static function saveSettings(array $input, int|string|null $userId = null): array
    {
        $db = getDB();

        $enabled = !empty($input['enabled']) ? '1' : '0';
        $trustedProxies = trim((string) ($input['trusted_proxies'] ?? self::DEFAULT_TRUSTED_PROXIES));
        $tlsTermination = (string) ($input['tls_termination'] ?? 'edge');
        if (!in_array($tlsTermination, ['edge', 're_encrypt', 'none'], true)) {
            $tlsTermination = 'edge';
        }

        $fqdn = strtolower(trim((string) ($input['external_fqdn'] ?? '')));
        if ($fqdn !== '' && !preg_match('/^[a-z0-9.-]+$/i', $fqdn)) {
            return ['success' => false, 'error' => t('reverse_proxy.err_invalid_fqdn')];
        }

        $port = (int) ($input['external_port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            $port = 443;
        }

        $proxyType = (string) ($input['proxy_type'] ?? 'nginx');
        if (!in_array($proxyType, ['nginx', 'caddy', 'traefik', 'haproxy', 'apache'], true)) {
            $proxyType = 'nginx';
        }

        $stmt = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        $updates = [
            self::SETTING_ENABLED => $enabled,
            self::SETTING_TRUSTED_PROXIES => $trustedProxies,
            self::SETTING_TLS_TERMINATION => $tlsTermination,
            self::SETTING_EXTERNAL_FQDN => $fqdn,
            self::SETTING_EXTERNAL_PORT => (string) $port,
            self::SETTING_PROXY_TYPE => $proxyType,
        ];

        foreach ($updates as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        writeAuditLog('security', 'reverse_proxy', 'update', "Updated reverse proxy settings (enabled={$enabled}, fqdn={$fqdn})", 'update', $userId);

        return ['success' => true];
    }

    /**
     * Resolves the real client IP address.
     * When reverse proxy support is enabled and the immediate connection ($_SERVER['REMOTE_ADDR'])
     * originates from an address in the trusted proxies list, X-Forwarded-For or X-Real-IP is used.
     * Untrusted connections will NEVER have their headers honored, preventing spoofing.
     *
     * @param string $remoteAddr
     * @param array<string, mixed> $server
     * @return string
     */
    public static function resolveClientIp(string $remoteAddr, array $server = []): string
    {
        if ($server === []) {
            $server = $_SERVER;
        }

        $remote = trim($remoteAddr);
        if ($remote === '') {
            $remote = '127.0.0.1';
        }

        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return $remote;
        }

        if (!self::isIpTrusted($remote, $settings['trusted_proxies'])) {
            return $remote;
        }

        // Remote is trusted. Traverse X-Forwarded-For from right to left to find the first untrusted IP.
        $forwardedFor = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwardedFor !== '') {
            $hops = array_map('trim', explode(',', $forwardedFor));
            $clientCandidate = null;
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $candidate = $hops[$i];
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    if (!self::isIpTrusted($candidate, $settings['trusted_proxies'])) {
                        return $candidate;
                    }
                    $clientCandidate = $candidate;
                }
            }
            if ($clientCandidate !== null) {
                return $clientCandidate;
            }
        }

        // Fallback to X-Real-IP
        $realIp = trim((string) ($server['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        return $remote;
    }

    /**
     * Checks if a given IP is in the trusted proxies list (supports exact IP and CIDR).
     */
    public static function isIpTrusted(string $ip, string $trustedList): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $tokens = preg_split('/[\s,]+/', trim($trustedList), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (str_contains($token, '/')) {
                if (self::ipInCidr($ip, $token)) {
                    return true;
                }
            } else {
                if (strcasecmp($ip, $token) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks if an IP is within a CIDR subnet (IPv4 or IPv6).
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return strcasecmp($ip, $cidr) === 0;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        $isIpV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isSubnetV6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ($isIpV6 !== $isSubnetV6) {
            return false;
        }

        if (!$isIpV6) {
            // IPv4 CIDR
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            if ($mask === 0) {
                return true;
            }
            $netmask = ~((1 << (32 - $mask)) - 1);
            return ($ipLong & $netmask) === ($subnetLong & $netmask);
        }

        // IPv6 CIDR
        if ($mask < 0 || $mask > 128) {
            return false;
        }
        $ipPacked = inet_pton($ip);
        $subnetPacked = inet_pton($subnet);
        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $remainder = $mask % 8;

        if (strncmp($ipPacked, $subnetPacked, $bytes) !== 0) {
            return false;
        }

        if ($remainder > 0 && isset($ipPacked[$bytes], $subnetPacked[$bytes])) {
            $byteMask = (0xFF << (8 - $remainder)) & 0xFF;
            return (ord($ipPacked[$bytes]) & $byteMask) === (ord($subnetPacked[$bytes]) & $byteMask);
        }

        return true;
    }

    /**
     * Complete network ports matrix for AiPBX.
     *
     * @return list<array{
     *   port: string,
     *   proto: string,
     *   service: string,
     *   scope: string,
     *   bypass: bool,
     *   description: string
     * }>
     */
    public static function portsMatrix(): array
    {
        return [
            [
                'port' => '5060',
                'proto' => 'UDP / TCP',
                'service' => 'SIP Signaling',
                'scope' => 'Public / LAN',
                'bypass' => true,
                'description' => 'Standard SIP phone signaling, PBX trunks, and softphone registrations.',
            ],
            [
                'port' => '5061',
                'proto' => 'TCP',
                'service' => 'SIP-TLS Signaling',
                'scope' => 'Public / LAN',
                'bypass' => true,
                'description' => 'Secure encrypted SIP signaling via TLS.',
            ],
            [
                'port' => '10000-20000',
                'proto' => 'UDP',
                'service' => 'RTP / SRTP Media',
                'scope' => 'Public / LAN',
                'bypass' => true,
                'description' => 'Real-time Transport Protocol streams for two-way audio and video calls.',
            ],
            [
                'port' => '80',
                'proto' => 'TCP',
                'service' => 'HTTP Web Portal / ACME',
                'scope' => 'Public / LAN',
                'bypass' => false,
                'description' => 'Web administration portal, phone auto-provisioning, and Let\'s Encrypt HTTP-01 challenge.',
            ],
            [
                'port' => '443',
                'proto' => 'TCP',
                'service' => 'HTTPS Web Portal',
                'scope' => 'Public / LAN',
                'bypass' => false,
                'description' => 'Encrypted Web portal, REST API endpoints, and WebSocket proxying.',
            ],
            [
                'port' => '8089',
                'proto' => 'TCP',
                'service' => 'Asterisk WSS (WebRTC)',
                'scope' => 'Public / LAN',
                'bypass' => false,
                'description' => 'Secure WebSocket signaling for in-browser WebRTC softphone calling.',
            ],
            [
                'port' => '8088',
                'proto' => 'TCP',
                'service' => 'Asterisk HTTP / WS',
                'scope' => 'Internal / Proxy',
                'bypass' => false,
                'description' => 'Asterisk built-in HTTP server for local WebSocket reverse proxying.',
            ],
            [
                'port' => '3478 / 5349',
                'proto' => 'UDP / TCP',
                'service' => 'STUN / TURNS (coturn)',
                'scope' => 'Public',
                'bypass' => true,
                'description' => 'NAT traversal relay for WebRTC media behind symmetric NAT or corporate firewalls.',
            ],
            [
                'port' => '69',
                'proto' => 'UDP',
                'service' => 'TFTP Provisioning',
                'scope' => 'LAN only',
                'bypass' => true,
                'description' => 'Legacy desk phone configuration and firmware downloads.',
            ],
            [
                'port' => '9061',
                'proto' => 'UDP',
                'service' => 'HEP / Homer SIP Capture',
                'scope' => 'Internal / Monitoring',
                'bypass' => true,
                'description' => 'Encapsulated SIP packet capture streaming for Homer VoIP monitoring.',
            ],
            [
                'port' => '5038',
                'proto' => 'TCP',
                'service' => 'AMI (Asterisk Manager)',
                'scope' => 'Localhost only (127.0.0.1)',
                'bypass' => true,
                'description' => 'Internal Asterisk management interface. NEVER expose to the internet.',
            ],
            [
                'port' => '3306',
                'proto' => 'TCP',
                'service' => 'MariaDB Database',
                'scope' => 'Localhost only (127.0.0.1)',
                'bypass' => true,
                'description' => 'Core PBX database. Bound to 127.0.0.1 only.',
            ],
        ];
    }

    /**
     * Inspect incoming request headers for live diagnostics.
     *
     * @param array<string, mixed> $server
     * @return array{
     *   remote_addr: string,
     *   is_trusted: bool,
     *   forwarded_for: string,
     *   forwarded_proto: string,
     *   forwarded_host: string,
     *   forwarded_port: string,
     *   real_ip: string,
     *   resolved_client_ip: string,
     *   proxy_enabled: bool
     * }
     */
    public static function inspectCurrentRequest(array $server = []): array
    {
        if ($server === []) {
            $server = $_SERVER;
        }

        $settings = self::getSettings();
        $remote = (string) ($server['REMOTE_ADDR'] ?? '127.0.0.1');
        $isTrusted = self::isIpTrusted($remote, $settings['trusted_proxies']);
        $resolved = self::resolveClientIp($remote, $server);

        return [
            'remote_addr' => $remote,
            'is_trusted' => $isTrusted,
            'forwarded_for' => (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''),
            'forwarded_proto' => (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''),
            'forwarded_host' => (string) ($server['HTTP_X_FORWARDED_HOST'] ?? ''),
            'forwarded_port' => (string) ($server['HTTP_X_FORWARDED_PORT'] ?? ''),
            'real_ip' => (string) ($server['HTTP_X_REAL_IP'] ?? ''),
            'resolved_client_ip' => $resolved,
            'proxy_enabled' => $settings['enabled'],
        ];
    }

    /**
     * Generate firewall configuration commands.
     */
    public static function generateFirewallCommands(string $tool = 'ufw'): string
    {
        return match ($tool) {
            'iptables' => self::generateIptablesRules(),
            'firewalld' => self::generateFirewalldRules(),
            default => self::generateUfwRules(),
        };
    }

    private static function generateUfwRules(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# AiPBX UFW Firewall Rules Setup
# Run with root privileges

set -euo pipefail

# 1. Reset or set baseline defaults
ufw default deny incoming
ufw default allow outgoing

# 2. Management & Web
ufw allow 22/tcp comment 'SSH Access'
ufw allow 80/tcp comment 'AiPBX Web Portal / HTTP-01'
ufw allow 443/tcp comment 'AiPBX Web Portal / HTTPS'

# 3. VoIP Signaling (MUST route directly to server)
ufw allow 5060/udp comment 'SIP Signaling UDP'
ufw allow 5060/tcp comment 'SIP Signaling TCP'
ufw allow 5061/tcp comment 'SIP-TLS Signaling TCP'

# 4. RTP Audio / Video Media Range (Direct UDP)
ufw allow 10000:20000/udp comment 'AiPBX RTP Media Streams'

# 5. WebRTC STUN / TURNS Relay
ufw allow 3478/udp comment 'STUN / TURN UDP'
ufw allow 3478/tcp comment 'STUN / TURN TCP'
ufw allow 5349/tcp comment 'TURNS TLS'

# 6. WebRTC WebSocket Signaling
ufw allow 8089/tcp comment 'Asterisk WebRTC WSS'

# 7. Internal Desk Phone Provisioning (Restrict to local subnet if desired)
# ufw allow from 192.168.1.0/24 to any port 69 proto udp comment 'TFTP Phone Provisioning'

# Enable firewall
ufw --force enable
ufw status verbose
BASH;
    }

    private static function generateFirewalldRules(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# AiPBX firewalld Rules Setup (RHEL, Rocky, AlmaLinux, Fedora)
# Run with root privileges

set -euo pipefail

# Standard Web & Management
firewall-cmd --permanent --add-service=ssh
firewall-cmd --permanent --add-service=http
firewall-cmd --permanent --add-service=https

# VoIP Signaling & Media (MUST bypass reverse proxy)
firewall-cmd --permanent --add-port=5060/udp
firewall-cmd --permanent --add-port=5060/tcp
firewall-cmd --permanent --add-port=5061/tcp
firewall-cmd --permanent --add-port=10000-20000/udp

# WebRTC STUN / TURN
firewall-cmd --permanent --add-port=3478/udp
firewall-cmd --permanent --add-port=3478/tcp
firewall-cmd --permanent --add-port=5349/tcp

# Asterisk WebRTC WSS
firewall-cmd --permanent --add-port=8089/tcp

# Reload rules
firewall-cmd --reload
firewall-cmd --list-all
BASH;
    }

    private static function generateIptablesRules(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# AiPBX iptables Stateful Firewall Rules
# Run with root privileges

set -euo pipefail

# Flush existing rules
iptables -F
iptables -X
iptables -t nat -F
iptables -t nat -X

# Default policies
iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT

# Allow loopback
iptables -A INPUT -i lo -j ACCEPT

# Allow established and related connections
iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

# SSH, HTTP, HTTPS
iptables -A INPUT -p tcp --dport 22 -j ACCEPT
iptables -A INPUT -p tcp --dport 80 -j ACCEPT
iptables -A INPUT -p tcp --dport 443 -j ACCEPT

# SIP Signaling (Direct)
iptables -A INPUT -p udp --dport 5060 -j ACCEPT
iptables -A INPUT -p tcp --dport 5060 -j ACCEPT
iptables -A INPUT -p tcp --dport 5061 -j ACCEPT

# RTP Media Streams (Direct UDP)
iptables -A INPUT -p udp --dport 10000:20000 -j ACCEPT

# WebRTC STUN/TURN & WSS
iptables -A INPUT -p udp --dport 3478 -j ACCEPT
iptables -A INPUT -p tcp --dport 3478 -j ACCEPT
iptables -A INPUT -p tcp --dport 5349 -j ACCEPT
iptables -A INPUT -p tcp --dport 8089 -j ACCEPT

# ICMP ping
iptables -A INPUT -p icmp --icmp-type echo-request -j ACCEPT
BASH;
    }

    /**
     * Generate Reverse Proxy configuration files.
     */
    public static function generateProxyConfig(
        string $type,
        string $fqdn,
        string $backendIp = '127.0.0.1',
        int $backendPort = 80,
        string $tlsMode = 'edge'
    ): string {
        $fqdn = trim($fqdn) ?: 'pbx.example.com';
        $backendIp = trim($backendIp) ?: '127.0.0.1';
        $backendPort = ($backendPort > 0 && $backendPort <= 65535) ? $backendPort : 80;

        return match ($type) {
            'caddy' => self::generateCaddyConfig($fqdn, $backendIp, $backendPort, $tlsMode),
            'traefik' => self::generateTraefikConfig($fqdn, $backendIp, $backendPort, $tlsMode),
            'haproxy' => self::generateHAProxyConfig($fqdn, $backendIp, $backendPort, $tlsMode),
            'apache' => self::generateApacheConfig($fqdn, $backendIp, $backendPort, $tlsMode),
            default => self::generateNginxConfig($fqdn, $backendIp, $backendPort, $tlsMode),
        };
    }

    private static function generateNginxConfig(string $fqdn, string $backendIp, int $backendPort, string $tlsMode): string
    {
        $proto = ($tlsMode === 're_encrypt') ? 'https' : 'http';
        $wsProto = ($tlsMode === 're_encrypt') ? 'https' : 'http';

        return <<<NGINX
# ==============================================================================
# /etc/nginx/sites-available/aipbx.conf
# AiPBX Reverse Proxy Configuration (Nginx)
# External FQDN: {$fqdn}
# Backend Target: {$proto}://{$backendIp}:{$backendPort}
#
# IMPORTANT: VoIP protocols (SIP 5060/5061 and RTP 10000-20000 UDP) MUST bypass
# this HTTP proxy and connect directly to the server host.
# ==============================================================================

map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

upstream aipbx_web_backend {
    server {$backendIp}:{$backendPort};
    keepalive 32;
}

upstream aipbx_webrtc_backend {
    server {$backendIp}:8089;
    keepalive 16;
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name {$fqdn};

    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

# Main HTTPS Server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$fqdn};

    # TLS Certificates (manage via certbot or centralized PKI)
    ssl_certificate /etc/letsencrypt/live/{$fqdn}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$fqdn}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    client_max_body_size 64M;

    # 1. WebRTC WebSocket Signaling (Softphones)
    location /ws {
        proxy_pass {$wsProto}://aipbx_webrtc_backend/ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;

        # Keep alive for long WebRTC sessions
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # 2. Main Portal & Desk Phone Provisioning
    location / {
        proxy_pass {$proto}://aipbx_web_backend;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;

        proxy_connect_timeout 60s;
        proxy_send_timeout 300s;
        proxy_read_timeout 300s;
        proxy_buffering off;
    }
}
NGINX;
    }

    private static function generateCaddyConfig(string $fqdn, string $backendIp, int $backendPort, string $tlsMode): string
    {
        $proto = ($tlsMode === 're_encrypt') ? 'https' : 'http';

        return <<<CADDY
# ==============================================================================
# /etc/caddy/Caddyfile
# AiPBX Reverse Proxy Configuration (Caddy)
# External FQDN: {$fqdn}
#
# IMPORTANT: SIP (5060/5061) and RTP (10000-20000 UDP) MUST route directly.
# ==============================================================================

{$fqdn} {
    encode zstd gzip

    # WebRTC WebSocket Signaling (Asterisk WSS)
    @websocket {
        path /ws*
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket {$backendIp}:8089 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }

    # Web Portal & Phone Provisioning
    reverse_proxy {$backendIp}:{$backendPort} {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-Host {host}
    }
}
CADDY;
    }

    private static function generateTraefikConfig(string $fqdn, string $backendIp, int $backendPort, string $tlsMode): string
    {
        $scheme = ($tlsMode === 're_encrypt') ? 'https' : 'http';

        return <<<YAML
# ==============================================================================
# traefik-dynamic.yaml
# AiPBX Reverse Proxy Configuration (Traefik Dynamic File Provider)
# External FQDN: {$fqdn}
#
# IMPORTANT: SIP (5060/5061) and RTP (10000-20000 UDP) MUST route directly.
# ==============================================================================

http:
  routers:
    aipbx-web-http:
      rule: "Host(`{$fqdn}`)"
      entryPoints: ["web"]
      middlewares: ["redirect-to-https"]
      service: "noop"

    aipbx-ws:
      rule: "Host(`{$fqdn}`) && PathPrefix(`/ws`)"
      entryPoints: ["websecure"]
      service: "aipbx-ws-svc"
      tls:
        certResolver: "letsencrypt"

    aipbx-web:
      rule: "Host(`{$fqdn}`)"
      entryPoints: ["websecure"]
      service: "aipbx-web-svc"
      tls:
        certResolver: "letsencrypt"

  middlewares:
    redirect-to-https:
      redirectScheme:
        scheme: "https"
        permanent: true

  services:
    aipbx-web-svc:
      loadBalancer:
        passHostHeader: true
        servers:
          - url: "{$scheme}://{$backendIp}:{$backendPort}"

    aipbx-ws-svc:
      loadBalancer:
        passHostHeader: true
        servers:
          - url: "http://{$backendIp}:8089"
YAML;
    }

    private static function generateHAProxyConfig(string $fqdn, string $backendIp, int $backendPort, string $tlsMode): string
    {
        return <<<HAPROXY
# ==============================================================================
# /etc/haproxy/haproxy.cfg (Snippet for AiPBX)
# External FQDN: {$fqdn}
#
# IMPORTANT: SIP (5060/5061) and RTP (10000-20000 UDP) MUST route directly.
# ==============================================================================

frontend http_in
    bind *:80
    bind *:443 ssl crt /etc/ssl/certs/{$fqdn}.pem alpn h2,http/1.1
    redirect scheme https code 301 if !{ ssl_fc }

    acl is_aipbx hdr(host) -i {$fqdn}
    acl is_websocket hdr(Upgrade) -i WebSocket
    acl is_ws_path path_beg /ws

    use_backend aipbx_ws_backend if is_aipbx is_websocket or is_aipbx is_ws_path
    use_backend aipbx_web_backend if is_aipbx

backend aipbx_web_backend
    mode http
    option forwardfor
    http-request set-header X-Forwarded-Proto https if { ssl_fc }
    http-request set-header X-Forwarded-Host %[req.hdr(host)]
    server aipbx1 {$backendIp}:{$backendPort} check

backend aipbx_ws_backend
    mode http
    option forwardfor
    timeout tunnel 3600s
    http-request set-header X-Forwarded-Proto https if { ssl_fc }
    server aipbx_ws1 {$backendIp}:8089 check
HAPROXY;
    }

    private static function generateApacheConfig(string $fqdn, string $backendIp, int $backendPort, string $tlsMode): string
    {
        $proto = ($tlsMode === 're_encrypt') ? 'https' : 'http';

        return <<<APACHE
# ==============================================================================
# /etc/apache2/sites-available/aipbx-reverse-proxy.conf
# AiPBX Reverse Proxy Configuration (Apache 2.4)
# External FQDN: {$fqdn}
#
# Required modules: a2enmod proxy proxy_http proxy_wstunnel ssl rewrite headers
# IMPORTANT: SIP (5060/5061) and RTP (10000-20000 UDP) MUST route directly.
# ==============================================================================

<VirtualHost *:80>
    ServerName {$fqdn}
    RewriteEngine On
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

<VirtualHost *:443>
    ServerName {$fqdn}

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/{$fqdn}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{$fqdn}/privkey.pem

    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Forwarded-Port "443"

    # WebRTC WebSocket Signaling
    ProxyPass /ws ws://{$backendIp}:8089/ws
    ProxyPassReverse /ws ws://{$backendIp}:8089/ws

    # Main Web Portal
    ProxyPass / {$proto}://{$backendIp}:{$backendPort}/
    ProxyPassReverse / {$proto}://{$backendIp}:{$backendPort}/
</VirtualHost>
APACHE;
    }
}
