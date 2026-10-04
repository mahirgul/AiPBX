<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/secret_box.php';
require_once dirname(__DIR__, 2) . '/src/services/tts/TtsProviders.php';

/**
 * Cloud TTS clients: the requests they build and how they read answers.
 * No network: TtsHttp::$transport records requests and returns canned
 * answers shaped like each provider's documented responses.
 */
final class TtsProvidersTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    private array $sent = [];

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        SecretBox::useKey(null);
    }

    private function answer(int $status, string $body, string $type = 'application/json'): void
    {
        $this->sent = [];
        TtsHttp::$transport = function ($method, $url, $headers, $reqBody) use ($status, $body, $type) {
            $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $reqBody];
            return ['status' => $status, 'body' => $body, 'type' => $type];
        };
    }

    /** AWS's published SigV4 test vector "get-vanilla". */
    public function testSigV4MatchesAwsTestSuite(): void
    {
        $h = AwsSigV4::sign('GET', 'https://example.amazonaws.com/', [], '', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service', gmmktime(12, 36, 0, 8, 30, 2015));
        $this->assertSame('20150830T123600Z', $h['x-amz-date']);
        $this->assertSame('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $h['Authorization']);
    }

    public function testSecretBox(): void
    {
        SecretBox::useKey(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $enc = SecretBox::encrypt('AIzaSy-secret-1234');
        $this->assertStringStartsWith('sb1:', $enc);
        $this->assertStringNotContainsString('secret', $enc);
        $this->assertSame('AIzaSy-secret-1234', SecretBox::decrypt($enc));
        $this->assertNotSame($enc, SecretBox::encrypt('AIzaSy-secret-1234'), 'random nonce');
        SecretBox::useKey(str_repeat('x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->assertSame('', SecretBox::decrypt($enc), 'another key cannot read it');
        $this->assertSame('••••1234', SecretBox::mask('AIzaSy-secret-1234'));
        $this->assertSame('', SecretBox::decrypt('plain'));
    }

    public function testGoogle(): void
    {
        $g = new GoogleTts(['api_key' => 'KEY1']);
        $this->answer(200, json_encode(['voices' => [['name' => 'tr-TR-Wavenet-A', 'languageCodes' => ['tr-TR'], 'ssmlGender' => 'FEMALE']]]));
        $this->assertSame([['id' => 'tr-TR-Wavenet-A', 'name' => 'tr-TR-Wavenet-A', 'language' => 'tr-TR', 'gender' => 'female']], $g->voices('tr-TR'));
        $this->assertStringContainsString('/v1/voices?key=KEY1&languageCode=tr-TR', $this->sent[0]['url']);

        $this->answer(200, json_encode(['audioContent' => base64_encode('MP3DATA')]));
        $this->assertSame('MP3DATA', $g->synthesize('Merhaba dünya', 'tr-TR-Wavenet-A', 'tr-TR', 1.1));
        $req = json_decode($this->sent[0]['body'], true);
        $this->assertSame(['text' => 'Merhaba dünya'], $req['input']);
        $this->assertSame('MP3', $req['audioConfig']['audioEncoding']);
        $this->assertSame(1.1, $req['audioConfig']['speakingRate']);

        $this->answer(403, json_encode(['error' => ['message' => 'API key not valid.']]));
        try {
            $g->voices();
            $this->fail('expected an error');
        } catch (TtsException $e) {
            $this->assertSame('Google 403: API key not valid.', $e->getMessage(), 'the provider\'s own message reaches the user');
        }
    }

    public function testPolly(): void
    {
        $p = new PollyTts(['access_key' => 'AKID', 'secret_key' => 'SECRET', 'region' => 'eu-central-1']);
        $this->answer(200, json_encode(['Voices' => [['Id' => 'Filiz', 'Name' => 'Filiz', 'LanguageCode' => 'tr-TR', 'Gender' => 'Female', 'SupportedEngines' => ['standard']], ['Id' => 'Burcu', 'Name' => 'Burcu', 'LanguageCode' => 'tr-TR', 'Gender' => 'Female', 'SupportedEngines' => ['neural']]]]));
        $this->assertSame(['Filiz|standard', 'Burcu|neural'], array_column($p->voices('tr-TR'), 'id'));
        $req = $this->sent[0];
        $this->assertStringStartsWith('https://polly.eu-central-1.amazonaws.com/v1/voices?', $req['url']);
        $this->assertStringContainsString('Credential=AKID/', $req['headers']['Authorization']);
        $this->assertStringContainsString('/eu-central-1/polly/aws4_request', $req['headers']['Authorization']);

        $this->answer(200, 'ID3audio', 'audio/mpeg');
        $this->assertSame('ID3audio', $p->synthesize('A & B <c>', 'Burcu|neural', 'tr-TR', 0.9));
        $body = json_decode($this->sent[0]['body'], true);
        $this->assertSame(['neural', 'Burcu', 'mp3', 'ssml'], [$body['Engine'], $body['VoiceId'], $body['OutputFormat'], $body['TextType']]);
        $this->assertSame('<speak><prosody rate="90%">A &amp; B &lt;c&gt;</prosody></speak>', $body['Text'], 'text is escaped inside SSML');

        $this->expectException(TtsException::class);
        (new PollyTts(['access_key' => 'a', 'secret_key' => 'b', 'region' => 'evil.com/x']))->voices();
    }

    public function testAzure(): void
    {
        $a = new AzureTts(['api_key' => 'SK', 'region' => 'westeurope']);
        $this->answer(200, json_encode([['ShortName' => 'tr-TR-EmelNeural', 'LocalName' => 'Emel', 'Locale' => 'tr-TR', 'Gender' => 'Female'], ['ShortName' => 'en-US-JennyNeural', 'LocalName' => 'Jenny', 'Locale' => 'en-US', 'Gender' => 'Female']]));
        $this->assertSame(['tr-TR-EmelNeural'], array_column($a->voices('tr-TR'), 'id'));
        $this->assertSame('SK', $this->sent[0]['headers']['Ocp-Apim-Subscription-Key']);

        $this->answer(200, 'audio', 'audio/mpeg');
        $a->synthesize('Merhaba', 'tr-TR-EmelNeural', 'tr-TR', 1.25);
        $this->assertSame('https://westeurope.tts.speech.microsoft.com/cognitiveservices/v1', $this->sent[0]['url']);
        $this->assertStringContainsString("<voice name='tr-TR-EmelNeural'><prosody rate='+25%'>Merhaba</prosody>", $this->sent[0]['body']);
        $this->assertStringContainsString('mp3', $this->sent[0]['headers']['X-Microsoft-OutputFormat']);
    }

    public function testElevenLabsAndOpenAi(): void
    {
        $e = new ElevenLabsTts(['api_key' => 'XI']);
        $this->answer(200, 'mp3', 'audio/mpeg');
        $e->synthesize('Merhaba', 'abcdefgh12345678', 'tr-TR', 1.0);
        $this->assertStringContainsString('/v1/text-to-speech/abcdefgh12345678?output_format=mp3', $this->sent[0]['url']);
        $this->assertSame('eleven_multilingual_v2', json_decode($this->sent[0]['body'], true)['model_id']);

        $o = new OpenAiTts(['api_key' => 'sk-1']);
        $this->assertContains('nova', array_column($o->voices(), 'id'));
        $this->answer(200, 'mp3', 'audio/mpeg');
        $o->synthesize('Merhaba', 'nova', 'tr-TR', 1.0);
        $this->assertSame('Bearer sk-1', $this->sent[0]['headers']['Authorization']);
        $this->answer(401, json_encode(['error' => ['message' => 'Incorrect API key provided']]));
        $this->expectExceptionMessage('OpenAI 401: Incorrect API key provided');
        $o->synthesize('Merhaba', 'nova', 'tr-TR', 1.0);
    }

    public function testConfiguredNeedsRequiredFields(): void
    {
        $this->assertFalse((new PollyTts(['access_key' => 'a', 'region' => 'eu-central-1']))->configured());
        $this->assertTrue((new PollyTts(['access_key' => 'a', 'secret_key' => 'b', 'region' => 'eu-central-1']))->configured());
        $this->assertTrue((new OpenAiTts(['api_key' => 'k']))->configured(), 'model is optional');
    }
}
