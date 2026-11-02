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
}
