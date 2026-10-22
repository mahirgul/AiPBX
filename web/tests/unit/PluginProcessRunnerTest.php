<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/PluginProcessRunner.php';

/**
 * These tests start real PHP processes. That is the point: the promise is that
 * a plugin job cannot hang or kill the dispatcher, and a fake process cannot
 * prove it.
 */
final class PluginProcessRunnerTest extends TestCase
{
    private string $dir;
    private PluginProcessRunner $runner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aipbx-jobs-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
        $this->runner = new PluginProcessRunner(PHP_BINARY, $this->dir . '/job.php');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function script(string $body): void
    {
        file_put_contents($this->dir . '/job.php', "<?php\n" . $body . "\n");
    }

    public function testTheExitCodeAndOutputComeBack(): void
    {
        $this->script('fwrite(STDOUT, "hello from " . $argv[1] . "/" . $argv[2]); exit(7);');
        $result = ($this->runner)('demo', 'collect', 30);
        self::assertSame(7, $result['status']);
        self::assertStringContainsString('hello from demo/collect', $result['output']);
    }

    public function testStderrIsCapturedToo(): void
    {
        $this->script('fwrite(STDERR, "PHP Fatal error: boom"); exit(1);');
        $result = ($this->runner)('demo', 'collect', 30);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('boom', $result['output']);
    }

    public function testAHangingJobIsKilledAtTheDeadline(): void
    {
        $this->script('sleep(30);');
        $started = microtime(true);
        $result = ($this->runner)('demo', 'slow', 1);
        $elapsed = microtime(true) - $started;

        self::assertSame(-1, $result['status']);
        self::assertStringContainsString('timed out', $result['output']);
        self::assertLessThan(5.0, $elapsed, 'the deadline is enforced, not waited out');
    }

    public function testOutputIsCapped(): void
    {
        $runner = new PluginProcessRunner(PHP_BINARY, $this->dir . '/job.php', 256);
        $this->script('echo str_repeat("x", 10000);');
        $result = $runner('demo', 'noisy', 10);
        self::assertSame(0, $result['status']);
        self::assertLessThanOrEqual(256 + 1, strlen($result['output']));
    }

    public function testAMissingScriptIsAFailedRunNotACrashedDispatcher(): void
    {
        // No script written: PHP exits non-zero and says why on stderr.
        $result = ($this->runner)('demo', 'collect', 10);
        self::assertNotSame(0, $result['status']);
        self::assertNotSame('', $result['output']);
    }
}
