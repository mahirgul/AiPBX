<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ai/BuiltinAiAgentService.php';
require_once dirname(__DIR__, 2) . '/src/services/IvrDataStepService.php';

final class ExternalDbQueryTest extends TestCase
{
    public function testOneDefinitionIsSharedByVoiceToolsAndIvr(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $connection = ExternalDbService::saveConnection([
                'name' => 'Saved lookup test', 'host' => 'localhost', 'database_name' => 'test_lookup', 'username' => 'readonly',
            ])['id'];
            $data = ['query_key' => 'saved_lookup_test', 'external_db_id' => $connection, 'is_active' => 1,
                'access_mode' => 'public', 'description' => 'Account status',
                'query_sql' => 'SELECT status FROM accounts WHERE account_no = :input AND location = :did LIMIT 1'];
            $id = ExternalDbQueryService::save($data);
            $lookup = AiAppDbLookup::parse('saved_lookup_test');
            $metadata = [['id' => 'saved_lookup_test', 'description' => 'Account status', 'params' => ['input']]];
            $this->assertSame($metadata, AiAppDbLookup::metadata($lookup));
            $step = IvrDataStepService::saveStep(['name' => 'Saved query step', 'data_source' => 'saved_query', 'saved_query_id' => $id]);
            $this->assertTrue($step['ok']);
            $this->assertSame($data['query_sql'], IvrDataStepService::sourceOf(IvrDataStepRepository::findById($step['id']))['sql']);

            ExternalDbQueryService::save(['id' => $id, 'access_mode' => 'verified',
                'query_sql' => 'SELECT status FROM accounts WHERE account_no = :input AND customer = :var_verified_customer_id LIMIT 1'] + $data);
            $this->assertSame(0, ExternalDbQueryService::ivrSource($id)['db_id']);
            $this->assertSame($metadata, AiAppDbLookup::metadata($lookup));
            ExternalDbQueryService::save(['id' => $id, 'is_active' => 0] + $data);
            $this->assertSame([], AiAppDbLookup::metadata($lookup));

            $rwConn = ExternalDbService::saveConnection([
                'name' => 'Saved write test', 'host' => 'localhost', 'database_name' => 'test_write', 'username' => 'writer', 'allow_write' => 1,
            ])['id'];
            $wId = ExternalDbQueryService::save([
                'query_key' => 'saved_write_test',
                'external_db_id' => $rwConn,
                'is_active' => 1,
                'access_mode' => 'write',
                'description' => 'Save survey score',
                'query_sql' => 'INSERT INTO call_surveys (q, s) VALUES (:queue, :score)',
            ]);
            $wQuery = ExternalDbQueryService::find($wId);
            $this->assertNotNull($wQuery);
            $this->assertFalse(ExternalDbQueryService::available($wQuery));
            $this->assertTrue(ExternalDbQueryService::writeAvailable($wQuery));
            $this->assertNotContains('saved_write_test', ExternalDbQueryService::keysForConnection($rwConn));

            $autoId = ExternalDbQueryService::save([
                'query_key' => 'auto_braces_test',
                'external_db_id' => $connection,
                'is_active' => 1,
                'description' => 'Auto public with braces',
                'query_sql' => 'SELECT name FROM customers WHERE phone = {caller_id} LIMIT 1',
            ]);
            $this->assertSame('public', ExternalDbQueryService::find($autoId)['access_mode'] ?? null);

            $autoVerifiedId = ExternalDbQueryService::save([
                'query_key' => 'auto_verified_dollar',
                'external_db_id' => $connection,
                'is_active' => 1,
                'description' => 'Auto verified with dollar',
                'query_sql' => 'SELECT balance FROM accounts WHERE cid = $var_verified_customer_id LIMIT 1',
            ]);
            $this->assertSame('verified', ExternalDbQueryService::find($autoVerifiedId)['access_mode'] ?? null);

            $colId = AiKnowledgeService::saveCollection(0, 'Agent Direct Query Coll', 'Test', ['*'], '');
            $models = BuiltinAiAgentService::models();
            if ($models['llm'] && $models['stt'] && $models['tts']) {
                $llm = (string) array_key_first($models['llm']);
                $stt = (string) array_key_first($models['stt']);
                $tts = (string) array_key_first($models['tts']);
                $validated = BuiltinAiAgentService::validate([
                    'title' => 'Agent With Direct DB',
                    'understand' => $llm,
                    'stt_model' => $stt,
                    'model_id' => $tts,
                    'knowledge_collection' => $colId,
                    'fallback' => 'hangup:',
                    'instructions' => 'Greet {caller_name} ({customer_id})',
                    'db_queries' => ['auto_braces_test', 'auto_verified_dollar'],
                ]);
                $this->assertSame('Greet {caller_name} ({customer_id})', $validated['agent_config']['instructions']);
                $this->assertSame(['auto_braces_test', 'auto_verified_dollar'], $validated['agent_config']['db_queries']);
                $this->assertCount(2, $validated['agent_config']['db_lookups']);
            }
        } finally {
            $db->rollBack();
        }
    }
}
