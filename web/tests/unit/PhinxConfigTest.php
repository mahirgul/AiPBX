<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * The migration config must follow the deployment's database port.
 *
 * The compose stack publishes MariaDB on 127.0.0.1:${AIPBX_DB_PORT} and gives
 * every service that value as DB_PORT (docker/README.md). phinx.php had a fixed
 * 3306, so on a host that moved the port aipbx-init reached the database for
 * its own setup — it passes -P everywhere — and then died at `phinx migrate`,
 * the one step with a config file of its own. Nothing else in the deployment
 * could be blamed from that error message.
 */
final class PhinxConfigTest extends TestCase
{
    /** @var list<string> env files written by a test, removed afterwards */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->files = [];
    }

    private function envFile(array $values): string
    {
        $f = tempnam(sys_get_temp_dir(), 'aipbx-phinx-');
        $this->files[] = $f;
        $body = "# phinx config test\n";
        foreach ($values as $k => $v) {
            $body .= $k . '=' . $v . "\n";
        }
        file_put_contents($f, $body);
        @chmod($f, 0644);
        return $f;
    }

    /**
     * phinx.php declares functions, so it cannot be required twice in one
     * process; every case therefore asks a child PHP for the result, with an
     * environment this test controls. The parent exports the test database
     * (tests/bootstrap.php) and that must not leak into the child.
     */
    private function production(array $env, string $envFile): array
    {
        $web = dirname(__DIR__, 2);
        $vars = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'AIPBX_ENV_FILE' => $envFile,
        ] + $env;
        $code = '$c = require ' . var_export($web . '/phinx.php', true)
            . '; echo json_encode($c["environments"]["production"]);';

        $proc = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $web,
            $vars
        );
        $this->assertIsResource($proc, 'could not start php');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), 'phinx.php failed: ' . $err . $out);

        $cfg = json_decode($out, true);
        $this->assertIsArray($cfg, 'phinx.php returned no production config: ' . $out);
        return $cfg;
    }

    public function testPortComesFromTheEnvironment(): void
    {
        // What compose does: DB_PORT in the service's environment.
        $cfg = $this->production(['DB_PORT' => '13306'], $this->envFile(['DB_PORT' => 3306]));
        $this->assertSame(13306, $cfg['port']);
    }

    public function testPortFallsBackToTheDeploymentEnvFile(): void
    {
        // A host install: nothing exported, DB_PORT written to the env file.
        $cfg = $this->production([], $this->envFile(['DB_PORT' => 3307]));
        $this->assertSame(3307, $cfg['port']);
    }

    public function testPortDefaultsToTheServerDefault(): void
    {
        $cfg = $this->production([], $this->envFile([]));
        $this->assertSame(3306, $cfg['port']);
    }

    public function testTheRestOfTheDeploymentSettingsStillComeFromTheFile(): void
    {
        $cfg = $this->production([], $this->envFile([
            'DB_HOST' => '127.0.0.1',
            'DB_NAME' => 'asterisk',
            'MIGRATOR_DB_USER' => 'aipbx_migrator',
            'MIGRATOR_DB_PASS' => 'not-a-real-password',
        ]));
        $this->assertSame('127.0.0.1', $cfg['host']);
        $this->assertSame('asterisk', $cfg['name']);
        $this->assertSame('aipbx_migrator', $cfg['user']);
        $this->assertSame('not-a-real-password', $cfg['pass']);
        $this->assertSame('mysql', $cfg['adapter']);
    }
}
