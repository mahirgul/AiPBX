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
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup',
            'invalid_dest_id' => 0,
        ];

        $conf = buildIVRDialplanBlock($ivr, [], []);

        $this->assertStringContainsString('Set(IVR2FAILS=$[0${IVR2FAILS} + 1])', $conf);
        $this->assertStringContainsString('GotoIf($[0${IVR2FAILS} < 5]?s,1)', $conf);
    }
}
