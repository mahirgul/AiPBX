<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ReverseProxyService.php';

final class ReverseProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset sys_settings for proxy keys
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'proxy_%'");
    }

    public function testPortsMatrixContainsEssentialVoipAndWebPorts(): void
    {
        $ports = ReverseProxyService::portsMatrix();
        $this->assertNotEmpty($ports);

        $byPort = [];
        foreach ($ports as $p) {
            $byPort[$p['port']] = $p;
        }

        // Essential VoIP ports must bypass HTTP reverse proxy
        $this->assertArrayHasKey('5060', $byPort);
        $this->assertTrue($byPort['5060']['bypass'], 'SIP 5060 must bypass HTTP reverse proxy');

        $this->assertArrayHasKey('5061', $byPort);
        $this->assertTrue($byPort['5061']['bypass'], 'SIP-TLS 5061 must bypass HTTP reverse proxy');

        $this->assertArrayHasKey('10000-20000', $byPort);
        $this->assertTrue($byPort['10000-20000']['bypass'], 'RTP 10000-20000 must bypass HTTP reverse proxy');

        // Web ports can be reverse proxied
        $this->assertArrayHasKey('80', $byPort);
        $this->assertFalse($byPort['80']['bypass'], 'HTTP 80 can be proxied');

        $this->assertArrayHasKey('443', $byPort);
        $this->assertFalse($byPort['443']['bypass'], 'HTTPS 443 can be proxied');

        // WebRTC WSS signaling
        $this->assertArrayHasKey('8089', $byPort);
        $this->assertFalse($byPort['8089']['bypass'], 'WSS 8089 can be proxied');
    }

    public function testIpInCidrIpv4(): void
    {
        // Exact IP match
        $this->assertTrue(ReverseProxyService::ipInCidr('192.168.1.10', '192.168.1.10'));
        $this->assertFalse(ReverseProxyService::ipInCidr('192.168.1.11', '192.168.1.10'));

        // CIDR subnet matches
        $this->assertTrue(ReverseProxyService::ipInCidr('10.20.30.40', '10.0.0.0/8'));
        $this->assertTrue(ReverseProxyService::ipInCidr('172.16.5.99', '172.16.0.0/12'));
        $this->assertTrue(ReverseProxyService::ipInCidr('172.31.255.254', '172.16.0.0/12'));
        $this->assertTrue(ReverseProxyService::ipInCidr('192.168.1.254', '192.168.1.0/24'));

        // CIDR subnet non-matches
        $this->assertFalse(ReverseProxyService::ipInCidr('11.0.0.1', '10.0.0.0/8'));
        $this->assertFalse(ReverseProxyService::ipInCidr('172.32.0.1', '172.16.0.0/12'));
        $this->assertFalse(ReverseProxyService::ipInCidr('192.168.2.1', '192.168.1.0/24'));

        // Edge case: invalid CIDR or IP
        $this->assertFalse(ReverseProxyService::ipInCidr('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(ReverseProxyService::ipInCidr('10.0.0.1', 'invalid-cidr'));
    }

    public function testIpInCidrIpv6(): void
    {
        $this->assertTrue(ReverseProxyService::ipInCidr('::1', '::1'));
        $this->assertTrue(ReverseProxyService::ipInCidr('2001:db8:85a3::8a2e:370:7334', '2001:db8::/32'));
        $this->assertTrue(ReverseProxyService::ipInCidr('fd12:3456:789a:1::1', 'fd00::/8'));

        $this->assertFalse(ReverseProxyService::ipInCidr('2001:db9::1', '2001:db8::/32'));
        $this->assertFalse(ReverseProxyService::ipInCidr('fe80::1', 'fd00::/8'));

        // Cross-family mismatch
        $this->assertFalse(ReverseProxyService::ipInCidr('192.168.1.1', '2001:db8::/32'));
        $this->assertFalse(ReverseProxyService::ipInCidr('2001:db8::1', '192.168.1.0/24'));
    }

    public function testIsIpTrusted(): void
    {
        $trustedList = "127.0.0.1, ::1\n10.0.0.0/8 192.168.0.0/16";

        $this->assertTrue(ReverseProxyService::isIpTrusted('127.0.0.1', $trustedList));
        $this->assertTrue(ReverseProxyService::isIpTrusted('::1', $trustedList));
        $this->assertTrue(ReverseProxyService::isIpTrusted('10.5.9.1', $trustedList));
        $this->assertTrue(ReverseProxyService::ipInCidr('192.168.10.50', '192.168.0.0/16'));

        $this->assertFalse(ReverseProxyService::isIpTrusted('203.0.113.195', $trustedList));
        $this->assertFalse(ReverseProxyService::isIpTrusted('8.8.8.8', $trustedList));
    }

    public function testResolveClientIpWhenDisabled(): void
    {
        // Reverse proxy disabled by default
        $server = [
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '5.6.7.8',
        ];

        $resolved = ReverseProxyService::resolveClientIp('203.0.113.50', $server);
        $this->assertSame('203.0.113.50', $resolved, 'Must ignore X-Forwarded headers when proxy mode is disabled');
    }

    public function testResolveClientIpWithUntrustedRemote(): void
    {
        // Enable reverse proxy mode with trusted proxies: 127.0.0.1, 10.0.0.0/8
        ReverseProxyService::saveSettings([
            'enabled' => 1,
            'trusted_proxies' => '127.0.0.1, 10.0.0.0/8',
            'external_fqdn' => 'pbx.example.com',
        ]);

        // Attacker connects directly from 198.51.100.99 and fakes headers
        $server = [
            'REMOTE_ADDR' => '198.51.100.99',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 127.0.0.1',
            'HTTP_X_REAL_IP' => '127.0.0.1',
        ];

        $resolved = ReverseProxyService::resolveClientIp('198.51.100.99', $server);
        $this->assertSame('198.51.100.99', $resolved, 'Must NEVER trust headers from untrusted immediate connections');
    }

    public function testResolveClientIpWithTrustedProxyChain(): void
    {
        // Enable reverse proxy mode with trusted proxies
        ReverseProxyService::saveSettings([
            'enabled' => 1,
            'trusted_proxies' => '127.0.0.1, 10.0.0.0/8, 172.16.0.0/12',
            'external_fqdn' => 'pbx.example.com',
        ]);

        // Connection comes from local proxy 10.0.0.2 with real client 203.0.113.77
        $server = [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 10.0.0.2',
        ];

        $resolved = ReverseProxyService::resolveClientIp('10.0.0.2', $server);
        $this->assertSame('203.0.113.77', $resolved);

        // Fallback to X-Real-IP
        $server2 = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REAL_IP' => '198.51.100.33',
        ];
        $resolved2 = ReverseProxyService::resolveClientIp('127.0.0.1', $server2);
        $this->assertSame('198.51.100.33', $resolved2);
    }

    public function testSaveSettingsValidation(): void
    {
        // Valid settings save
        $res = ReverseProxyService::saveSettings([
            'enabled' => 1,
            'trusted_proxies' => '127.0.0.1, 192.168.1.0/24',
            'tls_termination' => 're_encrypt',
            'external_fqdn' => 'telecom.mycompany.org',
            'external_port' => 8443,
            'proxy_type' => 'caddy',
        ]);
        $this->assertTrue($res['success']);

        $st = ReverseProxyService::getSettings();
        $this->assertTrue($st['enabled']);
        $this->assertSame('127.0.0.1, 192.168.1.0/24', $st['trusted_proxies']);
        $this->assertSame('re_encrypt', $st['tls_termination']);
        $this->assertSame('telecom.mycompany.org', $st['external_fqdn']);
        $this->assertSame(8443, $st['external_port']);
        $this->assertSame('caddy', $st['proxy_type']);

        // Invalid FQDN rejection
        $resBad = ReverseProxyService::saveSettings([
            'external_fqdn' => 'bad domain with spaces!',
        ]);
        $this->assertFalse($resBad['success']);
    }

    public function testConfigGenerators(): void
    {
        $fqdn = 'pbx.example.com';
        $backendIp = '192.168.1.150';
        $backendPort = 80;

        // Nginx
        $nginx = ReverseProxyService::generateProxyConfig('nginx', $fqdn, $backendIp, $backendPort, 'edge');
        $this->assertStringContainsString('server_name pbx.example.com;', $nginx);
        $this->assertStringContainsString('proxy_pass http://aipbx_web_backend;', $nginx);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $nginx);
        $this->assertStringContainsString('proxy_set_header Connection $connection_upgrade;', $nginx);
        $this->assertStringContainsString('location /ws {', $nginx);
        $this->assertStringContainsString('proxy_read_timeout 3600s;', $nginx);

        // Caddy
        $caddy = ReverseProxyService::generateProxyConfig('caddy', $fqdn, $backendIp, $backendPort, 'edge');
        $this->assertStringContainsString('pbx.example.com {', $caddy);
        $this->assertStringContainsString('reverse_proxy @websocket 192.168.1.150:8089', $caddy);
        $this->assertStringContainsString('reverse_proxy 192.168.1.150:80', $caddy);

        // Traefik
        $traefik = ReverseProxyService::generateProxyConfig('traefik', $fqdn, $backendIp, $backendPort, 'edge');
        $this->assertStringContainsString('Host(`pbx.example.com`)', $traefik);
        $this->assertStringContainsString('url: "http://192.168.1.150:80"', $traefik);
        $this->assertStringContainsString('url: "http://192.168.1.150:8089"', $traefik);

        // HAProxy
        $haproxy = ReverseProxyService::generateProxyConfig('haproxy', $fqdn, $backendIp, $backendPort, 'edge');
        $this->assertStringContainsString('frontend http_in', $haproxy);
        $this->assertStringContainsString('acl is_websocket hdr(Upgrade) -i WebSocket', $haproxy);
        $this->assertStringContainsString('server aipbx1 192.168.1.150:80', $haproxy);

        // Apache
        $apache = ReverseProxyService::generateProxyConfig('apache', $fqdn, $backendIp, $backendPort, 'edge');
        $this->assertStringContainsString('ServerName pbx.example.com', $apache);
        $this->assertStringContainsString('ProxyPass /ws ws://192.168.1.150:8089/ws', $apache);
        $this->assertStringContainsString('ProxyPass / http://192.168.1.150:80/', $apache);
    }

    public function testFirewallGenerators(): void
    {
        $ufw = ReverseProxyService::generateFirewallCommands('ufw');
        $this->assertStringContainsString('ufw allow 5060/udp', $ufw);
        $this->assertStringContainsString('ufw allow 10000:20000/udp', $ufw);
        $this->assertStringContainsString('ufw allow 80/tcp', $ufw);
        $this->assertStringContainsString('ufw allow 443/tcp', $ufw);

        $firewalld = ReverseProxyService::generateFirewallCommands('firewalld');
        $this->assertStringContainsString('firewall-cmd --permanent --add-port=5060/udp', $firewalld);
        $this->assertStringContainsString('firewall-cmd --permanent --add-port=10000-20000/udp', $firewalld);

        $iptables = ReverseProxyService::generateFirewallCommands('iptables');
        $this->assertStringContainsString('iptables -A INPUT -p udp --dport 5060 -j ACCEPT', $iptables);
        $this->assertStringContainsString('iptables -A INPUT -p udp --dport 10000:20000 -j ACCEPT', $iptables);
    }

    public function testIsHttpsDetection(): void
    {
        // Direct HTTPS via $_SERVER['HTTPS'] or SERVER_PORT 443
        $this->assertTrue(ReverseProxyService::isHttps(['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.10']));
        $this->assertTrue(ReverseProxyService::isHttps(['SERVER_PORT' => '443', 'REMOTE_ADDR' => '198.51.100.10']));
        $this->assertFalse(ReverseProxyService::isHttps(['HTTPS' => 'off', 'REMOTE_ADDR' => '198.51.100.10']));

        // X-Forwarded-Proto ignored when reverse proxy is disabled
        $this->assertFalse(ReverseProxyService::isHttps([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        // Enable reverse proxy with trusted 10.0.0.0/8
        ReverseProxyService::saveSettings([
            'enabled' => 1,
            'trusted_proxies' => '127.0.0.1, 10.0.0.0/8',
            'external_fqdn' => 'pbx.example.com',
        ]);

        // Trusted proxy sending X-Forwarded-Proto: https -> true
        $this->assertTrue(ReverseProxyService::isHttps([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        // Multi-hop X-Forwarded-Proto: "https, http" from trusted proxy -> true
        $this->assertTrue(ReverseProxyService::isHttps([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ]));

        // Untrusted remote spoofing X-Forwarded-Proto: https -> false
        $this->assertFalse(ReverseProxyService::isHttps([
            'REMOTE_ADDR' => '198.51.100.77',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
    }
}
