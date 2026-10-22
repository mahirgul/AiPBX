<?php
/**
 * Runs one plugin job in its own PHP process, with a deadline.
 *
 * A plugin's cron entry is code someone else wrote. In-process it could: call
 * exit and end the dispatcher mid-list; fatal on a missing extension; leak
 * memory until the next OOM; or block forever on a socket. A child process
 * contains all four. The contract with the child is a file and two arguments —
 * `plugin_job.php <slug> <job>` — so the only thing that crosses the boundary
 * is the exit code and the first few kilobytes of output.
 *
 * `proc_open` is called with an argument ARRAY, never a string: no shell, so a
 * slug cannot become a command even if a manifest is hostile. The slug is also
 * validated before it reaches here (PluginRuntime).
 *
 * Output is capped while it is read, not after: a job that prints in a loop
 * cannot fill the disk or the dispatcher's memory. On timeout the child is
 * killed with SIGKILL; there is no "please stop" that a stuck job could ignore.
 */

final class PluginProcessRunner
{
    public function __construct(
        private string $phpBinary,
        private string $jobScript,
        private int $outputLimit = 4096
    ) {
    }

    /** @return array{status:int, output:string} status -1 means timed out */
    public function __invoke(string $slug, string $job, int $timeout): array
    {
        $timeout = max(1, min(3600, $timeout));
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            [$this->phpBinary, $this->jobScript, $slug, $job],
            $descriptors,
            $pipes,
            dirname($this->jobScript)
        );
        if (!is_resource($process)) {
            return ['status' => 1, 'output' => 'cannot start the plugin job process'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $timeout;
        $output = '';
        $timedOut = false;
        $exitCode = 1; // overwritten when the child exits

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            if (strlen($output) > $this->outputLimit) {
                // Keep the end: a PHP fatal error is at the bottom.
                $output = substr($output, -$this->outputLimit);
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // proc_get_status consumes the exit code; proc_close would
                // return -1 afterwards, so take it from here.
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(20000);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        if (strlen($output) > $this->outputLimit) {
            $output = substr($output, -$this->outputLimit);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            return ['status' => -1,
                    'output' => trim($output . "\n" . sprintf('timed out after %d s', $timeout))];
        }
        return ['status' => $exitCode, 'output' => $output];
    }
}
