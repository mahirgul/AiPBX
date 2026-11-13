<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/AdvancedPjsipService.php';

final class AdvancedPjsipTest extends TestCase
{
    public function testHelpOutputGivesTheOptionNames(): void
    {
        $help = "\x1b[1;35m[Synopsis]\x1b[0m\nendpoint -- not this one\n\x1b[1;35m[Config Options]\x1b[0m\n100rel                    -- Allow support\nallow                     -- Codecs\n";
        $this->assertSame(['100rel', 'allow'], AdvancedPjsipService::parseHelpOptions($help));
        $this->assertSame([], AdvancedPjsipService::parseHelpOptions('no such thing'));
    }

    public function testUnknownOptionsAreRefusedBecauseTheyLoseTheObjectAtRestart(): void
    {
        $check = AdvancedPjsipService::checkOptions("language=de\nbogus_option=1", ['language']);
        $this->assertSame(['language=de'], $check['lines']);
        $this->assertCount(1, $check['errors']);
        $this->assertStringContainsString('bogus_option', $check['errors'][0]);
    }

    /** @return array<string, array{string}> */
    public static function refusedLines(): array
    {
        return [
            'include' => ['#include "/etc/passwd"'],
            'exec' => ['#exec /bin/sh'],
            'another section' => ['[other]'],
            'no equals' => ['language de'],
            'changing the type' => ['type=aor'],
            'control character' => ["language=de\x07"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedLines')]
    public function testLinesThatAreNotOptionsAreRefused(string $line): void
    {
        $this->assertNotSame([], AdvancedPjsipService::checkOptions($line, [])['errors']);
    }

    public function testCommentsAndRepeatedOptionsAreFine(): void
    {
        $check = AdvancedPjsipService::checkOptions("; note\r\nallow = ulaw\r\n\r\nallow=alaw", ['allow']);
        $this->assertSame([], $check['errors']);
        $this->assertSame(['allow=ulaw', 'allow=alaw'], $check['lines']);
    }

    public function testObjectsAreFoundWithTheTypeOfTheirTemplate(): void
    {
        $objects = pjsipObjectsInConfigs([
            "[endpoint-wss](!)\ntype=endpoint\n[aor-mobile](!)\ntype=aor\n",
            "[1000](endpoint-wss)\nauth=x\n[1000](aor-mobile)\n[transport-udp]\ntype=transport\n[auth1]\ntype=auth\n",
        ]);
        sort($objects);
        $this->assertSame(['aor:1000', 'auth:auth1', 'endpoint:1000', 'transport:transport-udp'], $objects);
    }

    public function testAnEntryForAnObjectThatIsGoneIsLeftOut(): void
    {
        $conf = buildAdvancedPjsipConf([
            ['object_type' => 'endpoint', 'object_name' => '1000', 'options' => 'language=de', 'is_active' => 1],
            ['object_type' => 'endpoint', 'object_name' => '2000', 'options' => 'language=fr', 'is_active' => 1],
            ['object_type' => 'aor', 'object_name' => '1000', 'options' => 'max_contacts=2', 'is_active' => 0],
        ], ['endpoint:1000', 'aor:1000']);
        $this->assertStringContainsString("[1000](+type=endpoint)\nlanguage=de\n", $conf);
        $this->assertStringNotContainsString('2000', $conf);
        $this->assertStringNotContainsString('max_contacts', $conf);
    }

    public function testRefusalsInTheLogAreFound(): void
    {
        $log = "[Nov  2 09:43:46] ERROR[1] config_options.c: Could not find option suitable for category 'x' named 'bogus'\n"
            . "[Nov  2 09:43:46] WARNING[1] taskprocessor.c: queue reached 500 scheduled tasks\n"
            . "[Nov  2 09:43:46] ERROR[1] res_sorcery_config.c: Could not create an object of type 'endpoint' with id 'x'\n";
        $problems = pjsipReloadProblems($log);
        $this->assertCount(2, $problems);
        $this->assertSame([], pjsipReloadProblems("[Nov  2 09:43:46] WARNING[1] taskprocessor.c: queue\n"));
    }

    public function testAHandEditedFileIsCopiedBeforeItIsReplaced(): void
    {
        $name = 'zz_handedit_test.conf';
        $path = ASTERISK_PBX_DIR . '/' . $name;
        foreach (glob($path . '*') ?: [] as $f) {
            @unlink($f);
        }
        try {
            writePBXConf($name, "generated\n");
            writePBXConf($name, "generated again\n");
            $this->assertSame([], glob($path . '.edited-*'), 'our own rewrites are not hand edits');

            file_put_contents($path, "changed by hand\n");
            writePBXConf($name, "generated\n");
            $copies = glob($path . '.edited-*');
            $this->assertCount(1, $copies);
            $this->assertSame("changed by hand\n", file_get_contents($copies[0]));
            $this->assertSame("generated\n", file_get_contents($path));
        } finally {
            foreach (array_merge(glob($path . '*') ?: []) as $f) {
                @unlink($f);
            }
        }
    }

    public function testAGroupReachesItsSipObjectsAndOneObjectWins(): void
    {
        $objects = ['endpoint:1000-sip', 'endpoint:1000-webrtc', 'endpoint:2000-sip', 'endpoint:gw'];
        $groups = ['extensions' => advancedExtensionObjects(['1000', '2000', '3000']), 'trunks' => advancedTrunkObjects(['gw'])];
        $conf = buildAdvancedPjsipConf([
            ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'object_name' => '1000-sip', 'options' => 'tos_audio=ef', 'is_active' => 1],
            ['config_file' => 'pjsip', 'target' => 'extensions', 'object_type' => 'endpoint', 'object_name' => '', 'options' => 'tos_audio=cs3', 'is_active' => 1],
            ['config_file' => 'pjsip', 'target' => 'trunks', 'object_type' => 'endpoint', 'object_name' => '', 'options' => 'send_pai=yes', 'is_active' => 1],
            ['config_file' => 'rtp', 'target' => 'object', 'object_type' => '', 'object_name' => 'general', 'options' => 'strictrtp=no', 'is_active' => 1],
        ], $objects, $groups);
        $this->assertStringContainsString("[2000-sip](+type=endpoint)\ntos_audio=cs3\n", $conf);
        $this->assertStringContainsString("[gw](+type=endpoint)\nsend_pai=yes\n", $conf);
        $this->assertStringNotContainsString('webrtc', $conf);
        $this->assertStringNotContainsString('3000', $conf);
        $this->assertStringNotContainsString('strictrtp', $conf);
        $this->assertGreaterThan(strpos($conf, 'tos_audio=cs3'), strrpos($conf, 'tos_audio=ef'));
    }

    public function testARegisteringTrunkFindsItsOwnAor(): void
    {
        $aors = pjsipEndpointAors(["[gw]\ntype=endpoint\naors=user1\n\n[gw]\ntype=identify\nendpoint=gw\n\n[user1]\ntype=aor\n"]);
        $this->assertSame(['gw' => 'user1'], $aors);
        $this->assertSame(['user1'], advancedTrunkObjects(['gw'], $aors)['aor']);
    }

    public function testAnotherFileAddsOnlyToSectionsItHas(): void
    {
        $conf = buildAdvancedFileConf('rtp', [
            ['config_file' => 'rtp', 'object_name' => 'general', 'options' => 'strictrtp=no', 'is_active' => 1],
            ['config_file' => 'rtp', 'object_name' => 'gone', 'options' => 'x=1', 'is_active' => 1],
            ['config_file' => 'rtp', 'object_name' => 'general', 'options' => 'dtmftimeout=9', 'is_active' => 0],
            ['config_file' => 'cdr', 'object_name' => 'general', 'options' => 'size=1', 'is_active' => 1],
        ], ['general']);
        $this->assertStringContainsString("[general](+)\nstrictrtp=no\n", $conf);
        foreach (['gone', 'dtmftimeout', 'size'] as $absent) {
            $this->assertStringNotContainsString($absent, $conf);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function deniedLines(): array
    {
        return [
            'voicemail mail program' => ['voicemail', 'mailcmd=/bin/sh -c x'],
            'upper case' => ['voicemail', 'MAILCMD=/bin/sh'],
            'music program' => ['musiconhold', 'application=/bin/sh'],
            'log rotate program' => ['logger', 'exec_after_rotate=/bin/sh'],
            'log path' => ['logger', '/etc/asterisk/x.conf => notice'],
            'rollback log' => ['logger', 'messages.log => debug'],
            'ami address' => ['manager', 'bindaddr=0.0.0.0'],
            'rtp ports' => ['rtp', 'rtpstart=1000'],
            'directive' => ['queues', '#exec /bin/sh'],
            'section' => ['queues', '[other]'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedLines')]
    public function testDangerousLinesAreRefused(string $file, string $line): void
    {
        $this->assertNotSame([], AdvancedPjsipService::checkOptions($line, [], $file)['errors']);
        $conf = buildAdvancedFileConf($file, [['config_file' => $file, 'object_name' => 'general', 'options' => $line, 'is_active' => 1]], ['general']);
        $this->assertStringNotContainsString($line, $conf, 'a row from SQL or a backup must not reach Asterisk');
    }

    public function testOtherFilesKeepArrowLines(): void
    {
        $this->assertSame(['member => PJSIP/1000'], AdvancedPjsipService::checkOptions('member=>PJSIP/1000', [], 'queues')['lines']);
        $this->assertSame(['*9=leave_conference'], AdvancedPjsipService::checkOptions('*9=leave_conference', [], 'confbridge')['lines']);
    }

    public function testApplicationMapAndTheAmiLoginCannotBeChanged(): void
    {
        $this->assertTrue(AdvancedPjsipService::sectionDenied('features', 'ApplicationMap'));
        $this->assertTrue(AdvancedPjsipService::sectionDenied('manager', 'aipbx-manager'));
        $this->assertFalse(AdvancedPjsipService::sectionDenied('manager', 'general'));
        $this->assertFalse(AdvancedPjsipService::sectionDenied('features', 'featuremap'));
    }

    public function testHelpDescriptionsAreRead(): void
    {
        $help = "\x1b[1mConfiguration\x1b[0m\n[Config Options]\nsend_pai   -- Send the P-Asserted-Identity header   \ntos_audio  -- DSCP TOS bits\n";
        $this->assertSame(['send_pai' => 'Send the P-Asserted-Identity header', 'tos_audio' => 'DSCP TOS bits'], AdvancedPjsipService::parseHelpDescriptions($help));
        $this->assertSame([], AdvancedPjsipService::parseHelpDescriptions('No such module'));
    }

    public function testNewObjectsComeFirstAndGiveWayToGeneratedOnes(): void
    {
        $conf = buildAdvancedPjsipConf([
            ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'transport', 'object_name' => 'my-udp', 'options' => 'tos=ef', 'is_active' => 1],
            ['config_file' => 'pjsip', 'target' => 'new', 'object_type' => 'transport', 'object_name' => 'my-udp', 'options' => 'protocol=udp', 'is_active' => 1],
            ['config_file' => 'pjsip', 'target' => 'new', 'object_type' => 'endpoint', 'object_name' => '1000', 'options' => 'context=x', 'is_active' => 1],
        ], ['endpoint:1000']);
        $this->assertStringContainsString("[my-udp]\ntype=transport\nprotocol=udp\n", $conf);
        $this->assertGreaterThan(strpos($conf, 'protocol=udp'), strpos($conf, '[my-udp](+type=transport)'));
        $this->assertStringNotContainsString('context=x', $conf);
    }

    public function testRawTextDropsAdditionsToSectionsThatAreGone(): void
    {
        $raw = "[gone](+)\nweight=1\n\n[mine]\nstrategy=ringall\n\n[mine](+)\nweight=2\n\n[sales](+)\nweight=3";
        $text = advancedRawText($raw, ['sales']);
        $this->assertStringNotContainsString('weight=1', $text);
        $this->assertStringContainsString('weight=2', $text);
        $this->assertStringContainsString('weight=3', $text);
        $conf = buildAdvancedFileConf('queues', [
            ['config_file' => 'queues', 'target' => 'object', 'object_name' => 'mine', 'options' => 'timeout=9', 'is_active' => 1],
            ['config_file' => 'queues', 'target' => 'raw', 'object_name' => '', 'options' => $raw, 'is_active' => 1],
        ], ['sales']);
        $this->assertGreaterThan(strpos($conf, 'strategy=ringall'), strpos($conf, 'timeout=9'), 'an entry can add to a section the raw text starts');
    }

    public function testRawTextOwnObjectsCanBeAddedTo(): void
    {
        $conf = buildAdvancedPjsipConf([
            ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'aor', 'object_name' => 'gw', 'options' => 'max_contacts=2', 'is_active' => 1],
            ['config_file' => 'pjsip', 'target' => 'raw', 'object_type' => '', 'object_name' => '', 'options' => "[gw]\ntype=aor\ncontact=sip:h", 'is_active' => 1],
        ], []);
        $this->assertGreaterThan(strpos($conf, 'contact=sip:h'), strpos($conf, '[gw](+type=aor)'));
    }

    public function testRawTextRefusesWhatTheFormRefuses(): void
    {
        $this->assertSame([], AdvancedPjsipService::checkRaw("; note\n[mine]\nmember => PJSIP/1000\n", 'queues'));
        foreach (["#include \"/etc/passwd\"", "#exec /bin/sh", "x=1"] as $bad) {
            $this->assertNotSame([], AdvancedPjsipService::checkRaw($bad, 'queues'), $bad);
        }
        $this->assertNotSame([], AdvancedPjsipService::checkRaw("[general]\nmailcmd=/bin/sh", 'voicemail'));
        $this->assertNotSame([], AdvancedPjsipService::checkRaw("[newlogin]\nsecret=x", 'manager'));
        $this->assertNotSame([], AdvancedPjsipService::checkRaw("[applicationmap]\nx => *9,self,System,id", 'features'));
        $conf = buildAdvancedFileConf('voicemail', [['config_file' => 'voicemail', 'target' => 'raw', 'object_name' => '', 'options' => "[general]\nmailcmd=/bin/sh", 'is_active' => 1]], ['general']);
        $this->assertStringNotContainsString('mailcmd', $conf, 'a row from SQL or a backup must not reach Asterisk');
    }

    public function testTransportsNeedARestart(): void
    {
        $this->assertTrue(AdvancedPjsipService::needsRestart(['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'transport', 'options' => 'tos=ef']));
        $this->assertTrue(AdvancedPjsipService::needsRestart(['config_file' => 'pjsip', 'target' => 'raw', 'object_type' => '', 'options' => "[t]\ntype=transport"]));
        $this->assertFalse(AdvancedPjsipService::needsRestart(['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'options' => 'language=de']));
        $this->assertFalse(AdvancedPjsipService::needsRestart(['config_file' => 'rtp', 'target' => 'object', 'object_type' => '', 'options' => 'strictrtp=no']));
    }

    public function testReplacedValuesSayWhoWins(): void
    {
        $generated = ['language' => 'en', 'allow' => 'ulaw', 'password' => 'hunter2', 'tos_audio' => 'ef'];
        $found = AdvancedPjsipService::replacedValues(['config_file' => 'pjsip', 'options' => "language=de\nallow=gsm\npassword=x\ntos_audio=ef"], $generated);
        $this->assertSame([
            ['name' => 'language', 'value' => 'en', 'wins' => true],
            ['name' => 'password', 'value' => '***', 'wins' => true],
        ], $found);
        $found = AdvancedPjsipService::replacedValues(['config_file' => 'rtp', 'options' => 'dtmftimeout=9'], ['dtmftimeout' => '3000']);
        $this->assertFalse($found[0]['wins']);
    }

    public function testGeneratedValuesIncludeTemplates(): void
    {
        $values = pjsipObjectValues(["[endpoint-sip](!)\ntype=endpoint\nlanguage=en\ntos_audio=ef\n\n[1000-sip](endpoint-sip)\nlanguage=tr\n\n[1000-sip]\ntype=aor\nmax_contacts=1\n"]);
        $this->assertSame(['language' => 'tr', 'tos_audio' => 'ef'], $values['endpoint:1000-sip']);
        $this->assertSame(['max_contacts' => '1'], $values['aor:1000-sip']);
        $this->assertArrayNotHasKey('endpoint:endpoint-sip', $values);
        $this->assertSame(['general' => ['a' => '1', 'b' => 'x']], configSectionValues(["; c\n[general]\na = 1 ; note\na=2\nb => x\n"]));
    }

    public function testAnImportThatIsNotAnExportChangesNothing(): void
    {
        foreach (['not json', '{"entries":[]}', '{"format":"aipbx-advanced-config"}'] as $bad) {
            try {
                AdvancedPjsipService::replaceFromJson($bad);
                $this->fail('accepted: ' . $bad);
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(\Exception::class);
        AdvancedPjsipService::replaceFromJson('{"format":"aipbx-advanced-config","entries":[{"config_file":"voicemail","target":"object","object_name":"general","options":"mailcmd=/bin/sh"}]}');
    }

    public function testRecipesOnlyUseOptionsTheFormAccepts(): void
    {
        foreach (AdvancedPjsipService::RECIPES as $key => $recipe) {
            if ($recipe['target'] === 'raw') {
                $this->assertSame([], AdvancedPjsipService::checkRaw($recipe['options'], $recipe['config_file']), $key);
            } else {
                $this->assertSame([], AdvancedPjsipService::checkOptions($recipe['options'], [], $recipe['config_file'])['errors'], $key);
            }
            $this->assertNotSame('advanced_config.recipe_' . $key, t('advanced_config.recipe_' . $key));
        }
    }
}
