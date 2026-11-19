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
            $tools = BuiltinAiAgentService::parseTools("db_connection | $connection");
            $this->assertSame($metadata, AiAppDbLookup::metadata($tools['db_lookups']));
            $step = IvrDataStepService::saveStep(['name' => 'Saved query step', 'data_source' => 'saved_query', 'saved_query_id' => $id]);
            $this->assertTrue($step['ok']);
            $this->assertSame($data['query_sql'], IvrDataStepService::sourceOf(IvrDataStepRepository::findById($step['id']))['sql']);

            ExternalDbQueryService::save(['id' => $id, 'access_mode' => 'verified',
                'query_sql' => 'SELECT status FROM accounts WHERE account_no = :input AND customer = :var_verified_customer_id LIMIT 1'] + $data);
            $this->assertSame(0, ExternalDbQueryService::ivrSource($id)['db_id']);
            $this->assertSame($metadata, AiAppDbLookup::metadata($lookup));
            ExternalDbQueryService::save(['id' => $id, 'is_active' => 0] + $data);
            $this->assertSame([], AiAppDbLookup::metadata($lookup));
        } finally {
            $db->rollBack();
        }
    }
}
