<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/core/PluginManager.php';
require_once dirname(__DIR__,2).'/src/services/BundledPluginMigrationService.php';

final class BundledPluginMigrationTest extends TestCase
{
    public function testTransitionPackageInstallsThroughPluginManager(): void
    {
        $dir=sys_get_temp_dir().'/aipbx-upload-dialer-'.bin2hex(random_bytes(6));
        $previous=getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR='.$dir);
        $_SESSION['csrf_token']='move-test';$_SESSION['role']='admin';$_SESSION['user_role']='admin';$_SESSION['user_id']=1;
        $db=getDB();$db->beginTransaction();
        try {
            $db->exec("DELETE FROM sys_plugins WHERE slug='auto-dialer'");
            $zip=dirname(__DIR__,3).'/conf/plugin-transitions/auto-dialer-1.1.0.zip';
            $result=PluginManager::installFromZip(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$zip,'size'=>filesize($zip)],'move-test');
            $this->assertTrue($result['success'],json_encode($result));
            $p=PluginManager::getPlugin('auto-dialer');
            $this->assertSame('custom',$p['source']);
            $this->assertSame('1.1.0',$p['version']);
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            putenv($previous===false?'AIPBX_CUSTOM_PLUGINS_DIR':'AIPBX_CUSTOM_PLUGINS_DIR='.$previous);PluginManager::resetCache();
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
                @rmdir($dir);
            }
        }
    }

    public function testCommunityAssistantIsReplacedByTheOfficialPbxAssistant(): void
    {
        $db=getDB();$dir=sys_get_temp_dir().'/aipbx-assistant-retire-'.bin2hex(random_bytes(6));
        mkdir($dir.'/ai-assistant/lib',0700,true);
        file_put_contents($dir.'/ai-assistant/plugin.json','{"slug":"ai-assistant","version":"1.1.0"}');
        file_put_contents($dir.'/ai-assistant/lib/AssistantApi.php','<?php');
        $db->beginTransaction();
        try {
            foreach ([0, 1] as $enabled) {
                $db->prepare("INSERT INTO sys_plugins (slug,name,version,source,enabled) VALUES ('ai-assistant','AI Assistant','1.1.0','custom',?) ON DUPLICATE KEY UPDATE source='custom',name='AI Assistant',enabled=VALUES(enabled)")->execute([$enabled]);
                $token=ApiTokenService::issuePluginToken('ai-assistant','Community assistant',['pbx:read']);
                PluginManager::setSetting('ai-assistant','_worker_api_token',$token,true);
                PluginManager::kvSet('ai-assistant','drafts','d1',['path'=>'/api/v1/queues']);
                BundledPluginMigrationService::retireCommunityAssistant($db,$dir);
                BundledPluginMigrationService::retireCommunityAssistant($db,$dir);   // a second run changes nothing
                PluginManager::resetCache();
                $this->assertDirectoryDoesNotExist($dir.'/ai-assistant');
                $this->assertSame(0,(int)$db->query("SELECT COUNT(*) FROM sys_plugins WHERE slug='ai-assistant'")->fetchColumn(), 'the old id is gone');
                $row=$db->query("SELECT source,name,version,enabled FROM sys_plugins WHERE slug='pbx-assistant'")->fetch(PDO::FETCH_ASSOC);
                $this->assertSame(['source'=>'official','name'=>'PBX Assistant','version'=>'2.0.0','enabled'=>$enabled],
                    ['source'=>$row['source'],'name'=>$row['name'],'version'=>$row['version'],'enabled'=>(int)$row['enabled']]);
                $this->assertSame(0,(int)$db->query("SELECT COUNT(*) FROM sys_api_tokens WHERE plugin_slug='ai-assistant' AND is_active=1")->fetchColumn());
                $this->assertSame(0,(int)$db->query("SELECT COUNT(*) FROM sys_plugin_settings WHERE plugin_slug='ai-assistant'")->fetchColumn());
                $this->assertSame(0,(int)$db->query("SELECT COUNT(*) FROM sys_plugin_kv WHERE plugin_slug='ai-assistant'")->fetchColumn());
                $this->assertSame('official',PluginManager::getPlugin('pbx-assistant')['source'] ?? null, 'the bundled manifest is the one found');
                mkdir($dir.'/ai-assistant',0700);file_put_contents($dir.'/ai-assistant/plugin.json','{}');
            }
        } finally {
            $db->rollBack();PluginManager::resetCache();
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $f){if($f->isDir()){rmdir($f->getPathname());}else{unlink($f->getPathname());}}rmdir($dir);
        }
    }

    public function testAutoDialerMigrationPreservesCampaignsAndWorkerState(): void
    {
        $db=getDB();$dir=sys_get_temp_dir().'/aipbx-dialer-transition-'.bin2hex(random_bytes(6));
        mkdir($dir,0700);
        $zip=new ZipArchive();$zip->open(dirname(__DIR__,3).'/conf/plugin-transitions/auto-dialer-1.1.0.zip');
        $schema=$zip->getFromName('schema.sql');$zip->close();
        $tables=[];
        foreach (PluginManager::parseAndValidatePluginSchemaSql($schema) as $statement) {
            $db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$statement['sql']));
            $tables[]=$statement['table'];
        }
        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM sys_plugins WHERE slug='auto-dialer'");
            BundledPluginMigrationService::migrateAutoDialer($db,$dir);
            $this->assertDirectoryDoesNotExist($dir.'/auto-dialer');
            $db->exec("INSERT INTO plg_autodial_campaigns (id,name,status) VALUES (1,'Preserved campaign','running')");
            $db->exec("INSERT INTO plg_autodial_numbers (campaign_id,phone_number,status,attempts) VALUES (1,'15550001111','answered',2)");
            $campaign=$db->query('SELECT * FROM plg_autodial_campaigns')->fetchAll();
            $numbers=$db->query('SELECT * FROM plg_autodial_numbers')->fetchAll();
            foreach ([0,1] as $enabled) {
                $db->prepare("INSERT INTO sys_plugins (slug,name,version,source,enabled,worker_enabled) VALUES ('auto-dialer','Auto Dialer','1.0.0','official',?,?) ON DUPLICATE KEY UPDATE source='official',enabled=VALUES(enabled),worker_enabled=VALUES(worker_enabled)")->execute([$enabled,$enabled]);
                BundledPluginMigrationService::migrateAutoDialer($db,$dir);
                PluginManager::resetCache();$r=PluginManager::installedMap()['auto-dialer'];
                $this->assertSame('custom',$r['source']);
                $this->assertSame($enabled,(int)$r['enabled']);
                $this->assertSame($enabled,(int)$r['worker_enabled']);
                $this->assertSame($campaign,$db->query('SELECT * FROM plg_autodial_campaigns')->fetchAll());
                $this->assertSame($numbers,$db->query('SELECT * FROM plg_autodial_numbers')->fetchAll());
            }
            BundledPluginMigrationService::migrateAutoDialer($db,$dir);
        } finally {
            $db->rollBack();PluginManager::resetCache();
            foreach ($tables as $table) { $db->exec('DROP TEMPORARY TABLE `'.$table.'`'); }
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $f){if($f->isDir()){rmdir($f->getPathname());}else{unlink($f->getPathname());}}
            rmdir($dir);
        }
    }

    public function testMigrationPreservesEnabledStateSecretsAndSummaries(): void
    {
        $db=getDB();
        $dir=sys_get_temp_dir().'/aipbx-transition-'.bin2hex(random_bytes(6));
        mkdir($dir,0700);
        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM sys_plugins WHERE slug='ai-voicemail'");
            BundledPluginMigrationService::migrateVoicemail($db,$dir);
            $this->assertDirectoryDoesNotExist($dir.'/ai-voicemail');
            foreach ([0,1] as $enabled) {
                $db->prepare("INSERT INTO sys_plugins (slug,name,version,source,enabled) VALUES ('ai-voicemail','Voicemail','1.0.0','official',?) ON DUPLICATE KEY UPDATE source='official',enabled=VALUES(enabled)")->execute([$enabled]);
                PluginManager::setSetting('ai-voicemail','api_key','migration-test-secret',true);
                PluginManager::kvSet('ai-voicemail','summaries','migration-test',['summary'=>'Keep me']);
                BundledPluginMigrationService::migrateVoicemail($db,$dir);
                PluginManager::resetCache();
                $row=PluginManager::installedMap()['ai-voicemail'];
                $this->assertSame($enabled,(int)$row['enabled']);
                $this->assertSame('custom',$row['source']);
                $this->assertSame('1.1.0',$row['version']);
                $this->assertSame('migration-test-secret',PluginManager::getSetting('ai-voicemail','api_key'));
                $this->assertSame('Keep me',PluginManager::kvGet('ai-voicemail','summaries','migration-test')['value']['summary']);
            }
            // A previously uploaded version must not be downgraded or replaced.
            $p=$dir.'/ai-voicemail/plugin.json';$m=json_decode(file_get_contents($p),true);$m['version']='9.0.0';file_put_contents($p,json_encode($m));
            $db->exec("UPDATE sys_plugins SET source='official' WHERE slug='ai-voicemail'");
            BundledPluginMigrationService::migrateVoicemail($db,$dir);
            BundledPluginMigrationService::migrateVoicemail($db,$dir);
            $this->assertSame('9.0.0',$db->query("SELECT version FROM sys_plugins WHERE slug='ai-voicemail'")->fetchColumn());
        } finally {
            $db->rollBack();PluginManager::resetCache();
            foreach (['admin/page.php','events/handler.php','plugin.json'] as $f) { if(is_file($dir.'/ai-voicemail/'.$f)){unlink($dir.'/ai-voicemail/'.$f);} }
            foreach (['/ai-voicemail/admin','/ai-voicemail/events','/ai-voicemail',''] as $d) { if(is_dir($dir.$d)){rmdir($dir.$d);} }
        }
    }
}
