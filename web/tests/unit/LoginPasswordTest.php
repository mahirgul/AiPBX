<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Shared sign-in helpers used by the web login and the mobile API:
 *  - a password chosen with leading/trailing spaces can sign in (logins used
 *    to trim the input, while self-service changes stored it as typed),
 *  - passwords stored trimmed (old admin resets) keep working,
 *  - a username match wins over another user's extension.
 */
final class LoginPasswordTest extends TestCase
{
    private PDO $db;
    private array $usernames = ['loginpw_test_a', 'loginpw_test_b'];

    protected function setUp(): void
    {
        $this->db = getDB();
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

    public function testPasswordWithSurroundingSpacesSignsIn(): void
    {
        $hash = password_hash(' secret pass ', PASSWORD_DEFAULT);
        $this->assertTrue(verifyLoginPassword(' secret pass ', $hash));
        $this->assertFalse(verifyLoginPassword('secret pass', $hash));
    }

    public function testTrimmedStoredPasswordStillSignsIn(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->assertTrue(verifyLoginPassword('secret', $hash));
        $this->assertTrue(verifyLoginPassword(' secret ', $hash));
        $this->assertFalse(verifyLoginPassword('other', $hash));
        $this->assertFalse(verifyLoginPassword('', $hash));
    }

    public function testUsernameMatchWinsOverExtension(): void
    {
        $insert = $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, is_active) VALUES (?, '', ?, 'user', ?, 1)");
        // User B's extension equals user A's username.
        $insert->execute(['loginpw_test_a', 'A', '987001']);
        $insert->execute(['loginpw_test_b', 'B', 'loginpw_test_a']);

        $this->assertSame('loginpw_test_a', findLoginUser('loginpw_test_a', 'username')['username']);
        $this->assertSame('loginpw_test_a', findLoginUser('987001', 'username')['username']);
        $this->assertFalse(findLoginUser('loginpw_no_such_user', 'username'));
    }
}
