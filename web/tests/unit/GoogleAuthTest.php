<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/api/mobile/auth_helper.php';
require_once __DIR__ . '/../../src/services/GoogleAuthService.php';

final class GoogleAuthTest extends TestCase
{
    private static ?int $testUserId = null;
    private static string $testEmail = 'google_test_suite@aipbx.bid';

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute(['google_test_user', password_hash('Secret123!', PASSWORD_BCRYPT), 'Google Tester', self::$testEmail, 'user', '8889']);
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

    public function testFindUserByEmail(): void
    {
        // Exact match
        $user = GoogleAuthService::findUserByEmail(self::$testEmail);
        $this->assertNotNull($user);
        $this->assertSame(self::$testUserId, (int)$user['id']);

        // Case-insensitive match
        $upperEmail = strtoupper(self::$testEmail);
        $userUpper = GoogleAuthService::findUserByEmail($upperEmail);
        $this->assertNotNull($userUpper);
        $this->assertSame(self::$testUserId, (int)$userUpper['id']);

        // Trim whitespace match
        $paddedEmail = '  ' . self::$testEmail . '   ';
        $userPadded = GoogleAuthService::findUserByEmail($paddedEmail);
        $this->assertNotNull($userPadded);
        $this->assertSame(self::$testUserId, (int)$userPadded['id']);

        // Non-existent email
        $nonExistent = GoogleAuthService::findUserByEmail('does_not_exist@example.com');
        $this->assertNull($nonExistent);

        // Empty email
        $this->assertNull(GoogleAuthService::findUserByEmail(''));
    }

    public function testGenerateAndVerifyState(): void
    {
        $webState = GoogleAuthService::generateState(false);
        $this->assertNotEmpty($webState);
        $resWeb = GoogleAuthService::verifyState($webState);
        $this->assertTrue($resWeb['valid']);
        $this->assertFalse($resWeb['mobile']);

        $mobileState = GoogleAuthService::generateState(true);
        $this->assertNotEmpty($mobileState);
        $resMobile = GoogleAuthService::verifyState($mobileState);
        $this->assertTrue($resMobile['valid']);
        $this->assertTrue($resMobile['mobile']);

        // Tampered state
        $tamperedState = $webState . 'x';
        $resTampered = GoogleAuthService::verifyState($tamperedState);
        $this->assertFalse($resTampered['valid']);

        // Invalid base64
        $this->assertFalse(GoogleAuthService::verifyState('!!!invalid-state!!!')['valid']);
    }

    public function testVerifyIdTokenWithInvalidToken(): void
    {
        $this->assertNull(GoogleAuthService::verifyIdToken(''));
        $this->assertNull(GoogleAuthService::verifyIdToken('invalid.jwt.token'));
    }

    public function testBuildMobileLoginResponse(): void
    {
        $user = GoogleAuthService::findUserByEmail(self::$testEmail);
        $this->assertNotNull($user);

        $response = buildMobileLoginResponse($user);
        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['token']);
        $this->assertArrayHasKey('user', $response);
        $this->assertSame('google_test_user', $response['user']['username']);
        $this->assertSame(self::$testEmail, $response['user']['email']);
        $this->assertArrayHasKey('sip', $response);
        $this->assertArrayHasKey('push_config', $response);
    }
}
