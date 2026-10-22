<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * The `call-quality` package, the way a catalog install runs it.
 *
 * The class under test lives in the plugin directory and is loaded by path
 * here, exactly as the loader's `php.files` map would load it in production.
 * Nothing is copied into `web/`.
 *
 * What these tests pin:
 *  - capture state is read from Asterisk's `hep.conf`, and "cannot see local
 *    RTCP" is never reported as "the lines are healthy";
 *  - the one automatic change is off unless an administrator turns it on,
 *    happens once per six hours, and leaves an audit row;
 *  - every decision leaves evidence, and every person is told through the
 *    portal's own notice cabinet, not a private channel.
 */
final class CallQualityPluginTest extends TestCase
{
    private PDO $db;
    private string $confDir;

    private const SERVICE = __DIR__ . '/../../../plugins/call-quality/web/src/services/CallQualityService.php';

    protected function setUp(): void
    {
        $this->db = getDB();
        require_once dirname(__DIR__, 3) . '/plugins/call-quality/web/src/services/CaptureState.php';
        require_once self::SERVICE;

        $this->confDir = sys_get_temp_dir() . '/aipbx-cq-' . bin2hex(random_bytes(4));
        mkdir($this->confDir, 0700, true);
        putenv('AIPBX_HEP_CONF=' . $this->confDir . '/hep.conf');
        putenv('AIPBX_SIGNALING_LISTEN');
        $this->hep("enabled = yes\ncapture_address = 127.0.0.1:9061\n");

        $this->createEvidenceTable();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        putenv('AIPBX_HEP_CONF');
        foreach ((array) glob($this->confDir . '/{,*/}*', GLOB_BRACE) as $file) {
            is_dir((string) $file) ? @rmdir((string) $file) : @unlink((string) $file);
        }
        @rmdir($this->confDir);
    }

    /** The package's own table is the loader's job in production; here it is ours. */
    private function createEvidenceTable(): void
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 3) . '/plugins/call-quality/schema.sql'
        );
        $this->db->exec($sql);
    }

    private function cleanup(): void
    {
        $this->db->exec('DELETE FROM pbx_signaling_events');
        $this->db->exec('DELETE FROM plg_call_quality_evidence');
        // pbx_notices arrives with #119. On a portal without it the notice cases
        // cannot run, and that should be the reason they fail — not a "table not
        // found" thrown from setUp before any assertion is reached.
        if ($this->tableExists('pbx_notices')) {
            $this->db->exec('DELETE FROM pbx_notices');
        }
        $this->db->exec("DELETE FROM sys_audit_log WHERE domain = 'call_quality'");
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key IN
            ('notice_email', 'notice_email_to', 'notice_quiet_hours', 'notice_window_hours')");
        $this->db->exec("DELETE FROM pbx_trunks WHERE trunk_name IN ('qc_lossy', 'qc_good')");
        $this->db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = 'QC plugin route'");
        $this->db->exec("DELETE FROM sys_pending_sync WHERE domain = 'outbound_dialplan'");
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private function hep(string $general, string $name = 'hep.conf'): void
    {
        file_put_contents($this->confDir . '/' . $name, "[general]\n" . $general);
    }

    private function setting(string $key, string $value): void
    {
        $this->db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
    }

    private function report(string $callId, int $fraction, int $jitter = 0,
                            string $src = '203.0.113.9:10000',
                            string $dst = '192.0.2.10:10000'): void
    {
        $this->db->prepare(
            "INSERT INTO pbx_signaling_events
                (ts, call_id, method, proto, src, dst, first_line, line,
                 rtcp_fraction_lost, rtcp_lost, rtcp_jitter)
             VALUES (NOW() - INTERVAL 1 MINUTE, ?, 'RTCP', 'rtcp', ?, ?, 'RTCP', 'RTCP', ?, ?, ?)"
        )->execute([$callId, $src, $dst, $fraction, $fraction, $jitter]);
    }

    /** Two trunks in one route: the lossy one first, a good one behind it. */
    private function routeWithTwoTrunks(): void
    {
        $this->db->prepare('INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)')
            ->execute(['qc_lossy', 'QC lossy carrier', '203.0.113.77']);
        $this->db->prepare('INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)')
            ->execute(['qc_good', 'QC good carrier', '203.0.113.78']);
        $this->db->prepare(
            "INSERT INTO pbx_outbound_routes (route_name, match_pattern, trunks_json, is_active)
             VALUES ('QC plugin route', '9.', ?, 1)"
        )->execute([json_encode([
            ['trunk_name' => 'qc_lossy'],
            ['trunk_name' => 'qc_good'],
        ])]);
        $this->report('call-qc', 26, 96, '203.0.113.77:10000');
        $this->report('call-qc', 26, 96, '203.0.113.77:10000');
    }

    /** @return array<int, string> */
    private function routeOrder(): array
    {
        $stored = $this->db->query(
            "SELECT trunks_json FROM pbx_outbound_routes WHERE route_name = 'QC plugin route'"
        )->fetchColumn();
        return array_column((array) json_decode((string) $stored, true), 'trunk_name');
    }

    // ------------------------------------------------------- capture state

    public function testCaptureIsLocalOnlyWhenAsteriskSendsToThisDaemon(): void
    {
        $state = CaptureState::snapshot();
        $this->assertTrue($state['local']);
        $this->assertSame('builtin', $state['mode']);
        $this->assertStringContainsString('stored locally', $state['detail']);

        $this->hep("enabled = yes\ncapture_address = 10.0.0.5:9060\n");
        $state = CaptureState::snapshot();
        $this->assertFalse($state['local'], 'a remote collector keeps the reports there, not here');
        $this->assertSame('external', $state['mode']);
        $this->assertStringContainsString('not here', $state['detail']);

        $this->hep("enabled = no\ncapture_address = 127.0.0.1:9061\n");
        $state = CaptureState::snapshot();
        $this->assertFalse($state['local']);
        $this->assertSame('off', $state['mode']);
    }

    public function testACollectorOnLoopbackAtAnotherPortIsNotOurDaemon(): void
    {
        $this->hep("enabled = yes\ncapture_address = 127.0.0.1:9060\n");
        $state = CaptureState::snapshot();
        $this->assertFalse($state['local'], 'port 9060 is the collector, not aipbx-signaling');
        $this->assertSame('external', $state['mode']);
    }

    public function testMissingConfigurationIsUnknownNotHealthy(): void
    {
        unlink($this->confDir . '/hep.conf');
        $state = CaptureState::snapshot();
        $this->assertSame('unknown', $state['mode']);
        $this->assertFalse($state['readable']);
        $this->assertFalse($state['local']);
        $this->assertStringContainsString('unknown', $state['detail']);
    }

    public function testAnUnreadableEnabledValueIsUnknownAndSaysSo(): void
    {
        $this->hep("enabled = truee\ncapture_address = 127.0.0.1:9061\n");
        $state = CaptureState::snapshot();
        $this->assertSame('unknown', $state['mode']);
        $this->assertFalse($state['local'], 'a typo must never be read as on');
        $this->assertStringNotContainsString('switched on', $state['detail']);
        $this->assertStringContainsString('not yes/no', $state['detail']);
    }

    public function testAnIncludedFileCanMoveTheCaptureAddress(): void
    {
        // Asterisk ships that directive commented out; an administrator who
        // enables it expects the included values to win, as Asterisk does.
        mkdir($this->confDir . '/pbx', 0700, true);
        file_put_contents(
            $this->confDir . '/pbx/hep_local.conf',
            "[general]\nenabled = yes\ncapture_address = 10.0.0.9:9060\n"
        );
        file_put_contents(
            $this->confDir . '/hep.conf',
            "[general]\nenabled = yes\ncapture_address = 127.0.0.1:9061\n#tryinclude \"pbx/hep_*.conf\"\n"
        );

        $state = CaptureState::snapshot();
        $this->assertSame('10.0.0.9:9060', $state['address'], 'the included file wins, as it does in Asterisk');
        $this->assertFalse($state['local']);
        $this->assertNotEmpty($state['includes']);
    }

    // ------------------------------------------------------------ scoring

    public function testVerdictNeedsTwoBadReportsToBePoor(): void
    {
        $one = CallQualityService::verdict(['worst_fraction' => 26, 'reports' => 1, 'poor_reports' => 1]);
        $this->assertSame('fair', $one['level'], 'a single spike is not sustained loss');
        $this->assertSame(10.16, $one['loss_pct'], 'fraction 26 is ~10 % (26/256)');

        $two = CallQualityService::verdict(['worst_fraction' => 26, 'reports' => 4, 'poor_reports' => 2]);
        $this->assertSame('poor', $two['level']);

        $noise = CallQualityService::verdict(['worst_fraction' => 2, 'reports' => 6, 'poor_reports' => 0]);
        $this->assertSame('good', $noise['level'], '0.78 % is background noise');

        $fair = CallQualityService::verdict(['worst_fraction' => 3, 'reports' => 6, 'poor_reports' => 0]);
        $this->assertSame('fair', $fair['level'], 'above 1 % is degraded');
    }

    public function testEvidenceKeepsTheRtcpProofAndOneRowPerIncident(): void
    {
        $this->report('call-ev', 26, 96);
        $this->report('call-ev', 26, 240);
        $call = CallQualityService::active()[0];

        $id = CallQualityService::recordEvidence($call, 'no_alternative', 'Nothing to fail over to.');
        $this->assertNotNull($id);
        $this->assertSame($id, CallQualityService::recordEvidence($call, 'no_alternative', ''),
            'the same call and first report is one incident, not one per cron run');

        $trail = CallQualityService::evidenceTrail((int) $id);
        $this->assertStringContainsString('Evidence #' . $id, $trail);
        $this->assertStringContainsString('call-ev', $trail);
        $this->assertStringContainsString('fraction_lost=26', $trail);
        $this->assertStringContainsString('res_hep_rtcp -> aipbx-signaling -> pbx_signaling_events', $trail);
        $this->assertStringContainsString('two reports at 5%', $trail);
    }

    // ------------------------------------------------------------- the tick

    public function testNothingIsScoredWhenCaptureGoesElsewhere(): void
    {
        $this->hep("enabled = yes\ncapture_address = 10.0.0.5:9060\n");
        $this->routeWithTwoTrunks();

        $res = CallQualityService::tick(['auto_fix' => '1']);
        $this->assertContains('capture_not_local', $res['skipped']);
        $this->assertSame('external', $res['capture']);
        $this->assertSame(0, $res['notices'], 'no RTCP rows is not a clean bill of health');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM pbx_notices')->fetchColumn());
        $this->assertSame(['qc_lossy', 'qc_good'], $this->routeOrder());
    }

    public function testObserveOnlyKeepsTheProofAndLeavesTheRouteAlone(): void
    {
        $this->routeWithTwoTrunks();

        $res = CallQualityService::tick(['auto_fix' => '0']);
        $this->assertArrayNotHasKey('error', $res);
        $this->assertContains('auto_fix_off', $res['skipped']);
        $this->assertSame([], $res['fixed']);
        $this->assertSame(['qc_lossy', 'qc_good'], $this->routeOrder(), 'observe-only must not reorder the route');

        $evidence = $this->db->query(
            'SELECT id, action, evidence_json FROM plg_call_quality_evidence ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('observed', $evidence['action']);
        $this->assertNotEmpty(json_decode((string) $evidence['evidence_json'], true)['samples']);

        $notice = $this->db->query('SELECT severity, title, body, source FROM pbx_notices ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('warn', $notice['severity']);
        $this->assertSame('call-quality', $notice['source']);
        $this->assertStringContainsString('qc_lossy', (string) $notice['title']);
        $this->assertStringContainsString('Evidence #' . $evidence['id'], (string) $notice['body']);

        // One problem, one notice: the next pass inside the dedupe window adds
        // nothing, and the trunk is not demoted either.
        $again = CallQualityService::tick(['auto_fix' => '0']);
        $this->assertSame(0, $again['notices']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM pbx_notices')->fetchColumn());
    }

    public function testDemotionMovesTheTrunkOnceIsAuditedAndIsRemembered(): void
    {
        $this->routeWithTwoTrunks();

        $res = CallQualityService::tick(['auto_fix' => '1']);
        $this->assertArrayNotHasKey('error', $res);
        $this->assertContains('qc_lossy', $res['fixed'], 'the lossy trunk is demoted');
        $this->assertSame(['qc_good', 'qc_lossy'], $this->routeOrder(), 'new calls try the good trunk first');
        $this->assertSame('0', (string) $this->db->query(
            "SELECT COUNT(*) FROM sys_pending_sync WHERE domain = 'outbound_dialplan'"
        )->fetchColumn(), 'the applied domain leaves no pending row behind');

        $audit = $this->db->query(
            "SELECT entity_type, entity_id, entity_label, action FROM sys_audit_log
              WHERE domain = 'call_quality' ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('trunk_failover', $audit['entity_type']);
        $this->assertSame('qc_lossy', $audit['entity_id']);
        $this->assertStringContainsString('undo: Outbound routes', (string) $audit['entity_label']);
        $this->assertSame('update', $audit['action']);

        $evidence = $this->db->query(
            'SELECT action, action_detail FROM plg_call_quality_evidence ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('trunk_demoted', $evidence['action']);
        $this->assertStringContainsString('qc_lossy', (string) $evidence['action_detail']);

        $notice = $this->db->query(
            "SELECT body FROM pbx_notices WHERE dedupe_key = 'quality:fix:qc_lossy'"
        )->fetchColumn();
        $this->assertNotFalse($notice);
        $this->assertStringContainsString('QC plugin route', (string) $notice);

        // Six-hour cooldown, read from the evidence itself: a second pass does
        // not reorder the route again.
        $this->report('call-qc2', 26, 96, '203.0.113.77:10000');
        $this->report('call-qc2', 26, 96, '203.0.113.77:10000');
        $again = CallQualityService::tick(['auto_fix' => '1']);
        $this->assertSame([], $again['fixed']);
        $this->assertSame(['qc_good', 'qc_lossy'], $this->routeOrder());
        $this->assertSame(
            'cooldown',
            $this->db->query("SELECT action FROM plg_call_quality_evidence WHERE call_id = 'call-qc2' LIMIT 1")->fetchColumn()
        );
    }

    // ---------------------------------------------------------- entry points

    public function testTheCronEntryPrintsItsDecisionAndReturnsZero(): void
    {
        $this->routeWithTwoTrunks();
        ob_start();
        $status = CallQualityService::run(['auto_fix' => '0']);
        $printed = (string) ob_get_clean();

        $this->assertSame(0, $status);
        $decision = json_decode(trim($printed), true);
        $this->assertIsArray($decision, 'the dispatcher records this line as the run output');
        $this->assertSame(['auto_fix_off'], $decision['skipped']);
        $this->assertSame('builtin', $decision['capture']);
    }

    public function testTheCliExplainsCaptureAndPrintsOneIncident(): void
    {
        ob_start();
        $status = CallQualityService::cliCapture();
        $printed = (string) ob_get_clean();
        $this->assertSame(0, $status);
        $this->assertStringContainsString('mode: builtin', $printed);
        $this->assertStringContainsString('local: yes', $printed);

        $this->report('call-cli', 26, 96);
        $this->report('call-cli', 26, 96);
        $id = (int) CallQualityService::recordEvidence(CallQualityService::active()[0], 'local_peer', 'Not a trunk.');

        ob_start();
        $status = CallQualityService::cliEvidence([], 'call-quality', [(string) $id]);
        $trail = (string) ob_get_clean();
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Evidence #' . $id, $trail);

        ob_start();
        $status = CallQualityService::cliEvidence([], 'call-quality', ['999999']);
        ob_end_clean();
        $this->assertSame(1, $status, 'an unknown evidence id is a failure, not silence');
    }

    public function testEvidenceDaysIsClampedToTheSchemaBoundary(): void
    {
        $this->assertSame(180, CallQualityService::evidenceDays([]));
        $this->assertSame(7, CallQualityService::evidenceDays(['evidence_days' => '1']));
        $this->assertSame(3650, CallQualityService::evidenceDays(['evidence_days' => '99999']));
        $this->assertSame(30, CallQualityService::evidenceDays(['evidence_days' => '30']));
    }
}
