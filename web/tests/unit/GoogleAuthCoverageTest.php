<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/GoogleAuthService.php';

final class GoogleAuthCoverageTest extends TestCase
{
    private int $activeUserId = 0;
    private int $inactiveUserId = 0;
    private int $resetUserId = 0;
    private string $activeEmail = 'zz_cov_g_act_7903@example.com';
    private string $inactiveEmail = 'zz_cov_g_inact_7903@example.com';
    private string $resetEmail = 'zz_cov_g_reset_7903@example.com';
    /** @var array<string, string|null> */
    private array $origSettings = [];

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        // Save original system settings
        $db = getDB();
        $keys = ['google_oauth_enabled', 'google_client_id', 'google_client_secret', 'google_redirect_uri'];
        foreach ($keys as $k) {
            $stmt = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
            $stmt->execute([$k]);
            $val = $stmt->fetchColumn();
            $this->origSettings[$k] = $val !== false ? (string)$val : null;
        }

        $passHash = password_hash('Pass_zz_7903!', PASSWORD_DEFAULT);

        // Active user
        $uStmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, must_reset_password) VALUES (?, ?, ?, ?, ?, ?, 1, 0)');
        $uStmt->execute(['zz_cov_g_active_7903', $passHash, 'Google Active', $this->activeEmail, 'admin', '7906']);
        $this->activeUserId = (int)$db->lastInsertId();

        // Inactive user
        $uStmt->execute(['zz_cov_g_inactive_7903', $passHash, 'Google Inactive', $this->inactiveEmail, 'user', '7907']);
        $this->inactiveUserId = (int)$db->lastInsertId();
        $db->exec("UPDATE sys_users SET is_active = 0 WHERE id = {$this->inactiveUserId}");

        // Active user requiring password reset
        $uStmt2 = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, must_reset_password) VALUES (?, ?, ?, ?, ?, ?, 1, 1)');
        $uStmt2->execute(['zz_cov_g_reset_7903', $passHash, 'Google Reset', $this->resetEmail, 'user', '7908']);
        $this->resetUserId = (int)$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $ids = implode(',', array_filter([$this->activeUserId, $this->inactiveUserId, $this->resetUserId]));
        if (!empty($ids)) {
            $db->exec("DELETE FROM sys_users WHERE id IN ($ids)");
        }
        $db->exec("DELETE FROM sys_login_logs WHERE username LIKE 'zz_cov_g_%'");

        // Restore original system settings
        foreach ($this->origSettings as $k => $val) {
            if ($val === null) {
                $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([$k]);
            } else {
                $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$k, $val]);
            }
        }

        $_SESSION = [];
    }

    public function testInactiveUserNeverSignsIn(): void
    {
        // 1. Inactive user lookup by email returns null
        $inactive = GoogleAuthService::findUserByEmail($this->inactiveEmail);
        $this->assertNull($inactive, 'Inactive user must never be returned by findUserByEmail');

        // Case-insensitive and trimmed attempts also fail
        $this->assertNull(GoogleAuthService::findUserByEmail('  ' . strtoupper($this->inactiveEmail) . '  '));

        // 2. Active user lookup succeeds
        $active = GoogleAuthService::findUserByEmail($this->activeEmail);
        $this->assertNotNull($active);
        $this->assertSame($this->activeUserId, (int)$active['id']);
        $this->assertSame('zz_cov_g_active_7903', $active['username']);
    }

    public function testGenerateAndVerifyStateTamperingRefused(): void
    {
        // 1. Valid state verification
        $webState = GoogleAuthService::generateState(false);
        $this->assertNotEmpty($webState);
        $res = GoogleAuthService::verifyState($webState);
        $this->assertTrue($res['valid']);
        $this->assertFalse($res['mobile']);

        // 2. Reusing state fails (single-use)
        $resReused = GoogleAuthService::verifyState($webState);
        $this->assertFalse($resReused['valid']);

        // 3. Mobile state
        $mobileState = GoogleAuthService::generateState(true);
        $resMob = GoogleAuthService::verifyState($mobileState);
        $this->assertTrue($resMob['valid']);
        $this->assertTrue($resMob['mobile']);

        // 4. State tampering: appended characters
        $state2 = GoogleAuthService::generateState(false);
        $tampered = $state2 . 'tampered';
        $resTampered = GoogleAuthService::verifyState($tampered);
        $this->assertFalse($resTampered['valid']);

        // 5. State tampering: invalid base64 / invalid json
        $this->assertFalse(GoogleAuthService::verifyState('!@#$%^&*()')['valid']);
        $this->assertFalse(GoogleAuthService::verifyState(base64_encode('{"plain":"string"}'))['valid']);

        // 6. Expired state (older than 600s)
        $expiredData = base64_encode(json_encode([
            'csrf' => bin2hex(random_bytes(16)),
            'mobile' => 0,
            'time' => time() - 650,
        ]));
        $_SESSION['google_oauth_state'] = $expiredData;
        $resExpired = GoogleAuthService::verifyState($expiredData);
        $this->assertFalse($resExpired['valid']);
    }

    public function testLoginUserNormalAndMustResetPassword(): void
    {
        // 1. User requiring password reset -> redirects to /force-reset
        $resetUser = GoogleAuthService::findUserByEmail($this->resetEmail);
        $this->assertNotNull($resetUser);
        $redirectReset = GoogleAuthService::loginUser($resetUser, '127.0.0.1');
        $this->assertSame('/force-reset', $redirectReset);
        $this->assertSame($this->resetUserId, $_SESSION['pending_reset_user_id'] ?? null);

        // 2. Normal user login -> returns roleHomePath (/dashboard)
        $_SESSION = [];
        $activeUser = GoogleAuthService::findUserByEmail($this->activeEmail);
        $this->assertNotNull($activeUser);
        $redirect = GoogleAuthService::loginUser($activeUser, '127.0.0.1');
        $this->assertSame('/dashboard', $redirect);
        $this->assertSame($this->activeUserId, $_SESSION['user_id'] ?? null);
        $this->assertSame('zz_cov_g_active_7903', $_SESSION['username'] ?? null);
        $this->assertSame('admin', $_SESSION['user_role'] ?? null);
    }

    public function testSaveSettingsValidationAndEnablement(): void
    {
        // 1. Enabling with empty client ID must fail
        $resFail = GoogleAuthService::saveSettings([
            'google_oauth_enabled' => '1',
            'google_client_id' => '  ',
            'google_client_secret' => 'sec123',
        ]);
        $this->assertFalse($resFail['success']);
        $this->assertSame(t('srv_google.err_client_id'), $resFail['error'] ?? '');

        // 2. Enabling with valid client ID succeeds
        $resOk = GoogleAuthService::saveSettings([
            'google_oauth_enabled' => '1',
            'google_client_id' => 'zz_cov_client_id_7903.apps.googleusercontent.com',
            'google_client_secret' => 'zz_cov_secret_7903',
        ]);
        $this->assertTrue($resOk['success']);
        $this->assertTrue(GoogleAuthService::isEnabled());
        $this->assertSame('zz_cov_client_id_7903.apps.googleusercontent.com', GoogleAuthService::getClientId());
        $this->assertSame('zz_cov_secret_7903', GoogleAuthService::getClientSecret());

        // 3. Disabling succeeds
        $resDis = GoogleAuthService::saveSettings([
            'google_oauth_enabled' => '0',
            'google_client_id' => '',
            'google_client_secret' => '',
        ]);
        $this->assertTrue($resDis['success']);
        $this->assertFalse(GoogleAuthService::isEnabled());
    }

    public function testRedirectUriAndAuthUrl(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('google_client_id', 'client-7903') ON DUPLICATE KEY UPDATE setting_value = 'client-7903'")->execute();
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('google_redirect_uri', 'https://custom.aipbx.test/callback') ON DUPLICATE KEY UPDATE setting_value = 'https://custom.aipbx.test/callback'")->execute();

        $this->assertSame('https://custom.aipbx.test/callback', GoogleAuthService::getRedirectUri());

        $url = GoogleAuthService::getAuthUrl(false);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('client_id=client-7903', $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://custom.aipbx.test/callback'), $url);
        $this->assertStringContainsString('scope=' . urlencode('openid email profile'), $url);
        $this->assertStringContainsString('state=', $url);

        $settings = GoogleAuthService::settingsForPage();
        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('client_id', $settings);
        $this->assertSame('client-7903', $settings['client_id']);
    }

    public function testIsEmailVerifiedReflectionHelper(): void
    {
        $method = new ReflectionMethod(GoogleAuthService::class, 'isEmailVerified');

        // Various verified representations
        $this->assertTrue($method->invoke(null, ['email_verified' => true]));
        $this->assertTrue($method->invoke(null, ['email_verified' => 'true']));
        $this->assertTrue($method->invoke(null, ['email_verified' => 1]));
        $this->assertTrue($method->invoke(null, ['email_verified' => '1']));
        $this->assertTrue($method->invoke(null, ['verified_email' => true]));
        $this->assertTrue($method->invoke(null, ['verified_email' => '1']));

        // Unverified representations
        $this->assertFalse($method->invoke(null, ['email_verified' => false]));
        $this->assertFalse($method->invoke(null, ['email_verified' => 'false']));
        $this->assertFalse($method->invoke(null, ['email_verified' => 0]));
        $this->assertFalse($method->invoke(null, ['verified_email' => false]));
        $this->assertFalse($method->invoke(null, []));
    }

    public function testVerifyIdTokenRefusesEmptyAndBadTokens(): void
    {
        // Empty token returns null immediately
        $this->assertNull(GoogleAuthService::verifyIdToken(''));

        // Invalid token returns null
        $this->assertNull(GoogleAuthService::verifyIdToken('invalid_token_format'));
    }
}
