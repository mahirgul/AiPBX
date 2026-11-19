<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/IvrDataStepService.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncIvrDataSteps.php';

final class DynamicRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->beginTransaction();
    }

    protected function tearDown(): void
    {
        getDB()->rollBack();
    }

    public function testRulesAreCheckedAndKept(): void
    {
        $rules = IvrDataStepService::normalizeRules([
            ['type' => 'exact', 'value' => 'VIP', 'dest_type' => 'queue', 'dest_id' => 'vip'],
            ['type' => 'range', 'value' => '0', 'value2' => '99.5', 'dest_type' => 'hangup', 'dest_id' => ''],
            ['type' => 'prefix', 'value' => '', 'dest_type' => 'queue', 'dest_id' => 'x'],
            ['type' => 'exact', 'value' => 'loop', 'dest_type' => 'ivr_data', 'dest_id' => '5'],
        ], 5);
        $this->assertCount(3, $rules, 'an empty value is no rule');
        $this->assertSame(['hangup', ''], [$rules[2]['dest_type'], $rules[2]['dest_id']], 'not straight into itself');

        $this->expectException(InvalidArgumentException::class);
        IvrDataStepService::normalizeRules([['type' => 'exact', 'value' => 'a"]?x', 'dest_type' => 'hangup']]);
    }

    public function testDatabaseStepWithRulesNameAndDirectExtension(): void
    {
        $block = buildIvrDataStepDialplanBlock([
            'id' => 901, 'name' => 'VIP check', 'input_source' => 'caller_id', 'data_source' => 'database',
            'query_sql' => 'SELECT segment, name FROM c WHERE phone = :caller_id AND acc = :var_ACC', 'var_prefix' => 'CRM_',
            'rules_json' => json_encode([
                ['type' => 'exact', 'value' => 'VIP', 'dest_type' => 'hangup', 'dest_id' => ''],
                ['type' => 'range', 'value' => '1', 'value2' => '9', 'dest_type' => 'hangup', 'dest_id' => ''],
            ]),
            'caller_name_mode' => 'prefix', 'caller_name_template' => '{NAME} –', 'direct_extension' => 1,
            'found_dest_type' => 'hangup', 'not_found_dest_type' => 'hangup', 'timeout_dest_type' => 'hangup',
        ]);
        $this->assertStringContainsString('ivr_data_lookup.php?step_id=901&input=${URIENCODE(${DR_INPUT})}&caller_id=${URIENCODE(${CALLERID(num)})}&var_ACC=${URIENCODE(${ACC})}', $block);
        $this->assertStringNotContainsString('Answer()', $block, 'no keys asked: the call is not answered');
        $this->assertStringContainsString('Set(CRM_${CUT(DR_KV,=,1)}=${CUT(DR_KV,=,2-)})', $block);
        $this->assertStringContainsString('Set(CALLERID(name)=${CRM_NAME} – ${CALLERID(name)})', $block);
        $this->assertStringContainsString('GotoIf($["${DR_VALUE}" = "VIP"]?rule0)', $block);
        $this->assertStringContainsString('${DR_NUM} >= 1 & ${DR_NUM} <= 9]?rule1)', $block);
        $this->assertStringContainsString('Dial(Local/${DR_VALUE}@from-internal-pbx-ortak/n,,tT)', $block);
        $this->assertLessThan(strpos($block, 'Dial(Local/${DR_VALUE}'), strpos($block, '?rule1)'), 'rules first, then the extension');
    }

    public function testKeysWithRetriesAndValueSource(): void
    {
        $block = buildIvrDataStepDialplanBlock([
            'id' => 902, 'name' => 'Menu', 'input_source' => 'dtmf', 'min_digits' => 3, 'max_digits' => 6, 'retries' => 2,
            'invalid_prompt' => 'custom/wrong', 'data_source' => 'value', 'value_template' => '{input}-{did}',
            'found_dest_type' => 'hangup', 'not_found_dest_type' => 'hangup', 'timeout_dest_type' => 'hangup',
        ]);
        $this->assertStringContainsString('n(ask),Read(DR_INPUT,,6,,,5)', $block);
        $this->assertStringContainsString('GotoIf($[${LEN(${DR_INPUT})} < 3]?invalid)', $block);
        $this->assertStringContainsString('Set(DR_VALUE=${DR_INPUT}-${NORMALIZED_DID})', $block);
        $this->assertStringContainsString('GotoIf($[${DR_TRY} > 2]?not_found)', $block);
        $this->assertStringContainsString('Playback(custom/wrong)', $block);
        $this->assertStringNotContainsString('CURL(', $block);
    }

    public function testValueTemplateKeepsOnlySafeText(): void
    {
        $this->assertSame('${CALLERID(num)}xSHELLid – a', dynamicRoutingExpr('{caller_id}x${SHELL(id)} – a,;'));
    }

    public function testSaveKeepsSavedHeadersAndRefusesUnknownVariables(): void
    {
        $base = [
            'name' => 'zz api', 'input_source' => 'caller_id', 'data_source' => 'http', 'http_method' => 'GET',
            'http_url' => 'https://1.1.1.1/l?p={caller_id}', 'http_headers' => 'Authorization: Bearer abc',
            'channel_var_name' => 'IVR_DATA_VAL', 'rules' => [['type' => 'exact', 'value' => 'VIP', 'dest_type' => 'hangup']],
        ];
        $res = IvrDataStepService::saveStep($base);
        $this->assertTrue($res['ok'], implode(' ', $res['errors']));
        $row = IvrDataStepRepository::findById($res['id']);
        $this->assertStringNotContainsString('abc', (string) $row['http_headers_enc'], 'stored encrypted');
        $this->assertCount(1, json_decode((string) $row['rules_json'], true));

        $res2 = IvrDataStepService::saveStep(['id' => $res['id'], 'http_headers' => ''] + $base);
        $this->assertTrue($res2['ok']);
        $this->assertSame($row['http_headers_enc'], IvrDataStepRepository::findById($res['id'])['http_headers_enc'], 'empty field keeps them');

        $bad = IvrDataStepService::saveStep(['http_url' => 'https://1.1.1.1/l?p={phone}'] + $base);
        $this->assertFalse($bad['ok']);
        $this->assertArrayHasKey('http_url', $bad['errors']);
    }
}
