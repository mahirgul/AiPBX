<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/UserImportService.php';

final class UserImportTest extends TestCase
{
    private PDO $db;
    private const PREFIX = 'csvtest_';

    /** Generated at test time: no password-like constant in the repo (secret scanners). */
    private static function tempSecret(): string
    {
        return 'T' . bin2hex(random_bytes(8));
    }

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->db->prepare('DELETE FROM sys_users WHERE username LIKE ?')->execute([self::PREFIX . '%']);
    }

    public function testParseHandlesBomSemicolonAndTurkishHeaders(): void
    {
        $csv = "\xEF\xBB\xBFKullanıcı Adı;Ad Soyad;E-posta;Dahili\r\ncsvtest_a;Çağrı Öztürk;a@example.com;7101\r\n\r\ncsvtest_b;Şule İnce;;7102\r\n";
        $res = UserImportService::parse($csv);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertCount(2, $res['rows']);
        $first = $res['rows'][2];
        $this->assertSame('csvtest_a', $first['username']);
        $this->assertSame('Çağrı Öztürk', $first['full_name']);
        $this->assertSame('7101', $first['extension']);
        $this->assertSame('', $res['rows'][4]['email']); // line 3 is empty, skipped
    }

    public function testParseConvertsWindows1254CommaCsv(): void
    {
        $csv = mb_convert_encoding("username,full_name\ncsvtest_c,Gülşen Ağaoğlu\n", 'Windows-1254', 'UTF-8');
        $res = UserImportService::parse($csv);
        $this->assertTrue($res['success']);
        $this->assertSame('Gülşen Ağaoğlu', $res['rows'][2]['full_name']);
    }

    public function testParseRejectsMissingRequiredColumns(): void
    {
        $res = UserImportService::parse("eposta;dahili\nx@example.com;7103\n");
        $this->assertFalse($res['success']);
    }

    public function testValidateFindsDuplicatesAndBadValues(): void
    {
        $rows = [
            2 => ['username' => 'csvtest_d', 'full_name' => 'D', 'email' => '', 'extension' => '7104', 'role' => '', 'password' => ''],
            3 => ['username' => 'CSVTEST_D', 'full_name' => 'D2', 'email' => 'bozuk', 'extension' => '7104', 'role' => '', 'password' => ''],
            4 => ['username' => 'csv test', 'full_name' => '', 'email' => '', 'extension' => '71a', 'role' => 'yok_boyle_rol', 'password' => 'kisa'],
        ];
        $v = UserImportService::validate($rows, 'cc_agent');

        $this->assertSame([], $v[2]['errors']);
        $this->assertSame('cc_agent', $v[2]['row']['role']); // the default role was applied
        $this->assertCount(3, $v[3]['errors']); // duplicate username + email + extension
        $this->assertGreaterThanOrEqual(5, count($v[4]['errors']));
    }

    public function testValidateRejectsExistingUsernameAndExtension(): void
    {
        $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, is_active) VALUES ('csvtest_mevcut', 'x', 'Mevcut', 'cc_agent', '7105', 1)")->execute();
        $v = UserImportService::validate([
            2 => ['username' => 'csvtest_mevcut', 'full_name' => 'X', 'email' => '', 'extension' => '', 'role' => '', 'password' => ''],
            3 => ['username' => 'csvtest_yeni', 'full_name' => 'Y', 'email' => '', 'extension' => '7105', 'role' => '', 'password' => ''],
        ], 'cc_agent');
        $this->assertStringContainsString('zaten var', $v[2]['errors'][0]);
        $this->assertStringContainsString('başka bir kullanıcıya', $v[3]['errors'][0]);
    }

    public function testImportCreatesUsersAndReturnsGeneratedPasswords(): void
    {
        $rows = [
            2 => ['username' => 'csvtest_e', 'full_name' => 'E-postalı', 'email' => 'csvtest_e@example.com', 'extension' => '', 'role' => 'cc_agent', 'password' => ''],
            3 => ['username' => 'csvtest_f', 'full_name' => 'E-postasız', 'email' => '', 'extension' => '', 'role' => 'cc_agent', 'password' => ''],
            4 => ['username' => 'csvtest_g', 'full_name' => 'Şifreli', 'email' => '', 'extension' => '', 'role' => 'cc_agent', 'password' => self::tempSecret()],
        ];
        $res = UserImportService::import($rows, false, 'test-token');

        $this->assertSame(3, $res['created']);
        $this->assertSame([], $res['failed']);
        $this->assertSame(0, $res['invited']);
        // A password is generated only for a user without an email and password.
        $this->assertSame([3], array_keys($res['generated']));

        $st = $this->db->prepare('SELECT password_hash, must_reset_password FROM sys_users WHERE username = ?');
        $st->execute(['csvtest_f']);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify($res['generated'][3]['password'], $f['password_hash']));
        $this->assertSame(1, (int) $f['must_reset_password']);

        // With invitations off, no password-setup link must be generated for a user with an email.
        $st->execute(['csvtest_e']);
        $this->assertSame(0, (int) $st->fetch(PDO::FETCH_ASSOC)['must_reset_password']);
    }

    public function testSaveUserRejectsDuplicateExtension(): void
    {
        $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, is_active) VALUES ('csvtest_h', 'x', 'H', 'cc_agent', '7106', 1)")->execute();
        $res = UserService::saveUser([
            'csrf_token' => 'test-token', 'username' => 'csvtest_i', 'full_name' => 'I',
            'password' => self::tempSecret(), 'role' => 'cc_agent', 'extension' => '7106',
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('başka bir kullanıcıya atanmış', $res['error']);
    }
}
