<?php

use PHPUnit\Framework\TestCase;
use PBX\Destinations\DestinationRegistry;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

/** DestinationRegistry::usageCount: the one check of "is this destination still used?" before a delete. */
final class DestinationUsageTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    private function clean(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_ai_agents WHERE title LIKE 'zztest%'");
        $db->exec("DELETE FROM pbx_time_conditions WHERE title LIKE 'zztest%'");
        $db->exec("DELETE FROM pbx_time_groups WHERE title LIKE 'zztest%'");
    }

    public function testEveryDestinationColumnAndTimeConditionRulesCount(): void
    {
        $db = getDB();
        $this->assertSame(0, DestinationRegistry::usageCount('queue', ['zztestq', '990001']));

        // An external agent's hand-over (new table) counts: the queue cannot be deleted under it.
        $db->exec("INSERT INTO pbx_ai_agents (title, sip_uri, handover_dest_type, handover_dest_id, fallback_dest_type, fallback_dest_id)
            VALUES ('zztest agent', 'sip:a@h', 'queue', 'zztestq', 'queue', '990001')");
        $this->assertSame(2, DestinationRegistry::usageCount('queue', ['zztestq', '990001']), 'by name and by id');
        $this->assertSame(1, DestinationRegistry::usageCount('queue', 'zztestq'));

        $db->exec("INSERT INTO pbx_time_groups (title) VALUES ('zztest group')");
        $group = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO pbx_time_conditions (title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, rules_json)
            VALUES ('zztest tc', ?, 'hangup', 'hangup', 'hangup', 'hangup', '[{\"match_dest_type\":\"ivr\",\"match_dest_id\":\"990002\"}]')")->execute([$group]);
        $tc = (int) $db->lastInsertId();
        $this->assertSame(1, DestinationRegistry::usageCount('ivr', '990002'), 'a rule of a time condition');
        $this->assertSame(0, DestinationRegistry::usageCount('ivr', '99000'), 'not a prefix');
        $this->assertSame(0, DestinationRegistry::usageCount('ivr', '990002', ['pbx_time_conditions' => ['id', $tc]]), 'its own row left out');
        $this->assertSame(0, DestinationRegistry::usageCount('queue', 'zztestq', ['pbx_ai_agents' => null]), 'a table left out');
    }
}
