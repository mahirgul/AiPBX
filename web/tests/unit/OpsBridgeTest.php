<?php

use PHPUnit\Framework\TestCase;

/**
 * bin/aipbx_ops.php — the sealed op executor a cloud node command is allowed
 * to reach.
 *
 * These run the real script as a subprocess with the bootstrap's isolated
 * environment (test database, temp config dir, AIPBX_NO_ASTERISK lock), so
 * what is tested is exactly the interface the connector agent calls: argv in,
 * one JSON object out, exit code as the verdict.
 *
 * The assertions that matter most are the REFUSALS: a cloud command must never
 * come back "ok" for work this node did not do, and an op or module outside
 * the allowlist must never reach Asterisk at all.
 */
final class OpsBridgeTest extends TestCase
{
    private function opsScript(): string
    {
        return dirname(__DIR__, 2) . '/bin/aipbx_ops.php';
    }

    /** Runs the bridge the way the agent does: argv array, real child process. */
    private function runOps(string $op, array $args = []): array
    {
        $argv = ['php', $this->opsScript(), $op, json_encode($args)];
        $proc = proc_open(
            $argv,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv() // the bootstrap's putenv() environment, passed explicitly
        );
        $this->assertIsResource($proc, 'could not start the ops bridge');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'json' => json_decode((string) $stdout, true),
        ];
    }

    /** The op vocabulary is shared with the cloud — an unknown word is refused, not guessed. */
    public function testOpOutsideTheAllowlistIsRefused(): void
    {
        foreach (['shell', 'exec', 'snapshot; id', '../../etc/passwd'] as $op) {
            $res = $this->runOps($op);
            $this->assertSame(64, $res['code'], "op '{$op}' must be refused");
            $this->assertSame('unknown_op', $res['json']['error'] ?? null);
            $this->assertSame(
                ['snapshot', 'diagnose', 'reload', 'module_reload'],
                $res['json']['allowed'] ?? null
            );
        }
    }

    /** An empty module is a client error, not a silent success. */
    public function testModuleReloadWithoutAModuleIsRefused(): void
    {
        $res = $this->runOps('module_reload');
        $this->assertSame(64, $res['code']);
        $this->assertSame('module_required', $res['json']['error'] ?? null);
        $this->assertSame(['core', 'pjsip'], $res['json']['supported'] ?? null);
    }

    /**
     * The cloud's allowlist is FreePBX-shaped. Those names must come back as an
     * explicit refusal with a reason — never translated into a different action
     * than the operator asked for, and never reported as success.
     */
    public function testFreePbxOnlyModulesAreRefusedWithAReason(): void
    {
        $freePbxOnly = ['sipsettings', 'ivr', 'timeconditions', 'ringgroups', 'queues', 'miscapps', 'customappctx', 'confirm', 'findmefollow'];
        foreach ($freePbxOnly as $module) {
            $res = $this->runOps('module_reload', ['module' => $module]);
            $this->assertSame(64, $res['code'], "module '{$module}' must be refused");
            $this->assertSame('unsupported_module', $res['json']['error'] ?? null);
            $this->assertStringContainsString('FreePBX', $res['json']['reason'] ?? '');
            $this->assertArrayNotHasKey('success', $res['json']);
        }
    }

    /** A module AiPBX really has is executed through AiPBX's own helper. */
    public function testSupportedModuleUsesAiPbxHelper(): void
    {
        $res = $this->runOps('module_reload', ['module' => 'pjsip']);
        $this->assertSame(0, $res['code'], $res['stdout'] . $res['stderr']);
        $this->assertSame('pjsip reload', $res['json']['command'] ?? null);
        $this->assertTrue($res['json']['success'] ?? false);
    }

    /** Snapshot reports what this box is, in AiPBX's own words. */
    public function testSnapshotReportsPlatformAndVersion(): void
    {
        $res = $this->runOps('snapshot');
        $this->assertSame(0, $res['code'], $res['stdout'] . $res['stderr']);
        $this->assertSame('aipbx', $res['json']['platform'] ?? null);
        $this->assertSame(
            trim((string) file_get_contents(dirname(__DIR__, 3) . '/VERSION')),
            $res['json']['aipbx_version'] ?? null
        );
        $this->assertIsArray($res['json']['disk'] ?? null);
        $this->assertIsInt($res['json']['pending_sync'] ?? null);
        $this->assertArrayNotHasKey('freepbx_version', $res['json']);
    }

    /** Nothing pending ⇒ a clean, no-op reload that says so. */
    public function testReloadWithNothingPendingSucceeds(): void
    {
        $this->clearPendingSync();
        $res = $this->runOps('reload');
        $this->assertSame(0, $res['code'], $res['stdout'] . $res['stderr']);
        $this->assertTrue($res['json']['nothing_pending'] ?? false);
    }

    /**
     * The reload op rides AiPBX's real apply path: the pending row is applied
     * (config regenerated), the row is deleted, and the change is audit-logged.
     */
    public function testReloadAppliesPendingChangesAndAudits(): void
    {
        $this->clearPendingSync();
        $db = getDB();
        $db->prepare(
            "INSERT INTO sys_pending_sync (domain, entity_type, entity_id, entity_label, action, changed_by, changed_at)
             VALUES ('udptl', 'settings', '1', 'UDPTL settings', 'update', NULL, NOW())"
        )->execute();

        $res = $this->runOps('reload');
        $this->assertSame(0, $res['code'], $res['stdout'] . $res['stderr']);
        $this->assertContains('udptl', $res['json']['applied'] ?? []);
        $this->assertSame([], $res['json']['failed'] ?? null);
        $this->assertSame(0, $res['json']['pending_after'] ?? null);

        $audit = $db->prepare("SELECT COUNT(*) FROM sys_audit_log WHERE domain = 'udptl' AND entity_type = 'sync_apply'");
        $audit->execute();
        $this->assertGreaterThan(0, (int) $audit->fetchColumn(), 'the cloud-triggered apply must be audit-logged');
    }

    private function clearPendingSync(): void
    {
        getDB()->exec('DELETE FROM sys_pending_sync');
    }
}
