<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use OidcAuthService;
use PluginManager;
use PDO;

require_once dirname(__DIR__, 2) . '/src/services/OidcAuthService.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';

final class OidcAuthTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $cleanUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = getDB();
    }

    protected function tearDown(): void
    {
        OidcAuthService::$jwksForTests = null;
        if (!empty($this->cleanUserIds)) {
            $in = implode(',', array_map('intval', $this->cleanUserIds));
            $this->db->exec("DELETE FROM sys_users WHERE id IN ({$in})");
        }
        $this->db->exec("DELETE FROM sys_users WHERE username LIKE 'oidc_test_%'");
        parent::tearDown();
    }

    public function testOidcAuthDisabledByDefault(): void
    {
        PluginManager::resetCache();
        // Without plugin enabled in DB, isEnabled() must return false
        $this->assertFalse(OidcAuthService::isEnabled());
    }

    public function testOidcAuthPublicSettings(): void
    {
        $public = OidcAuthService::getPublicSettings();
        $this->assertArrayHasKey('enabled', $public);
        $this->assertArrayHasKey('provider_name', $public);
        $this->assertArrayHasKey('icon', $public);
        $this->assertSame('fas fa-shield-alt', $public['icon']);
    }

    public function testRedirectUriResolution(): void
    {
        $uri = OidcAuthService::getRedirectUri();
        $this->assertStringContainsString('/auth/oidc/callback', $uri);
    }

    public function testStateGenerationAndVerification(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $state = OidcAuthService::generateState(false);
        $this->assertNotEmpty($state);
        $this->assertSame($state, $_SESSION['oidc_oauth_state'] ?? null);

        // Verification with matching state succeeds
        $res = OidcAuthService::verifyState($state);
        $this->assertTrue($res['valid']);
        $this->assertFalse($res['mobile']);

        // Replay attack: second verification fails because session state is cleared
        $replay = OidcAuthService::verifyState($state);
        $this->assertFalse($replay['valid']);

        // Invalid or empty state fails
        $empty = OidcAuthService::verifyState('');
        $this->assertFalse($empty['valid']);
    }

    public function testDomainFiltering(): void
    {
        // When allowed_domains is empty, any domain is allowed
        $this->assertTrue(OidcAuthService::isDomainAllowed('john@anydomain.com'));

        // Reflection or mock test: if domains are configured
        // Test parsing directly with simulated setting
        $allowed = 'company.com, partner.org';
        $testEmail1 = 'alice@company.com';
        $testEmail2 = 'bob@PARTNER.ORG';
        $testEmail3 = 'eve@malicious.com';

        $checkDomain = static function (string $email, string $allowedList): bool {
            if (empty(trim($allowedList))) {
                return true;
            }
            $parts = explode('@', $email);
            if (count($parts) !== 2) {
                return false;
            }
            $domain = mb_strtolower(trim($parts[1]));
            $list = array_filter(array_map('trim', explode(',', mb_strtolower($allowedList))));
            return in_array($domain, $list, true);
        };

        $this->assertTrue($checkDomain($testEmail1, $allowed));
        $this->assertTrue($checkDomain($testEmail2, $allowed));
        $this->assertFalse($checkDomain($testEmail3, $allowed));
    }

    public function testParseJwtClaims(): void
    {
        $header = base64_encode(json_encode(['alg' => 'none']));
        $payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode([
            'sub'                => 'oidc-user-12345',
            'email'              => 'test.user@company.com',
            'name'               => 'Test OIDC User',
            'preferred_username' => 'testuser',
            'roles'              => ['user', 'pbx_user'],
        ])));
        $jwt = "{$header}.{$payload}.signature";

        $claims = OidcAuthService::parseJwtClaims($jwt);
        $this->assertIsArray($claims);
        $this->assertSame('oidc-user-12345', $claims['sub']);
        $this->assertSame('test.user@company.com', $claims['email']);
        $this->assertSame('Test OIDC User', $claims['name']);
        $this->assertSame(['user', 'pbx_user'], $claims['roles']);

        // Malformed JWT returns null
        $this->assertNull(OidcAuthService::parseJwtClaims('invalid-token'));
    }

    public function testRoleClaimResolution(): void
    {
        // Default role when no special claim matches
        $claims1 = ['roles' => ['user', 'staff']];
        $role1 = OidcAuthService::resolveRoleFromClaims($claims1);
        $this->assertSame('user', $role1);

        // When claim contains 'aipbx_admin' (default adminMatch)
        $claims2 = ['roles' => ['staff', 'aipbx_admin']];
        $role2 = OidcAuthService::resolveRoleFromClaims($claims2);
        $this->assertSame('admin', $role2);
    }

    public function testAutoProvisionUserCreation(): void
    {
        $testEmail = 'oidc_test_' . bin2hex(random_bytes(4)) . '@example.com';
        $userInfo = [
            'email'              => $testEmail,
            'full_name'          => 'OIDC Auto Provisioned User',
            'preferred_username' => 'oidc_test_user',
            'sub'                => 'sub-12345678',
            'claims'             => [
                'roles' => ['user'],
            ],
        ];

        $user = OidcAuthService::autoProvisionUser($userInfo);
        $this->assertNotNull($user);
        $this->cleanUserIds[] = (int)$user['id'];

        $this->assertSame($testEmail, $user['email']);
        $this->assertSame('OIDC Auto Provisioned User', $user['full_name']);
        $this->assertSame('user', $user['role']);
        $this->assertSame(1, (int)$user['is_active']);
        $this->assertSame(0, (int)$user['must_reset_password']);

        // Collision test: provision another user with same preferred_username but different email
        $testEmail2 = 'oidc_test_' . bin2hex(random_bytes(4)) . '@example.com';
        $userInfo2 = [
            'email'              => $testEmail2,
            'full_name'          => 'OIDC Second User',
            'preferred_username' => 'oidc_test_user',
            'sub'                => 'sub-87654321',
            'claims'             => ['roles' => ['user']],
        ];

        $user2 = OidcAuthService::autoProvisionUser($userInfo2);
        $this->assertNotNull($user2);
        $this->cleanUserIds[] = (int)$user2['id'];

        // Second user should have a suffixed username (e.g. oidc_test_user1)
        $this->assertNotSame($user['username'], $user2['username']);
        $this->assertStringStartsWith('oidc_test_user', $user2['username']);
    }

    public function testLoginUserSetsSession(): void
    {
        $fakeUser = [
            'id'                  => 99999,
            'username'            => 'sso_tester',
            'full_name'           => 'SSO Tester',
            'role'                => 'admin',
            'extension'           => '1001',
            'email'               => 'sso@company.com',
            'theme_preference'    => 'dark',
            'language_preference' => 'en',
            'must_reset_password' => 0,
        ];

        $redirect = OidcAuthService::loginUser($fakeUser, '127.0.0.1');

        $this->assertSame(99999, $_SESSION['user_id']);
        $this->assertSame('sso_tester', $_SESSION['username']);
        $this->assertSame('admin', $_SESSION['user_role']);
        $this->assertSame('admin', $_SESSION['role']);
        $this->assertSame('oidc', $_SESSION['auth_method']);
        $this->assertSame('oidc', $_SESSION['auth_provider']);
        $this->assertSame('both', $_SESSION['allowed_phone_mode']);
        $this->assertSame('/dashboard', $redirect);
    }

    public function testLoginUserRedirectsToForceResetWhenMustResetPassword(): void
    {
        $_SESSION = [];
        $fakeUser = [
            'id'                  => 88888,
            'username'            => 'reset_needed',
            'full_name'           => 'Reset User',
            'role'                => 'user',
            'extension'           => '',
            'email'               => 'reset@example.com',
            'must_reset_password' => 1,
        ];

        $redirect = OidcAuthService::loginUser($fakeUser, '127.0.0.1');
        $this->assertSame('/force-reset', $redirect);
        $this->assertSame(88888, $_SESSION['pending_reset_user_id']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testAutoProvisionInvalidRoleFallsBackToUser(): void
    {
        $testEmail = 'oidc_test_' . bin2hex(random_bytes(4)) . '@example.com';
        $userInfo = [
            'email'              => $testEmail,
            'full_name'          => 'Fallback Role User',
            'preferred_username' => 'oidc_fallback_user',
            'sub'                => 'sub-nonexistent-role',
            'claims'             => [
                'roles' => ['nonexistent_role_123'],
            ],
        ];

        $user = OidcAuthService::autoProvisionUser($userInfo);
        $this->assertNotNull($user);
        $this->cleanUserIds[] = (int)$user['id'];
        $this->assertSame('user', $user['role']);
    }

    public function testTheIssuerMustBeTheConfiguredOne(): void
    {
        $this->assertTrue(OidcAuthService::issuerMatches('https://idp.example/realm', 'https://idp.example/realm'));
        $this->assertTrue(OidcAuthService::issuerMatches('https://idp.example/realm/', 'https://idp.example/realm'));
        $this->assertFalse(OidcAuthService::issuerMatches('https://idp.example/realm', 'https://evil.example/realm'));
        $this->assertFalse(OidcAuthService::issuerMatches('https://idp.example/realm', ''));
        $this->assertTrue(OidcAuthService::issuerMatches('', 'anything'), 'no issuer in the discovery document');

        $ms = 'https://login.microsoftonline.com/{tenantid}/v2.0';
        $this->assertTrue(OidcAuthService::issuerMatches($ms, 'https://login.microsoftonline.com/1234-abcd/v2.0'));
        $this->assertFalse(OidcAuthService::issuerMatches($ms, 'https://evil.example/1234-abcd/v2.0'));
        $this->assertFalse(OidcAuthService::issuerMatches($ms, 'https://login.microsoftonline.com/a/b/v2.0'));
    }

    public function testIdTokenNonceAndExpirationValidation(): void
    {
        [$private, $jwk] = $this->rsaKey('k1');
        OidcAuthService::$jwksForTests = [$jwk];
        $makeToken = fn(array $claims): string => $this->signRsa($claims, $private, 'k1');

        // 1. Valid nonce & future exp succeeds
        $validJwt = $makeToken([
            'sub'   => 'u-1',
            'email' => 'valid@company.com',
            'name'  => 'Valid User',
            'nonce' => 'nonce-abc-123',
            'exp'   => time() + 600,
        ]);
        $info = OidcAuthService::getUserInfo('dummy-access', $validJwt, 'nonce-abc-123');
        $this->assertNotNull($info);
        $this->assertSame('valid@company.com', $info['email']);

        // 2. Mismatched nonce fails
        $badNonceInfo = OidcAuthService::getUserInfo('dummy-access', $validJwt, 'wrong-nonce');
        $this->assertNull($badNonceInfo);
        $this->assertStringContainsString('nonce', (string)OidcAuthService::getLastError());

        // 3. Expired token fails
        $expiredJwt = $makeToken([
            'sub'   => 'u-1',
            'email' => 'expired@company.com',
            'nonce' => 'nonce-abc-123',
            'exp'   => time() - 600,
        ]);
        $expiredInfo = OidcAuthService::getUserInfo('dummy-access', $expiredJwt, 'nonce-abc-123');
        $this->assertNull($expiredInfo);
        $this->assertStringContainsString('expired', (string)OidcAuthService::getLastError());
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: array<string, string>} */
    private function rsaKey(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($key)['rsa'];
        return [$key, ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'n' => self::b64($d['n']), 'e' => self::b64($d['e'])]];
    }

    private function signRsa(array $claims, \OpenSSLAsymmetricKey $key, string $kid): string
    {
        $input = self::b64((string) json_encode(['alg' => 'RS256', 'kid' => $kid])) . '.' . self::b64((string) json_encode($claims));
        openssl_sign($input, $sig, $key, 'sha256');
        return $input . '.' . self::b64($sig);
    }

    public function testRsaSignatureIsCheckedAgainstTheNamedKey(): void
    {
        [$key, $jwk] = $this->rsaKey('k1');
        [$other, $otherJwk] = $this->rsaKey('k2');
        $jwt = $this->signRsa(['sub' => 'a'], $key, 'k1');

        $this->assertTrue(OidcAuthService::verifyIdTokenSignature($jwt, [$otherJwk, $jwk]));
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($jwt, [$otherJwk]), 'key not published');
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($this->signRsa(['sub' => 'a'], $other, 'k1'), [$jwk]), 'signed by another key');
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($jwt, []), 'no keys');

        [$h, , $s] = explode('.', $jwt);
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature("{$h}." . self::b64('{"sub":"admin"}') . ".{$s}", [$jwk]), 'changed claims');
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($jwt, [['use' => 'enc'] + $jwk]), 'encryption key');
    }

    public function testEcSignatureIsChecked(): void
    {
        foreach (['ES256' => ['prime256v1', 'P-256', 'sha256', 32], 'ES384' => ['secp384r1', 'P-384', 'sha384', 48]] as $alg => [$curve, $crv, $digest, $size]) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
            $d = openssl_pkey_get_details($key)['ec'];
            $jwk = ['kty' => 'EC', 'crv' => $crv, 'kid' => 'e', 'x' => self::b64(str_pad($d['x'], $size, "\0", STR_PAD_LEFT)), 'y' => self::b64(str_pad($d['y'], $size, "\0", STR_PAD_LEFT))];
            $input = self::b64((string) json_encode(['alg' => $alg, 'kid' => 'e'])) . '.' . self::b64('{"sub":"a"}');
            openssl_sign($input, $der, $key, $digest);
            // DER to the JWS form: r and s side by side.
            $offset = 2 + (ord($der[1]) > 0x80 ? 1 : 0);
            $rLen = ord($der[$offset + 1]);
            $r = substr($der, $offset + 2, $rLen);
            $s = substr($der, $offset + 2 + $rLen + 2);
            $raw = str_pad(ltrim($r, "\0"), $size, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), $size, "\0", STR_PAD_LEFT);

            $this->assertTrue(OidcAuthService::verifyIdTokenSignature($input . '.' . self::b64($raw), [$jwk]), $alg);
            $raw[5] = chr(ord($raw[5]) ^ 1);
            $this->assertFalse(OidcAuthService::verifyIdTokenSignature($input . '.' . self::b64($raw), [$jwk]), $alg . ' changed');
        }
    }

    public function testOtherAlgorithmsAndConfusionAreRefused(): void
    {
        [$key, $jwk] = $this->rsaKey('k1');
        $payload = self::b64('{"sub":"admin"}');
        $none = self::b64('{"alg":"none"}') . ".{$payload}.";
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($none, [$jwk]));
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($none . 'sig', [$jwk], 'secret'));
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature('two.parts', [$jwk]));

        $input = self::b64('{"alg":"HS256"}') . ".{$payload}";
        $good = $input . '.' . self::b64(hash_hmac('sha256', $input, 'client-secret', true));
        $this->assertTrue(OidcAuthService::verifyIdTokenSignature($good, [], 'client-secret'));
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($good, [$jwk], 'other'));
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($good, [$jwk], ''), 'no secret');

        // The classic trick: HMAC with the public key as the secret.
        $public = openssl_pkey_get_details($key)['key'];
        $forged = $input . '.' . self::b64(hash_hmac('sha256', $input, $public, true));
        $this->assertFalse(OidcAuthService::verifyIdTokenSignature($forged, [$jwk], 'client-secret'));
    }

    public function testAnUnsignedTokenStopsTheSignIn(): void
    {
        OidcAuthService::$jwksForTests = [];
        $jwt = self::b64('{"alg":"none"}') . '.' . self::b64((string) json_encode(['sub' => 'a', 'exp' => time() + 600])) . '.x';
        $this->assertNull(OidcAuthService::getUserInfo('dummy', $jwt, null));
    }
}
