<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/QueueService.php';

final class QueueImprovementsTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->beginTransaction();
        getDB()->exec("INSERT INTO pbx_announcements (title, audio_file, is_active) VALUES ('Q1', 'custom/zz_q1', 1), ('Q2', 'custom/zz_q2', 1), ('Rate', 'custom/zz_rate', 1)");
    }

    protected function tearDown(): void
    {
        getDB()->rollBack();
    }

    public function testFormValues(): void
    {
        $this->assertSame([], QueueService::improvementColumns(['title' => 'x'], []), 'the API leaves them as they are');
        $c = QueueService::improvementColumns([
            'periodic_random' => '1',
            'member_level' => ['1001' => '3', '1002' => '0', '1003' => '12', '9999' => '2'],
            'survey_enabled' => '1', 'survey_announce' => 'custom/zz_rate',
        ], ['1001', '1002', '1003']);
        $this->assertSame(1, $c['periodic_random']);
        $this->assertSame('{"1001":3,"1003":9}', $c['member_levels_json'], 'level 0 is the default; only members; at most 9');
        $this->assertSame(1, $c['survey_enabled']);
        $this->assertSame('custom/zz_rate', $c['survey_announce']);

        $this->expectException(Exception::class);
        QueueService::improvementColumns(['survey_enabled' => '1', 'survey_announce' => 'custom/missing'], []);
    }

    public function testAsteriskSettingsAndDialplan(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO pbx_queues (queue_name, title, is_active, members_json, static_members_json, periodic_announce, periodic_random, member_levels_json, survey_enabled, survey_announce)
                   VALUES ('zzq', 'ZZ', 1, '[\"7961\",\"7962\"]', '[\"7961\"]', 'custom/zz_q1,custom/zz_q2', 1, '{\"7961\":2}', 1, 'custom/zz_rate')");
        $q = $db->query("SELECT * FROM pbx_queues WHERE queue_name = 'zzq'")->fetch(PDO::FETCH_ASSOC);
        QueueHelper::syncQueueToDetails($q);
        $map = QueueHelper::getDetailsMap('zzq');
        $this->assertSame('custom/zz_q1,custom/zz_q2', $map['periodic-announce']);
        $this->assertSame('yes', $map['random-periodic-announce']);
        $this->assertSame(2, QueueHelper::memberLevel('7961', 'zzq'));
        $this->assertSame(0, QueueHelper::memberLevel('7962', 'zzq'));

        $d = buildDestinationLines('queue', 'zzq');
        $this->assertStringContainsString('Queue(zzq,tTc,', $d);
        $this->assertStringContainsString('Gosub(sub-queue-survey,s,1(zzq,custom/zz_rate))', $d);
        // Not answered: the fallback, not the survey.
        $this->assertMatchesRegularExpression('/GotoIf\(\$\["\$\{MEMBERINTERFACE\}" = ""\]\?qsurvey_no_\d+\)/', $d);

        $db->exec("UPDATE pbx_queues SET survey_enabled = 0 WHERE queue_name = 'zzq'");
        $this->assertStringContainsString('Queue(zzq,tT,', buildDestinationLines('queue', 'zzq'));

        syncGeneralDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $this->assertStringContainsString('[sub-queue-survey]', $conf);
        $this->assertStringContainsString('/api/queue_survey.php?q=${ARG1}', $conf);
    }

    public function testStaticMembersCarryTheirLevel(): void
    {
        getDB()->exec("INSERT INTO pbx_queues (queue_name, title, is_active, members_json, static_members_json, member_levels_json)
                       VALUES ('zzq2', 'ZZ2', 1, '[\"7963\"]', '[\"7963\"]', '{\"7963\":4}')");
        try {
            syncAllQueues();
        } catch (\Throwable $e) {
            // Asterisk is not running in the test environment; the file is written first.
        }
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/queues_pbx.conf');
        $this->assertStringContainsString('member => Local/7963@from-internal-pbx/n,4,Agent 7963', $conf);
    }

    public function testSurveyStats(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO queue_survey_results (queue_name, agent, caller, score, uniqueid) VALUES
                   ('zzq', '7961', '0532', 5, 'zz.1'), ('zzq', '7961', '0533', 3, 'zz.2'), ('zzq', '7962', '0534', 4, 'zz.3')");
        $this->assertSame(['avg' => 4.0, 'count' => 3], QueueService::surveyStats(30)['zzq']);
        $this->assertSame(['avg' => 4.0, 'count' => 2], QueueService::surveyStats(30, true)['7961']);
    }
}
