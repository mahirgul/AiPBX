<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/PasskeyService.php';

final class PasskeyCoverageTest extends TestCase
{
    private int $activeUserId = 0;
    private int $inactiveUserId = 0;
    private string $activeCredId = '';
    private string $inactiveCredId = '';

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $db = getDB();
        $passHash = password_hash('Pass_zz_7902!', PASSWORD_DEFAULT);

        // Active user
        $stmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute(['zz_cov_pk_active_7902', $passHash, 'PK Active', 'zz_cov_pk_act@example.com', 'admin', '7904']);
        $this->activeUserId = (int)$db->lastInsertId();

        // Inactive user
        $stmt->execute(['zz_cov_pk_inact_7902', $passHash, 'PK Inactive', 'zz_cov_pk_inact@example.com', 'user', '7905']);
        $this->inactiveUserId = (int)$db->lastInsertId();
        $db->exec("UPDATE sys_users SET is_active = 0 WHERE id = {$this->inactiveUserId}");

        // Passkey for active user
        $this->activeCredId = base64_encode('zz_cov_cred_act_7902_' . bin2hex(random_bytes(8)));
        $pkStmt = $db->prepare('INSERT INTO sys_user_passkeys (user_id, credential_id, public_key, counter, device_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $pkStmt->execute([$this->activeUserId, $this->activeCredId, 'fake-active-public-key', 0, 'Active Laptop']);

        // Passkey for inactive user
        $this->inactiveCredId = base64_encode('zz_cov_cred_inact_7902_' . bin2hex(random_bytes(8)));
        $pkStmt->execute([$this->inactiveUserId, $this->inactiveCredId, 'fake-inactive-public-key', 0, 'Inactive Phone']);
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_user_passkeys WHERE user_id IN ({$this->activeUserId}, {$this->inactiveUserId})");
        $db->exec("DELETE FROM sys_users WHERE id IN ({$this->activeUserId}, {$this->inactiveUserId})");
        $db->exec("DELETE FROM sys_login_logs WHERE username LIKE 'zz_cov_pk_%'");
        $_SESSION = [];
    }

    public function testGetRpIdHostParsing(): void
    {
        $orig = $_SERVER['HTTP_HOST'] ?? null;

        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $this->assertSame('localhost', PasskeyService::getRpId());

        $_SERVER['HTTP_HOST'] = '127.0.0.1:443';
        $this->assertSame('127.0.0.1', PasskeyService::getRpId());

        $_SERVER['HTTP_HOST'] = 'pbx.example.com:8443';
        $rp = PasskeyService::getRpId();
        $this->assertNotEmpty($rp);

        if ($orig !== null) {
            $_SERVER['HTTP_HOST'] = $orig;
        } else {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    public function testRegisterArgsExcludesExistingPasskeys(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $args = PasskeyService::getRegisterArgs($this->activeUserId, 'zz_cov_pk_active_7902', 'PK Active');

        $this->assertNotEmpty($args->challenge);
        $this->assertSame($this->activeUserId, $_SESSION['webauthn_reg_user_id'] ?? null);
        $this->assertNotEmpty($_SESSION['webauthn_reg_challenge'] ?? null);
        $this->assertNotEmpty($args->excludeCredentials);
    }

    public function testProcessRegisterSessionMissingOrMismatchRefused(): void
    {
        // 1. Missing challenge and user id
        $_SESSION = [];
        $resMissing = PasskeyService::processRegister($this->activeUserId, '{}', 'attest', 'Device');
        $this->assertFalse($resMissing['success']);
        $this->assertSame(t('srv_passkey.err_reg_session'), $resMissing['error'] ?? '');

        // 2. User ID mismatch
        $_SESSION['webauthn_reg_challenge'] = 'some_challenge';
        $_SESSION['webauthn_reg_user_id'] = $this->activeUserId + 999;
        $resMismatch = PasskeyService::processRegister($this->activeUserId, '{}', 'attest', 'Device');
        $this->assertFalse($resMismatch['success']);
        $this->assertSame(t('srv_passkey.err_user_mismatch'), $resMismatch['error'] ?? '');

        // 3. Malformed clientData / attestation
        $_SESSION['webauthn_reg_challenge'] = 'some_challenge';
        $_SESSION['webauthn_reg_user_id'] = $this->activeUserId;
        $resMalformed = PasskeyService::processRegister($this->activeUserId, 'not-valid-json', 'bad-attestation', 'Device');
        $this->assertFalse($resMalformed['success']);
        $this->assertStringContainsString('regist', strtolower($resMalformed['error'] ?? ''));
    }

    public function testGetLoginArgsResidentAndUsernameModes(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';

        // 1. Resident key mode (no username)
        $argsResident = PasskeyService::getLoginArgs(null);
        $this->assertNotEmpty($argsResident->challenge);
        $this->assertFalse(isset($argsResident->allowCredentials));
        $this->assertNotEmpty($_SESSION['webauthn_auth_challenge']);

        // 2. With username
        $argsUser = PasskeyService::getLoginArgs('zz_cov_pk_active_7902');
        $this->assertNotEmpty($argsUser->challenge);
        $this->assertTrue(isset($argsUser->allowCredentials));
        $this->assertNotEmpty($argsUser->allowCredentials);
    }

    public function testProcessLoginExpiredOrMissingChallengeRefused(): void
    {
        $_SESSION = [];
        $res = PasskeyService::processLogin('{}', 'auth', 'sig', $this->activeCredId, '127.0.0.1');

        $this->assertFalse($res['success']);
        $this->assertSame(t('srv_passkey.err_timeout'), $res['error'] ?? '');
    }

    public function testProcessLoginChallengeIsSingleUse(): void
    {
        $_SESSION['webauthn_auth_challenge'] = 'single_use_challenge_data';

        // Unknown credential consumes the challenge
        $res1 = PasskeyService::processLogin('{}', 'auth', 'sig', 'unknown-cred-id', '127.0.0.1');
        $this->assertFalse($res1['success']);
        $this->assertSame(t('srv_passkey.err_unknown'), $res1['error'] ?? '');
        $this->assertArrayNotHasKey('webauthn_auth_challenge', $_SESSION);

        // Second attempt with same inputs now fails with timeout because challenge was spent
        $res2 = PasskeyService::processLogin('{}', 'auth', 'sig', 'unknown-cred-id', '127.0.0.1');
        $this->assertFalse($res2['success']);
        $this->assertSame(t('srv_passkey.err_timeout'), $res2['error'] ?? '');
    }

    public function testProcessLoginInactiveUserRefused(): void
    {
        $_SESSION['webauthn_auth_challenge'] = 'test_challenge_inactive';

        $res = PasskeyService::processLogin('{}', 'auth', 'sig', $this->inactiveCredId, '127.0.0.1');

        $this->assertFalse($res['success']);
        $this->assertSame(t('srv_passkey.err_inactive'), $res['error'] ?? '');
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testProcessLoginChallengeMismatchRefused(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SESSION['webauthn_auth_challenge'] = 'challenge_abc_123';

        // Provide an invalid clientData / signature
        $clientData = base64_encode(json_encode(['type' => 'webauthn.get', 'challenge' => 'wrong_challenge', 'origin' => 'http://localhost']));
        $res = PasskeyService::processLogin($clientData, base64_encode('fake-auth-data'), base64_encode('fake-sig'), $this->activeCredId, '127.0.0.1');

        $this->assertFalse($res['success']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);

        // Verify that failed attempt was logged
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE username = 'zz_cov_pk_active_7902' AND status = 'FAILED'");
        $stmt->execute();
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }

    public function testDeletePasskeyOwnershipCheck(): void
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM sys_user_passkeys WHERE user_id = ?');
        $stmt->execute([$this->activeUserId]);
        $pkId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $pkId);

        // Another user cannot delete
        $delDenied = PasskeyService::deletePasskey($this->inactiveUserId, $pkId);
        $this->assertFalse($delDenied);

        // Owner can delete
        $delOk = PasskeyService::deletePasskey($this->activeUserId, $pkId);
        $this->assertTrue($delOk);

        // Now empty
        $keys = PasskeyService::getUserPasskeys($this->activeUserId);
        $this->assertEmpty($keys);
    }
}
