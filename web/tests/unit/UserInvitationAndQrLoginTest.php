<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/UserInvitationService.php';
require_once __DIR__ . '/../../src/services/QrLoginService.php';
require_once __DIR__ . '/../../src/repositories/ResetPasswordRepository.php';

final class UserInvitationAndQrLoginTest extends TestCase
{
    private PDO $db;
    private int $testUserId = 0;
    private string $testExtension = '9876';
    /** Generated at test time: no password-like constant in the repo (secret scanners). */
    private string $testSipSecret = '';

    protected function setUp(): void
    {
        $this->db = getDB();

        // Create a clean test user
        $stmt = $this->db->prepare("DELETE FROM sys_users WHERE username = 'inv_test_user' OR extension = ?");
        $stmt->execute([$this->testExtension]);

        $this->testSipSecret = 'S' . bin2hex(random_bytes(8));
        $ins = $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, sip_password)
                                  VALUES ('inv_test_user', ?, 'Aktivasyon Test', 'inv_test@example.com', 'cc_agent', ?, 1, ?)");
        $ins->execute([password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $this->testExtension, $this->testSipSecret]);
        $this->testUserId = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->testUserId > 0) {
            $this->db->prepare("DELETE FROM sys_user_qr_tokens WHERE user_id = ?")->execute([$this->testUserId]);
            $this->db->prepare("DELETE FROM sys_users WHERE id = ?")->execute([$this->testUserId]);
        }
    }

    public function testSendInvitationEmailGeneratesValidTokenAndSetsFlag(): void
    {
        $res = UserInvitationService::sendInvitationEmail($this->testUserId, true);
        $this->assertTrue($res['success'], 'Invitation email should succeed');
        $this->assertNotEmpty($res['token'], 'Token should be returned');

        // sys_users tablosunu kontrol et
        $stmt = $this->db->prepare("SELECT reset_token, reset_token_expires, must_reset_password FROM sys_users WHERE id = ?");
        $stmt->execute([$this->testUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(1, (int)$user['must_reset_password']);
        $this->assertNotEmpty($user['reset_token']);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $user['reset_token_expires']);

        // ResetPasswordRepository lookup test
        $found = ResetPasswordRepository::lookupResetUser($res['token']);
        $this->assertNotNull($found, 'ResetPasswordRepository should find user by generated token');
        $this->assertSame($this->testUserId, (int)$found['id']);
        $this->assertSame('inv_test_user', $found['username']);
    }

    public function testSendInvitationEmailFailsWithoutValidEmail(): void
    {
        // Make a user without an email
        $this->db->prepare("UPDATE sys_users SET email = '' WHERE id = ?")->execute([$this->testUserId]);

        $res = UserInvitationService::sendInvitationEmail($this->testUserId, false);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('geçerli bir e-posta adresi bulunmuyor', $res['error']);
    }

    public function testSendBulkInvitations(): void
    {
        $res = UserInvitationService::sendBulkInvitations([$this->testUserId, 999999]);
        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['sent_count']);
        $this->assertGreaterThanOrEqual(1, $res['failed_count']);
    }

    public function testQrLoginGenerateAndAuthenticateFlow(): void
    {
        // 1. Generate the QR code and pairing data
        $qrRes = QrLoginService::generateQr($this->testUserId, 600);
        $this->assertTrue($qrRes['success']);
        $this->assertNotEmpty($qrRes['qr_token']);
        $this->assertNotEmpty($qrRes['qr_data_uri']);
        $this->assertStringStartsWith('data:image/', $qrRes['qr_data_uri']);
        $this->assertSame($this->testExtension, $qrRes['payload']['ext']);
        $this->assertSame('aipbx_qr_login', $qrRes['payload']['type']);

        $token = $qrRes['qr_token'];

        // 2. The state must be "not used yet"
        $statusBefore = QrLoginService::checkStatus($token);
        $this->assertFalse($statusBefore['used']);
        $this->assertFalse($statusBefore['expired']);

        // 3. The mobile app scans the QR code and sends a sign-in request
        $authRes = QrLoginService::authenticateMobile($token, 'Test-Android-Device', '127.0.0.1');
        $this->assertTrue($authRes['success']);
        $this->assertArrayHasKey('response', $authRes);

        $loginData = $authRes['response'];
        $this->assertTrue($loginData['success']);
        $this->assertNotEmpty($loginData['token']);
        $this->assertSame('inv_test_user', $loginData['user']['username']);
        $this->assertSame($this->testExtension, $loginData['user']['extension']);
        $this->assertSame($this->testExtension, $loginData['sip']['extension']);
        $this->assertSame($this->testExtension . '-mob-webrtc', $loginData['sip']['sip_username']);
        $this->assertSame($this->testSipSecret, $loginData['sip']['sip_password']);

        // 4. State check: it must be used now
        $statusAfter = QrLoginService::checkStatus($token);
        $this->assertTrue($statusAfter['used']);
        $this->assertSame('Test-Android-Device', $statusAfter['device_name']);

        // 5. SINGLE-USE CHECK: a 2nd sign-in with the same token must be REJECTED
        $secondAttempt = QrLoginService::authenticateMobile($token, 'Hacker-Device', '192.168.1.50');
        $this->assertFalse($secondAttempt['success']);
        $this->assertStringContainsString('daha önce kullanılmış', $secondAttempt['error']);
    }

    public function testQrLoginRejectsExpiredToken(): void
    {
        // Generate with a 1 second TTL
        $qrRes = QrLoginService::generateQr($this->testUserId, 1);
        $token = $qrRes['qr_token'];

        // Move the token's expiry into the past
        $pastDate = date('Y-m-d H:i:s', time() - 60);
        $this->db->prepare("UPDATE sys_user_qr_tokens SET expires_at = ? WHERE token = ?")->execute([$pastDate, $token]);

        $authRes = QrLoginService::authenticateMobile($token, 'Test-Device', '127.0.0.1');
        $this->assertFalse($authRes['success']);
        $this->assertStringContainsString('süresi dolmuş', $authRes['error']);
    }

    public function testEmailLinkInspectionDoesNotConsumeToken(): void
    {
        $link = QrLoginService::createEmailLink($this->testUserId);
        $this->assertTrue($link['success']);
        $this->assertStringContainsString('/mobile-login?token=', $link['url']);
        $token = substr($link['url'], strpos($link['url'], 'token=') + 6);

        // Email scanners may open the page several times: the code must not be spent.
        for ($i = 0; $i < 3; $i++) {
            $info = QrLoginService::inspectToken($token);
            $this->assertTrue($info['valid']);
        }
        $this->assertSame($this->testExtension, $info['user']['extension']);

        // Valid for 7 days
        $exp = $this->db->prepare('SELECT purpose, expires_at FROM sys_user_qr_tokens WHERE token = ?');
        $exp->execute([$token]);
        $row = $exp->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('email', $row['purpose']);
        $this->assertGreaterThan(time() + 6 * 86400, strtotime($row['expires_at']));

        // The app sign-in spends the code; afterwards the page says "used".
        $auth = QrLoginService::authenticateMobile($token, 'Test-Android', '127.0.0.1');
        $this->assertTrue($auth['success']);
        $this->assertSame('used', QrLoginService::inspectToken($token)['reason']);
    }

    public function testNewEmailLinkRevokesPreviousOne(): void
    {
        $first = QrLoginService::createEmailLink($this->testUserId);
        $second = QrLoginService::createEmailLink($this->testUserId);
        $t1 = substr($first['url'], strpos($first['url'], 'token=') + 6);
        $t2 = substr($second['url'], strpos($second['url'], 'token=') + 6);

        $this->assertSame('invalid', QrLoginService::inspectToken($t1)['reason']);
        $this->assertTrue(QrLoginService::inspectToken($t2)['valid']);
    }

    public function testGoogleCodeIsNotAcceptedOnMobileLoginPage(): void
    {
        $code = QrLoginService::createGoogleCode($this->testUserId);
        $this->assertTrue($code['success']);
        // Only for the app's API exchange, not shown on the page.
        $this->assertFalse(QrLoginService::inspectToken($code['token'])['valid']);
        $auth = QrLoginService::authenticateMobile($code['token'], 'Test-Android', '127.0.0.1');
        $this->assertTrue($auth['success']);
        $this->assertSame($this->testExtension, $auth['response']['user']['extension']);
    }

    public function testMalformedTokenIsRejectedWithoutQuery(): void
    {
        $this->assertSame('invalid', QrLoginService::inspectToken("x' OR 1=1 --")['reason']);
        $this->assertSame('invalid', QrLoginService::inspectToken('')['reason']);
    }

    public function testInvitationEmailCreatesMobileLinkForUsersWithExtension(): void
    {
        $res = UserInvitationService::sendInvitationEmail($this->testUserId, true);
        $this->assertTrue($res['success']);
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM sys_user_qr_tokens WHERE user_id = ? AND purpose = 'email' AND used_at IS NULL");
        $cnt->execute([$this->testUserId]);
        $this->assertSame(1, (int)$cnt->fetchColumn());
    }
}
