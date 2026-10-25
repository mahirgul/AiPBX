<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/controllers/TwoFactorLoginController.php';
require_once dirname(__DIR__, 2) . '/src/services/TwoFactorService.php';

class TestableTwoFactorLoginController extends TwoFactorLoginController
{
    public static ?string $redirectedTo = null;
    /** @var array<string, mixed> */
    public static array $renderedView = [];

    public static function resetTestState(): void
    {
        self::$redirectedTo = null;
        self::$renderedView = [];
    }

    protected static function redirect(string $path): void
    {
        self::$redirectedTo = $path;
    }

    protected static function renderAuthPage(string $view, array $data = [], array $page = []): void
    {
        self::$renderedView = ['view' => $view, 'data' => $data, 'page' => $page];
    }
}

final class TwoFactorCoverageTest extends TestCase
{
    private int $testUserId = 0;
    private int $inactiveUserId = 0;
    private int $no2faUserId = 0;
    private string $testSecret = '';
    private string $testRecoveryCode = 'ABCD-EF23';

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.79';
        TestableTwoFactorLoginController::resetTestState();

        $db = getDB();
        $this->testSecret = TwoFactorService::generateSecret(32);
        $passHash = password_hash('Pass_zz_7901!', PASSWORD_DEFAULT);
        $recoveryJson = TwoFactorService::hashRecoveryCodes([$this->testRecoveryCode, 'GHIJ-KL45']);

        // Active 2FA user
        $stmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, two_factor_enabled, two_factor_secret, two_factor_recovery_codes) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?)');
        $stmt->execute(['zz_cov_2fa_7901', $passHash, 'TwoFactor User', 'zz_cov_2fa@example.com', 'admin', '7901', $this->testSecret, $recoveryJson]);
        $this->testUserId = (int)$db->lastInsertId();

        // Inactive user with 2FA
        $stmt->execute(['zz_cov_inactive_7901', $passHash, 'Inactive User', 'zz_cov_inact@example.com', 'admin', '7902', $this->testSecret, $recoveryJson]);
        $this->inactiveUserId = (int)$db->lastInsertId();
        $db->exec("UPDATE sys_users SET is_active = 0 WHERE id = {$this->inactiveUserId}");

        // Active user without 2FA
        $stmt2 = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, two_factor_enabled) VALUES (?, ?, ?, ?, ?, ?, 1, 0)');
        $stmt2->execute(['zz_cov_no2fa_7901', $passHash, 'No 2FA User', 'zz_cov_no2fa@example.com', 'user', '7903']);
        $this->no2faUserId = (int)$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username IN ('zz_cov_2fa_7901', 'zz_cov_inactive_7901', 'zz_cov_no2fa_7901')");
        $db->exec("DELETE FROM sys_login_logs WHERE ip_address = '192.0.2.79' OR username LIKE 'zz_cov_%'");
        $_SESSION = [];
        $_POST = [];
        TestableTwoFactorLoginController::resetTestState();
    }

    public function testNoPendingUserRedirectsToLogin(): void
    {
        $_SESSION = [];
        TestableTwoFactorLoginController::index();

        $this->assertSame('/login', TestableTwoFactorLoginController::$redirectedTo);
    }

    public function testInactiveUserNeverSignsIn(): void
    {
        $_SESSION['pending_2fa_user_id'] = $this->inactiveUserId;
        $_SESSION['pending_2fa_username'] = 'zz_cov_inactive_7901';

        TestableTwoFactorLoginController::index();

        $this->assertSame('/login', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertArrayNotHasKey('pending_2fa_user_id', $_SESSION);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testUserWithoutTwoFactorEnabledRedirectsToLogin(): void
    {
        $_SESSION['pending_2fa_user_id'] = $this->no2faUserId;

        TestableTwoFactorLoginController::index();

        $this->assertSame('/login', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertArrayNotHasKey('pending_2fa_user_id', $_SESSION);
    }

    public function testGetRequestRendersAuthPage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;

        TestableTwoFactorLoginController::index();

        $this->assertNull(TestableTwoFactorLoginController::$redirectedTo);
        $this->assertSame('login/two_factor', TestableTwoFactorLoginController::$renderedView['view']);
        $this->assertArrayHasKey('data', TestableTwoFactorLoginController::$renderedView);
        $data = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data);
        $this->assertNotEmpty($data['csrf_token']);
        $this->assertSame('zz_cov_2fa_7901', $data['user']['username']);
    }

    public function testCancelActionClearsSessionAndRedirects(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'cancel'];
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $_SESSION['pending_2fa_username'] = 'zz_cov_2fa_7901';

        TestableTwoFactorLoginController::index();

        $this->assertSame('/login', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertArrayNotHasKey('pending_2fa_user_id', $_SESSION);
        $this->assertArrayNotHasKey('pending_2fa_username', $_SESSION);
    }

    public function testBruteForceLockoutBlocksAndClearsPendingSession(): void
    {
        $db = getDB();
        $logStmt = $db->prepare("INSERT INTO sys_login_logs (ip_address, username, status, created_at) VALUES (?, ?, 'FAILED', NOW())");
        for ($i = 0; $i < 6; $i++) {
            $logStmt->execute(['192.0.2.79', 'zz_cov_2fa_7901']);
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.79';
        $_POST = ['action' => 'verify'];
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $_SESSION['pending_2fa_username'] = 'zz_cov_2fa_7901';

        TestableTwoFactorLoginController::index();

        $this->assertSame('/login', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertArrayNotHasKey('pending_2fa_user_id', $_SESSION);
        $this->assertNotEmpty($_SESSION['sys_notifications'] ?? []);
        $this->assertSame('danger', $_SESSION['sys_notifications'][0]['type']);
    }

    public function testInvalidCsrfTokenRefused(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'verify',
            'csrf_token' => 'invalid_csrf_token_value',
            'auth_mode' => 'totp',
            'totp_code' => '123456',
        ];
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;

        TestableTwoFactorLoginController::index();

        $this->assertNull(TestableTwoFactorLoginController::$redirectedTo);
        $data = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data);
        $this->assertSame(t('login.csrf_error'), $data['error']);
    }

    public function testTotpBadFormatAndWrongCodeRefused(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $csrf = getCSRFToken();

        // 1. Wrong format (not 6 digits)
        $_POST = [
            'action' => 'verify',
            'csrf_token' => $csrf,
            'auth_mode' => 'totp',
            'totp_code' => '12345',
        ];
        TestableTwoFactorLoginController::index();
        $data = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data);
        $this->assertSame(t('login_2fa.enter_valid_totp'), $data['error']);
        $this->assertSame(1, $_SESSION['pending_2fa_failures'] ?? 0);

        // 2. Wrong 6-digit code
        $_POST['totp_code'] = '000000';
        TestableTwoFactorLoginController::resetTestState();
        TestableTwoFactorLoginController::index();
        $data2 = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data2);
        $this->assertSame(t('login_2fa.invalid_totp_code'), $data2['error']);
        $this->assertSame(2, $_SESSION['pending_2fa_failures'] ?? 0);
    }

    public function testTotpExpiredCodeRefused(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $expiredCode = TwoFactorService::calculateCode($this->testSecret, time() - 150);

        $_POST = [
            'action' => 'verify',
            'csrf_token' => getCSRFToken(),
            'auth_mode' => 'totp',
            'totp_code' => $expiredCode,
        ];
        TestableTwoFactorLoginController::index();

        $data = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data);
        $this->assertSame(t('login_2fa.invalid_totp_code'), $data['error']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testTotpValidCodeSuccessfulSignIn(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $_SESSION['pending_2fa_username'] = 'zz_cov_2fa_7901';
        $_SESSION['pending_2fa_failures'] = 2;
        $validCode = TwoFactorService::calculateCode($this->testSecret, time());

        $_POST = [
            'action' => 'verify',
            'csrf_token' => getCSRFToken(),
            'auth_mode' => 'totp',
            'totp_code' => $validCode,
        ];
        TestableTwoFactorLoginController::index();

        $this->assertSame('/dashboard', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertSame($this->testUserId, $_SESSION['user_id']);
        $this->assertSame('zz_cov_2fa_7901', $_SESSION['username']);
        $this->assertSame('admin', $_SESSION['user_role']);
        $this->assertSame('7901', $_SESSION['extension']);
        $this->assertArrayNotHasKey('pending_2fa_user_id', $_SESSION);
        $this->assertArrayNotHasKey('pending_2fa_failures', $_SESSION);

        // Verify successful login log in DB
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE username = 'zz_cov_2fa_7901' AND status = 'SUCCESS'");
        $stmt->execute();
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }

    public function testFiveFailuresLockoutPendingSession(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $_SESSION['pending_2fa_failures'] = 4;

        $_POST = [
            'action' => 'verify',
            'csrf_token' => getCSRFToken(),
            'auth_mode' => 'totp',
            'totp_code' => '999999',
        ];
        TestableTwoFactorLoginController::index();

        $this->assertSame('/login', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertArrayNotHasKey('pending_2fa_user_id', $_SESSION);
        $this->assertArrayNotHasKey('pending_2fa_failures', $_SESSION);
        $this->assertNotEmpty($_SESSION['sys_notifications'] ?? []);
        $this->assertSame('danger', $_SESSION['sys_notifications'][0]['type']);
    }

    public function testRecoveryCodeFlowAndSingleUseRefusal(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $csrf = getCSRFToken();

        // 1. Empty recovery code refused
        $_POST = [
            'action' => 'verify',
            'csrf_token' => $csrf,
            'auth_mode' => 'recovery',
            'recovery_code' => '',
        ];
        TestableTwoFactorLoginController::index();
        $data1 = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data1);
        $this->assertSame(t('login_2fa.enter_recovery_code'), $data1['error']);

        // 2. Wrong recovery code refused
        $_POST['recovery_code'] = 'WRONG-CODE';
        TestableTwoFactorLoginController::resetTestState();
        TestableTwoFactorLoginController::index();
        $data2 = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data2);
        $this->assertSame(t('login_2fa.invalid_recovery_code'), $data2['error']);

        // 3. Valid recovery code consumed and logs in
        $_POST['recovery_code'] = $this->testRecoveryCode;
        TestableTwoFactorLoginController::resetTestState();
        TestableTwoFactorLoginController::index();
        $this->assertSame('/dashboard', TestableTwoFactorLoginController::$redirectedTo);
        $this->assertSame($this->testUserId, $_SESSION['user_id']);

        // 4. Reusing the same recovery code on a new login must be refused
        $_SESSION = [];
        $_SESSION['pending_2fa_user_id'] = $this->testUserId;
        $newCsrf = getCSRFToken();
        $_POST = [
            'action' => 'verify',
            'csrf_token' => $newCsrf,
            'auth_mode' => 'recovery',
            'recovery_code' => $this->testRecoveryCode,
        ];
        TestableTwoFactorLoginController::resetTestState();
        TestableTwoFactorLoginController::index();

        $this->assertNull(TestableTwoFactorLoginController::$redirectedTo);
        $data3 = TestableTwoFactorLoginController::$renderedView['data'];
        $this->assertIsArray($data3);
        $this->assertSame(t('login_2fa.invalid_recovery_code'), $data3['error']);
    }

    public function testTwoFactorServiceHelpers(): void
    {
        // Base32 decode and secret generation
        $secret = TwoFactorService::generateSecret(32);
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $decoded = TwoFactorService::base32Decode('MZXW6===');
        $this->assertSame('foo', $decoded);

        // Recovery code generator
        $codes = TwoFactorService::generateRecoveryCodes(4);
        $this->assertCount(4, $codes);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[2-9A-Z]{4}-[2-9A-Z]{4}$/', $c);
        }
    }
}
