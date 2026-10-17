<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ExtensionService.php';
require_once __DIR__ . '/../../src/services/UserService.php';

/**
 * A changed SIP password must reach the PJSIP endpoint.
 *
 * SyncExtensions prefers sip.secret over sys_users.sip_password, so the
 * panel/reset had to write the secret into the `sip` table too. It did not:
 * the endpoint kept the OLD secret (which stayed valid) and the new
 * password never registered. The create path always worked, which is why
 * nobody noticed — the bug fires only on change/reset.
 */
final class SipSecretRotationTest extends TestCase
{
    private const EXT = '7411';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
        $this->db->exec("DELETE FROM sip WHERE id = '" . self::EXT . "'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
        $this->db->exec("DELETE FROM sip WHERE id = '" . self::EXT . "'");
    }

    private function save(array $extra): array
    {
        return ExtensionService::saveExtension($extra + [
            'csrf_token' => 'test-token',
            'extension' => self::EXT,
            'full_name' => 'Secret Rotation Test',
            'extension_type' => 'sip',
            'is_active' => '1',
        ]);
    }

    private function sipSecret(): ?string
    {
        $st = $this->db->prepare('SELECT data FROM sip WHERE id = ? AND keyword = ?');
        $st->execute([self::EXT, 'secret']);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function userId(): int
    {
        return (int) $this->db->query(
            "SELECT id FROM sys_users WHERE extension = '" . self::EXT . "'"
        )->fetchColumn();
    }

    public function testUpdatedPasswordReachesSipTable(): void
    {
        $pw1 = 'First-' . bin2hex(random_bytes(6));
        $this->assertTrue($this->save(['sip_password' => $pw1])['success']);
        $this->assertSame($pw1, $this->sipSecret(), 'precondition: create must store the secret');

        $pw2 = 'Second-' . bin2hex(random_bytes(6));
        $res = $this->save(['user_id' => $this->userId(), 'sip_password' => $pw2]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame(
            $pw2,
            $this->sipSecret(),
            'the changed password never reached the PJSIP endpoint: the OLD secret stayed valid'
        );
    }

    public function testAdminResetReachesSipTable(): void
    {
        $pw1 = 'Reset-' . bin2hex(random_bytes(6));
        $this->assertTrue($this->save(['sip_password' => $pw1])['success']);

        $pw2 = 'Rotated-' . bin2hex(random_bytes(6));
        $res = UserService::resetPassword([
            'csrf_token' => 'test-token',
            'user_id' => $this->userId(),
            'new_sip_password' => $pw2,
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame(
            $pw2,
            $this->sipSecret(),
            'an admin rotation that only marks pending sync never updates sip.secret'
        );
    }

    public function testSwitchToFaxRemovesSipRow(): void
    {
        $pw = 'Fax-' . bin2hex(random_bytes(6));
        $this->assertTrue($this->save(['sip_password' => $pw])['success']);
        $this->assertNotNull($this->sipSecret(), 'precondition: the SIP row must exist');

        $res = $this->save(['user_id' => $this->userId(), 'extension_type' => 'fax']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertNull(
            $this->sipSecret(),
            'a fax extension has no endpoint: the leftover row would shadow a later SIP reuse'
        );
    }

    public function testShortPasswordRejectedWhenDigestOn(): void
    {
        $pw = 'Long-' . bin2hex(random_bytes(6));
        $this->assertTrue($this->save(['sip_password' => $pw])['success']);

        // Same floor as the Extensions page: digest auth needs 6+ characters.
        $res = UserService::resetPassword([
            'csrf_token' => 'test-token',
            'user_id' => $this->userId(),
            'new_sip_password' => 'abc',
        ]);
        $this->assertFalse($res['success'], 'a weak reset must not be accepted');
        $this->assertSame($pw, $this->sipSecret(), 'a refused reset must leave the secret alone');
    }

    public function testShortPasswordAllowedWhenDigestOff(): void
    {
        $this->assertTrue($this->save(['sip_password' => 'abc', 'sip_auth_digest' => '0'])['success']);

        // No digest, no floor — but the value must still reach the endpoint.
        $res = UserService::resetPassword([
            'csrf_token' => 'test-token',
            'user_id' => $this->userId(),
            'new_sip_password' => 'xyz',
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame('xyz', $this->sipSecret());
    }
}
