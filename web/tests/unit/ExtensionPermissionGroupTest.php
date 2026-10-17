<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/services/ExtensionService.php';

/**
 * The endpoint carries the user's dial-permission group. The sync query did
 * not select permission_group_id, so every endpoint got group 1 (no
 * restriction) and restricted groups were never applied.
 */
final class ExtensionPermissionGroupTest extends TestCase
{
    private const EXT = '7412';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
        $this->db->exec("DELETE FROM sip WHERE id = '" . self::EXT . "'");
        $this->db->exec("DELETE FROM pbx_permission_groups WHERE group_name IN ('PermTestOn', 'PermTestOff')");
    }

    private function group(string $name, int $active): int
    {
        $this->db->prepare("INSERT INTO pbx_permission_groups (group_name, default_action, is_active) VALUES (?, 'deny', ?)")
            ->execute([$name, $active]);
        return (int) $this->db->lastInsertId();
    }

    private function endpointGroup(int $group): string
    {
        $res = ExtensionService::saveExtension([
            'csrf_token' => 'test-token',
            'extension' => self::EXT,
            'full_name' => 'Permission Group Test',
            'extension_type' => 'sip',
            'sip_password' => 'Perm-' . bin2hex(random_bytes(6)),
            'permission_group_id' => (string) $group,
            'is_active' => '1',
        ]);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');
        syncAllExtensions();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');
        $groups = [];
        foreach (explode("\n[", $conf) as $section) {
            if (str_starts_with($section, self::EXT) && preg_match('/set_var=PERMISSION_GROUP_ID=(\d+)/', $section, $m)) {
                $groups[] = $m[1];
            }
        }
        $this->assertNotEmpty($groups, 'endpoint has no group');
        $this->assertCount(1, array_unique($groups), 'all endpoints of the extension share the group');
        return $groups[0];
    }

    public function testEndpointCarriesTheUsersGroup(): void
    {
        $id = $this->group('PermTestOn', 1);
        $this->assertSame((string) $id, $this->endpointGroup($id));
    }

    public function testDisabledGroupMeansNoRestriction(): void
    {
        // perm-group-N exists only for active groups: a disabled one must not
        // send calls to a missing context.
        $this->assertSame('1', $this->endpointGroup($this->group('PermTestOff', 0)));
    }
}
