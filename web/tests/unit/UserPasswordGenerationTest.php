<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/UserService.php';

/**
 * When the password is left empty for a new user:
 *  - without an email a readable password is generated and returned ONCE,
 *    and changing it at the first web sign-in is mandatory,
 *  - if the admin enters a password, nothing is generated.
 */
final class UserPasswordGenerationTest extends TestCase
{
    private PDO $db;
    private array $usernames = ['pwgen_test_a', 'pwgen_test_b'];

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
        $in = implode(',', array_fill(0, count($this->usernames), '?'));
        $this->db->prepare("DELETE FROM sys_users WHERE username IN ($in)")->execute($this->usernames);
    }

    private function create(string $username, string $password = ''): array
    {
        return UserService::saveUser([
            'csrf_token' => 'test-token',
            'username' => $username,
            'full_name' => 'Şifre Üretim Testi',
            'password' => $password,
            'email' => '',
            'role' => 'cc_agent',
            'is_active' => '1',
        ]);
    }

    public function testReadablePasswordFormat(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $pw = UserService::generateReadablePassword();
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-np-z2-9]{4}-[A-HJ-NP-Za-km-np-z2-9]{4}-[A-HJ-NP-Za-km-np-z2-9]{4}$/', $pw);
        }
        $this->assertNotSame(UserService::generateReadablePassword(), UserService::generateReadablePassword());
    }

    public function testNoEmailAndNoPasswordGeneratesOneTimePassword(): void
    {
        $res = $this->create('pwgen_test_a');
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertNotEmpty($res['generated_password']);

        $row = $this->db->prepare('SELECT password_hash, must_reset_password FROM sys_users WHERE username = ?');
        $row->execute(['pwgen_test_a']);
        $u = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify($res['generated_password'], $u['password_hash']));
        $this->assertSame(1, (int) $u['must_reset_password']);
        // The password must not leak into the message text (6 s notification, audit).
        $this->assertStringNotContainsString($res['generated_password'], $res['message']);
    }

    public function testExplicitPasswordIsKeptAndNothingGenerated(): void
    {
        $secret = 'T' . bin2hex(random_bytes(8)); // no password-like constant is kept in the repository
        $res = $this->create('pwgen_test_b', $secret);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertArrayNotHasKey('generated_password', $res);

        $row = $this->db->prepare('SELECT password_hash, must_reset_password FROM sys_users WHERE username = ?');
        $row->execute(['pwgen_test_b']);
        $u = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify($secret, $u['password_hash']));
        $this->assertSame(0, (int) $u['must_reset_password']);
    }

    public function testOnlyTheUsernameAndOnTheUsersPageTheExtensionAreRequired(): void
    {
        $name = 'zz_min_' . bin2hex(random_bytes(3));
        $ext = '97' . random_int(100, 999);
        $base = ['csrf_token' => getCSRFToken(), 'user_id' => 0, 'role' => 'user'];
        $db = getDB();
        try {
            $this->assertFalse(UserService::saveUser($base + ['username' => '', 'extension' => $ext])['success']);
            $this->assertFalse(UserService::saveUser($base + ['username' => $name, 'require_extension' => 1])['success'], 'the page needs one');
            $this->assertSame('0', (string) $db->query("SELECT COUNT(*) FROM sys_users WHERE username = '{$name}'")->fetchColumn());

            $res = UserService::saveUser($base + ['username' => $name, 'extension' => $ext, 'require_extension' => 1]);
            $this->assertTrue($res['success'], (string) ($res['error'] ?? ''));
            $this->assertNotSame('', (string) ($res['generated_password'] ?? ''), 'shown once');
            $row = $db->query("SELECT full_name, must_reset_password FROM sys_users WHERE username = '{$name}'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($name, $row['full_name']);
            $this->assertSame(1, (int) $row['must_reset_password']);
        } finally {
            $db->prepare('DELETE FROM sys_users WHERE username = ?')->execute([$name]);
            $db->prepare('DELETE FROM sip WHERE id = ?')->execute([$ext]);
        }
    }
}
