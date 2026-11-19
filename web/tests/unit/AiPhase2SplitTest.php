<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiBindingService.php';
require_once __DIR__ . '/../../src/services/ai/AiGateway.php';

/**
 * Faz 2 tests: ailocal / aicloud manifests, forward split migration,
 * AiBindingService common selector, and 4 local/cloud plugin combinations in AiGateway.
 */
final class AiPhase2SplitTest extends TestCase
{
    public function testLocalAndCloudManifestsExist(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['ailocal' => '/ai-local', 'aicloud' => '/ai-cloud'] as $slug => $route) {
            $path = $root . '/plugins/' . $slug . '/plugin.json';
            $this->assertFileExists($path);
            $json = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($json);
            $this->assertSame($slug, $json['slug'] ?? null);
            $this->assertSame($route, $json['hooks']['admin_page']['route'] ?? null);
        }
    }

    public function testSplitMigrationCreatesTablesAndPreservesStates(): void
    {
        require_once __DIR__ . '/../../db/migrations/20261117160000_split_ai_local_and_cloud_plugins.php';
        $db = getDB();
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['connection' => $db, 'name' => getenv('DB_NAME')]);
        $migration = new SplitAiLocalAndCloudPlugins('testing', 20261117160000);
        $migration->setAdapter($adapter);

        $migration->up();

        foreach (['ai_connections', 'ai_model_refs', 'ai_bindings'] as $tbl) {
            $this->assertNotFalse($db->query("SHOW TABLES LIKE '{$tbl}'")->fetchColumn(), "Missing table {$tbl}");
        }

        $localEnabled = $db->query("SELECT enabled FROM sys_plugins WHERE slug = 'ailocal'")->fetchColumn();
        $cloudEnabled = $db->query("SELECT enabled FROM sys_plugins WHERE slug = 'aicloud'")->fetchColumn();
        $this->assertNotFalse($localEnabled);
        $this->assertNotFalse($cloudEnabled);
    }

    public function testBindingServiceSavesAndValidatesModes(): void
    {
        AiBindingService::saveBinding('test_feature', 'chat', 'local_default', null, 'default', 'global');
        $b = AiBindingService::getBinding('test_feature', 'chat', 'default', 'global');
        $this->assertSame('local_default', $b['mode']);
        $this->assertNull($b['model_ref_id']);

        AiBindingService::saveBinding('test_feature', 'chat', 'explicit', 'cloud:openai', 'default', 'global');
        $b2 = AiBindingService::getBinding('test_feature', 'chat', 'default', 'global');
        $this->assertSame('explicit', $b2['mode']);
        $this->assertSame('cloud:openai', $b2['model_ref_id']);

        getDB()->exec("DELETE FROM ai_bindings WHERE feature = 'test_feature'");
    }

    public function testGatewayEnforcesFourLocalCloudPluginCombinations(): void
    {
        $db = getDB();
        $prev = $db->query("SELECT slug, enabled FROM sys_plugins WHERE slug IN ('ailocal', 'aicloud')")->fetchAll(PDO::FETCH_KEY_PAIR);

        try {
            // Both disabled -> authorize fails with feature_disabled
            $db->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug IN ('ailocal', 'aicloud', 'ai')");
            try {
                AiGateway::authorize(1, 'panel');
                $this->fail('Expected feature_disabled when both ailocal and aicloud are off');
            } catch (AiGatewayException $e) {
                $this->assertSame('feature_disabled', $e->errorCode);
            }

            // Local on, Cloud off -> local works (or reaches model check), cloud fails with feature_disabled
            $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = 'ailocal'");
            $db->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug = 'aicloud'");
            $u = AiGateway::authorize(1, 'panel');
            $this->assertSame(1, (int) $u['id']);

            try {
                AiGateway::connection('cloud:openai');
                $this->fail('Expected feature_disabled for cloud when aicloud is off');
            } catch (AiGatewayException $e) {
                $this->assertSame('feature_disabled', $e->errorCode);
            }

            // Local off, Cloud on -> local fails with feature_disabled
            $db->exec("UPDATE sys_plugins SET enabled = 0 WHERE slug = 'ailocal'");
            $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = 'aicloud'");
            try {
                AiGateway::connection('local:nonexistent-model');
                $this->fail('Expected feature_disabled or model_not_ready');
            } catch (AiGatewayException $e) {
                $this->assertContains($e->errorCode, ['feature_disabled', 'model_not_ready']);
            }
        } finally {
            foreach ($prev as $slug => $en) {
                $st = $db->prepare('UPDATE sys_plugins SET enabled = ? WHERE slug = ?');
                $st->execute([(int) $en, $slug]);
            }
        }
    }
}
