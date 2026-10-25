<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class SyncTimeConditionsCoverageTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $createdTcIds = [];
    /** @var list<int> */
    private array $createdTgIds = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->createdTcIds = [];
        $this->createdTgIds = [];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdTcIds)) {
            $ids = implode(',', array_map('intval', $this->createdTcIds));
            $this->db->exec("DELETE FROM pbx_time_conditions WHERE id IN ({$ids})");
            foreach ($this->createdTcIds as $id) {
                @unlink(ASTERISK_PBX_DIR . "/extensions_timecondition_{$id}.conf");
            }
        }
        if (!empty($this->createdTgIds)) {
            $ids = implode(',', array_map('intval', $this->createdTgIds));
            $this->db->exec("DELETE FROM pbx_time_groups WHERE id IN ({$ids})");
        }

        $this->db->exec("DELETE FROM pbx_time_conditions WHERE title LIKE 'zz_cov_%' OR id BETWEEN 7911 AND 7919");
        $this->db->exec("DELETE FROM pbx_time_groups WHERE title LIKE 'zz_cov_%' OR id BETWEEN 7911 AND 7919");

        foreach (glob(ASTERISK_PBX_DIR . '/extensions_timecondition_791*.conf') as $f) {
            @unlink($f);
        }
    }

    public function testSingleRuleTimeConditionDialplanGeneration(): void
    {
        $tgId = 7911;
        $tcId = 7911;
        $this->createdTgIds[] = $tgId;
        $this->createdTcIds[] = $tcId;

        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tgId}, 'zz_cov_tg_7911', '09:00:00', '18:00:00', '1,2,3,4,5', 1)"
        );

        $this->db->exec(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES ({$tcId}, 'zz_cov_tc_7911', {$tgId}, 'hangup', '', 'hangup', '', 1)"
        );

        $this->assertTrue(syncTimeCondition($tcId));

        $filePath = ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf";
        $this->assertFileExists($filePath);
        $conf = (string)file_get_contents($filePath);

        $this->assertStringContainsString("[app-timecondition-{$tcId}]", $conf);
        $this->assertStringContainsString("exten => s,1,NoOp(Evaluating Native Time Condition ID {$tcId}: zz_cov_tc_7911)", $conf);
        $this->assertStringContainsString('GotoIfTime(09:00-18:00,mon-fri,*,*?tc_rule_0)', $conf);
        $this->assertStringContainsString('Goto(tc_nomatch)', $conf);
        $this->assertStringContainsString("same => n(tc_rule_0),NoOp(Time Condition {$tcId} Rule 1 Matched -> hangup)", $conf);
        $this->assertStringContainsString("same => n(tc_nomatch),NoOp(Time Condition {$tcId} Not Matched -> hangup)", $conf);
        $this->assertStringContainsString('same => n,Hangup()', $conf);
    }

    public function testMultipleRulesJsonOrderAndEvaluation(): void
    {
        $tg1 = 7912;
        $tg2 = 7913;
        $tcId = 7912;
        $this->createdTgIds[] = $tg1;
        $this->createdTgIds[] = $tg2;
        $this->createdTcIds[] = $tcId;

        // TG 1: Mon, Wed, Fri morning
        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tg1}, 'zz_cov_tg_7912', '08:00:00', '12:00:00', '1,3,5', 1)"
        );
        // TG 2: Tue, Thu afternoon
        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tg2}, 'zz_cov_tg_7913', '13:00:00', '17:00:00', '2,4', 1)"
        );

        $rulesJson = json_encode([
            ['time_group_id' => $tg1, 'match_dest_type' => 'hangup', 'match_dest_id' => ''],
            ['time_group_id' => $tg2, 'match_dest_type' => 'hangup', 'match_dest_id' => ''],
        ]);

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, rules_json, is_active)
             VALUES (?, 'zz_cov_tc_7912', ?, 'hangup', '', 'hangup', '', ?, 1)"
        );
        $stmt->execute([$tcId, $tg1, $rulesJson]);

        syncTimeCondition($tcId);

        $filePath = ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf";
        $this->assertFileExists($filePath);
        $conf = (string)file_get_contents($filePath);

        $this->assertStringContainsString('GotoIfTime(08:00-12:00,mon&wed&fri,*,*?tc_rule_0)', $conf);
        $this->assertStringContainsString('GotoIfTime(13:00-17:00,tue&thu,*,*?tc_rule_1)', $conf);
        $this->assertStringContainsString("same => n(tc_rule_0),NoOp(Time Condition {$tcId} Rule 1 Matched -> hangup)", $conf);
        $this->assertStringContainsString("same => n(tc_rule_1),NoOp(Time Condition {$tcId} Rule 2 Matched -> hangup)", $conf);
    }

    public function testMidnightCrossingTimeRange(): void
    {
        $tgId = 7914;
        $tcId = 7914;
        $this->createdTgIds[] = $tgId;
        $this->createdTcIds[] = $tcId;

        // Night shift 22:00 to 06:00, all days (1,2,3,4,5,6,7 -> '*')
        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tgId}, 'zz_cov_tg_7914', '22:00:00', '06:00:00', '1,2,3,4,5,6,7', 1)"
        );

        $this->db->exec(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES ({$tcId}, 'zz_cov_tc_7914', {$tgId}, 'hangup', '', 'hangup', '', 1)"
        );

        syncTimeCondition($tcId);

        $filePath = ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf";
        $this->assertFileExists($filePath);
        $conf = (string)file_get_contents($filePath);

        // Crossing midnight produces 22:00-06:00
        $this->assertStringContainsString('GotoIfTime(22:00-06:00,', $conf);
    }

    public function testHolidaysCheckEvaluationBeforeWorkingHours(): void
    {
        $tgId = 7915;
        $tcId = 7915;
        $this->createdTgIds[] = $tgId;
        $this->createdTcIds[] = $tcId;

        $holidaysJson = json_encode(['2026-01-01', '2026-10-29', 'invalid_date_format', '2026/12/25']);

        $stmtTg = $this->db->prepare(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, holidays_json, is_active)
             VALUES (?, 'zz_cov_tg_7915', '09:00:00', '18:00:00', '1,2,3,4,5', ?, 1)"
        );
        $stmtTg->execute([$tgId, $holidaysJson]);

        $this->db->exec(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES ({$tcId}, 'zz_cov_tc_7915', {$tgId}, 'hangup', '', 'hangup', '', 1)"
        );

        syncTimeCondition($tcId);

        $filePath = ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf";
        $this->assertFileExists($filePath);
        $conf = (string)file_get_contents($filePath);

        // Date extraction
        $this->assertStringContainsString('Set(BUGUN=${STRFTIME(${EPOCH},,%Y-%m-%d)})', $conf);
        $this->assertStringContainsString('GotoIf($["${BUGUN}" = "2026-01-01"]?tc_nomatch)', $conf);
        $this->assertStringContainsString('GotoIf($["${BUGUN}" = "2026-10-29"]?tc_nomatch)', $conf);

        // Invalid formats must NOT appear in dialplan
        $this->assertStringNotContainsString('invalid_date_format', $conf);
        $this->assertStringNotContainsString('2026/12/25', $conf);

        // Holiday check must come BEFORE the GotoIfTime rule
        $holidayPos = strpos($conf, 'GotoIf($["${BUGUN}"');
        $rulePos = strpos($conf, 'GotoIfTime(');
        $this->assertNotFalse($holidayPos);
        $this->assertNotFalse($rulePos);
        $this->assertLessThan($rulePos, $holidayPos, 'Holiday checks must precede GotoIfTime working hours checks');
    }

    public function testInactiveTimeConditionDeletesConfFile(): void
    {
        $tgId = 7916;
        $tcId = 7916;
        $this->createdTgIds[] = $tgId;
        $this->createdTcIds[] = $tcId;

        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tgId}, 'zz_cov_tg_7916', '09:00:00', '18:00:00', '1,2,3,4,5', 1)"
        );

        $this->db->exec(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES ({$tcId}, 'zz_cov_tc_7916', {$tgId}, 'hangup', '', 'hangup', '', 1)"
        );

        syncTimeCondition($tcId);
        $filePath = ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf";
        $this->assertFileExists($filePath);

        // Deactivate
        $this->db->exec("UPDATE pbx_time_conditions SET is_active = 0 WHERE id = {$tcId}");
        syncTimeCondition($tcId);

        $this->assertFileDoesNotExist($filePath);
    }

    public function testSyncAllTimeConditionsAndOrphanCleanup(): void
    {
        $tgId = 7917;
        $tcId = 7917;
        $this->createdTgIds[] = $tgId;
        $this->createdTcIds[] = $tcId;

        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tgId}, 'zz_cov_tg_7917', '09:00:00', '18:00:00', '1,2,3,4,5', 1)"
        );

        $this->db->exec(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES ({$tcId}, 'zz_cov_tc_7917', {$tgId}, 'hangup', '', 'hangup', '', 1)"
        );

        // Create an orphan file on disk that does not match any active DB condition
        $orphanFile = ASTERISK_PBX_DIR . '/extensions_timecondition_7918.conf';
        file_put_contents($orphanFile, "; orphan file\n");
        $this->assertFileExists($orphanFile);

        syncAllTimeConditions();

        // Active condition file should exist
        $this->assertFileExists(ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf");
        // Orphan file must be cleaned up
        $this->assertFileDoesNotExist($orphanFile);
    }

    public function testDialplanInjectionResistanceInTitleAndDestinations(): void
    {
        $tgId = 7919;
        $tcId = 7919;
        $this->createdTgIds[] = $tgId;
        $this->createdTcIds[] = $tcId;

        $this->db->exec(
            "INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
             VALUES ({$tgId}, 'zz_cov_tg_7919', '09:00:00', '18:00:00', '1,2,3,4,5', 1)"
        );

        $maliciousTitle = "Inject\n same => n,System(touch /tmp/pwned)\n; [evil_context]\nexten => s,1,Hangup()";

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
             VALUES (?, ?, ?, 'hangup', '', 'hangup', '', 1)"
        );
        $stmt->execute([$tcId, $maliciousTitle, $tgId]);

        syncTimeCondition($tcId);

        $filePath = ASTERISK_PBX_DIR . "/extensions_timecondition_{$tcId}.conf";
        $this->assertFileExists($filePath);
        $conf = (string)file_get_contents($filePath);

        // System( and evil_context must NOT be injected as standalone dialplan commands or sections
        $this->assertDoesNotMatchRegularExpression('/^\s*same => n,System/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\[evil_context\]/m', $conf);
    }
}
