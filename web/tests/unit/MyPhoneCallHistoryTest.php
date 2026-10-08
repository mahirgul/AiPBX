<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';

final class MyPhoneCallHistoryTest extends TestCase
{
    private static int $user1Id = 0;
    private static int $user2Id = 0;

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        // Insert two test users: 3002 (Alice) and 3001 (Bob)
        $stmt = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, cid_internal, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute(['myphone_test_1', password_hash('Test1234!', PASSWORD_BCRYPT), 'Alice Temsilci', 'user', '3002', '3002']);
        self::$user1Id = (int)$db->lastInsertId();

        $stmt->execute(['myphone_test_2', password_hash('Test1234!', PASSWORD_BCRYPT), 'Bob Destek', 'user', '3001', '3001']);
        self::$user2Id = (int)$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $db = getDB();
        if (self::$user1Id) {
            $db->exec("DELETE FROM sys_users WHERE id = " . self::$user1Id);
        }
        if (self::$user2Id) {
            $db->exec("DELETE FROM sys_users WHERE id = " . self::$user2Id);
        }
    }

    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-myphone-%'");
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-myphone-%'");
    }

    public function testOutboundCallWithStatusLegResolvesTrueDestinationFromDialLegAndUserfield(): void
    {
        $db = getDB();
        $linkedid = 'test-myphone-call-1';

        // Leg 1: Dial leg with trunk destination
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
            VALUES (NOW() - INTERVAL 10 SECOND, '\"Alice Temsilci\" <3002>', '3002', '505379902352', 'from-internal-pbx', 'PJSIP/3002-sip-0001', 'PJSIP/VOIP-0001', 'Dial', 'PJSIP/05379902352@VOIP,60', 0, 15, 'NO ANSWER', ?, ?, ?)"
        )->execute([$linkedid, $linkedid . '-leg1', '/var/spool/asterisk/monitor/outbound_20260923_123232_03704187840_to_05379902352.wav']);

        // Leg 2: Hangup trampoline leg from sub-outbound-status with dst=CHANUNAVAIL
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
            VALUES (NOW(), '\"Alice Temsilci\" <3002>', '3002', 'CHANUNAVAIL', 'sub-outbound-status', 'PJSIP/3002-sip-0001', '', 'Hangup', '21', 0, 0, 'NO ANSWER', ?, ?, ?)"
        )->execute([$linkedid, $linkedid . '-leg2', '/var/spool/asterisk/monitor/outbound_20260923_123232_03704187840_to_05379902352.wav']);

        $calls = MyPhoneRepository::getRecentCalls('3002', null, null, 10);
        $found = null;
        foreach ($calls as $c) {
            if ($c['party'] === '05379902352') {
                $found = $c;
                break;
            }
        }

        $this->assertNotNull($found, 'Outbound call party should be resolved to the true dialed number 05379902352, not CHANUNAVAIL');
        $this->assertSame('out', $found['direction']);
        $this->assertSame('05379902352', $found['party']);
        $this->assertSame('', $found['party_name'], 'Caller own name Alice should NOT be set as partyName for outbound call');
    }

    public function testOutboundCallWhereAsteriskCopiedSelfExtensionResolvesFromLastdata(): void
    {
        $db = getDB();
        $linkedid = 'test-myphone-call-2';

        // When call was originated via AMI, dst was set to self extension 3002, but lastdata dialed 8476
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
            VALUES (NOW(), '\"Alice Temsilci\" <3002>', '3002', '3002', 'from-internal-pbx', 'PJSIP/3002-sip-0002', 'PJSIP/neco-0002', 'Dial', 'PJSIP/8476@neco,60', 0, 5, 'NO ANSWER', ?, ?, ?)"
        )->execute([$linkedid, $linkedid, '/var/spool/asterisk/monitor/outbound_20260923_122839_3002_to_8476.wav']);

        $calls = MyPhoneRepository::getRecentCalls('3002', null, null, 10);
        $found = null;
        foreach ($calls as $c) {
            if ($c['party'] === '8476') {
                $found = $c;
                break;
            }
        }

        $this->assertNotNull($found, 'Party should resolve to 8476 instead of self-extension 3002');
        $this->assertSame('8476', $found['party']);
        $this->assertSame('', $found['party_name']);
    }

    public function testOutboundCallToInternalUserResolvesRecipientName(): void
    {
        $db = getDB();
        $linkedid = 'test-myphone-call-3';

        // Alice calls Bob (3001)
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
            VALUES (NOW(), '\"Alice Temsilci\" <3002>', '3002', '3001', 'from-internal-pbx', 'PJSIP/3002-sip-0003', 'PJSIP/3001-sip-0004', 'Dial', 'PJSIP/3001-sip', 15, 20, 'ANSWERED', ?, ?, ?)"
        )->execute([$linkedid, $linkedid, '']);

        $calls = MyPhoneRepository::getRecentCalls('3002', null, null, 10);
        $found = null;
        foreach ($calls as $c) {
            if ($c['party'] === '3001') {
                $found = $c;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('3001', $found['party']);
        $this->assertSame('Bob Destek', $found['party_name'], 'Internal callee Bob Destek must be resolved from directory');
    }

    public function testSyncOutboundDialplanDoesNotSetReadOnlyCdrDst(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->prepare("INSERT INTO pbx_outbound_routes (route_name, match_pattern, prepend, append, strip_front, strip_back, is_active, route_group, trunks_json)
                      VALUES ('TestRoute', '_0X.', '0', '', 0, 0, 1, 1, ?)")
           ->execute([json_encode([['trunk_name' => Fixtures::TRUNK_NAME]])]);
        $routeId = (int)$db->lastInsertId();

        $res = syncOutboundDialplan();
        $this->assertTrue($res);

        $confPath = ASTERISK_PBX_DIR . '/extensions_outbound.conf';
        $this->assertFileExists($confPath);
        $content = file_get_contents($confPath);

        // Asterisk 22 rejects Set(CDR(dst)) ("read-only variable"); the CDR keeps the dialled number.
        $this->assertStringNotContainsString('Set(CDR(dst)=', $content);
        $this->assertStringNotContainsString('SAVED_DST', $content);
        $this->assertStringContainsString('[sub-outbound-status]', $content);
        // Each route is also reachable directly (destination "outbound route").
        $this->assertStringContainsString("[outbound-route-{$routeId}]", $content);
    }

    /** #10: a user hides calls from their own list; the CDR rows stay. */
    public function testHiddenAndClearedCallsLeaveTheListButNotTheCdr(): void
    {
        $db = getDB();
        $ins = $db->prepare("INSERT INTO asteriskcdr
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
            VALUES (NOW() - INTERVAL ? MINUTE, '\"Alice Temsilci\" <3002>', '3002', '3001', 'from-internal-pbx', 'PJSIP/3002-sip-0003', 'PJSIP/3001-sip-0004', 'Dial', 'PJSIP/3001-sip', 5, 8, 'ANSWERED', ?, ?, '')");
        foreach ([3 => 'a', 2 => 'b'] as $ago => $k) {
            $ins->execute([$ago, "test-myphone-hide-$k", "test-myphone-hide-$k"]);
        }
        $uid = self::$user1Id;
        $keys = fn() => array_column(MyPhoneRepository::getRecentCalls('3002', null, null, 50, $uid), 'call_key');
        try {
            $this->assertContains('test-myphone-hide-a', $keys());

            $this->assertSame(1, MyPhoneRepository::hideCalls($uid, ['test-myphone-hide-a', "' ;", str_repeat('k', 80)]));
            $this->assertNotContains('test-myphone-hide-a', $keys());
            $this->assertContains('test-myphone-hide-b', $keys());
            // Another user (and the list without a user) still sees everything
            $this->assertContains('test-myphone-hide-a', array_column(MyPhoneRepository::getRecentCalls('3002', null, null, 50), 'call_key'));

            sleep(1);
            MyPhoneRepository::clearCallHistory($uid);
            $this->assertNotContains('test-myphone-hide-b', $keys());
            $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM asteriskcdr WHERE linkedid LIKE 'test-myphone-hide-%'")->fetchColumn());
        } finally {
            $db->exec("DELETE FROM sys_call_history_hidden WHERE user_id = $uid");
            $db->exec("UPDATE sys_users SET call_history_cleared_at = NULL WHERE id = $uid");
        }
    }
}
