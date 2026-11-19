<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/DataSourceService.php';
require_once dirname(__DIR__, 2) . '/src/services/AgiScriptService.php';

final class DataSourceServiceTest extends TestCase
{
    public function testVariablesInSqlAreParametersEachTime(): void
    {
        [$sql, $params] = DataSourceService::bindSql(
            "SELECT 'Label :time' AS lbl, a FROM t WHERE x = :caller_id OR y = :caller_id OR z = :var_ACCOUNT AND w = '12:30' AND v::text = :input /* :date */",
            ['caller_id' => '0532', 'var_ACCOUNT' => '77', 'input' => '5', 'time' => '09:00']
        );
        $this->assertSame("SELECT 'Label :time' AS lbl, a FROM t WHERE x = :p1 OR y = :p2 OR z = :p3 AND w = '12:30' AND v::text = :p4 /* :date */", $sql);
        $this->assertSame([':p1' => '0532', ':p2' => '0532', ':p3' => '77', ':p4' => '5'], $params);
    }

    public function testUnknownNamesAreFound(): void
    {
        $this->assertSame(['phone'], DataSourceService::unknownPlaceholders('SELECT 1 WHERE a = :phone AND b = :input', true));
        $this->assertSame(['foo'], DataSourceService::unknownPlaceholders('https://x.test/?a={foo}&b={did}&c={var_X}'));
        $this->assertSame(['caller_id', 'var_X'], DataSourceService::placeholders('https://x.test/?a={caller_id}&b={var_X}&c={foo}'));
    }

    public function testFillEncodesForUrls(): void
    {
        $this->assertSame('https://x.test/?q=a%26b%3D1&n=%2B49', DataSourceService::fill('https://x.test/?q={input}&n={caller_id}', ['input' => 'a&b=1', 'caller_id' => '+49'], true));
        $this->assertSame('x {nope} y', DataSourceService::fill('x {nope} y', []));
    }

    public function testJsonAnswers(): void
    {
        [$st, $val, $vars] = DataSourceService::fromBody('{"data":{"customer":{"segment":"VIP","name":"Ayşe","id":7}}}', 'data.customer.segment');
        $this->assertSame(['found', 'VIP'], [$st, $val]);
        $this->assertSame(['SEGMENT' => 'VIP', 'NAME' => 'Ayşe', 'ID' => '7'], $vars);

        $this->assertSame('not_found', DataSourceService::fromBody('{"a":1}', 'b.c')[0]);
        $this->assertSame(['found', '42'], array_slice(DataSourceService::fromBody('{"value":42,"other":"x"}'), 0, 2));
        $this->assertSame(['found', 'plain text'], array_slice(DataSourceService::fromBody('plain text'), 0, 2));
        $this->assertSame(['found', 'a'], array_slice(DataSourceService::fromBody('[{"k":"a"},{"k":"b"}]'), 0, 2), 'a list: its first row');
        $this->assertSame('not_found', DataSourceService::fromBody('"VIP"', 'customer.segment')[0]);
        $this->assertSame('not_found', DataSourceService::fromBody('null')[0]);
        $this->assertSame('not_found', DataSourceService::fromBody('false')[0]);
        $this->assertSame(['found', 'VIP'], array_slice(DataSourceService::fromBody('"VIP"'), 0, 2));
        $this->assertSame(['found', '1'], array_slice(DataSourceService::fromBody('true'), 0, 2));
    }

    public function testValuesCannotBreakTheDialplanOrTheAnswer(): void
    {
        $this->assertSame('a b c d', DataSourceService::clean("a|b;c\nd"));
        $this->assertSame("found|VIP|NAME=A B|CITY=x\n", DataSourceService::encodeAnswer(['status' => 'found', 'value' => 'VIP', 'vars' => ['NAME' => "A|B", 'CITY' => 'x']]));
        $vars = DataSourceService::flatVars(array_fill_keys(array_map(fn ($i) => "col $i", range(1, 30)), 'v') + ['nested' => ['x']]);
        $this->assertCount(DataSourceService::MAX_VARS, $vars);
        $this->assertArrayHasKey('COL_1', $vars);
    }

    public function testValueSourceAndHeaders(): void
    {
        $r = DataSourceService::run(['type' => 'value', 'value' => '{var_SEG}-{did}'], ['var_SEG' => 'gold', 'did' => '212']);
        $this->assertSame(['found', 'gold-212'], [$r['status'], $r['value']]);
        $this->assertSame(['X-Token: 1', 'Accept: application/json'], DataSourceService::parseHeaders("X-Token: 1\n\nAccept: application/json"));
        $this->assertNull(DataSourceService::parseHeaders("not a header"));
    }

    public function testWriteStatementsAreOneInsertOrUpdate(): void
    {
        $this->assertNull(DataSourceService::writeProblem('UPDATE leads SET r = :result WHERE id = :external_id', ['result', 'external_id']));
        $this->assertNotNull(DataSourceService::writeProblem('SELECT 1'));
        $this->assertNotNull(DataSourceService::writeProblem('UPDATE a SET b = 1; DROP TABLE a'));
        $this->assertNotNull(DataSourceService::writeProblem('UPDATE a SET b = :nope'));
        $this->assertNull(DataSourceService::writeProblem("UPDATE a SET note = 'x; DROP TABLE a' WHERE id = :input"), 'text in quotes is not a statement');
    }

    public function testAgiTestAnswersLikeAsterisk(): void
    {
        $set = [];
        $this->assertSame(['200 result=1 (0532)', false], AgiScriptService::answer('GET VARIABLE caller_id', ['caller_id' => '0532'], $set));
        $this->assertSame(['200 result=0', false], AgiScriptService::answer('GET VARIABLE MISSING', [], $set));
        $this->assertSame(['200 result=1', false], AgiScriptService::answer('SET VARIABLE AGI_RESULT "vip"', [], $set));
        $this->assertSame(['AGI_RESULT' => 'vip'], $set);
        $this->assertSame(['200 result=1 (vip)', false], AgiScriptService::answer('GET VARIABLE AGI_RESULT', [], $set), 'what it set reads back');
        $this->assertSame(['200 result=1', true], AgiScriptService::answer('HANGUP', [], $set));
        $this->assertSame(['200 result=0', false], AgiScriptService::answer('STREAM FILE beep ""', [], $set));
    }

    public function testAgiTargets(): void
    {
        $this->assertSame('agi://127.0.0.1:4573/vip-check', AgiScriptService::dialplanTarget('script:vip-check'));
        $this->assertSame('', AgiScriptService::dialplanTarget('script:../x'));
        $this->assertSame('', AgiScriptService::dialplanTarget('bin:not-there.sh'));
    }
}
