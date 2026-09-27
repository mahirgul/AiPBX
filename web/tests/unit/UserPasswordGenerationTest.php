<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/UserService.php';

/**
 * Yeni kullanıcıda şifre boş bırakıldığında:
 *  - e-posta yoksa okunaklı bir şifre üretilip BİR KEZ döndürülür ve ilk web
 *    girişinde değiştirme zorunlu olur,
 *  - admin şifre girerse hiçbir şey üretilmez.
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
        // Şifre mesaj metnine (6 sn'lik bildirim, audit) sızmamalı.
        $this->assertStringNotContainsString($res['generated_password'], $res['message']);
    }

    public function testExplicitPasswordIsKeptAndNothingGenerated(): void
    {
        $secret = 'T' . bin2hex(random_bytes(8)); // repoda parola benzeri sabit tutulmuyor
        $res = $this->create('pwgen_test_b', $secret);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertArrayNotHasKey('generated_password', $res);

        $row = $this->db->prepare('SELECT password_hash, must_reset_password FROM sys_users WHERE username = ?');
        $row->execute(['pwgen_test_b']);
        $u = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify($secret, $u['password_hash']));
        $this->assertSame(0, (int) $u['must_reset_password']);
    }
}
