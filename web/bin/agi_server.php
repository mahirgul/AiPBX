<?php

declare(strict_types=1);

/**
 * aipbx-agi: FastAGI service for AGI scripts written in the portal.
 *
 * Listens on 127.0.0.1:4573 only (systemd unit aipbx-agi, as root). For each
 * connection it reads the AGI environment Asterisk sends, finds the active
 * script named in the request, and runs it in the scheduled-task sandbox
 * (as aipbx-task: read-only system, no home folders, no portal or Asterisk
 * files, memory/CPU/time limits, no network unless the script has it). The
 * script talks plain AGI on stdin/stdout, like a script in agi-bin; this
 * service passes the bytes both ways until either side closes.
 *
 * Request: agi://127.0.0.1:4573/<name>[?ignored], arguments as usual.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ScheduledTaskRunner.php';
require_once dirname(__DIR__) . '/src/services/AgiScriptService.php';

const AGI_RUN_DIR = '/run/aipbx-agi';
const AGI_MAX_ENV = 16384;

function agiLog(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

/** A root-owned folder of the given mode; null when it is not one (a link, someone else's). */
function agiRootDir(string $dir, int $mode): ?string
{
    if (!is_dir($dir) && !@mkdir($dir, $mode, true)) {
        return null;
    }
    clearstatcache();
    $st = @lstat($dir);
    if ($st === false || is_link($dir) || $st['uid'] !== 0 || ($st['mode'] & 0777) !== $mode) {
        return null;
    }
    return $dir;
}

/**
 * The script's own working folder, the only place it may write (kept between calls):
 * agi-<id> beside the scheduled tasks' folders, so the sandbox hides every other one.
 */
function agiWorkDir(int $id): ?string
{
    if (agiRootDir(ScheduledTaskRunner::BASE_DIR, 0755) === null) {
        return null;
    }
    $dir = ScheduledTaskRunner::BASE_DIR . '/agi-' . $id;
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        return null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0750)) {
        return null;
    }
    $u = ScheduledTaskRunner::USER;
    return (@chown($dir, $u) && @chgrp($dir, $u) && @chmod($dir, 0750)) ? $dir : null;
}

/**
 * Reads the environment block (lines up to the empty one).
 *
 * @param resource $conn
 */
function agiReadEnv($conn): ?string
{
    $env = '';
    while (($line = fgets($conn, 4096)) !== false) {
        $env .= $line;
        if (strlen($env) > AGI_MAX_ENV) {
            return null;
        }
        if (rtrim($line, "\r\n") === '') {
            return $env;
        }
    }
    return null;
}

/** The script name of the request: "agi_network_script: name?x" or the request URL's path. */
function agiScriptName(string $env): string
{
    if (preg_match('/^agi_network_script:\s*([^\s?\/]+)/m', $env, $m)) {
        return strtolower($m[1]);
    }
    if (preg_match('#^agi_request:\s*agi://[^/\s]+/([^\s?/]+)#m', $env, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

/**
 * One call: runs the script in the sandbox and joins its stdio to the connection.
 *
 * @param resource $conn
 */
function agiHandle($conn): void
{
    stream_set_timeout($conn, 10);
    $env = agiReadEnv($conn);
    if ($env === null) {
        return;
    }
    $name = agiScriptName($env);
    $script = $name === '' ? null : AgiScriptService::findActiveByName($name);
    if (!$script) {
        agiLog("unknown or inactive script: {$name}");
        fwrite($conn, "VERBOSE \"aipbx-agi: no active script {$name}\" 1\n");
        return;
    }
    $problem = ScheduledTaskRunner::sandboxProblem();
    $runDir = agiRootDir(AGI_RUN_DIR, 0711);
    $work = agiWorkDir((int) $script['id']);
    if ($problem !== null || $runDir === null || $work === null) {
        agiLog("cannot run {$name}: " . ($problem ?? 'folder'));
        return;
    }
    $ext = ['php' => 'php', 'python' => 'py'][$script['language']] ?? 'sh';
    $file = $runDir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents($file, (string) $script['content']) === false) {
        return;
    }
    @chmod($file, 0444);
    $task = [
        'id' => (int) $script['id'], 'timeout_sec' => (int) $script['timeout_sec'], 'memory_limit_mb' => (int) $script['memory_mb'],
        'cpu_limit_pct' => 50, 'network_access' => (int) $script['network_access'],
    ];
    $cmd = ScheduledTaskRunner::sandboxCommand($task, getmypid() ?: random_int(1, 1 << 30), $work, $file,
        ScheduledTaskRunner::interpreter((string) $script['language']), 'aipbx-agi-');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work);
    if (!is_resource($proc)) {
        @unlink($file);
        return;
    }
    fwrite($pipes[0], $env);
    agiPump($conn, $pipes, (int) $script['timeout_sec'] + 5);
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    $exit = proc_close($proc);
    @unlink($file);
    if ($exit !== 0) {
        agiLog("{$name} exited with {$exit}");
    }
}

/**
 * Bytes both ways until the script ends, Asterisk hangs up, or time is up.
 * The script's stderr goes to this service's log (journal), at most 4 KB per call.
 *
 * @param resource $conn
 * @param array<int, resource> $pipes
 */
function agiPump($conn, array $pipes, int $limitSec): void
{
    stream_set_blocking($conn, false);
    foreach ([1, 2] as $i) {
        stream_set_blocking($pipes[$i], false);
    }
    $end = time() + $limitSec;
    $logged = 0;
    $open = [1 => true, 2 => true];
    while ($open[1] && time() < $end) {
        $read = [$conn];
        foreach ($open as $i => $on) {
            if ($on) {
                $read[] = $pipes[$i];
            }
        }
        $w = $e = null;
        if (@stream_select($read, $w, $e, 1) === false) {
            return;
        }
        foreach ($read as $r) {
            $chunk = fread($r, 8192);
            if ($r === $conn) {
                if ($chunk === '' || $chunk === false) {
                    if (feof($conn)) {
                        // Asterisk hung up: the script reads end of input.
                        fclose($pipes[0]);
                        $pipes[0] = null;
                        return;
                    }
                    continue;
                }
                if (is_resource($pipes[0] ?? null)) {
                    fwrite($pipes[0], $chunk);
                }
            } elseif ($r === $pipes[1]) {
                if ($chunk === '' || $chunk === false) {
                    $open[1] = !feof($r);
                    continue;
                }
                fwrite($conn, $chunk);
            } else {
                if ($chunk === '' || $chunk === false) {
                    $open[2] = !feof($r);
                    continue;
                }
                if ($logged < 4096) {
                    agiLog(rtrim(substr($chunk, 0, 4096 - $logged)));
                    $logged += strlen($chunk);
                }
            }
        }
    }
}

$server = @stream_socket_server('tcp://' . AgiScriptService::HOST . ':' . AgiScriptService::PORT, $errno, $errstr);
if (!$server) {
    agiLog("listen failed: {$errstr}");
    exit(1);
}
pcntl_async_signals(true);
pcntl_signal(SIGCHLD, SIG_IGN);
pcntl_signal(SIGTERM, fn () => exit(0));
agiLog('aipbx-agi listening on ' . AgiScriptService::HOST . ':' . AgiScriptService::PORT);

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($server);
        // Each child opens its own database connection.
        try {
            agiHandle($conn);
        } catch (\Throwable $e) {
            agiLog('error: ' . $e->getMessage());
        }
        @fclose($conn);
        exit(0);
    }
    fclose($conn);
}
