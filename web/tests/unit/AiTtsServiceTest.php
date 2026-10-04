<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/AiTtsService.php';

/**
 * Cloud TTS service: settings stay encrypted and masked, long texts are
 * split per provider limit, results are kept and can become announcements.
 * The provider is faked (TtsHttp::$transport); paths are the test temp dirs.
 */
final class AiTtsServiceTest extends TestCase
{
    private static string $mp3 = '';
    private int $calls = 0;

    public static function setUpBeforeClass(): void
    {
        // A real (short) MP3 when sox can write one, so durations and WAV conversion run for real.
        $f = sys_get_temp_dir() . '/aipbx-tts-test.mp3';
        @exec('sox -n -r 22050 -c 1 ' . escapeshellarg($f) . ' synth 0.4 sine 440 2>/dev/null', $o, $ret);
        self::$mp3 = ($ret === 0 && is_file($f)) ? (string) file_get_contents($f) : "ID3\x03fake";
        @unlink($f);
    }

    protected function setUp(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.%'");
        $db->exec("DELETE FROM ai_tts_history");
        $db->exec("DELETE FROM pbx_announcements WHERE audio_file LIKE 'custom/tts_test%'");
        $this->calls = 0;
        TtsHttp::$transport = function ($method, $url, $headers, $body) {
            $this->calls++;
            if (str_contains($url, '/voices')) {
                return ['status' => 200, 'type' => 'application/json', 'body' => json_encode(['voices' => [['name' => 'tr-TR-Standard-A', 'languageCodes' => ['tr-TR'], 'ssmlGender' => 'FEMALE']]])];
            }
            return ['status' => 200, 'type' => 'application/json', 'body' => json_encode(['audioContent' => base64_encode(self::$mp3)])];
        };
    }

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.%'");
    }

    public function testChunksStayWithinTheLimitAndKeepTheText(): void
    {
        $text = str_repeat('Bu bir cümledir. ', 40) . str_repeat('uzun', 300);
        $chunks = AiTtsService::chunks($text, 200);
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(200, mb_strlen($c));
        }
        $this->assertSame(preg_replace('/\s+/', '', $text), preg_replace('/\s+/', '', implode('', $chunks)), 'nothing lost or added');
        $this->assertStringEndsWith('.', $chunks[0], 'cut at a sentence end');
        $this->assertSame(['Kısa metin.'], AiTtsService::chunks('  Kısa metin.  ', 200));
        $this->assertSame([], AiTtsService::chunks('   ', 200));
    }

    public function testSettingsAreEncryptedAndNeverSentToThePage(): void
    {
        AiTtsService::saveProvider('google', ['api_key' => 'AIzaSyTESTKEY987654']);
        $raw = (string) getSystemSetting('ai_tts.google.api_key', '');
        $this->assertStringStartsWith('sb1:', $raw);
        $this->assertStringNotContainsString('TESTKEY', $raw);
        $this->assertSame('AIzaSyTESTKEY987654', AiTtsService::config('google')['api_key']);

        $page = json_encode(AiTtsService::providersForPage());
        $this->assertStringNotContainsString('TESTKEY', $page, 'the secret never reaches the browser');
        $this->assertStringContainsString('7654', $page, 'only its last digits, masked');
        $this->assertSame(['google'], AiTtsService::configuredProviders());

        AiTtsService::saveProvider('google', ['api_key' => '']);
        $this->assertSame('AIzaSyTESTKEY987654', AiTtsService::config('google')['api_key'], 'an empty field keeps the stored key');
        AiTtsService::saveProvider('google', ['clear' => '1']);
        $this->assertSame([], AiTtsService::configuredProviders());
    }

    public function testSynthesizeStoresTheResultAndSplitsLongText(): void
    {
        AiTtsService::saveProvider('google', ['api_key' => 'k']);
        $row = AiTtsService::synthesize('google', 'tr-TR-Standard-A', 'tr-TR', 'Merhaba, AiPBX santraline hoş geldiniz.', 1.0);
        $this->assertSame(1, $this->calls);
        $this->assertSame('google', $row['provider']);
        $this->assertSame(39, (int) $row['chars']);
        $this->assertStringEqualsFile(AiTtsService::audioPath((int) $row['id']), self::$mp3);

        $this->calls = 0;
        $long = str_repeat('Bir cümle daha geliyor. ', 400);   // > Google's 4500 limit
        AiTtsService::synthesize('google', 'tr-TR-Standard-A', 'tr-TR', $long, 1.0);
        $this->assertGreaterThan(1, $this->calls, 'split into several requests');

        $this->expectException(TtsException::class);
        AiTtsService::synthesize('polly', 'Filiz|standard', 'tr-TR', 'x', 1.0);   // not configured
    }

    public function testSaveAsAnnouncement(): void
    {
        if (!str_starts_with(self::$mp3, "\xFF") && !str_starts_with(self::$mp3, 'ID3') || strlen(self::$mp3) < 100) {
            $this->markTestSkipped('sox cannot write MP3 here');
        }
        AiTtsService::saveProvider('google', ['api_key' => 'k']);
        $row = AiTtsService::synthesize('google', 'tr-TR-Standard-A', 'tr-TR', 'Mesai saatleri dışındayız.', 1.0);
        $file = AiTtsService::saveAsAnnouncement((int) $row['id'], 'tts_test_mesai', 'Mesai dışı');
        $this->assertSame('custom/tts_test_mesai', $file);
        $wav = SOUNDS_CUSTOM_DIR . '/tts_test_mesai.wav';
        $this->assertFileExists($wav);
        $this->assertStringContainsString('8000', (string) shell_exec('soxi -r ' . escapeshellarg($wav)), 'Asterisk format: 8 kHz');
        $this->assertSame('Mesai dışı', getDB()->query("SELECT title FROM pbx_announcements WHERE audio_file = 'custom/tts_test_mesai'")->fetchColumn());
        $this->assertSame('custom/tts_test_mesai', AiTtsService::get((int) $row['id'])['announcement']);
        @unlink($wav);

        // Path characters are stripped: the file can only land in the custom sounds folder.
        $this->assertSame('custom/tts_testetcpasswd', AiTtsService::saveAsAnnouncement((int) $row['id'], 'tts_test../../etc/passwd', 'x'));
        $this->assertFileExists(SOUNDS_CUSTOM_DIR . '/tts_testetcpasswd.wav');
        @unlink(SOUNDS_CUSTOM_DIR . '/tts_testetcpasswd.wav');

        $this->expectException(TtsException::class);
        AiTtsService::saveAsAnnouncement((int) $row['id'], '../..', 'x');
    }

    /**
     * api/ai_tts.php loads only config.php + auth.php before the service:
     * the service must bring its own dependencies (writeAuditLog was missing
     * there and every save failed half-way). Checked in a fresh process.
     */
    public function testServiceWorksWithWhatTheApiLoads(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/config.php', true) . '; require ' . var_export($root . '/auth.php', true) . ';'
            . ' require ' . var_export($root . '/src/services/AiTtsService.php', true) . ';'
            . ' AiTtsService::saveProvider("openai", ["api_key" => "sk-standalone"]); echo implode(",", AiTtsService::configuredProviders());';
        $env = '';
        foreach (['DB_NAME', 'DB_USER', 'DB_PASS', 'AIPBX_SETTINGS_KEY', 'AI_TTS_DIR', 'SOUNDS_CUSTOM_DIR'] as $k) {
            $env .= $k . '=' . escapeshellarg((string) getenv($k)) . ' ';
        }
        $out = (string) shell_exec($env . 'php -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertStringContainsString('openai', $out, $out);
    }
}
