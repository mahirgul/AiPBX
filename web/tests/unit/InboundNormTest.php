<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/InboundNormService.php';
require_once dirname(__DIR__, 2) . '/src/services/TrunkService.php';

final class InboundNormTest extends TestCase
{
    private function rule(string $pattern, int $strip, string $prepend, ?string $trunk = null, string $target = 'caller'): array
    {
        return ['trunk_name' => $trunk, 'target' => $target, 'rule_name' => $pattern, 'match_pattern' => $pattern, 'strip_front' => $strip, 'prepend' => $prepend, 'is_active' => 1];
    }

    public function testWithoutRulesNothingIsAddedToTheDialplan(): void
    {
        $this->assertSame('', buildInboundNormContexts([], ['t1']));
        $this->assertSame('', inboundNormLines([], 't1', 'caller'));
    }

    public function testATrunkContextIncludesTheRulesForAllTrunks(): void
    {
        $conf = buildInboundNormContexts([$this->rule('_+43.', 3, '0'), $this->rule('_+49.', 3, '0049', 't1')], ['t1', 't2']);
        $this->assertStringContainsString("[innorm-caller-all]\nexten => _+43.,1,", $conf);
        $this->assertStringContainsString('Set(CALLERID(num)=0${EXTEN:3})', $conf);
        $this->assertStringContainsString("[innorm-caller-t-t1]\ninclude => innorm-caller-all\nexten => _+49.,1,", $conf);
        $this->assertStringContainsString("[innorm-caller-t-t2]\ninclude => innorm-caller-all\n", $conf);
        $this->assertStringNotContainsString('innorm-did', $conf);
    }

    public function testTheDialledNumberHasItsOwnRules(): void
    {
        $conf = buildInboundNormContexts([$this->rule('_+431.', 3, '0', null, 'did')], ['t1']);
        $this->assertStringContainsString('Set(NORMALIZED_DID=0${EXTEN:3})', $conf);
        $lines = inboundNormLines([$this->rule('_+431.', 3, '0', null, 'did')], 't1', 'did');
        $this->assertStringContainsString('FILTER(0-9+*#,${NORMALIZED_DID})', $lines);
        $this->assertSame('', inboundNormLines([$this->rule('_+431.', 3, '0', null, 'did')], 't1', 'caller'));
    }

    public function testACallerNumberFromOutsideIsFilteredBeforeItIsUsed(): void
    {
        $lines = inboundNormLines([$this->rule('_+43.', 3, '0')], 't1', 'caller');
        $this->assertStringContainsString('Set(AIPBX_NORM_IN=${FILTER(0-9+*#,${CALLERID(num)})})', $lines);
        $this->assertStringContainsString('GosubIf($[${DIALPLAN_EXISTS(innorm-caller-t-t1,${AIPBX_NORM_IN},1)}]?', $lines);
    }

    public function testSimulationPicksTheTrunkRuleThenTheMostSpecificOne(): void
    {
        $rules = [$this->rule('_+X.', 1, '00'), $this->rule('_+43.', 3, '0'), $this->rule('_+43.', 3, '9', 't1')];
        $this->assertSame('066412345', InboundNormService::simulate($rules, '', 'caller', '+4366412345')['result']);
        $this->assertSame('966412345', InboundNormService::simulate($rules, 't1', 'caller', '+4366412345')['result']);
        $this->assertSame('0049301', InboundNormService::simulate($rules, 't2', 'caller', '+49301')['result']);
        $none = InboundNormService::simulate($rules, '', 'caller', '066412345');
        $this->assertSame('066412345', $none['result']);
        $this->assertNull($none['rule']);
        $this->assertNull(InboundNormService::simulate($rules, '', 'did', '+4366412345')['rule']);
    }

    public function testPresetsComeFromTheCountrysFile(): void
    {
        $at = InboundNormService::presets('43');
        $this->assertSame(['_+43.', 3, '0'], [$at[0]['match_pattern'], $at[0]['strip_front'], $at[0]['prepend']]);
        $this->assertSame('', InboundNormService::presets('39')[0]['prepend'], 'Italy has no national prefix');
        $this->assertSame(0, $at[2]['is_active'], 'a number without + can also be a national one, so this one starts off');
        $this->assertSame([], InboundNormService::presets('999'));
    }

    public function testAnOverrideFileWinsAndABadRuleInItIsIgnored(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx_presets_' . getmypid();
        mkdir($dir);
        file_put_contents("{$dir}/43.json", json_encode(['inbound' => [
            ['target' => 'caller', 'rule_name' => 'Mine', 'match_pattern' => '_+43.', 'strip_front' => 3, 'prepend' => '9'],
            ['target' => 'caller', 'rule_name' => 'Bad', 'match_pattern' => '_+43.,1,System(x)', 'strip_front' => 3, 'prepend' => '9'],
            ['target' => 'caller', 'rule_name' => 'Bad too', 'match_pattern' => '_+44.', 'strip_front' => 3, 'prepend' => '9)'],
        ], 'outbound' => [
            ['client_type' => 'web', 'rule_name' => 'Out', 'match_pattern' => '_+43.', 'strip_front' => 3, 'prepend' => '0'],
            ['client_type' => 'nope', 'rule_name' => 'Bad', 'match_pattern' => '_+43.', 'strip_front' => 3, 'prepend' => '0'],
        ]]));
        putenv("AIPBX_PRESET_DIR={$dir}");
        try {
            $this->assertSame(['Mine'], array_column(InboundNormService::presets('43'), 'rule_name'));
            $this->assertSame(['Out'], array_column(DialplanRuleService::getDefaultPresets('43'), 1));
            $this->assertNotSame([], InboundNormService::presets('49'), 'other countries still use the shipped file');
        } finally {
            putenv('AIPBX_PRESET_DIR');
            unlink("{$dir}/43.json");
            rmdir($dir);
        }
    }

    public function testShippedPresetFilesAreValidAndCoverTheKnownCountries(): void
    {
        $count = 0;
        foreach (DialplanRuleService::getAllCountries() as $cc => $_c) {
            if (DialplanRuleService::getDialPlan((string) $cc) === null) {
                continue;
            }
            $file = DialplanRuleService::presetFile((string) $cc);
            $this->assertSame(count($file['outbound']), count(DialplanRuleService::getDefaultPresets((string) $cc)), "outbound {$cc}");
            $this->assertSame(count($file['inbound']), count(InboundNormService::presets((string) $cc)), "inbound {$cc}");
            $count++;
        }
        $this->assertGreaterThan(100, $count);
    }

    public function testANewInstallationLoadsNoRulesForAnyCountry(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob($root . '/db/seeds/*') ?: [], glob($root . '/db/migrations/sql/*') ?: [], [dirname($root) . '/install.sh']);
        $this->assertGreaterThan(1, count($files));
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $this->assertStringNotContainsString('INSERT INTO `pbx_dialplan_rules`', $text, basename($file));
            $this->assertStringNotContainsString('INSERT INTO pbx_dialplan_rules', $text, basename($file));
            $this->assertStringNotContainsString('pbx_inbound_norm_rules', $text, basename($file));
        }
    }

    public function testLoadingACountryReplacesEveryRule(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM pbx_inbound_norm_rules');
        $db->exec("INSERT INTO pbx_inbound_norm_rules (trunk_name, target, rule_name, match_pattern, strip_front, prepend, is_active, created_at) VALUES ('t9', 'did', 'old', '_+1.', 1, '', 1, NOW())");
        $_SESSION['csrf_token'] = 'x';

        $this->assertFalse(InboundNormService::loadPresets(['csrf_token' => 'x', 'country_code' => '999'])['success']);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM pbx_inbound_norm_rules')->fetchColumn(), 'nothing is lost when a country has no rules');

        $this->assertTrue(InboundNormService::loadPresets(['csrf_token' => 'x', 'country_code' => '43'])['success']);
        $rows = $db->query('SELECT rule_name, trunk_name FROM pbx_inbound_norm_rules')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows);
        $this->assertNotContains('old', array_column($rows, 'rule_name'));
        $this->assertSame([null, null, null], array_column($rows, 'trunk_name'));
        $db->exec('DELETE FROM pbx_inbound_norm_rules');
    }

    public function testAPatternCannotCarryDialplanText(): void
    {
        $this->assertTrue(InboundNormService::isValidPattern('_+43.'));
        $this->assertTrue(InboundNormService::isValidPattern('_00[1-9].'));
        $this->assertTrue(InboundNormService::isValidPattern('+431234'));
        foreach (['_+43.,1,System(x)', "_43\nexten", '_[12', '', 's'] as $bad) {
            $this->assertFalse(InboundNormService::isValidPattern($bad), $bad);
        }
    }

    public function testSipHeadersAreCheckedLineByLine(): void
    {
        $ok = TrunkService::parseSipHeaders("X-Account: 1234\r\n\r\nX-Tag: a=b;c");
        $this->assertSame([['X-Account', '1234'], ['X-Tag', 'a=b;c']], $ok['lines']);
        $this->assertSame([], $ok['errors']);
    }

    public function testASipHeaderCannotInjectOrReplaceAnything(): void
    {
        foreach (['From: <sip:x@y>', 'contact: x', 'X-A: ${SHELL(id)}', 'X-A: a)', 'X-A: a,b', 'X A: b', 'nocolon'] as $bad) {
            $this->assertNotSame([], TrunkService::parseSipHeaders($bad)['errors'], $bad);
        }
    }

    public function testASemicolonInAHeaderIsEscapedInTheDialplan(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php');
        $this->assertStringContainsString("str_replace(';', '\\\\;', \$header_value)", $source);
    }
}
