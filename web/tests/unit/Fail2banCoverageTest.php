<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/Fail2banService.php';

final class Fail2banCoverageTest extends TestCase
{
    private static string $fakeBinDir = '';
    private static string $origPath = '';

    public static function setUpBeforeClass(): void
    {
        // Safe root helper stub: create a mock `sudo` script in PATH to guarantee
        // that the real /usr/local/sbin/aipbx-priv is NEVER executed from tests.
        self::$fakeBinDir = sys_get_temp_dir() . '/zz_cov_f2b_' . bin2hex(random_bytes(6));
        if (!is_dir(self::$fakeBinDir)) {
            mkdir(self::$fakeBinDir, 0755, true);
        }

        $sudoScript = self::$fakeBinDir . '/sudo';
        $scriptContent = <<<'SH'
#!/bin/sh
cmd="$*"
case "$cmd" in
    *"-n /usr/local/sbin/aipbx-priv f2b status asterisk"*)
        cat << 'EOF'
Status for the jail: asterisk
|- Filter
|  |- Currently failed: 0
|  |- Total failed: 12
|  `- File list: /var/log/asterisk/security.log
`- Actions
   |- Currently banned: 2
   |- Total banned: 45
   `- Banned IP list: 198.51.100.1 203.0.113.5
EOF
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b status"*)
        cat << 'EOF'
Status
|- Number of jail: 2
`- Jail list: asterisk, sshd
EOF
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b get asterisk bantime"*)
        echo "3600"
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b get asterisk findtime"*)
        echo "600"
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b get asterisk maxretry"*)
        echo "5"
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b get asterisk ignoreip"*)
        cat << 'EOF'
These IP addresses/subnets are ignored:
|- 127.0.0.0/8
|- ::1
`- 192.168.10.0/24
EOF
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b set"*)
        echo "1"
        exit 0
        ;;
    *"-n /usr/local/sbin/aipbx-priv f2b install-override"*)
        echo "Override installed"
        exit 0
        ;;
    *)
        echo "Mock sudo unhandled: $cmd" >&2
        exit 1
        ;;
esac
SH;
        file_put_contents($sudoScript, $scriptContent);
        chmod($sudoScript, 0755);

        self::$origPath = (string)getenv('PATH');
        putenv('PATH=' . self::$fakeBinDir . ':' . self::$origPath);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$origPath !== '') {
            putenv('PATH=' . self::$origPath);
        }
        if (self::$fakeBinDir !== '' && is_dir(self::$fakeBinDir)) {
            @unlink(self::$fakeBinDir . '/sudo');
            @rmdir(self::$fakeBinDir);
        }
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testListJailsParsesOutput(): void
    {
        $jails = Fail2banService::listJails();
        $this->assertSame(['asterisk', 'sshd'], $jails);
    }

    public function testJailDetailParsesCountersAndSettings(): void
    {
        // 1. Existing jail
        $detail = Fail2banService::jailDetail('asterisk');
        $this->assertNotNull($detail);
        $this->assertSame('asterisk', $detail['name']);
        $this->assertSame(2, $detail['currently_banned']);
        $this->assertSame(45, $detail['total_banned']);
        $this->assertSame(['198.51.100.1', '203.0.113.5'], $detail['banned_ips']);
        $this->assertSame(3600, $detail['bantime']);
        $this->assertSame(600, $detail['findtime']);
        $this->assertSame(5, $detail['maxretry']);

        // 2. Unknown jail returns null
        $this->assertNull(Fail2banService::jailDetail('non_existent_jail'));
    }

    public function testGetIgnoreIpsParsesWhitelist(): void
    {
        $ips = Fail2banService::getIgnoreIps();
        $this->assertSame(['127.0.0.0/8', '::1', '192.168.10.0/24'], $ips);
    }

    public function testValidateIpOrCidrReflection(): void
    {
        $method = new ReflectionMethod(Fail2banService::class, 'validateIpOrCidr');

        // Plain valid IPs
        $this->assertNull($method->invoke(null, '192.168.1.1'));
        $this->assertNull($method->invoke(null, '10.0.0.5'));
        $this->assertNull($method->invoke(null, '2001:db8::1'));

        // Invalid IP strings
        $this->assertSame(t('srv_f2b.err_ip'), $method->invoke(null, 'not-an-ip'));
        $this->assertSame(t('srv_f2b.err_ip'), $method->invoke(null, '999.999.999.999'));

        // Bad CIDR suffix
        $this->assertSame(t('srv_f2b.err_cidr'), $method->invoke(null, '192.168.1.0/abc'));

        // IPv4 prefix out of bounds
        $this->assertSame(sprintf(t('srv_f2b.err_too_wide'), 0, 8), $method->invoke(null, '0.0.0.0/0'));
        $this->assertSame(sprintf(t('srv_f2b.err_too_wide'), 7, 8), $method->invoke(null, '10.0.0.0/7'));
        $this->assertSame(sprintf(t('srv_f2b.err_cidr_max'), 32), $method->invoke(null, '192.168.1.0/33'));

        // IPv4 valid CIDR blocks
        $this->assertNull($method->invoke(null, '10.0.0.0/8'));
        $this->assertNull($method->invoke(null, '172.16.0.0/12'));
        $this->assertNull($method->invoke(null, '192.168.1.0/24'));
        $this->assertNull($method->invoke(null, '192.168.1.50/32'));

        // IPv6 prefix out of bounds
        $this->assertSame(sprintf(t('srv_f2b.err_too_wide'), 16, 32), $method->invoke(null, '2001:db8::/16'));
        $this->assertSame(sprintf(t('srv_f2b.err_cidr_max'), 128), $method->invoke(null, '2001:db8::/129'));

        // IPv6 valid CIDR blocks
        $this->assertNull($method->invoke(null, '2001:db8::/32'));
        $this->assertNull($method->invoke(null, '2001:db8::/64'));
        $this->assertNull($method->invoke(null, '2001:db8::1/128'));
    }

    public function testAddIgnoreIpValidationAndRefusal(): void
    {
        $csrf = getCSRFToken();

        // 1. Invalid CSRF
        $resCsrf = Fail2banService::addIgnoreIp('192.168.1.1', 'bad_csrf');
        $this->assertFalse($resCsrf['success']);
        $this->assertSame(t('common.invalid_csrf'), $resCsrf['error'] ?? '');

        // 2. Refused bad IP
        $resBad = Fail2banService::addIgnoreIp('999.1.1.1', $csrf);
        $this->assertFalse($resBad['success']);
        $this->assertSame(t('srv_f2b.err_ip'), $resBad['error'] ?? '');

        // 3. Refused too wide network (0.0.0.0/0)
        $resWide = Fail2banService::addIgnoreIp('0.0.0.0/0', $csrf);
        $this->assertFalse($resWide['success']);
        $this->assertStringContainsString('wide', strtolower($resWide['error'] ?? ''));
    }

    public function testRemoveIgnoreIpRefusesProtectedLocalhost(): void
    {
        $csrf = getCSRFToken();

        // 1. Invalid CSRF
        $resCsrf = Fail2banService::removeIgnoreIp('192.168.1.1', 'bad_csrf');
        $this->assertFalse($resCsrf['success']);
        $this->assertSame(t('common.invalid_csrf'), $resCsrf['error'] ?? '');

        // 2. Refused removing 127.0.0.0/8
        $resV4 = Fail2banService::removeIgnoreIp('127.0.0.0/8', $csrf);
        $this->assertFalse($resV4['success']);
        $this->assertSame(sprintf(t('srv_f2b.err_localhost'), '127.0.0.0/8'), $resV4['error'] ?? '');

        // 3. Refused removing ::1
        $resV6 = Fail2banService::removeIgnoreIp('::1', $csrf);
        $this->assertFalse($resV6['success']);
        $this->assertSame(sprintf(t('srv_f2b.err_localhost'), '::1'), $resV6['error'] ?? '');
    }

    public function testUpdateJailConfigSettingsValidation(): void
    {
        $csrf = getCSRFToken();

        // 1. Invalid CSRF
        $resCsrf = Fail2banService::updateJailConfig('asterisk', 3600, 600, 5, 'bad_csrf');
        $this->assertFalse($resCsrf['success']);
        $this->assertSame(t('common.invalid_csrf'), $resCsrf['error'] ?? '');

        // 2. Unknown jail name
        $resJail = Fail2banService::updateJailConfig('unknown_jail', 3600, 600, 5, $csrf);
        $this->assertFalse($resJail['success']);
        $this->assertSame(t('srv_f2b.err_jail'), $resJail['error'] ?? '');

        // 3. Bantime under 60
        $resBan = Fail2banService::updateJailConfig('asterisk', 59, 600, 5, $csrf);
        $this->assertFalse($resBan['success']);
        $this->assertSame(t('srv_f2b.err_values'), $resBan['error'] ?? '');

        // 4. Findtime under 60
        $resFind = Fail2banService::updateJailConfig('asterisk', 3600, 59, 5, $csrf);
        $this->assertFalse($resFind['success']);
        $this->assertSame(t('srv_f2b.err_values'), $resFind['error'] ?? '');

        // 5. Maxretry under 1
        $resRetry = Fail2banService::updateJailConfig('asterisk', 3600, 600, 0, $csrf);
        $this->assertFalse($resRetry['success']);
        $this->assertSame(t('srv_f2b.err_values'), $resRetry['error'] ?? '');

        // 6. Negative values
        $resNeg = Fail2banService::updateJailConfig('asterisk', -100, -100, -5, $csrf);
        $this->assertFalse($resNeg['success']);
        $this->assertSame(t('srv_f2b.err_values'), $resNeg['error'] ?? '');
    }

    public function testUnbanIpValidation(): void
    {
        $csrf = getCSRFToken();

        // 1. Invalid CSRF
        $resCsrf = Fail2banService::unbanIp('asterisk', '198.51.100.1', 'bad_csrf');
        $this->assertFalse($resCsrf['success']);
        $this->assertSame(t('common.invalid_csrf'), $resCsrf['error'] ?? '');

        // 2. Unknown jail
        $resJail = Fail2banService::unbanIp('unknown_jail', '198.51.100.1', $csrf);
        $this->assertFalse($resJail['success']);
        $this->assertSame(t('srv_f2b.err_jail_ip'), $resJail['error'] ?? '');

        // 3. Invalid IP
        $resIp = Fail2banService::unbanIp('asterisk', 'not-an-ip', $csrf);
        $this->assertFalse($resIp['success']);
        $this->assertSame(t('srv_f2b.err_jail_ip'), $resIp['error'] ?? '');
    }

    public function testProtectedConstants(): void
    {
        $this->assertSame(['127.0.0.0/8', '::1'], Fail2banService::PROTECTED_IGNOREIPS);
        $this->assertSame('/etc/fail2ban/jail.d/zz-ai-pbx.local', Fail2banService::OVERRIDE_FILE);
        $this->assertSame('/var/lib/aipbx/fail2ban-override.local', Fail2banService::STAGING_FILE);
    }
}
