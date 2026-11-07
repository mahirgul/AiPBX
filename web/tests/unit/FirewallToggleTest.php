<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/FirewallService.php';

/**
 * Turning a firewall rule off and on, and deleting one that is off, against a recorded firewalld
 * (PrivHelper::$fake): what reaches aipbx-priv and what fw_disabled_rules keeps.
 */
final class FirewallToggleTest extends TestCase
{
    private const OVERVIEW = <<<'TXT'
default public
iface eth0 public
### zone public
public (default, active)
  target: default
  interfaces: eth0
  services: ssh
  ports: 8080/tcp
  rich rules:
TXT;

    /** @var list<list<string>> */
    private array $calls = [];

    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'test';
        $this->calls = [];
        PrivHelper::$fake = function (array $args): array {
            $this->calls[] = $args;
            return ['success' => true, 'output' => $args === ['fw', 'overview'] ? self::OVERVIEW : ''];
        };
        getDB()->exec("DELETE FROM fw_disabled_rules WHERE value = '8080/tcp'");
    }

    protected function tearDown(): void
    {
        PrivHelper::$fake = null;
        getDB()->exec("DELETE FROM fw_disabled_rules WHERE value = '8080/tcp'");
    }

    /** The aipbx-priv calls that change a rule, as one line each (overview and reads left out). */
    private function changes(): array
    {
        return array_values(array_map(static fn(array $a): string => implode(' ', $a),
            array_filter($this->calls, static fn(array $a): bool => in_array('8080', $a, true))));
    }

    private function disabledRow(): array|false
    {
        $st = getDB()->prepare("SELECT scope, zones FROM fw_disabled_rules WHERE value = '8080/tcp'");
        $st->execute();
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public function testACommonRuleTurnedOffLeavesFirewalldAndIsKept(): void
    {
        $res = FirewallService::toggleRule('common', 'port', '8080/tcp', false, 'test');
        $this->assertTrue($res['success'], (string) ($res['error'] ?? ''));
        $changes = $this->changes();
        $this->assertSame(['fw zone public remove-port 8080 tcp', 'fw zone public remove-port 8080 tcp --permanent'], $changes,
            'out of the running firewall and for good, not only until a reload');
        $this->assertSame(['scope' => 'common', 'zones' => 'public'], $this->disabledRow());
    }

    public function testTurnedOnItComesBackAsItWasAndLeavesTheList(): void
    {
        FirewallService::toggleRule('common', 'port', '8080/tcp', false, 'test');
        $this->calls = [];
        $res = FirewallService::toggleRule('common', 'port', '8080/tcp', true, 'test');
        $this->assertTrue($res['success'], (string) ($res['error'] ?? ''));
        $this->assertSame(['fw zone public add-port 8080 tcp', 'fw zone public add-port 8080 tcp --permanent'], $this->changes(),
            'back in the zone it was taken from');
        $this->assertFalse($this->disabledRow());
    }

    public function testARuleThatIsOffIsDeletedFromTheListOnly(): void
    {
        FirewallService::toggleRule('common', 'port', '8080/tcp', false, 'test');
        $this->calls = [];
        $this->assertTrue(FirewallService::deleteDisabled('common', 'port', '8080/tcp', 'test')['success']);
        $this->assertFalse($this->disabledRow());
        $this->assertSame([], $this->changes(), 'nothing to take out of firewalld: it was already off');
    }

    public function testABadTokenChangesNothing(): void
    {
        $this->assertFalse(FirewallService::toggleRule('common', 'port', '8080/tcp', false, 'wrong')['success']);
        $this->assertSame([], $this->calls);
        $this->assertFalse($this->disabledRow());
    }
}
