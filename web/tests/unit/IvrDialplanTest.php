<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/src/file_helper.php';
require_once dirname(__DIR__, 2) . '/src/sync/DialplanBuilders.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncIVRs.php';

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

        // 1. Bash parameter expansion (${VAR:-0}) causes a syntax error in Asterisk
        // (ast_expr2 unexpected '+'). So it must never contain :-.
        $this->assertStringNotContainsString(':-', $conf,
            'the IVR dialplan must not use the :- parameter expansion, which Asterisk does not support');

        // 2. A valid failure counter and check for Asterisk
        $this->assertStringContainsString('Set(IVR1FAILS=$[0${IVR1FAILS} + 1])', $conf,
            'the IVR failure counter must be Set(IVR1FAILS=$[0${IVR1FAILS} + 1])');
        $this->assertStringContainsString('GotoIf($[0${IVR1FAILS} < 3]?s,1)', $conf,
            'the IVR threshold check must be GotoIf($[0${IVR1FAILS} < 3]?s,1)');
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
        // the caller hears that the key was wrong before the menu repeats
        $this->assertStringContainsString("exten => i,1,NoOp(IVR 2 Invalid Digit)\n same => n,Playback(conf-errormenu)\n", $conf);
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
            'with allow_direct_dial=0 extensions must not be added to the IVR dialplan');
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
            'digit_timeout' => 5, // 5 second key-press timeout
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

        // 1. The configured digit_timeout (5 s) must be written to TIMEOUT(digit)
        $this->assertStringContainsString('Set(TIMEOUT(digit)=5)', $conf,
            'a custom digit_timeout must appear in the Set(TIMEOUT(digit)=X) line');
        $this->assertStringContainsString('Set(TIMEOUT(response)=15)', $conf);

        // 2. Direct extension dialing lines
        $this->assertStringContainsString('exten => 1000,1,NoOp(IVR 4 Direct Dial to Extension 1000)', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,1000,1)', $conf);
        $this->assertStringContainsString('exten => 2000,1,NoOp(IVR 4 Direct Dial to Extension 2000)', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,2000,1)', $conf);

        // 3. Internal destination numbers (queue/ring group etc.) must be included too
        $this->assertStringContainsString('exten => 8000,1,NoOp(IVR 4 Direct Dial to Internal Target 8000)', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,8000,1)', $conf);

        // 4. Extension '1', colliding with menu key '1', must be skipped (to prevent a reload conflict)
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
            ['match_pattern' => '1', 'route_name' => 'Clashing route', 'is_internal' => 1],   // clashes with menu key '1': must be skipped
        ];

        $conf = buildIVRDialplanBlock($ivr, $entries, $exts, [], $routes);

        // 1. Internal PBX routes (e.g. _[4-9]XXX, which 9998 matches) must be added to the dialplan
        $this->assertStringContainsString('exten => _[4-9]XXX,1,NoOp(IVR 5 Direct Dial to Outbound Route dahili: ${EXTEN})', $conf);
        $this->assertStringContainsString('exten => 9999,1,NoOp(IVR 5 Direct Dial to Outbound Route Ozel Santral: ${EXTEN})', $conf);
        $this->assertStringContainsString(' same => n,Goto(from-internal-pbx,${EXTEN},1)', $conf);

        // 2. The dangerous catch-all (_X.) must not be added, so it does not break the IVR menu keys
        $this->assertStringNotContainsString('exten => _X.', $conf);

        // 3. A route pattern colliding with menu option (1) must be skipped
        $this->assertStringNotContainsString('Direct Dial to Outbound Route Cakisan Rota', $conf);
    }

    public function testNoKeyRepeatsTheMenuWhenAskedThenGoesToTheTimeoutDestination(): void
    {
        $ivr = ['id' => 4, 'title' => 'Repeat', 'prompt_file' => 'custom/welcome', 'timeout_seconds' => 5, 'max_failures' => 3,
            'language' => '', 'allow_direct_dial' => 0, 'digit_timeout' => 3, 'timeout_dest_type' => 'hangup', 'timeout_dest_id' => 0,
            'invalid_dest_type' => 'hangup', 'invalid_dest_id' => 0, 'timeout_repeat' => 1];
        $conf = buildIVRDialplanBlock($ivr, [], []);
        $t = substr($conf, strpos($conf, 'exten => t,1'));
        $this->assertStringContainsString("same => n,Set(IVR4FAILS=\$[0\${IVR4FAILS} + 1])\n same => n,GotoIf(\$[0\${IVR4FAILS} >= 3]?tdest)\n same => n,Playback(please-try-again)\n same => n,Goto(s,1)\n same => n(tdest)", $t);

        $ivr['timeout_repeat'] = 0;
        $this->assertStringNotContainsString('please-try-again', buildIVRDialplanBlock($ivr, [], []), 'off: straight to the timeout destination');
    }
}
