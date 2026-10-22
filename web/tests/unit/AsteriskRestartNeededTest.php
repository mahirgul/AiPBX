<?php

use PHPUnit\Framework\TestCase;

/**
 * scripts/asterisk-restart-needed.sh decides whether aipbx-update restarts
 * Asterisk (calls drop) or only reloads it. A wrong "no" would leave a new
 * Asterisk setting unloaded; a wrong "yes" drops calls for nothing.
 */
final class AsteriskRestartNeededTest extends TestCase
{
    private string $repo;
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 3) . '/scripts/asterisk-restart-needed.sh';
        $this->repo = sys_get_temp_dir() . '/aipbx_restart_' . bin2hex(random_bytes(4));
        mkdir($this->repo . '/asterisk-config/pbx', 0700, true);
        mkdir($this->repo . '/web', 0700, true);
        file_put_contents($this->repo . '/install.sh', "#!/bin/bash\nASTERISK_RESTART_EPOCH=1\necho install\n");
        file_put_contents($this->repo . '/asterisk-config/pjsip.conf', "[global]\n");
        file_put_contents($this->repo . '/asterisk-config/pbx/extensions_general.conf', "[x]\n");
        file_put_contents($this->repo . '/web/page.php', "<?php\n");
        $this->git('init -q');
        $this->commit('base');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo));
    }

    private function git(string $args): string
    {
        return (string) shell_exec('git -C ' . escapeshellarg($this->repo)
            . ' -c user.email=t@t -c user.name=t ' . $args . ' 2>&1');
    }

    private function commit(string $msg): string
    {
        $this->git('add -A');
        $this->git('commit -q -m ' . escapeshellarg($msg));
        return trim($this->git('rev-parse HEAD'));
    }

    private function decide(string $from, string $to): string
    {
        return trim((string) shell_exec('bash ' . escapeshellarg($this->script) . ' '
            . escapeshellarg($this->repo) . ' ' . escapeshellarg($from) . ' ' . escapeshellarg($to)));
    }

    private function change(string $file, string $text): string
    {
        file_put_contents($this->repo . '/' . $file, $text, FILE_APPEND);
        return $this->commit('change ' . $file);
    }

    public function testPortalCodeAndGeneratedDialplanNeedNoRestart(): void
    {
        $base = trim($this->git('rev-parse HEAD'));
        $this->change('web/page.php', "// fix\n");
        $this->change('asterisk-config/pbx/extensions_general.conf', "; seed\n");
        $head = $this->change('install.sh', "# unrelated installer fix\n");
        $this->assertSame('no', $this->decide($base, $head));
    }

    public function testAsteriskConfigurationNeedsARestart(): void
    {
        $base = trim($this->git('rev-parse HEAD'));
        $head = $this->change('asterisk-config/pjsip.conf', "; new\n");
        $this->assertStringStartsWith('yes: Asterisk configuration changed', $this->decide($base, $head));
    }

    public function testRaisedEpochNeedsARestart(): void
    {
        $base = trim($this->git('rev-parse HEAD'));
        file_put_contents($this->repo . '/install.sh', "#!/bin/bash\nASTERISK_RESTART_EPOCH=2\necho install\n");
        $head = $this->commit('epoch');
        $this->assertStringContainsString('ASTERISK_RESTART_EPOCH 1 -> 2', $this->decide($base, $head));
    }

    public function testUnknownReleasesRestart(): void
    {
        $base = trim($this->git('rev-parse HEAD'));
        file_put_contents($this->repo . '/install.sh', "#!/bin/bash\necho old installer\n");
        $old = $this->commit('no epoch');
        $this->assertStringStartsWith('yes:', $this->decide($old, $base), 'a release without the epoch must restart');
        $this->assertStringStartsWith('yes:', $this->decide($base, 'no-such-commit'));
    }

    public function testBridgeReleaseRestarts(): void
    {
        $base = trim($this->git('rev-parse HEAD'));
        $head = $this->change('UPGRADE_FROM', "1.50.0\n");
        $this->assertSame('yes: bridge release', $this->decide($base, $head));
    }
}
