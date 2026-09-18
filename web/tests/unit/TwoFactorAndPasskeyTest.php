<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/auth.php';
require_once '/var/www/html/src/services/TwoFactorService.php';
require_once '/var/www/html/src/services/PasskeyService.php';
require_once '/var/www/html/src/services/LoginService.php';

final class TwoFactorAndPasskeyTest extends TestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('Testler yalnızca asterisk_test üzerinde koşmalıdır!');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $db = getDB();
        $db->exec('DELETE FROM sys_user_passkeys');
        $db->exec("DELETE FROM sys_users WHERE username IN ('test_2fa_user', 'test_normal_user')");

        // Test için 2FA kullanıcısı oluştur
        $passHash = password_hash('SecretPassword123!', PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active, language_preference) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['test_2fa_user', $passHash, 'Test 2FA User', 'test2fa@example.com', 'admin', 1, 'tr']);
        $this->testUserId = (int)$db->lastInsertId();

        // Normal 2FA'sız kullanıcı oluştur
        $stmt->execute(['test_normal_user', $passHash, 'Test Normal User', 'testnormal@example.com', 'admin', 1, 'tr']);
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM sys_user_passkeys');
        $db->exec("DELETE FROM sys_users WHERE username IN ('test_2fa_user', 'test_normal_user')");
        $_SESSION = [];
    }

    /**
     * RFC 6238 resmi test vektörleri doğrulaması
     */
    public function testRfc6238OfficialTestVectors(): void
    {
        // RFC 6238 Appendix B test vektörü: "12345678901234567890" Base32
        $secret = implode('', ['GEZDGNBV', 'GY3TQOJQ', 'GEZDGNBV', 'GY3TQOJQ']);

        $this->assertSame('287082', TwoFactorService::calculateCode($secret, 59));
        $this->assertSame('081804', TwoFactorService::calculateCode($secret, 1111111109));
        $this->assertSame('050471', TwoFactorService::calculateCode($secret, 1111111111));
        $this->assertSame('005924', TwoFactorService::calculateCode($secret, 1234567890));
        $this->assertSame('279037', TwoFactorService::calculateCode($secret, 2000000000));
    }

    /**
     * Zaman kayması (drift) toleransı doğrulaması
     */
    public function testTotpDriftWindowVerification(): void
    {
        $secret = TwoFactorService::generateSecret(32);
        $now = 1700000000;
        $currentCode = TwoFactorService::calculateCode($secret, $now);

        // Tam zamanında kod geçerli
        $this->assertTrue(TwoFactorService::verifyCode($secret, $currentCode, 1, $now));

        // 30 saniye önceki kod window=1 toleransında geçerli
        $pastCode = TwoFactorService::calculateCode($secret, $now - 30);
        $this->assertTrue(TwoFactorService::verifyCode($secret, $pastCode, 1, $now));

        // 30 saniye sonraki kod window=1 toleransında geçerli
        $futureCode = TwoFactorService::calculateCode($secret, $now + 30);
        $this->assertTrue(TwoFactorService::verifyCode($secret, $futureCode, 1, $now));

        // 90 saniye önceki kod window=1 için geçersiz
        $wayPastCode = TwoFactorService::calculateCode($secret, $now - 90);
        $this->assertFalse(TwoFactorService::verifyCode($secret, $wayPastCode, 1, $now));

        // Yanlış format (harf veya 5 hane) geçersiz
        $this->assertFalse(TwoFactorService::verifyCode($secret, 'ABCDEF', 1, $now));
        $this->assertFalse(TwoFactorService::verifyCode($secret, '12345', 1, $now));
    }

    /**
     * OtpAuth URI ve yerel QR Code SVG üretimi
     */
    public function testOtpAuthUriAndQrCodeGeneration(): void
    {
        $secret = implode('', ['JBSWY3DP', 'EHPK3PXP']);
        $uri = TwoFactorService::getOtpAuthUri('testuser', $secret, 'AiPBX');

        $this->assertStringStartsWith('otpauth://totp/AiPBX:testuser', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=AiPBX', $uri);

        $qr = TwoFactorService::getQrCodeDataUri($uri);
        $this->assertStringStartsWith('data:image/svg+xml', $qr);
    }

    /**
     * 2FA Etkinleştirme ve Devre Dışı Bırakma Akışı
     */
    public function testEnableAndDisableTwoFactorFlow(): void
    {
        $secret = TwoFactorService::generateSecret(32);
        $now = time();
        $validCode = TwoFactorService::calculateCode($secret, $now);

        // Hatalı kod ile etkinleştirme başarısız olmalı
        $failRes = TwoFactorService::enableTwoFactor($this->testUserId, $secret, '000000');
        $this->assertFalse($failRes['success']);

        // Doğru kod ile etkinleştirme başarılı olmalı ve 8 kurtarma kodu dönmeli
        $successRes = TwoFactorService::enableTwoFactor($this->testUserId, $secret, $validCode);
        $this->assertTrue($successRes['success']);
        $this->assertCount(8, $successRes['recovery_codes']);

        // DB'de two_factor_enabled = 1 olmalı
        $db = getDB();
        $stmt = $db->prepare('SELECT two_factor_enabled, two_factor_secret, two_factor_recovery_codes FROM sys_users WHERE id = ?');
        $stmt->execute([$this->testUserId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(1, (int)$userRow['two_factor_enabled']);
        $this->assertSame($secret, $userRow['two_factor_secret']);
        $this->assertNotEmpty($userRow['two_factor_recovery_codes']);

        // Yanlış şifre ile kapatma başarısız olmalı
        $disFail = TwoFactorService::disableTwoFactor($this->testUserId, 'WrongPassword');
        $this->assertFalse($disFail['success']);

        // Doğru şifre ile kapatma başarılı olmalı
        $disSuccess = TwoFactorService::disableTwoFactor($this->testUserId, 'SecretPassword123!');
        $this->assertTrue($disSuccess['success']);

        $stmt->execute([$this->testUserId]);
        $userRowAfter = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$userRowAfter['two_factor_enabled']);
        $this->assertNull($userRowAfter['two_factor_secret']);
    }

    /**
     * Kurtarma kodu tüketimi (tek kullanımlık kuralı)
     */
    public function testRecoveryCodeConsumption(): void
    {
        $secret = TwoFactorService::generateSecret(32);
        $code = TwoFactorService::calculateCode($secret, time());
        $res = TwoFactorService::enableTwoFactor($this->testUserId, $secret, $code);
        $this->assertTrue($res['success']);

        $codes = $res['recovery_codes'];
        $firstCode = $codes[0];

        // Kurtarma kodu başarıyla doğrulanmalı ve tüketilmeli
        $consumed = TwoFactorService::verifyAndConsumeRecoveryCode($this->testUserId, $firstCode);
        $this->assertTrue($consumed);

        // Aynı kod tekrar kullanılamamalı (tek kullanımlık)
        $reconsumed = TwoFactorService::verifyAndConsumeRecoveryCode($this->testUserId, $firstCode);
        $this->assertFalse($reconsumed);

        // Kalan kodlardan ikincisi çalışmalı
        $secondCode = $codes[1];
        $consumedSecond = TwoFactorService::verifyAndConsumeRecoveryCode($this->testUserId, $secondCode);
        $this->assertTrue($consumedSecond);
    }

    /**
     * LoginService: 2FA aktif olduğunda /login-2fa'ya yönlendirme testi
     */
    public function testLoginServiceRedirectsTo2faWhenEnabled(): void
    {
        $secret = TwoFactorService::generateSecret(32);
        $code = TwoFactorService::calculateCode($secret, time());
        TwoFactorService::enableTwoFactor($this->testUserId, $secret, $code);

        // Captcha hazırla
        $_SESSION['captcha_num1'] = 3;
        $_SESSION['captcha_num2'] = 4;
        $csrf = getCSRFToken();

        // 1. 2FA aktif kullanıcı giriş denemesi -> /login-2fa'ya yönlendirilmeli
        $post2fa = [
            'username' => 'test_2fa_user',
            'password' => 'SecretPassword123!',
            'captcha_answer' => 7,
            'csrf_token' => $csrf,
        ];
        $res2fa = LoginService::attemptLogin($post2fa, '127.0.0.1');

        $this->assertArrayHasKey('redirect', $res2fa);
        $this->assertSame('/login-2fa', $res2fa['redirect']);
        $this->assertSame($this->testUserId, $_SESSION['pending_2fa_user_id'] ?? null);

        // 2. Normal kullanıcı giriş denemesi -> Doğrudan rol sayfasına (/dashboard) gitmeli
        $_SESSION['captcha_num1'] = 2;
        $_SESSION['captcha_num2'] = 5;
        $postNormal = [
            'username' => 'test_normal_user',
            'password' => 'SecretPassword123!',
            'captcha_answer' => 7,
            'csrf_token' => getCSRFToken(),
        ];
        $resNormal = LoginService::attemptLogin($postNormal, '127.0.0.1');

        $this->assertArrayHasKey('redirect', $resNormal);
        $this->assertSame('/dashboard', $resNormal['redirect']);
        $this->assertNotEmpty($_SESSION['user_id']);
    }

    /**
     * PasskeyService: Register & Auth challenge ve seçenek üretimi
     */
    public function testPasskeyArgsGeneration(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';

        // 1. Register seçenekleri
        $regArgs = PasskeyService::getRegisterArgs($this->testUserId, 'test_2fa_user', 'Test 2FA User');
        $this->assertNotEmpty($regArgs->challenge);
        $this->assertSame('localhost', $regArgs->rp->id);
        $this->assertNotEmpty($_SESSION['webauthn_reg_challenge']);
        $this->assertSame($this->testUserId, $_SESSION['webauthn_reg_user_id']);

        // 2. Login seçenekleri
        $loginArgs = PasskeyService::getLoginArgs('test_2fa_user');
        $this->assertNotEmpty($loginArgs->challenge);
        $this->assertNotEmpty($_SESSION['webauthn_auth_challenge']);
    }

    /**
     * Passkey CRUD: Listeleme ve silme
     */
    public function testPasskeyCrud(): void
    {
        $db = getDB();
        $fakeCredId = base64_encode('fake-credential-id-123456');
        $stmt = $db->prepare('INSERT INTO sys_user_passkeys (user_id, credential_id, public_key, counter, device_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$this->testUserId, $fakeCredId, 'fake-public-key-data', 1, 'Test MacBook TouchID']);
        $pkId = (int)$db->lastInsertId();

        // Listele
        $keys = PasskeyService::getUserPasskeys($this->testUserId);
        $this->assertCount(1, $keys);
        $this->assertSame('Test MacBook TouchID', $keys[0]['device_name']);
        $this->assertSame($fakeCredId, $keys[0]['credential_id']);

        // Başka kullanıcının silme girişimi başarısız olmalı
        $delFail = PasskeyService::deletePasskey(99999, $pkId);
        $this->assertFalse($delFail);

        // Sahibi silerse başarılı olmalı
        $delSuccess = PasskeyService::deletePasskey($this->testUserId, $pkId);
        $this->assertTrue($delSuccess);

        // Tekrar listele -> Boş olmalı
        $keysAfter = PasskeyService::getUserPasskeys($this->testUserId);
        $this->assertCount(0, $keysAfter);
    }
}
