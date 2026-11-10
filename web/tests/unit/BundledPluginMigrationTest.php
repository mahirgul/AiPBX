<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/core/PluginManager.php';
require_once dirname(__DIR__,2).'/src/services/BundledPluginMigrationService.php';

final class BundledPluginMigrationTest extends TestCase
{
    public function testTransitionPackageInstallsThroughPluginManager(): void
    {
        $dir=sys_get_temp_dir().'/aipbx-upload-voicemail-'.bin2hex(random_bytes(6));
        $previous=getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR='.$dir);
        $_SESSION['csrf_token']='move-test';$_SESSION['role']='admin';$_SESSION['user_role']='admin';$_SESSION['user_id']=1;
        $db=getDB();$db->beginTransaction();
        try {
            $zip=dirname(__DIR__,3).'/conf/plugin-transitions/ai-voicemail-1.1.0.zip';
            $result=PluginManager::installFromZip(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$zip,'size'=>filesize($zip)],'move-test');
            $this->assertTrue($result['success'],json_encode($result));
            $p=PluginManager::getPlugin('ai-voicemail');
            $this->assertSame('custom',$p['source']);
            $this->assertSame('community',$p['type']);
            $this->assertSame('1.1.0',$p['version']);
            PluginManager::setSetting('ai-voicemail','api_key','');
            PluginManager::deliverEventToPlugin('ai-voicemail','voicemail.transcribed',[
                'event_id'=>'uploaded-plugin','data'=>['extension'=>'101','summary'=>'Uploaded plugin works']]);
            $key=hash('sha256','101:uploaded-plugin');
            $this->assertSame('Uploaded plugin works',PluginManager::kvGet('ai-voicemail','summaries',$key)['value']['summary']);
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            putenv($previous===false?'AIPBX_CUSTOM_PLUGINS_DIR':'AIPBX_CUSTOM_PLUGINS_DIR='.$previous);PluginManager::resetCache();
            foreach (['admin/page.php','events/handler.php','plugin.json'] as $f) { if(is_file($dir.'/ai-voicemail/'.$f)){unlink($dir.'/ai-voicemail/'.$f);} }
            foreach (['/ai-voicemail/admin','/ai-voicemail/events','/ai-voicemail',''] as $d) { if(is_dir($dir.$d)){rmdir($dir.$d);} }
        }
    }

    public function testAssistantMigrationPreservesTokenDraftsAndHistory(): void
    {
        $db=getDB();$dir=sys_get_temp_dir().'/aipbx-assistant-transition-'.bin2hex(random_bytes(6));mkdir($dir,0700);
        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM sys_plugins WHERE slug='ai-assistant'");
            BundledPluginMigrationService::migrateAssistant($db,$dir);
            $this->assertDirectoryDoesNotExist($dir.'/ai-assistant');
            $token=ApiTokenService::issuePluginToken('ai-assistant','Migration test',['pbx:read']);
            PluginManager::setSetting('ai-assistant','_worker_api_token',$token,true);
            PluginManager::setSetting('ai-assistant','api_key','migration-secret',true);
            PluginManager::kvSet('ai-assistant','drafts','move-test',['method'=>'GET','path'=>'/api/v1/queues']);
            PluginManager::kvSet('ai-assistant','history','move-test',['status_code'=>200]);
            foreach([0,1] as $enabled) {
                $db->prepare("INSERT INTO sys_plugins (slug,name,version,source,enabled) VALUES ('ai-assistant','Assistant','1.0.0','official',?) ON DUPLICATE KEY UPDATE source='official',enabled=VALUES(enabled)")->execute([$enabled]);
                BundledPluginMigrationService::migrateAssistant($db,$dir);PluginManager::resetCache();
                $row=PluginManager::installedMap()['ai-assistant'];
                $this->assertSame('custom',$row['source']);$this->assertSame($enabled,(int)$row['enabled']);
                $this->assertSame($token,PluginManager::getSetting('ai-assistant','_worker_api_token'));
                $this->assertSame('migration-secret',PluginManager::getSetting('ai-assistant','api_key'));
                $this->assertSame('/api/v1/queues',PluginManager::kvGet('ai-assistant','drafts','move-test')['value']['path']);
                $this->assertSame(200,PluginManager::kvGet('ai-assistant','history','move-test')['value']['status_code']);
                $q=$db->prepare('SELECT is_active,scopes_json FROM sys_api_tokens WHERE token_hash=?');$q->execute([hash('sha256',$token)]);
                $tokenRow=$q->fetch(PDO::FETCH_ASSOC);$this->assertSame(1,(int)$tokenRow['is_active']);
                $scopes=json_decode($tokenRow['scopes_json'],true);
                $this->assertContains('queues:read',$scopes);$this->assertNotContains('queues:write',$scopes);
            }
            BundledPluginMigrationService::migrateAssistant($db,$dir);
        } finally {
            $db->rollBack();PluginManager::resetCache();
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $f){if($f->isDir()){rmdir($f->getPathname());}else{unlink($f->getPathname());}}rmdir($dir);
        }
    }

    public function testAssistantPackageUsesHostIssuedApiToken(): void
    {
        $dir=sys_get_temp_dir().'/aipbx-assistant-upload-'.bin2hex(random_bytes(6));
        $previous=getenv('AIPBX_CUSTOM_PLUGINS_DIR');putenv('AIPBX_CUSTOM_PLUGINS_DIR='.$dir);
        $_SESSION=['csrf_token'=>'move-test','role'=>'admin','user_role'=>'admin','user_id'=>1];
        $db=getDB();$db->beginTransaction();
        try {
            $zip=dirname(__DIR__,3).'/conf/plugin-transitions/ai-assistant-1.1.0.zip';
            $result=PluginManager::installFromZip(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$zip,'size'=>filesize($zip)],'move-test');
            $this->assertTrue($result['success'],json_encode($result));
            $this->assertSame('custom',PluginManager::getPlugin('ai-assistant')['source']);
            require $dir.'/ai-assistant/lib/AssistantApi.php';
            $requests=[];AssistantApi::$http=static function($method,$url,$headers,$body) use (&$requests): array {
                $requests[]=[$method,$url,$headers];return [200,'{"success":true}'];
            };
            $this->assertSame(200,AssistantApi::execute('GET','/api/v1/queues')['status_code']);
            $token=PluginManager::getSetting('ai-assistant','_worker_api_token');$this->assertNotSame('',$token);
            $q=$db->prepare('SELECT scopes_json FROM sys_api_tokens WHERE token_hash=?');$q->execute([hash('sha256',$token)]);
            $this->assertContains('queues:write',json_decode($q->fetchColumn(),true));
            $this->assertContains('Authorization: Bearer '.$token,$requests[0][2]);
            PluginManager::disable('ai-assistant','move-test');
            $this->assertSame(403,AssistantApi::execute('GET','/api/v1/queues')['status_code']);
            $this->assertCount(1,$requests);
        } finally {
            AssistantApi::$http=null;
            $db->rollBack();putenv($previous===false?'AIPBX_CUSTOM_PLUGINS_DIR':'AIPBX_CUSTOM_PLUGINS_DIR='.$previous);PluginManager::resetCache();
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
