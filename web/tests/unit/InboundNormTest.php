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
