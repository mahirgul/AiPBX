<?php

declare(strict_types=1);

require_once __DIR__ . '/ScheduledTaskService.php';
require_once __DIR__ . '/../repositories/ScheduledTaskRepository.php';
require_once __DIR__ . '/NoticeService.php';

/**
 * Runs one scheduled task in a systemd sandbox (System -> Scheduled tasks).
 *
 * Only ever as the unprivileged user aipbx-task (created by install.sh), never
 * as root or www-data, and only from the root dispatcher: without systemd-run
 * and that user the task is not run at all — there is no unsandboxed fallback.
 * Nothing but the task's own folder is writable, home folders and devices are
 * hidden, and without "network access" no address can be reached.
 */
class ScheduledTaskRunner
{
    public const USER = 'aipbx-task';
    public const BASE_DIR = '/var/lib/aipbx/tasks';

    /** Null when tasks can run here, else why not (translated). */
    public static function sandboxProblem(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_getuid') || posix_getuid() !== 0) {
            return t('scheduled_tasks.err_not_root');
        }
        if (!@exec('command -v systemd-run 2>/dev/null')) {
            return t('scheduled_tasks.err_no_systemd');
        }
        $state = (string) @exec('systemctl is-system-running 2>/dev/null');
        if (!in_array($state, ['running', 'degraded'], true)) {
            return t('scheduled_tasks.err_no_systemd');
        }
        if (posix_getpwnam(self::USER) === false) {
            return t('scheduled_tasks.err_no_user');
        }
        return null;
    }

    /**
     * @return array{ok: bool, run_id: int, exit_code: int, output: string, error?: string}
     */
    public static function run(int $taskId, string $triggeredBy = 'schedule'): array
    {
        $task = ScheduledTaskRepository::findById($taskId);
        if (!$task) {
            return ['ok' => false, 'run_id' => 0, 'exit_code' => -1, 'output' => '', 'error' => 'Task not found'];
        }
        if (ScheduledTaskRepository::isTaskRunning($taskId)) {
            return ['ok' => false, 'run_id' => 0, 'exit_code' => -1, 'output' => '', 'error' => 'Task is already running'];
        }

        $runId = ScheduledTaskRepository::recordRunStart($taskId, $triggeredBy);
        $problem = self::sandboxProblem();
        if ($problem !== null) {
            ScheduledTaskRepository::recordRunFinish($runId, -1, '', $problem);
            self::notify($task, -1, $problem);
            return ['ok' => false, 'run_id' => $runId, 'exit_code' => -1, 'output' => '', 'error' => $problem];
        }

        // The task's own folder: the only writable place, owned by aipbx-task.
        $dir = self::BASE_DIR . '/' . $taskId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @chown($dir, self::USER);
        @chgrp($dir, self::USER);
        @chmod($dir, 0750);

        $scriptType = (string) ($task['script_type'] ?? 'shell');
        $timeoutSec = max(5, min(3600, (int) ($task['timeout_sec'] ?? 300)));
        $ext = match ($scriptType) {
            'php' => 'php',
            'python' => 'py',
            default => 'sh',
        };
        $scriptFile = $dir . '/.run_' . $runId . '.' . $ext;
        file_put_contents($scriptFile, (string) ($task['script_content'] ?? ''));
        @chown($scriptFile, self::USER);
        @chmod($scriptFile, 0500);
        $interpreter = match ($scriptType) {
            'php' => '/usr/bin/php',
            'python' => '/usr/bin/python3',
            default => '/bin/bash',
        };

        $cmd = self::sandboxCommand($task, $runId, $dir, $scriptFile, $interpreter);
        [$exitCode, $output] = self::executeWithTimeout($cmd, $dir, (int) ($task['timeout_sec'] ?? 300) + 10);
        @unlink($scriptFile);

        $errorMessage = ($exitCode !== 0) ? ('Process exited with code ' . $exitCode) : null;
        if ($exitCode === 124 || $exitCode === 137) {
            $errorMessage = 'Task timed out after ' . $timeoutSec . ' seconds';
        }
        ScheduledTaskRepository::recordRunFinish($runId, $exitCode, $output, $errorMessage);
        self::notify($task, $exitCode, $output !== '' ? $output : (string) $errorMessage);

        return [
            'ok' => $exitCode === 0,
            'run_id' => $runId,
            'exit_code' => $exitCode,
            'output' => $output,
            'error' => $errorMessage ?? '',
        ];
    }

    /** The systemd-run command line of one run (pure, so the sandbox is tested). */
    public static function sandboxCommand(array $task, int $runId, string $dir, string $scriptFile, string $interpreter): string
    {
        $timeoutSec = max(5, min(3600, (int) ($task['timeout_sec'] ?? 300)));
        $memoryMb = max(32, min(4096, (int) ($task['memory_limit_mb'] ?? 256)));
        $cpuPct = max(5, min(100, (int) ($task['cpu_limit_pct'] ?? 50)));
        $props = [
            'ProtectSystem=strict', 'ReadWritePaths=' . $dir, 'ProtectHome=yes', 'PrivateTmp=yes',
            'PrivateDevices=yes', 'NoNewPrivileges=yes', 'RestrictSUIDSGID=yes', 'ProtectKernelTunables=yes',
            'ProtectKernelModules=yes', 'ProtectControlGroups=yes', 'LockPersonality=yes',
            'MemoryMax=' . $memoryMb . 'M', 'CPUQuota=' . $cpuPct . '%', 'RuntimeMaxSec=' . $timeoutSec,
        ];
        // Its own network namespace with only loopback: unlike IPAddressDeny
        // (cgroup BPF, missing in containers and some kernels) it always holds.
        if (empty($task['network_access'])) {
            $props[] = 'PrivateNetwork=yes';
        }
        $cmd = 'systemd-run --wait --pipe --collect --quiet --unit=' . escapeshellarg('aipbx-task-' . (int) $task['id'] . '-' . $runId)
            . ' --uid=' . self::USER . ' --gid=' . self::USER . ' --working-directory=' . escapeshellarg($dir);
        foreach ($props as $p) {
            $cmd .= ' --property=' . escapeshellarg($p);
        }
        return $cmd . ' ' . escapeshellarg($interpreter) . ' ' . escapeshellarg($scriptFile);
    }

    /** A failed run is an alert in the bell (and the health "Other alerts" mail); a good one ends it. */
    private static function notify(array $task, int $exitCode, string $detail): void
    {
        $key = 'scheduled_task_' . (int) $task['id'];
        if ($exitCode === 0) {
            NoticeService::resolve($key);
            return;
        }
        NoticeService::raise($key, 'fail', sprintf(t('scheduled_tasks.alert_failed'), (string) $task['name']),
            mb_substr($detail, 0, 300), '/scheduled-tasks');
    }

    /**
     * Executes command with a strict timeout and captures up to 64 KB of output.
     *
     * @return array{0: int, 1: string} [exitCode, output]
     */
    public static function executeWithTimeout(string $command, string $cwd, int $timeoutSec): array
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Wrap with timeout command if available
        $fullCmd = 'timeout ' . (int)$timeoutSec . ' ' . $command;

        $process = proc_open($fullCmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return [-1, 'Failed to spawn process'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $startTime = time();

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            $status = proc_get_status($process);
            $changed = stream_select($read, $write, $except, 0, 100000); // 100ms

            if ($changed > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                        if (strlen($output) > 65536) {
                            $output = substr($output, -65536);
                        }
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            if (time() - $startTime > ($timeoutSec + 2)) {
                // Force kill if timeout binary didn't terminate it
                proc_terminate($process, 9);
                $exitCode = 124;
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $procExit = proc_close($process);
        $exitCode = ($status['running'] ? 124 : ($status['exitcode'] !== -1 ? $status['exitcode'] : $procExit));

        return [$exitCode, $output];
    }
}
