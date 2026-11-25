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
        $db->exec("DELETE FROM pbx_time_conditions WHERE title LIKE 'zztest%'");
        $db->exec("DELETE FROM pbx_time_groups WHERE title LIKE 'zztest%'");
    }

    public function testEveryDestinationColumnAndTimeConditionRulesCount(): void
    {
        $db = getDB();
        $this->assertSame(0, DestinationRegistry::usageCount('queue', ['zztestq', '990001']));

        $db->exec("INSERT INTO pbx_time_groups (title) VALUES ('zztest group')");
        $group = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO pbx_time_conditions (title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, rules_json)
            VALUES ('zztest tc', ?, 'hangup', 'hangup', 'hangup', 'hangup', '[{\"match_dest_type\":\"ivr\",\"match_dest_id\":\"990002\"}]')")->execute([$group]);
        $tc = (int) $db->lastInsertId();
        $this->assertSame(1, DestinationRegistry::usageCount('ivr', '990002'), 'a rule of a time condition');
        $this->assertSame(0, DestinationRegistry::usageCount('ivr', '99000'), 'not a prefix');
        $this->assertSame(0, DestinationRegistry::usageCount('ivr', '990002', ['pbx_time_conditions' => ['id', $tc]]), 'its own row left out');
    }
}
