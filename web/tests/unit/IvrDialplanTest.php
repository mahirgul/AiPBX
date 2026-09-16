<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/config.php';
require_once '/var/www/html/src/file_helper.php';
require_once '/var/www/html/src/sync/DialplanBuilders.php';
require_once '/var/www/html/src/sync/SyncIVRs.php';

final class IvrDialplanTest extends TestCase
{
    public function testIvrDialplanFailureCounterSyntax(): void
    {
        $ivr = [
            'id' => 1,
            'title' => 'Main IVR',
            'prompt_file' => 'custom/welcome',
            'timeout_seconds' => 10,
            'max_failures' => 3,
            'language' => 'tr',
            'allow_direct_dial' => 0,
            'digit_timeout' => 3,
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup',
            'invalid_dest_id' => 0,
        ];

        $entries = [
            ['digit' => '1', 'dest_type' => 'hangup', 'dest_id' => 0]
        ];

        $conf = buildIVRDialplanBlock($ivr, $entries, []);

        // 1. Bash parametre genislemesi (${VAR:-0}) Asterisk'te soz dizimi hatasina
        // (ast_expr2 unexpected '+') yol acar. Bu yuzden kesinlikle :- barindirmamali.
        $this->assertStringNotContainsString(':-', $conf,
            'IVR dialplaninda Asterisk tarafindan desteklenmeyen :- parametre genislemesi bulunmamali');

        // 2. Asterisk icin gecerli basarisizlik sayaci ve kontrolu
        $this->assertStringContainsString('Set(IVR1FAILS=$[0${IVR1FAILS} + 1])', $conf,
            'IVR basarisizlik sayaci Set(IVR1FAILS=$[0${IVR1FAILS} + 1]) seklinde olmali');
        $this->assertStringContainsString('GotoIf($[0${IVR1FAILS} < 3]?s,1)', $conf,
            'IVR esik kontrolu GotoIf($[0${IVR1FAILS} < 3]?s,1) seklinde olmali');
    }

    public function testIvrDialplanCustomMaxFailures(): void
    {
        $ivr = [
            'id' => 2,
            'title' => 'Support IVR',
            'prompt_file' => 'custom/support',
            'timeout_seconds' => 5,
            'max_failures' => 5,
            'language' => 'en',
            'allow_direct_dial' => 0,
            'digit_timeout' => 3,
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup',
            'invalid_dest_id' => 0,
        ];

        $conf = buildIVRDialplanBlock($ivr, [], []);

        $this->assertStringContainsString('Set(IVR2FAILS=$[0${IVR2FAILS} + 1])', $conf);
        $this->assertStringContainsString('GotoIf($[0${IVR2FAILS} < 5]?s,1)', $conf);
    }

    public function testIvrDirectDialDisabled(): void
    {
        $ivr = [
            'id' => 3,
            'title' => 'Direct Dial Off IVR',
            'prompt_file' => 'custom/welcome',
            'timeout_seconds' => 10,
            'max_failures' => 3,
            'language' => '',
            'allow_direct_dial' => 0,
            'digit_timeout' => 3,
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup',
            'invalid_dest_id' => 0,
        ];

        $exts = [
            ['extension' => '1000', 'full_name' => 'Ahmet Yilmaz'],
            ['extension' => '2000', 'full_name' => 'Mehmet Demir']
        ];

        $conf = buildIVRDialplanBlock($ivr, [], $exts);

        $this->assertStringNotContainsString('exten => 1000', $conf,
            'allow_direct_dial=0 iken dahili numaralar IVR dialplanina eklenmemeli');
        $this->assertStringNotContainsString('exten => 2000', $conf);
    }

    public function testIvrDirectDialEnabledWithConfigurableTimeout(): void
    {
        $ivr = [
            'id' => 4,
            'title' => 'Direct Dial On IVR',
            'prompt_file' => 'custom/welcome',
            'timeout_seconds' => 15,
            'max_failures' => 3,
            'language' => '',
            'allow_direct_dial' => 1,
            'digit_timeout' => 5, // 5 saniye tuşlama bekleme süresi
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup',
            'invalid_dest_id' => 0,
        ];

        $entries = [
            ['digit' => '1', 'dest_type' => 'hangup', 'dest_id' => 0]
        ];

        $exts = [
            ['extension' => '1', 'full_name' => 'Cakisan Dahili 1'],
            ['extension' => '1000', 'full_name' => 'Dahili 1000'],
            ['extension' => '2000', 'full_name' => 'Dahili 2000']
        ];

        $internals = [
            ['number' => '8000', 'label' => 'Satis Kuyrugu']
        ];

        $conf = buildIVRDialplanBlock($ivr, $entries, $exts, $internals);

        // 1. Ayarlanan digit_timeout (5 sn) TIMEOUT(digit)'e yazılmalı
        $this->assertStringContainsString('Set(TIMEOUT(digit)=5)', $conf,
            'Ozel digit_timeout degeri Set(TIMEOUT(digit)=X) satirina yansitilmali');
        $this->assertStringContainsString('Set(TIMEOUT(response)=15)', $conf);

        // 2. Doğrudan dahili arama satırları
        $this->assertStringContainsString('exten => 1000,1,NoOp(IVR 4 Direct Dial to Extension 1000)', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,1000,1)', $conf);
        $this->assertStringContainsString('exten => 2000,1,NoOp(IVR 4 Direct Dial to Extension 2000)', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,2000,1)', $conf);

        // 3. Dahili hedef numaraları (queue/ring group vs) da dahil olmalı
        $this->assertStringContainsString('exten => 8000,1,NoOp(IVR 4 Direct Dial to Internal Target 8000)', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,8000,1)', $conf);

        // 4. Menü tuşu '1' ile çakışan dahili '1' atlanmalı (Reload çakışmasını engellemek için)
        $this->assertStringNotContainsString('Direct Dial to Extension 1)', $conf);
    }

    public function testIvrDirectDialWithOutboundRoutes(): void
    {
        $ivr = [
            'id' => 5,
            'title' => 'Outbound Route Direct Dial IVR',
            'prompt_file' => 'custom/welcome',
            'timeout_seconds' => 10,
            'max_failures' => 3,
            'language' => '',
            'allow_direct_dial' => 1,
            'digit_timeout' => 4,
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup',
            'invalid_dest_id' => 0,
        ];

        $entries = [
            ['digit' => '1', 'dest_type' => 'hangup', 'dest_id' => 0]
        ];

        $exts = [
            ['extension' => '1000', 'full_name' => 'Dahili 1000']
        ];

        $routes = [
            ['match_pattern' => '_[4-9]XXX', 'route_name' => 'dahili', 'is_internal' => 1],
            ['match_pattern' => '9999', 'route_name' => 'Ozel Santral', 'is_internal' => 1],
            ['match_pattern' => '_X.', 'route_name' => 'Genel Rota', 'is_internal' => 1], // Catch-all atlanmali
            ['match_pattern' => '1', 'route_name' => 'Cakisan Rota', 'is_internal' => 1],   // Menu tusu '1' ile cakisan atlanmali
        ];

        $conf = buildIVRDialplanBlock($ivr, $entries, $exts, [], $routes);

        // 1. Dahili santral rotaları (örn. 9998'in eşleştiği _[4-9]XXX) dialplan'a eklenmeli
        $this->assertStringContainsString('exten => _[4-9]XXX,1,NoOp(IVR 5 Direct Dial to Outbound Route dahili: ${EXTEN})', $conf);
        $this->assertStringContainsString('exten => 9999,1,NoOp(IVR 5 Direct Dial to Outbound Route Ozel Santral: ${EXTEN})', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,${EXTEN},1)', $conf);

        // 2. Tehlikeli catch-all (_X.) IVR menü tuşlarını bozmaması için eklenmemeli
        $this->assertStringNotContainsString('exten => _X.', $conf);

        // 3. Menü seçeneği (1) ile çakışan rota deseni atlanmalı
        $this->assertStringNotContainsString('Direct Dial to Outbound Route Cakisan Rota', $conf);
    }
}

