<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * A number that lives on this system stays here, even if an outbound route
 * matches it: on a live system a fax for the local 9276 went to another exchange.
 */
final class InternalFaxNumberTest extends TestCase
{
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $unitIds = [];

    protected function setUp(): void
    {
        Fixtures::load();
    }

    protected function tearDown(): void
    {
        $db = getDB();
        foreach ($this->unitIds as $id) {
            $db->prepare('DELETE FROM sys_did_mappings WHERE id = ?')->execute([$id]);
        }
        foreach ($this->userIds as $id) {
            $db->prepare('DELETE FROM sys_users WHERE id = ?')->execute([$id]);
        }
    }

    private function addUser(string $ext, string $type, int $active = 1): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active) VALUES (?, 'x', 'T', ?, ?, ?)")
            ->execute(["t{$type}{$ext}", $ext, $type, $active]);
        $this->userIds[] = (int) $db->lastInsertId();
    }

    private function addUnit(string $did, int $active = 1): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_did_mappings (did_extension, department_name, is_active) VALUES (?, 'T', ?)")
            ->execute([$did, $active]);
        $this->unitIds[] = (int) $db->lastInsertId();
    }

    private function generalConf(): string
    {
        __syncGeneralDialplanBody();
        return (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
    }

    public function testFaxUserAndFaxUnitNumbersStayOnThisSystem(): void
    {
        $this->addUser('9276', 'fax');
        $this->addUnit('19350');

        $conf = $this->generalConf();

        $this->assertStringContainsString('Goto(from-trunk-fax,9276,1)', $conf);
        $this->assertStringContainsString('Goto(from-trunk-fax,19350,1)', $conf);
        $shared = (string) strstr($conf, '[from-internal-pbx-ortak]');
        $this->assertLessThan(
            strpos($shared, 'include => internal-numbers-pbx'),
            strpos($shared, 'exten => 9276,1'),
            'the fax number must be in the shared internal context'
        );
    }

    public function testInactiveNumbersAreLeftOut(): void
    {
        $this->addUser('9277', 'fax', 0);
        $this->addUnit('19351', 0);

        $conf = $this->generalConf();

        $this->assertStringNotContainsString('from-trunk-fax,9277,1', $conf);
        $this->assertStringNotContainsString('from-trunk-fax,19351,1', $conf);
    }

    public function testASipExtensionKeepsItsNumberAndNothingIsWrittenTwice(): void
    {
        $this->addUser('9278', 'sip');
        $this->addUnit('9278');
        $this->addUser('9279', 'fax');
        $this->addUnit('9279');

        $conf = $this->generalConf();

        $this->assertStringNotContainsString('from-trunk-fax,9278,1', $conf);
        $this->assertSame(1, substr_count($conf, 'Goto(from-trunk-fax,9279,1)'));
    }

    public function testATextValueNeverReachesTheDialplan(): void
    {
        $this->addUnit("1\nexten");

        $this->assertStringNotContainsString('Internal call to fax number 1', $this->generalConf());
    }
}
