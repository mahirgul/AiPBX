<?php

use PHPUnit\Framework\TestCase;

final class VoicemailPluginEventTest extends TestCase
{
    public function testNotificationProducerDeliversFullTranscriptToPlugin(): void
    {
        $root = sys_get_temp_dir() . '/aipbx-vm-event-' . bin2hex(random_bytes(6));
        mkdir($root . '/bin', 0700, true);
        mkdir($root . '/src/services', 0700, true);
        $files = [];
        $write = static function (string $path, string $text) use (&$files): void {
            file_put_contents($path, $text);
            $files[] = $path;
        };
        try {
            $write($root . '/bin/voicemail_notify.php', file_get_contents(dirname(__DIR__, 2) . '/bin/voicemail_notify.php'));
            $write($root . '/handler.php', file_get_contents(dirname(__DIR__, 3) . '/plugins/ai-voicemail/events/handler.php'));
            $write($root . '/config.php', <<<'FIXTURE'
<?php
// No access to real spool files, providers, database, chat or push services.
function glob($pattern) { return []; }
class PluginManager {
    public static array $values = [];
    public static function kvGet($slug, $collection, $key) { return self::$values[$key] ?? null; }
    public static function kvSet($slug, $collection, $key, $value) { self::$values[$key] = $value; }
}
class VoicemailAiService {
    public static function forNewest($ext) { return ['summary' => 'Send the contract tomorrow.', 'transcript' => str_repeat('Actual recording content. ', 20)]; }
}
class WebhookService {
    public static array $events = [];
    public static function emit($event, $data) {
        self::$events[$event] = $data;
        $handler = require __DIR__ . '/handler.php';
        $handler($event, ['event_id' => 'producer-test', 'data' => $data], []);
    }
}
FIXTURE);
            $write($root . '/src/services/VoicemailAiService.php', '<?php');
            $write($root . '/src/services/WebhookService.php', '<?php');
            // Stop after actual event dispatch, before unrelated chat/push delivery.
            $write($root . '/src/services/FileStorageService.php', '<?php echo json_encode([WebhookService::$events, PluginManager::$values]); exit;');
            $process = proc_open([PHP_BINARY, '-d', 'disable_functions=glob', $root . '/bin/voicemail_notify.php', '--send', '101', '1'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            [$events, $summaries] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('transcript', $events['voicemail.created']);
            $this->assertSame(str_repeat('Actual recording content. ', 20), $events['voicemail.transcribed']['transcript']);
            $this->assertCount(1, $summaries);
            $value = reset($summaries);
            $this->assertSame(trim($events['voicemail.transcribed']['transcript']), $value['transcript']);
            $this->assertSame('Send the contract tomorrow.', $value['summary']);
        } finally {
            foreach ($files as $file) { unlink($file); }
            rmdir($root . '/src/services'); rmdir($root . '/src'); rmdir($root . '/bin'); rmdir($root);
        }
    }
}
