<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/OllamaService.php';
require_once __DIR__ . '/../../src/services/LocalAiService.php';

final class OllamaServiceTest extends TestCase
{
    protected function tearDown(): void { TtsHttp::$transport = null; }

    public function testNativeModelsAreUsableWithoutSpeechRuntime(): void
    {
        $calls = [];
        TtsHttp::$transport = static function ($method, $url, $headers, $body) use (&$calls): array {
            $calls[] = [$method, $url, $body];
            $data = str_ends_with($url, '/api/tags')
                ? ['models' => [['name' => 'local:test', 'size' => 100], ['name' => 'remote:cloud'],
                    ['name' => 'hidden-remote', 'remote_host' => 'https://example.com']]]
                : ['message' => ['content' => 'Local answer']];
            return ['status' => 200, 'body' => json_encode($data), 'type' => 'application/json'];
        };
        $models = OllamaService::models();
        $this->assertCount(1, $models);
        $id = $models[0]['id'];
        $this->assertSame('Local answer', LocalAiService::chat($id, 'Question'));
        $this->assertSame('http://127.0.0.1:11434/api/chat', $calls[array_key_last($calls)][1]);
        $this->assertSame('local:test', json_decode($calls[array_key_last($calls)][2], true)['model']);
        $this->assertTrue(LocalAiService::validId($id));
    }

    public function testUnknownModelNeverReachesGenerationEndpoint(): void
    {
        $calls = [];
        TtsHttp::$transport = static function ($method, $url) use (&$calls): array {
            $calls[] = $url;
            return ['status' => 200, 'body' => '{"models":[]}', 'type' => 'application/json'];
        };
        try {
            OllamaService::chat('ollama-unknown', 'Question');
            $this->fail('unknown model was accepted');
        } catch (TtsException $e) {
            $this->assertSame(['http://127.0.0.1:11434/api/tags'], $calls);
        }
    }
}
