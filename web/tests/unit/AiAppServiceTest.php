<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiAppService.php';
require_once __DIR__ . '/../../src/sync/SyncAiApps.php';

/** AI → Applications: validation, text encoding for the dialplan, the generated contexts. */
final class AiAppServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM pbx_ai_apps WHERE title LIKE 'Test app%'");
        getDB()->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'ai_app'");
    }

    private static function form(array $over = []): array
    {
        return $over + ['title' => 'Test app', 'model_id' => 'ema-lightning', 'speed' => '1',
            'text_template' => 'Sayın {caller_name}, borcunuz {amount} liradır.', 'dest_type' => 'hangup',
            'dest_id' => '', 'max_concurrent' => '4', 'is_active' => '1', 'internal_number' => ''];
    }

    public function testValidation(): void
    {
        $v = AiAppService::validate(self::form(['speed' => '9', 'max_concurrent' => '500']));
        $this->assertSame(['2', '50'], [$v['speed'], $v['max_concurrent']]);
        foreach ([['title' => ''], ['model_id' => '../x'], ['text_template' => ''], ['lookup_url' => 'ftp://x/'],
                  ['text_template' => str_repeat('a', 2001)], ['dest_type' => 'Bad Type']] as $bad) {
            try {
                AiAppService::validate(self::form($bad));
                $this->fail('accepted ' . json_encode($bad));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame('engine:tr', AiAppService::validate(self::form(['model_id' => 'engine:tr']))['model_id']);
    }

    public function testDialplanEncodingKeepsTheParserSafe(): void
    {
        $enc = AiAppService::dialplanEncode('Sayın {caller_name}; borcunuz (1,5) {amount} $ liradır, {did}.');
        $this->assertStringNotContainsString(';', $enc);
        $this->assertStringNotContainsString(',', str_replace(['${URIENCODE(${CALLERID(name)})}', '${URIENCODE(${CDR(did)})}'], '', $enc));
        $this->assertStringContainsString('${URIENCODE(${CALLERID(name)})}', $enc);
        $this->assertStringContainsString('%7Bamount%7D', $enc);   // lookup values stay for the service
        $this->assertSame(1, substr_count($enc, '${URIENCODE(${CDR(did)})}'));
    }

    public function testDialplan(): void
    {
        $conf = buildAiAppsDialplan([['id' => 7, 'title' => 'Borç', 'model_id' => 'ema-lightning', 'speed' => '1.10',
            'max_concurrent' => 3, 'text_template' => 'Merhaba {caller_name}', 'lookup_url' => 'https://crm.example/x?a=1',
            'fallback_text' => '', 'dest_type' => 'hangup', 'dest_id' => '']], str_repeat('ab', 32));
        $this->assertStringContainsString('[aipbx-ai-app-7]', $conf);
        $this->assertStringContainsString('Set(AI_CALL=${CURL(http://127.0.0.1:8790/v1/calls/start,app=7&key=' . str_repeat('ab', 32) . '&model=ema-lightning&speed=1.10&max=3&', $conf);
        $this->assertStringContainsString('&lookup=https%3A%2F%2Fcrm.example%2Fx%3Fa%3D1&', $conf);
        $this->assertStringContainsString('AudioSocket(${AI_CALL},127.0.0.1:8791)', $conf);
        $this->assertStringContainsString('Set(CURLOPT(httptimeout)=5)', $conf);
        // Braces and brackets of the text never reach the dialplan raw (Asterisk counts them).
        $odd = buildAiAppsDialplan([['id' => 8, 'title' => 'x', 'model_id' => 'ema-lightning', 'speed' => '1', 'max_concurrent' => 1,
            'text_template' => 'a ( b { c ) d } e', 'lookup_url' => '', 'fallback_text' => '', 'dest_type' => 'hangup', 'dest_id' => '']], 'k');
        $text = explode('&text=', $odd)[1];
        $this->assertSame(0, preg_match('/[(){}]/', explode('&lookup=', $text)[0]));
        $this->assertMatchesRegularExpression('/same => n\(done\),NoOp/', $conf);
        // Without the service's key the calls go straight on.
        $off = buildAiAppsDialplan([['id' => 7, 'title' => 'x', 'model_id' => 'ema-lightning', 'speed' => '1', 'max_concurrent' => 3,
            'text_template' => 'x', 'lookup_url' => '', 'fallback_text' => '', 'dest_type' => 'hangup', 'dest_id' => '']], '');
        $this->assertStringNotContainsString('CURL(', $off);
        $this->assertStringContainsString('[aipbx-ai-app-7]', $off);
    }

    public function testDestinationLinesAndNumber(): void
    {
        $this->assertStringContainsString('Goto(aipbx-ai-app-12,s,1)', buildDestinationLines('ai_app', '12'));
        $_SESSION['user_id'] = null;
        $id = AiAppService::save(self::form(['internal_number' => '7299']));
        $this->assertSame('7299', AiAppService::get($id)['internal_number']);
        $this->expectException(\Exception::class);
        AiAppService::save(self::form(['title' => 'Test app 2', 'internal_number' => '7299']));
    }

    public function testSelfDestinationRefused(): void
    {
        $this->expectException(\Exception::class);
        AiAppService::validate(self::form(['dest_type' => 'ai_app', 'dest_id' => '5']), 5);
    }
}
