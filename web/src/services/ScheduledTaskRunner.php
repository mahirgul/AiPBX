<?php

declare(strict_types=1);

require_once __DIR__ . '/ScheduledTaskService.php';
require_once __DIR__ . '/../repositories/ScheduledTaskRepository.php';
require_once __DIR__ . '/NoticeService.php';

/**
 * Sandboxed execution engine for Scheduled Tasks (Roadmap 2.1).
 * Supports systemd-run sandboxing with process fallback for container/test environments.
 */
class ScheduledTaskRunner
{
    /**
     * Executes the given task.
     *
     * @return array{ok: bool, run_id: int, exit_code: int, output: string, error?: string}
     */
    public static function run(int $taskId, string $triggeredBy = 'manual'): array
    {
        $task = ScheduledTaskRepository::findById($taskId);
        if (!$task) {
            return ['ok' => false, 'run_id' => 0, 'exit_code' => -1, 'output' => '', 'error' => 'Task not found'];
        }

        if (ScheduledTaskRepository::isTaskRunning($taskId)) {
            return ['ok' => false, 'run_id' => 0, 'exit_code' => -1, 'output' => '', 'error' => 'Task is already running'];
        }

        $runId = ScheduledTaskRepository::recordRunStart($taskId, $triggeredBy);

        // Prepare working directory
        $baseTaskDir = '/var/lib/aipbx/tasks/' . $taskId;
        if (!is_dir($baseTaskDir)) {
            if (!@mkdir($baseTaskDir, 0755, true)) {
                $baseTaskDir = sys_get_temp_dir() . '/aipbx_tasks/' . $taskId;
                @mkdir($baseTaskDir, 0755, true);
            }
        }

        $scriptType = (string)($task['script_type'] ?? 'shell');
        $scriptContent = (string)($task['script_content'] ?? '');
        $timeoutSec = (int)($task['timeout_sec'] ?? 300);
        $memoryMb = (int)($task['memory_limit_mb'] ?? 256);
        $cpuPct = (int)($task['cpu_limit_pct'] ?? 50);
        $networkAccess = !empty($task['network_access']);

        $ext = match ($scriptType) {
            'php' => 'php',
            'python' => 'py',
            default => 'sh',
        };

        $scriptFile = $baseTaskDir . '/run_' . $runId . '.' . $ext;
        file_put_contents($scriptFile, $scriptContent);
        @chmod($scriptFile, 0755);

        // Prepare interpreter command
        $execCmd = match ($scriptType) {
            'php' => 'php ' . escapeshellarg($scriptFile),
            'python' => 'python3 ' . escapeshellarg($scriptFile),
            default => 'bash ' . escapeshellarg($scriptFile),
        };

        // Determine if systemd-run is available and usable
        $useSystemd = self::isSystemdRunAvailable();

        if ($useSystemd) {
            $unitName = 'aipbx-task-' . $taskId . '-' . $runId;
            $sysdArgs = [
                'systemd-run',
                '--wait',
                '--pipe',
                '--collect',
                '--quiet',
                '--unit=' . escapeshellarg($unitName),
                '--property=ProtectSystem=strict',
                '--property=PrivateTmp=yes',
                '--property=NoNewPrivileges=yes',
                '--property=WorkingDirectory=' . escapeshellarg($baseTaskDir),
                '--property=MemoryMax=' . $memoryMb . 'M',
                '--property=CPUQuota=' . $cpuPct . '%',
            ];

            // If user aipbx-task exists, run as aipbx-task
            if (self::hasSystemUser('aipbx-task')) {
                $sysdArgs[] = '--uid=aipbx-task';
            }

            if (!$networkAccess) {
                $sysdArgs[] = '--property=IPAddressDeny=any';
            }

            $command = implode(' ', $sysdArgs) . ' ' . $execCmd;
        } else {
            $command = $execCmd;
        }

        [$exitCode, $output] = self::executeWithTimeout($command, $baseTaskDir, $timeoutSec);

        // Clean up temporary script file
        @unlink($scriptFile);

        $errorMessage = ($exitCode !== 0) ? ('Process exited with code ' . $exitCode) : null;
        if ($exitCode === 124 || $exitCode === 137) {
            $errorMessage = 'Task timed out after ' . $timeoutSec . ' seconds';
        }

        ScheduledTaskRepository::recordRunFinish($runId, $exitCode, $output, $errorMessage);

        // Raise or resolve notice
        $taskName = (string)$task['name'];
        $noticeKey = 'scheduled_task_' . $taskId;
        if ($exitCode !== 0) {
            $snippet = mb_substr($output !== '' ? $output : ($errorMessage ?? 'Failed'), 0, 300);
            NoticeService::raise(
                $noticeKey,
                'fail',
                "Scheduled task '{$taskName}' failed",
                $snippet,
                '/scheduled-tasks',
                'task'
            );
        } else {
            NoticeService::resolve($noticeKey);
        }

        return [
            'ok' => $exitCode === 0,
            'run_id' => $runId,
            'exit_code' => $exitCode,
            'output' => $output,
            'error' => $errorMessage ?? '',
        ];
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

    private static function isSystemdRunAvailable(): bool
    {
        // Must be linux, have systemd-run, and systemd must be running (systemctl is-system-running)
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        // Check if root or has sudo
        $uid = posix_getuid();
        if ($uid !== 0) {
            return false;
        }

        $check = @exec('command -v systemd-run 2>/dev/null');
        if (!$check) {
            return false;
        }

        // Check if systemd is active
        $state = @exec('systemctl is-system-running 2>/dev/null');
        return $state === 'running' || $state === 'degraded';
    }

    private static function hasSystemUser(string $username): bool
    {
        return posix_getpwnam($username) !== false;
    }
}
