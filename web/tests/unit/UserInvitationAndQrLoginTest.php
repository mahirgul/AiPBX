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

    protected function setUp(): void
    {
        $this->db = getDB();

        // Temiz bir test kullanıcısı oluştur
        $stmt = $this->db->prepare("DELETE FROM sys_users WHERE username = 'inv_test_user' OR extension = ?");
        $stmt->execute([$this->testExtension]);

        $ins = $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, sip_password)
                                  VALUES ('inv_test_user', ?, 'Aktivasyon Test', 'inv_test@example.com', 'cc_agent', ?, 1, 'sipPass123')");
        $ins->execute([password_hash('InitPass123!', PASSWORD_DEFAULT), $this->testExtension]);
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
        // E-postası olmayan kullanıcı yap
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
        // 1. QR kod ve eşleştirme verisi üret
        $qrRes = QrLoginService::generateQr($this->testUserId, 600);
        $this->assertTrue($qrRes['success']);
        $this->assertNotEmpty($qrRes['qr_token']);
        $this->assertNotEmpty($qrRes['qr_data_uri']);
        $this->assertStringStartsWith('data:image/', $qrRes['qr_data_uri']);
        $this->assertSame($this->testExtension, $qrRes['payload']['ext']);
        $this->assertSame('aipbx_qr_login', $qrRes['payload']['type']);

        $token = $qrRes['qr_token'];

        // 2. Durum henüz kullanılmadı olmalı
        $statusBefore = QrLoginService::checkStatus($token);
        $this->assertFalse($statusBefore['used']);
        $this->assertFalse($statusBefore['expired']);

        // 3. Mobil uygulama QR kodu tarayıp giriş isteği gönderir
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
        $this->assertSame('sipPass123', $loginData['sip']['sip_password']);

        // 4. Durum kontrolü: Artık kullanılmış olmalı
        $statusAfter = QrLoginService::checkStatus($token);
        $this->assertTrue($statusAfter['used']);
        $this->assertSame('Test-Android-Device', $statusAfter['device_name']);

        // 5. TEK KULLANIMLIK KONTROLÜ: Aynı token ile 2. kez giriş denenirse REDDEDİLMELİ
        $secondAttempt = QrLoginService::authenticateMobile($token, 'Hacker-Device', '192.168.1.50');
        $this->assertFalse($secondAttempt['success']);
        $this->assertStringContainsString('daha önce kullanılmış', $secondAttempt['error']);
    }

    public function testQrLoginRejectsExpiredToken(): void
    {
        // 1 saniye TTL ile üret
        $qrRes = QrLoginService::generateQr($this->testUserId, 1);
        $token = $qrRes['qr_token'];

        // Token'ın süresini geçmişe al
        $pastDate = date('Y-m-d H:i:s', time() - 60);
        $this->db->prepare("UPDATE sys_user_qr_tokens SET expires_at = ? WHERE token = ?")->execute([$pastDate, $token]);

        $authRes = QrLoginService::authenticateMobile($token, 'Test-Device', '127.0.0.1');
        $this->assertFalse($authRes['success']);
        $this->assertStringContainsString('süresi (10 dakika) dolmuş', $authRes['error']);
    }
}
