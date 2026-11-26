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

    public function testBindingServiceChoosesOneModelPerFeatureOrOff(): void
    {
        AiBindingService::save('test_feature', 'chat', '');
        $this->assertNull(AiBindingService::resolve('test_feature', 'chat'));
        $this->assertTrue(AiBindingService::isConfigured('test_feature', 'chat'));

        getDB()->exec("INSERT INTO ai_bindings (feature, scope_ref, task, language, mode, model_ref_id, updated_at)
            VALUES ('test_feature', 'global', 'chat', 'default', 'explicit', 'cloud:openai', NOW())
            ON DUPLICATE KEY UPDATE mode = 'explicit', model_ref_id = 'cloud:openai'");
        $this->assertSame('cloud:openai', AiBindingService::resolve('test_feature', 'chat'));

        // Only a usable model can be chosen: nothing is configured in the test database.
        $this->expectException(\InvalidArgumentException::class);
        try {
            AiBindingService::save('test_feature', 'chat', 'cloud:nonexistent');
        } finally {
            getDB()->exec("DELETE FROM ai_bindings WHERE feature = 'test_feature'");
        }
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
                $this->assertContains($e->errorCode, ['feature_disabled', 'model_not_ready', 'binding_unresolved']);
            }
        } finally {
            foreach ($prev as $slug => $en) {
                $st = $db->prepare('UPDATE sys_plugins SET enabled = ? WHERE slug = ?');
                $st->execute([(int) $en, $slug]);
            }
        }
    }
}
