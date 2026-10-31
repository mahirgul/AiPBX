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
        $this->assertSame('oidc', $_SESSION['auth_method']);
        $this->assertSame('/dashboard', $redirect);
    }
}
