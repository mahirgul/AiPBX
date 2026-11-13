<?php

use PHPUnit\Framework\TestCase;

if (!defined('CC_DISPATCH_ACTIVE')) define('CC_DISPATCH_ACTIVE', true);
require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/api/cc_actions/cc_lib.php';

/**
 * Lines of the 1.76.0 review that only a live call had checked: the dialled-number
 * guard before the permission check, a member's voicemail kept out of queue and
 * ring-group calls, and the scope of a call an agent transfers.
 */
final class ReviewDialplanGuardsTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM pbx_ring_groups WHERE name = 'ReviewGuard'");
        getDB()->exec("DELETE FROM sys_users WHERE username = 'review_scope_user'");
    }

    public function testEveryPermissionCheckComesAfterTheNumberGuard(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
        $guard = ' same => n,GotoIf($[${LEN(${FILTER(0-9*#+,${EXTEN})})} != ${LEN(${EXTEN})}]?sub-permission-denied,s,1)' . "\n";
        $check = ' same => n,Gosub(sub-check-dial-permission,s,1(${EXTEN}))' . "\n";
        $this->assertGreaterThan(0, substr_count($conf, $check), 'the fixture route has a permission check');
        $this->assertSame(substr_count($conf, $check), substr_count($conf, $guard . $check));
    }

    public function testAQueueCallSkipsTheMembersVoicemailOnlyWhileInTheQueue(): void
    {
        $c = buildInternalNumbersConf([
            ['number' => '1092', 'source' => 'queue', 'dest_type' => 'queue', 'dest_id' => '1', 'label' => 'Queue', 'row_id' => 1],
        ]);
        $on = strpos($c, 'Set(__SKIP_VM=1)');
        $queue = strpos($c, ',Queue(');
        $off = strpos($c, 'Set(__SKIP_VM=0)');
        $this->assertNotFalse($on);
        $this->assertNotFalse($queue);
        $this->assertNotFalse($off);
        $this->assertTrue($on < $queue && $queue < $off, 'on before Queue(), off before the fallback');
    }

    public function testARingGroupCallSkipsTheMembersVoicemailUntilTheFallback(): void
    {
        getDB()->exec("INSERT INTO pbx_ring_groups (group_number, internal_number, name, numbers_list, ring_strategy, ring_timeout, cid_prefix, fallback_dest_type, fallback_dest_id, record_call, is_active)
                       VALUES ('7488', '7488', 'ReviewGuard', '7001', 'ringall', 20, '', 'hangup', '', 0, 1)");
        syncRingGroups();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_ringgroups.conf');
        $block = substr($conf, (int) strpos($conf, 'ReviewGuard'));
        $on = strpos($block, 'Set(__SKIP_VM=1)');
        $dial = strpos($block, ',Dial(');
        $off = strpos($block, 'Set(__SKIP_VM=0)');
        $this->assertNotFalse($on);
        $this->assertNotFalse($dial);
        $this->assertNotFalse($off);
        $this->assertTrue($on < $dial && $dial < $off);
    }

    public function testATransferTakesTheAgentsRouteGroupAndPermissionGroup(): void
    {
        $db = getDB();
        $this->assertSame(['from-internal-web', 1], agentDialScope([], $db));
        $this->assertSame(['from-internal-web-3', 4], agentDialScope(['outbound_group' => 3, 'permission_group_id' => 4], $db));

        // The session user row has no permission_group_id: it is read from the database.
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, is_active, permission_group_id) VALUES ('review_scope_user', ?, 'Scope', 'cc_agent', 1, 6)")
            ->execute([password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
        $id = (int) $db->lastInsertId();
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->assertSame(['from-internal-web', 6], agentDialScope(['id' => $id], $db));
    }
}
