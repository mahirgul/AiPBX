<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once '/var/www/html/src/services/Fail2banService.php';
require_once '/var/www/html/src/services/FirewallService.php';

/**
 * Güvenlik sayfalarının (firewall / fail2ban) giriş doğrulaması.
 *
 * İkisi de 2026-08-31 denetiminde bulunan gerçek kusurların düzeltmesi:
 *  - addIgnoreIp() CIDR ekini hiç doğrulamıyordu → "0.0.0.0/0" ile tüm internet
 *    beyaz listeye alınıp fail2ban fiilen devre dışı bırakılabiliyordu.
 *  - removeRichRule() koruması yalnızca tam port eşleşmesine bakıyordu → RTP
 *    aralığını kapsayan bir kural kaldırılabiliyordu.
 */
final class ValidationTest extends TestCase
{
    /**
     * private static bir metodu çağırır.
     * NOT: setAccessible() çağrılmıyor — PHP 8.1'den beri etkisiz, 8.5'te
     * deprecated (testler PHP 8.5'te koşuyor).
     */
    private function ozelMetot(string $class, string $method, array $args)
    {
        return (new ReflectionMethod($class, $method))->invokeArgs(null, $args);
    }

    public static function ipDurumlari(): array
    {
        return [
            'tum internet'      => ['0.0.0.0/0',     false],
            'asiri genis /1'    => ['1.2.3.4/1',     false],
            'asiri genis /7'    => ['10.0.0.0/7',    false],
            'mesru ozel ag /8'  => ['10.0.0.0/8',    true],
            'duz IP'            => ['192.0.2.241',   true],
            'normal subnet'     => ['192.0.2.0/24',  true],
            'tek host /32'      => ['192.0.2.5/32',  true],
            'gecersiz prefix'   => ['192.0.2.0/33',  false],
            'sayisal olmayan'   => ['1.2.3.4/abc',   false],
            'IP degil'          => ['notanip',       false],
            'bos'               => ['',              false],
            'IPv6 localhost'    => ['::1',           true],
            'IPv6 subnet /48'   => ['2001:db8::/48', true],
            'IPv6 asiri genis'  => ['2001:db8::/16', false],
        ];
    }

    #[DataProvider('ipDurumlari')]
    public function testValidateIpOrCidr(string $input, bool $gecerli): void
    {
        $err = $this->ozelMetot('Fail2banService', 'validateIpOrCidr', [$input]);
        $this->assertSame(
            $gecerli,
            $err === null,
            "'{$input}' icin beklenen " . ($gecerli ? 'KABUL' : 'RED') . ", donen hata: " . var_export($err, true)
        );
    }

    public static function portDurumlari(): array
    {
        return [
            'SSH'                  => ['22',          true],
            'HTTP'                 => ['80',          true],
            'HTTPS'                => ['443',         true],
            'SIP'                  => ['5060',        true],
            'RTP araligi tam'      => ['10000-20000', true],
            'RTP icinde tek port'  => ['15000',       true],
            'RTP ile kesisen'      => ['19000-25000', true],
            'SSH kapsayan aralik'  => ['20-30',       true],
            'ilgisiz port'         => ['9999',        false],
            'ilgisiz aralik'       => ['30000-30010', false],
            'ters yazilmis aralik' => ['30-20',       true],
        ];
    }

    #[DataProvider('portDurumlari')]
    public function testCoversProtectedPort(string $port, bool $korumali): void
    {
        $r = $this->ozelMetot('FirewallService', 'coversProtectedPort', [$port]);
        $this->assertSame($korumali, $r, "port '{$port}' icin koruma beklentisi tutmadi");
    }

    public function testKritikServislerKorumaListesinde(): void
    {
        foreach (['ssh', 'http', 'https', 'sip'] as $s) {
            $this->assertContains(
                $s,
                FirewallService::PROTECTED_SERVICES,
                "'{$s}' korumali servis listesinde degil — service name=\"{$s}\" kurali kaldirilabilir"
            );
        }
    }

    public function testLocalhostBeyazListedenCikarilamaz(): void
    {
        $this->assertContains('127.0.0.0/8', Fail2banService::PROTECTED_IGNOREIPS);
        $this->assertContains('::1', Fail2banService::PROTECTED_IGNOREIPS);
    }

    public function testKritikPortlarKorumaListesinde(): void
    {
        foreach (['22', '80', '443', '5060'] as $p) {
            $this->assertContains($p, FirewallService::PROTECTED_PORTS);
        }
    }
}
