<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/AiTtsService.php';

/**
 * AI → Local models: the client of the local AI service (aipbx-ai) and the
 * EMA Lightning TTS provider, against a fake HTTP transport.
 */
final class LocalAiServiceTest extends TestCase
{
    private string $tokenFile;
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->tokenFile = tempnam(sys_get_temp_dir(), 'aitok');
        file_put_contents($this->tokenFile, str_repeat('ab', 32) . "\n");
        putenv('AIPBX_AI_TOKEN_FILE=' . $this->tokenFile);
        $this->resetCache();
    }

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        putenv('AIPBX_AI_TOKEN_FILE');
        @unlink($this->tokenFile);
        $this->resetCache();
    }

    private function resetCache(): void
    {
        (new ReflectionProperty(LocalAiService::class, 'modelsCache'))->setValue(null, null);
    }

    /** @param callable(string, string, ?string): array $answer */
    private function fake(callable $answer): void
    {
        TtsHttp::$transport = function (string $method, string $url, array $headers, ?string $body) use ($answer) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            return $answer($method, $url, $body);
        };
    }

    private static function json(array $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($data), 'type' => 'application/json'];
    }

    public function testRuntimeStatusIsNormalised(): void
    {
        $this->assertSame('absent', LocalAiService::parseRuntime('')['state']);
        $this->assertSame('absent', LocalAiService::parseRuntime('{"state":"weird"}')['state']);
        $r = LocalAiService::parseRuntime('{"state":"failed","step":"pip","error":"no network","started":5}');
        $this->assertSame(['failed', 'pip', 'no network', 5, null], [$r['state'], $r['step'], $r['error'], $r['started'], $r['finished']]);
    }

    public function testModelsCarryTheTokenAndDropBadIds(): void
    {
        $this->fake(fn() => self::json(['models' => [
            ['id' => 'ema-lightning', 'state' => 'ready'],
            ['id' => '../etc', 'state' => 'ready'],
        ]]));
        $models = LocalAiService::models();
        $this->assertSame(['ema-lightning'], array_column($models, 'id'));
        $this->assertSame('Bearer ' . str_repeat('ab', 32), $this->calls[0]['headers']['Authorization']);
        $this->assertSame('http://127.0.0.1:8790/v1/models', $this->calls[0]['url']);
        $this->assertTrue(LocalAiService::ready('ema-lightning'));
    }

    public function testServiceDownMeansNoModelsAndProviderNotConfigured(): void
    {
        $this->fake(function () { throw new TtsException('connection failed'); });
        $this->assertSame([], LocalAiService::models());
        $this->assertNull(LocalAiService::health());
        $this->assertFalse((new LocalEmaTts([]))->configured());
    }

    public function testMissingTokenFile(): void
    {
        putenv('AIPBX_AI_TOKEN_FILE=/nonexistent/ai.token');
        $this->fake(fn() => self::json(['models' => []]));
        $this->assertSame([], LocalAiService::models());
        $this->assertSame([], $this->calls, 'no request without a token');
    }

    public function testInstallNeedsTheLicence(): void
    {
        $this->fake(fn() => self::json(['state' => 'downloading'], 202));
        try {
            LocalAiService::installModel('ema-lightning', false);
            $this->fail('installed without licence');
        } catch (LocalAiException $e) {
            $this->assertSame([], $this->calls);
        }
        $_SESSION['user_id'] = null;
        LocalAiService::installModel('ema-lightning', true);
        $this->assertSame('{"accept_license":true}', $this->calls[0]['body']);
        $this->assertStringEndsWith('/v1/models/ema-lightning/install', $this->calls[0]['url']);
    }

    public function testBadModelIdNeverReachesTheService(): void
    {
        $this->fake(fn() => self::json([]));
        $this->expectException(LocalAiException::class);
        try {
            LocalAiService::removeModel('../../x');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function testTtsErrorsAreReadable(): void
    {
        $this->fake(fn() => self::json(['error' => 'model not ready'], 409));
        try {
            LocalAiService::tts('ema-lightning', 'Merhaba', 1.0);
            $this->fail('no exception');
        } catch (LocalAiException $e) {
            $this->assertSame('model not ready', $e->getMessage());
        }
        $this->expectException(LocalAiException::class);
        LocalAiService::tts('ema-lightning', 'Merhaba', 1.0, 11025);
    }

    public function testProviderTurnsWavIntoMp3(): void
    {
        if (trim((string) shell_exec('command -v lame')) === '') {
            $this->markTestSkipped('lame not installed');
        }
        $pcm = str_repeat(pack('v', 0), 2400);
        $wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
        $this->fake(fn($m, $url) => str_ends_with($url, '/v1/tts') ? ['status' => 200, 'body' => $wav, 'type' => 'audio/wav'] : self::json([]));
        $mp3 = (new LocalEmaTts([]))->synthesize('Merhaba', LocalEmaTts::VOICE, 'tr-TR', 1.0);
        $this->assertNotSame('', $mp3);
        $this->assertNotSame('RIFF', substr($mp3, 0, 4));
        $req = json_decode((string) $this->calls[0]['body'], true);
        $this->assertSame(['ema-lightning', 'Merhaba', 24000], [$req['model'], $req['text'], $req['sample_rate']]);
    }
}
