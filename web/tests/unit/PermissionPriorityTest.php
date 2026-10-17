<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncPermissions.php';

/**
 * Permission rules run in priority order and the FIRST match wins.
 *
 * Plain exten lines could not do that: Asterisk most-specific-match beats
 * file order, so a broad deny lost to a narrow allow whatever the priority
 * said. The group context is now an ordered REGEX() chain; a prefix also
 * matches the number itself (dialling 90 hits a 90-prefix rule), which the
 * old _90. lines never did.
 */
final class PermissionPriorityTest extends TestCase
{
    private const GROUP = 'PriorityTest group';

    protected function setUp(): void
    {
        Fixtures::load();
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->prepare('DELETE FROM pbx_permission_rules WHERE group_id IN (SELECT id FROM pbx_permission_groups WHERE group_name = ?)')
           ->execute([self::GROUP]);
        $db->prepare('DELETE FROM pbx_permission_groups WHERE group_name = ?')->execute([self::GROUP]);
    }

    public function testTranslator(): void
    {
        $this->assertSame('^0090$', permissionPatternToRegex('0090', true));
        $this->assertSame('^0090', permissionPatternToRegex('0090', false));
        $this->assertSame('^00.+', permissionPatternToRegex('_00.', false));
        $this->assertSame('^5[0-9][0-9]$', permissionPatternToRegex('5XX', true));
        $this->assertSame('^[2-9][0-9][1-9]$', permissionPatternToRegex('nxz', true));
        $this->assertSame('^\\*21$', permissionPatternToRegex('*21', true));
    }

    public function testFirstMatchInPriorityOrderWins(): void
    {
        $db = getDB();
        $db->prepare('INSERT INTO pbx_permission_groups (group_name, default_action, is_active) VALUES (?, ?, 1)')
           ->execute([self::GROUP, 'allow']);
        $gid = (int) $db->lastInsertId();
        // Broad deny FIRST, narrow allow second: best-match would pick the
        // allow for 0090...; priority order must pick the deny.
        $db->prepare("INSERT INTO pbx_permission_rules (group_id, pattern, pattern_type, action, priority, description)
                      VALUES (?, '_00.', 'prefix', 'deny', 1, 'priority-probe')")->execute([$gid]);
        $db->prepare("INSERT INTO pbx_permission_rules (group_id, pattern, pattern_type, action, priority, description)
                      VALUES (?, '_0090.', 'prefix', 'allow', 2, 'priority-probe')")->execute([$gid]);
        $db->prepare("INSERT INTO pbx_permission_rules (group_id, pattern, pattern_type, action, priority, description)
                      VALUES (?, '090', 'prefix', 'deny', 3, 'priority-probe')")->execute([$gid]);

        syncPermissions();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_permissions.conf');

        $denyPos = strpos($conf, 'GotoIf(${REGEX("^00.+"');
        $allowPos = strpos($conf, 'GotoIf(${REGEX("^0090.+"');
        $this->assertNotFalse($denyPos, 'deny rule missing from the chain');
        $this->assertNotFalse($allowPos, 'allow rule missing from the chain');
        $this->assertLessThan($allowPos, $denyPos, 'priority order must be the file order');

        // First-match-wins evaluated with the same translator: 0090555...
        // hits the deny even though the allow is more specific.
        $this->assertSame('deny', $this->decide($gid, '00905551234567'));
        $this->assertSame('deny', $this->decide($gid, '004930123456'));
        $this->assertSame('allow', $this->decide($gid, '0599'), 'default action applies past every rule');
        // The old _090. lines needed one more digit; the chain matches 090 itself.
        $this->assertSame('deny', $this->decide($gid, '090'), 'a prefix matches the number itself');
    }

    /** First matching rule in priority order, like the dialplan chain. */
    private function decide(int $gid, string $number): string
    {
        $db = getDB();
        $st = $db->prepare('SELECT pattern, pattern_type, action FROM pbx_permission_rules WHERE group_id = ? ORDER BY priority ASC, id ASC');
        $st->execute([$gid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pattern = (string) preg_replace('/[^0-9NXZnxz.\[\]_!*#-]/', '', trim($r['pattern']));
            if ($pattern === '') continue;
            if (preg_match('/' . str_replace('/', '\\/', permissionPatternToRegex($pattern, $r['pattern_type'] === 'exact')) . '/', $number)) {
                return $r['action'];
            }
        }
        return 'allow';
    }
}
