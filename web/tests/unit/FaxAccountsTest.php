<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/FaxSettingsService.php';
require_once dirname(__DIR__, 2) . '/src/services/FaxCoverService.php';
require_once dirname(__DIR__, 2) . '/src/repositories/FaxInboxRepository.php';
require_once dirname(__DIR__, 2) . '/src/repositories/SystemUserRepository.php';
require_once dirname(__DIR__, 2) . '/src/repositories/ExtensionRepository.php';
require_once dirname(__DIR__, 2) . '/src/services/RouteService.php';
require_once dirname(__DIR__, 2) . '/auth.php';

final class FaxAccountsTest extends TestCase
{
    private const NUMBERS = ['88801', '88802', '88803'];

    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'test';
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    private function clean(): void
    {
        $db = getDB();
        $db->exec("DELETE f FROM pbx_fax_number_users f JOIN sys_users u ON u.id = f.user_id WHERE u.username LIKE 'zzfax%'");
        $db->exec("DELETE FROM pbx_fax_cover_templates WHERE name LIKE 'zz%'");
        $db->exec("DELETE FROM sys_did_mappings WHERE did_extension IN ('88801','88802','88803')");
        $db->exec("DELETE FROM sys_users WHERE username LIKE 'zzfax%'");
        $db->exec("DELETE FROM sys_did_mappings WHERE department_name LIKE 'zzUnit%'");
        $db->exec("DELETE f FROM pbx_fax_number_users f LEFT JOIN sys_did_mappings m ON m.id = f.did_mapping_id WHERE m.id IS NULL");
    }

    private function save(array $data): array
    {
        return FaxSettingsService::saveAccount($data + ['csrf_token' => 'test', 'is_active' => 1, 'fax_mail' => 1, 'create_login' => isset($data['username']) ? 1 : 0]);
    }

    private function userId(string $username): int
    {
        return (int) DBHelper::fetchColumn('SELECT id FROM sys_users WHERE username = ?', [$username]);
    }

    public function testNumbersAreReadFromAField(): void
    {
        $this->assertSame(['9391', '9392'], FaxSettingsService::parseNumbers(" 9391, 9392;9391\n"));
        $this->assertSame([], FaxSettingsService::parseNumbers(' , '));
    }

    public function testTheLoginNumberIsOneNobodyElseHas(): void
    {
        $this->assertSame('1', FaxSettingsService::loginNumber(['1', '2'], []));
        $this->assertSame('2', FaxSettingsService::loginNumber(['1', '2'], ['1']));
        $this->assertNull(FaxSettingsService::loginNumber(['1'], ['1']));
    }

    public function testOneUserManyNumbers(): void
    {
        $res = $this->save(['department_name' => 'Unit A', 'did_extension' => '88801, 88802', 'username' => 'zzfaxa', 'notification_email' => 'zza@test.invalid']);
        $this->assertNotEmpty($res['success'] ?? false, json_encode($res));
        $id = $this->userId('zzfaxa');
        $this->assertSame(['88801', '88802'], FaxInboxRepository::getAllowedDIDs($id, '88801'));
        $this->assertSame('fax', DBHelper::fetchColumn('SELECT extension_type FROM sys_users WHERE id = ?', [$id]));

        $this->save(['did_id' => "u{$id}", 'department_name' => 'Unit A', 'did_extension' => '88802', 'username' => 'zzfaxa']);
        $this->assertSame(['88802'], FaxInboxRepository::getAllowedDIDs($id, ''));
        $this->assertFalse(DBHelper::fetchColumn("SELECT id FROM sys_did_mappings WHERE did_extension = '88801'"), 'a number nobody uses goes');
    }

    public function testASharedNumberIsSeenByBothAndOutlivesOne(): void
    {
        $this->save(['department_name' => 'Unit A', 'did_extension' => '88801', 'username' => 'zzfaxa']);
        $res = $this->save(['department_name' => 'Unit B', 'did_extension' => '88801', 'username' => 'zzfaxb']);
        $this->assertNotEmpty($res['success'] ?? false, json_encode($res));
        $a = $this->userId('zzfaxa');
        $b = $this->userId('zzfaxb');
        $this->assertContains('88801', FaxInboxRepository::getAllowedDIDs($a, ''));
        $this->assertContains('88801', FaxInboxRepository::getAllowedDIDs($b, ''));
        $this->assertSame(1, (int) DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_did_mappings WHERE did_extension = '88801'"));
        $this->assertNull(DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$b]), 'two rows with one extension would look like one person');
        $this->assertSame('Unit A', DBHelper::fetchColumn("SELECT department_name FROM sys_did_mappings WHERE did_extension = '88801'"), 'the second user does not rename a shared number');

        $account = array_values(array_filter(FaxSettingsService::accounts(), static fn(array $x): bool => $x['id'] === "u{$b}"))[0];
        $this->assertSame(['88801'], $account['numbers']);
        $this->assertSame(['88801'], $account['shared']);

        $this->assertNotEmpty(FaxSettingsService::deleteAccount(['csrf_token' => 'test', 'did_id' => "u{$a}"])['success'] ?? false);
        $this->assertContains('88801', FaxInboxRepository::getAllowedDIDs($b, ''));
        $this->assertSame($b, (int) DBHelper::fetchColumn("SELECT assigned_user_id FROM sys_did_mappings WHERE did_extension = '88801'"));
        $this->assertNotEmpty(FaxSettingsService::deleteAccount(['csrf_token' => 'test', 'did_id' => "u{$b}"])['success'] ?? false);
        $this->assertFalse(DBHelper::fetchColumn("SELECT id FROM sys_did_mappings WHERE did_extension = '88801'"));
    }

    public function testAnotherUsersExtensionIsRefused(): void
    {
        $sip = DBHelper::fetchColumn("SELECT extension FROM sys_users WHERE extension_type = 'sip' AND extension <> '' LIMIT 1");
        if ($sip === false || $sip === null) {
            $this->markTestSkipped('no SIP extension here');
        }
        $this->assertFalse($this->save(['department_name' => 'X', 'did_extension' => (string) $sip, 'username' => 'zzfaxc'])['success'] ?? true);
        $this->assertFalse($this->save(['department_name' => 'X', 'did_extension' => '12ab', 'username' => 'zzfaxc'])['success'] ?? true);
    }

    public function testFaxUsersLeaveTheOtherLists(): void
    {
        $this->save(['department_name' => 'Unit A', 'did_extension' => '88801', 'username' => 'zzfaxa']);
        $this->assertNotContains('zzfaxa', array_column(SystemUserRepository::allWithRoleName(), 'username'));
        $this->assertNotContains('zzfaxa', array_column(ExtensionRepository::allWithExtension(), 'username'));
    }

    public function testATemplateIsItsOwners(): void
    {
        $a = $this->userId('admin') ?: 1;
        $this->assertNotEmpty(FaxCoverService::saveTemplate(['csrf_token' => 'test', 'template_name' => 'zzmine', 'template_company' => 'Mine Ltd', 'template_logo' => 1], $a)['success'] ?? false);
        $this->assertFalse(FaxCoverService::saveTemplate(['csrf_token' => 'test', 'template_name' => 'zzmine'], $a)['success'] ?? true);
        $id = (int) array_values(array_filter(FaxCoverService::templatesFor($a), static fn(array $t): bool => $t['name'] === 'zzmine'))[0]['id'];
        $this->assertSame('Mine Ltd', FaxCoverService::settingsFor(['cover_template' => $id], $a, 'fax_user')['company']);
        $this->assertNotSame('Mine Ltd', FaxCoverService::settingsFor(['cover_template' => $id], $a + 99999, 'fax_user')['company'], 'someone else cannot use it by guessing the id');
        $this->assertSame('Mine Ltd', FaxCoverService::settingsFor(['cover_template' => $id], $a + 99999, 'admin')['company']);
        FaxCoverService::deleteTemplate(['csrf_token' => 'test', 'template_id' => $id], $a + 99999);
        $this->assertNotSame([], array_filter(FaxCoverService::templatesFor($a), static fn(array $t): bool => $t['name'] === 'zzmine'), 'only the owner deletes it');
    }

    public function testFaxUsersCannotDeleteReceivedFaxesByDefault(): void
    {
        $this->assertStringContainsString("('fax_user','fax_inbox',1,1,1,0,", (string) file_get_contents(dirname(__DIR__, 3) . '/db/seed.sql'));
    }

    private function sipUser(): int
    {
        DBHelper::insert('sys_users', ['username' => 'zzfaxsip', 'password_hash' => '', 'full_name' => 'Sip Person', 'email' => 'zzperson@test.invalid', 'role' => 'user', 'extension' => '88899', 'extension_type' => 'sip', 'sip_password' => 'x']);
        return $this->userId('zzfaxsip');
    }

    public function testAPersonWithAnAccountIsLinkedWithoutASecondOne(): void
    {
        $person = $this->sipUser();
        $res = $this->save(['department_name' => 'zzUnit', 'did_extension' => '88801, 88802', 'notification_email' => 'zzperson@test.invalid', 'linked_users' => [$person], 'linked_mail' => 1]);
        $this->assertNotEmpty($res['success'] ?? false, json_encode($res));
        $this->assertSame(1, (int) DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE username LIKE 'zzfax%'"), 'no fax login was made');
        $this->assertSame(['88801', '88802'], FaxInboxRepository::sendableNumbers($person), 'the fax numbers, not the phone extension');
        $this->assertContains('88801', FaxInboxRepository::getAllowedDIDs($person, '88899'));
        $this->assertSame('zzperson@test.invalid', DBHelper::fetchColumn("SELECT notification_email FROM sys_did_mappings WHERE did_extension = '88801'"), 'an address on an account is fine for a unit');

        $_SESSION['user_id'] = $person;
        $_SESSION['user_role'] = 'user';
        try {
            $this->assertTrue(userIsOnFaxNumber());
            $this->assertTrue(hasModulePermission('fax_inbox', 'access'));
            $this->assertTrue(hasModulePermission('fax_send', 'edit'));
            $this->assertFalse(hasModulePermission('fax_inbox', 'delete'), 'deleting stays with the role');
        } finally {
            unset($_SESSION['user_id'], $_SESSION['user_role']);
        }

        $row = array_values(array_filter(FaxSettingsService::accounts(), static fn(array $a): bool => $a['department_name'] === 'zzUnit' && $a['numbers'] === ['88801']))[0];
        $this->assertSame([$person], $row['linked']);
        $this->save(['did_id' => $row['id'], 'department_name' => 'zzUnit', 'did_extension' => '88801', 'linked_users' => []]);
        $this->assertNotContains('88801', FaxInboxRepository::sendableNumbers($person), 'taking someone off works');
        $this->assertTrue((bool) DBHelper::fetchColumn("SELECT id FROM sys_did_mappings WHERE did_extension = '88801'"), 'the unit stays without them');
    }

    public function testAFaxLoginTakesAnAddressThatIsAlreadyOnAnAccount(): void
    {
        $this->sipUser();
        $res = $this->save(['department_name' => 'zzUnit', 'did_extension' => '88803', 'create_login' => 1, 'username' => 'zzfaxd', 'notification_email' => 'zzperson@test.invalid']);
        $this->assertNotEmpty($res['success'] ?? false, json_encode($res));
        $id = $this->userId('zzfaxd');
        $this->assertSame('', (string) DBHelper::fetchColumn('SELECT email FROM sys_users WHERE id = ?', [$id]), 'the login stays unique');
        $this->assertSame('zzperson@test.invalid', DBHelper::fetchColumn("SELECT notification_email FROM sys_did_mappings WHERE did_extension = '88803'"));
    }

    public function testARouteKeepsItsPlus(): void
    {
        $_SESSION['csrf_token'] = 'test';
        $res = RouteService::saveDIDRoute(['csrf_token' => 'test', 'did_number' => '+18885550123', 'title' => 'zzplus', 'dest_type' => 'hangup', 'dest_id' => '']);
        $db = getDB();
        try {
            $this->assertNotEmpty($res['success'] ?? false, json_encode($res));
            $this->assertSame('+18885550123', DBHelper::fetchColumn("SELECT did_number FROM pbx_dids WHERE title = 'zzplus'"));
        } finally {
            $db->exec("DELETE FROM pbx_dids WHERE title = 'zzplus'");
        }
    }
}
