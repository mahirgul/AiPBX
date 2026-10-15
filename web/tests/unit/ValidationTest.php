<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/Fail2banService.php';
require_once dirname(__DIR__, 2) . '/src/services/FirewallService.php';

/**
 * Input validation of the security pages (firewall / fail2ban).
 *
 * Both fix real flaws found in the 2026-08-31 audit:
 *  - addIgnoreIp() never validated the CIDR suffix → with "0.0.0.0/0" the
 *    whole internet could be whitelisted, effectively disabling fail2ban.
 *  - removeRichRule()'s protection only looked at exact port matches → a rule
 *    covering the RTP range could be removed.
 */
final class ValidationTest extends TestCase
{
    /**
     * Calls a private static method.
     * NOTE: setAccessible() is not called — it has no effect since PHP 8.1
     * and is deprecated in 8.5 (the tests run on PHP 8.5).
     */
    private function privateMethod(string $class, string $method, array $args)
    {
        return (new ReflectionMethod($class, $method))->invokeArgs(null, $args);
    }

    public static function ipCases(): array
    {
        return [
            'tum internet'      => ['0.0.0.0/0',     false],
            'asiri genis /1'    => ['1.2.3.4/1',     false],
            'asiri genis /7'    => ['10.0.0.0/7',    false],
            'legit private /8'  => ['10.0.0.0/8',    true],
            'duz IP'            => ['192.0.2.241',   true],
            'normal subnet'     => ['192.0.2.0/24',  true],
            'tek host /32'      => ['192.0.2.5/32',  true],
            'invalid prefix'   => ['192.0.2.0/33',  false],
            'not numeric'       => ['1.2.3.4/abc',   false],
            'not an IP'          => ['notanip',       false],
            'empty'             => ['',              false],
            'IPv6 localhost'    => ['::1',           true],
            'IPv6 subnet /48'   => ['2001:db8::/48', true],
            'IPv6 asiri genis'  => ['2001:db8::/16', false],
        ];
    }

    #[DataProvider('ipCases')]
    public function testValidateIpOrCidr(string $input, bool $valid): void
    {
        $err = $this->privateMethod('Fail2banService', 'validateIpOrCidr', [$input]);
        $this->assertSame(
            $valid,
            $err === null,
            "'{$input}': expected " . ($valid ? 'ACCEPT' : 'REJECT') . ", error returned: " . var_export($err, true)
        );
    }

    public static function portCases(): array
    {
        return [
            'SSH'                  => ['22',          true],
            'HTTP'                 => ['80',          true],
            'HTTPS'                => ['443',         true],
            'SIP'                  => ['5060',        true],
            'RTP araligi tam'      => ['10000-20000', true],
            'single port in RTP'   => ['15000',       true],
            'overlaps RTP'         => ['19000-25000', true],
            'range covering SSH'   => ['20-30',       true],
            'unrelated port'       => ['9999',        false],
            'unrelated range'      => ['30000-30010', false],
            'reversed range'       => ['30-20',       true],
        ];
    }

    #[DataProvider('portCases')]
    public function testCoversProtectedPort(string $port, bool $protected): void
    {
        $r = $this->privateMethod('FirewallService', 'coversProtectedPort', [$port]);
        $this->assertSame($protected, $r, "port '{$port}': protection not as expected");
    }

    public function testCriticalServicesAreProtected(): void
    {
        foreach (['ssh', 'http', 'https', 'sip'] as $s) {
            $this->assertContains(
                $s,
                FirewallService::PROTECTED_SERVICES,
                "'{$s}' is not in the protected services: its service name=\"{$s}\" rule could be removed"
            );
        }
    }

    public function testLocalhostCannotLeaveTheAllowList(): void
    {
        $this->assertContains('127.0.0.0/8', Fail2banService::PROTECTED_IGNOREIPS);
        $this->assertContains('::1', Fail2banService::PROTECTED_IGNOREIPS);
    }

    public function testCriticalPortsAreProtected(): void
    {
        foreach (['22', '80', '443', '5060'] as $p) {
            $this->assertContains($p, FirewallService::PROTECTED_PORTS);
        }
    }
}
