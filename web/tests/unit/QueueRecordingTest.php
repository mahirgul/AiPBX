<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class QueueRecordingTest extends TestCase
{
    private function runAgi(array $variables): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/queue_record.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0], "agi_request: queue_record.php\n\n");
        $commands = [];
        $recording = false;
        while (($command = fgets($pipes[1])) !== false) {
            $command = trim($command);
            $commands[] = $command;
            if (str_starts_with($command, 'GET VARIABLE ')) {
                $key = substr($command, 13);
                $value = $key === 'MIXMONITOR_FILENAME' && $recording
                    ? ($variables['AIPBX_QUEUE_REC_FILE'] ?? '') : ($variables[$key] ?? '');
                fwrite($pipes[0], $value === '' ? "200 result=0\n" : "200 result=1 ($value)\n");
            } else {
                if (str_starts_with($command, 'EXEC MixMonitor ')) { $recording = true; }
                fwrite($pipes[0], "200 result=0\n");
            }
        }
        fclose($pipes[0]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        return $commands;
    }

    public function testQueueDefersRecordingToItsPostAnswerCallerAgi(): void
    {
        Fixtures::load();
        $code = buildDestinationLines('queue', Fixtures::QUEUE_NAME);
        $this->assertStringNotContainsString(',MixMonitor(', $code);
        $this->assertStringNotContainsString('Set(CDR(userfield)', $code);
        $this->assertStringContainsString(',/usr/local/bin/queue_record.php)', $code);
        getDB()->exec("UPDATE pbx_queues SET record_enabled=0 WHERE queue_name='" . Fixtures::QUEUE_NAME . "'");
        $this->assertStringNotContainsString('/usr/local/bin/queue_record.php', buildDestinationLines('queue', Fixtures::QUEUE_NAME));
    }

    public function testEnterModeRecordsFromTheStartWithoutTheHook(): void
    {
        Fixtures::load();
        getDB()->exec("UPDATE pbx_queues SET record_enabled=1, record_mode='enter' WHERE queue_name='" . Fixtures::QUEUE_NAME . "'");
        $code = buildDestinationLines('queue', Fixtures::QUEUE_NAME);
        $this->assertStringContainsString(',MixMonitor(${AIPBX_QUEUE_REC_FILE}', $code);
        $this->assertStringContainsString('"${PRIVACY_NO_RECORD}" = "1" | "${MIXMONITOR_FILENAME}" != ""', $code);
        $this->assertStringNotContainsString('queue_record.php', $code);
        $this->assertLessThan(strpos($code, ',Queue('), strpos($code, ',MixMonitor('));
    }

    public function testAnsweredCallStartsRecordingAndLinksTheActualFile(): void
    {
        $file = '/var/spool/asterisk/monitor/inbound_20261116_170000_101_1234.1.wav';
        $commands = $this->runAgi(['AIPBX_QUEUE_REC_FILE' => $file, 'AIPBX_QUEUE_REC_OPTIONS' => 'B(15)']);
        $this->assertContains('EXEC MixMonitor ' . $file . ',B(15)', $commands);
        $this->assertContains('SET VARIABLE CDR(userfield) "' . $file . '"', $commands);
    }

    public function testOptOutAndExistingRecordingNeverStartAnotherRecorder(): void
    {
        foreach ([['PRIVACY_NO_RECORD' => '1'], ['MIXMONITOR_FILENAME' => '/existing.wav']] as $vars) {
            $this->assertSame([], array_values(array_filter($this->runAgi($vars), fn($c) => str_starts_with($c, 'EXEC '))));
        }
    }

    public function testUntrustedFilenameAndOptionsCannotReachMixMonitor(): void
    {
        foreach ([['AIPBX_QUEUE_REC_FILE' => '/tmp/evil.wav'],
                  ['AIPBX_QUEUE_REC_FILE' => '/var/spool/asterisk/monitor/inbound_safe.wav', 'AIPBX_QUEUE_REC_OPTIONS' => ',System(touch /tmp/evil)']] as $vars) {
            $this->assertSame([], array_values(array_filter($this->runAgi($vars), fn($c) => str_starts_with($c, 'EXEC '))));
        }
    }
}
