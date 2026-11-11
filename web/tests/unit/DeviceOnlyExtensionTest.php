<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ExtensionService.php';
require_once __DIR__ . '/../../src/repositories/SystemUserRepository.php';
require_once __DIR__ . '/../../src/repositories/ExtensionRepository.php';
require_once __DIR__ . '/../../api/mobile/auth_helper.php';
require_once __DIR__ . '/../../auth.php';

/**
 * A device-only extension (lobby, door, shared line) is a SIP phone without a portal user: it
 * cannot sign in by any way, is not listed among people, and stays an extension everywhere else.
 */
final class DeviceOnlyExtensionTest extends TestCase
{
    private const EXT = '7402';
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
        unset($_SESSION['user_id'], $_SESSION['session_epoch']);
    }

    private function save(array $extra): array
    {
        return ExtensionService::saveExtension($extra + [
            'csrf_token' => 'test-token',
            'extension' => self::EXT,
            'full_name' => 'Lobby phone',
            'sip_password' => 'S' . bin2hex(random_bytes(8)),
            'extension_type' => 'sip',
            'is_active' => '1',
        ]);
    }

    private function row(): array
    {
        return $this->db->query("SELECT * FROM sys_users WHERE extension = '" . self::EXT . "'")->fetch(PDO::FETCH_ASSOC);
    }

    public function testTheBoxIsOffUnlessTicked(): void
    {
        $this->assertTrue($this->save([])['success']);
        $this->assertSame(0, (int) $this->row()['is_device']);
        $this->assertNotFalse(findLoginUser(self::EXT, 'id'));
    }

    public function testADeviceCannotSignInAndIsNoPerson(): void
    {
        $res = $this->save(['is_device' => '1']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $row = $this->row();
        $this->assertSame(1, (int) $row['is_device']);
        $this->assertSame('sip', $row['allowed_phone_mode']);

        // Password sign-in (web and mobile), by number or by its generated name.
        $this->assertFalse(findLoginUser(self::EXT, 'id'));
        $this->assertFalse(findLoginUser((string) $row['username'], 'id'));

        // A web session, whatever created it (SSO, passkey): refused on the next request.
        $_SESSION['user_id'] = (int) $row['id'];
        $this->assertFalse(_isSessionEpochCurrent());

        // A mobile token, even a correctly signed one.
        $this->assertNull(validateMobileToken(generateMobileToken((int) $row['id'])));

        // Not a person on the users page, still an extension on the extensions page.
        $this->assertNotContains((int) $row['id'], array_map('intval', array_column(SystemUserRepository::allWithRoleName(), 'id')));
        $listed = array_column(ExtensionRepository::allWithExtension(), 'is_device', 'extension');
        $this->assertSame(1, (int) ($listed[self::EXT] ?? 0));
    }

    public function testAFieldThatIsNotSentLeavesTheFlagAlone(): void
    {
        $this->save(['is_device' => '1']);
        $id = (int) $this->row()['id'];
        // The API and imports send no is_device.
        $this->assertTrue($this->save(['user_id' => $id, 'full_name' => 'Lobby 2'])['success']);
        $this->assertSame(1, (int) $this->row()['is_device']);
        $this->assertTrue($this->save(['user_id' => $id, 'is_device' => '0'])['success']);
        $this->assertSame(0, (int) $this->row()['is_device']);
    }

    public function testTurningAUserIntoADeviceEndsItsSessions(): void
    {
        $this->save([]);
        $row = $this->row();
        $token = generateMobileToken((int) $row['id']);
        $this->assertNotNull(validateMobileToken($token));
        $this->assertTrue($this->save(['user_id' => (int) $row['id'], 'is_device' => '1'])['success']);
        $this->assertGreaterThan((int) $row['token_epoch'], (int) $this->row()['token_epoch']);
        $this->assertNull(validateMobileToken($token));
    }

    public function testAnAdminOrOneselfCannotBecomeADevice(): void
    {
        $this->save([]);
        $id = (int) $this->row()['id'];
        $this->db->exec("UPDATE sys_users SET role = 'admin' WHERE id = {$id}");
        $this->assertFalse($this->save(['user_id' => $id, 'is_device' => '1'])['success']);

        $this->db->exec("UPDATE sys_users SET role = 'user' WHERE id = {$id}");
        $_SESSION['user_id'] = $id;
        $this->assertFalse($this->save(['user_id' => $id, 'is_device' => '1'])['success']);
        $this->assertSame(0, (int) $this->row()['is_device']);
    }
}
