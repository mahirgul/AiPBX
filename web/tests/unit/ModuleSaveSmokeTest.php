<?php

use PHPUnit\Framework\TestCase;

/**
 * Every module's "Save" flow runs once against the real database.
 *
 * The conference / ring group / push setting / extension saves had broken
 * silently before (null into a NOT NULL column, a missing method, a missing
 * argument) and the smoke test, which checks page loads, could not see them.
 * Here every service is called with the typical data its form sends; success
 * is expected, then the created rows are deleted.
 */
final class ModuleSaveSmokeTest extends TestCase
{
    private const TAG = 'ZZT Kayit Testi';

    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'save-smoke-token';
        $_SESSION['user_id'] = null;
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_time_conditions WHERE title = '" . self::TAG . "'");
        $db->exec("DELETE FROM pbx_time_groups WHERE title = '" . self::TAG . "'");
        $db->exec("DELETE FROM pbx_ivrs WHERE title = '" . self::TAG . "'");
        $db->exec("DELETE FROM pbx_dids WHERE did_number = '908509990001'");
        $db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = '" . self::TAG . "'");
        $db->exec("DELETE FROM pbx_trunks WHERE trunk_name = 'zzt_trunk'");
        $db->exec("DELETE FROM pbx_queues WHERE queue_name = 'zzt_queue'");
        $db->exec("DELETE FROM pbx_boss_secretary_groups WHERE group_name = '" . self::TAG . "'");
        $db->exec("DELETE FROM pbx_permission_groups WHERE group_name = '" . self::TAG . "'");
        $db->exec("DELETE FROM pbx_hangup_actions WHERE action_key = 'zzt_hangup'");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_label LIKE '%ZZT%' OR entity_label LIKE '%zzt%'");
    }

    private function ok(array $res): void
    {
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? json_encode($res, JSON_UNESCAPED_UNICODE));
    }

    private function c(array $data): array
    {
        return $data + ['csrf_token' => 'save-smoke-token'];
    }

    public function testTrunkQueueAndOutboundRoute(): void
    {
        $this->ok(TrunkService::saveTrunk($this->c([
            'trunk_name' => 'zzt_trunk', 'title' => self::TAG, 'ip_address' => '192.0.2.10',
            'port' => '5060', 'transport' => 'udp', 'codecs' => 'alaw,ulaw', 'qualify_frequency' => '60',
            'connection_mode' => 'ip',
        ])));

        $this->ok(RouteService::saveOutboundRoute($this->c([
            'route_name' => self::TAG, 'match_pattern' => '_0XXXXXXXXXX', 'route_group' => '1',
            'trunk_name' => ['zzt_trunk'], 'trunk_cid' => [''],
        ])));

        $this->ok(QueueService::saveQueue($this->c([
            'queue_name' => 'zzt_queue', 'title' => self::TAG, 'strategy' => 'ringall',
            'timeout' => '15', 'retry' => '5', 'wrapuptime' => '0', 'maxlen' => '0',
            'max_wait_seconds' => '300', 'fallback_action' => 'hangup', 'musicclass' => 'default',
            'members' => [], 'static_members' => [], 'record_format' => 'wav',
        ])));
    }

    public function testIvrTimeGroupConditionAndDid(): void
    {
        $this->ok(IVRService::saveIVR($this->c([
            'title' => self::TAG, 'prompt_file' => 'custom/welcome', 'timeout_seconds' => '10',
            'timeout_dest_type' => 'hangup', 'invalid_dest_type' => 'hangup',
        ])));
        $ivrId = (int) DBHelper::fetchColumn('SELECT id FROM pbx_ivrs WHERE title = ?', [self::TAG]);
        $this->ok(IVRService::saveIVREntry($this->c(['ivr_id' => $ivrId, 'digit' => '1', 'dest_type' => 'hangup', 'dest_id' => ''])));

        $this->ok(TimeConditionService::saveTimeGroup($this->c([
            'title' => self::TAG, 'time_start' => '08:30', 'time_end' => '17:30', 'days' => ['1', '2', '3', '4', '5'],
        ])));
        $tgId = (int) DBHelper::fetchColumn('SELECT id FROM pbx_time_groups WHERE title = ?', [self::TAG]);

        $this->ok(TimeConditionService::saveTimeCondition($this->c([
            'title' => self::TAG,
            'rules' => [['time_group_id' => $tgId, 'match_dest_type' => 'ivr', 'match_dest_id' => (string) $ivrId]],
            'nomatch_dest_type' => 'hangup', 'nomatch_dest_id' => '',
        ])));
        $tcId = (int) DBHelper::fetchColumn('SELECT id FROM pbx_time_conditions WHERE title = ?', [self::TAG]);

        $this->ok(RouteService::saveDIDRoute($this->c([
            'did_number' => '908509990001', 'title' => self::TAG, 'dest_type' => 'time_condition', 'dest_id' => (string) $tcId,
        ])));
    }

    public function testBossSecretaryDialPermissionAndHangupAction(): void
    {
        $this->ok(BossSecretaryService::saveGroup($this->c([
            'group_number' => '97', 'group_name' => self::TAG, 'boss_extension' => '9701',
            'secretaries' => ['9702'], 'ring_strategy' => 'ringall', 'ring_timeout' => '20',
            'fallback_dest_type' => 'hangup', 'fallback_dest_id' => 'busy',
        ])));

        $this->ok(DialPermissionService::saveGroup($this->c(['group_name' => self::TAG, 'default_action' => 'allow'])));
        $gid = (int) DBHelper::fetchColumn('SELECT id FROM pbx_permission_groups WHERE group_name = ?', [self::TAG]);
        $this->ok(DialPermissionService::saveRule($this->c([
            'group_id' => $gid, 'pattern' => '00', 'pattern_type' => 'prefix', 'action' => 'deny', 'priority' => '10',
        ])));

        $this->ok(HangupActionService::saveHangupAction($this->c([
            'action_key' => 'zzt_hangup', 'title' => self::TAG, 'action_type' => 'hangup',
        ])));
    }
}
