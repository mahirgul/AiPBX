<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/api/mobile/auth_helper.php';

final class MobileApiTest extends TestCase
{
    private static ?int $testUserId = null;

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute(['test_mobile_user', password_hash('Secret123!', PASSWORD_BCRYPT), 'Mobile Tester', 'user', '8888']);
        self::$testUserId = (int)$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$testUserId) {
            $db = getDB();
            $stmt = $db->prepare("DELETE FROM sys_users WHERE id = ?");
            $stmt->execute([self::$testUserId]);
        }
    }

    public function testValidateMobileTokenWithEmptyToken(): void
    {
        $this->assertNull(validateMobileToken(null));
        $this->assertNull(validateMobileToken(''));
        $this->assertNull(validateMobileToken('invalid-base64-string!@#$'));
    }

    public function testValidateMobileTokenWithForgedSignature(): void
    {
        $payload = self::$testUserId . ':' . (time() + 3600);
        $fakeSig = hash_hmac('sha256', $payload, 'wrong_secret_key');
        $fakeToken = base64_encode($payload . ':' . $fakeSig);

        $this->assertNull(validateMobileToken($fakeToken));
    }

    public function testValidateMobileTokenWithExpiredToken(): void
    {
        $payload = self::$testUserId . ':' . (time() - 3600); // 1 hour ago
        $secretKey = defined('TURN_SECRET') && TURN_SECRET !== '' ? TURN_SECRET : DB_PASS;
        $sig = hash_hmac('sha256', $payload, $secretKey);
        $token = base64_encode($payload . ':' . $sig);

        $this->assertNull(validateMobileToken($token));
    }

    public function testValidateMobileTokenWithValidUser(): void
    {
        $userId = self::$testUserId;
        $expiresAt = time() + 3600;
        $payload = $userId . ':' . $expiresAt;
        $secretKey = defined('TURN_SECRET') && TURN_SECRET !== '' ? TURN_SECRET : DB_PASS;
        $sig = hash_hmac('sha256', $payload, $secretKey);
        $validToken = base64_encode($payload . ':' . $sig);

        $validatedUser = validateMobileToken($validToken);
        $this->assertNotNull($validatedUser);
        $this->assertSame($userId, (int)$validatedUser['id']);
        $this->assertSame('test_mobile_user', $validatedUser['username']);
        $this->assertSame('8888', $validatedUser['extension']);
    }

    public function testKullaniciAdiEslesmesiDahiliEslesmesineOncelikli(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username IN ('9001','testuser2')");
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active)
                      VALUES ('9001','x','Adi 9001 Olan','user','7001','sip',1)")->execute();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active)
                      VALUES ('testuser2','x','Dahilisi 9001 Olan','user','9001','sip',1)")->execute();

        try {
            $st = $db->prepare("SELECT full_name FROM sys_users
                                WHERE username = ? OR extension = ?
                                ORDER BY (username = ?) DESC, id ASC LIMIT 1");
            $st->execute(['9001', '9001', '9001']);
            $this->assertSame('Adi 9001 Olan', $st->fetchColumn());
        } finally {
            $db->exec("DELETE FROM sys_users WHERE username IN ('9001','testuser2')");
        }
    }
}
