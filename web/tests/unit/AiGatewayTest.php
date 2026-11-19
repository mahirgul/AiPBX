<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiGateway.php';
final class AiGatewayTest extends TestCase
{
    private array $settings;
    private array $plugins;
    private array $session;
    private int $uid;
    private string $token;
    protected function setUp(): void
    {
        $db=getDB(); $this->settings=$db->query('SELECT * FROM sys_settings')->fetchAll();
        $this->plugins=$db->query("SELECT * FROM sys_plugins WHERE slug IN ('ailocal','aicloud','ai-assistant','ai-knowledge')")->fetchAll();
        $this->session=$_SESSION;
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.nvidia.%' OR setting_key LIKE 'ai_tts.openai.%' OR setting_key='privacy_block_cloud_ai'");
        $db->exec("INSERT INTO sys_users (username,password_hash,full_name,email,role,is_active,is_device,token_epoch) VALUES ('gateway_test_user','','Gateway Test','','admin',1,0,0)");
        $this->uid=(int)$db->lastInsertId(); $_SESSION['user_id']=$this->uid; $_SESSION['user_role']='admin';
        $db->exec("INSERT INTO sys_plugins (slug,enabled) VALUES ('ailocal',1),('aicloud',1),('ai-assistant',1),('ai-knowledge',1) ON DUPLICATE KEY UPDATE enabled=1");
        CloudAiService::save('nvidia',['api_key'=>'test-provider-secret','model'=>'test-model']);
        $this->token=tempnam(sys_get_temp_dir(),'gateway-token-');file_put_contents($this->token,str_repeat('a',64));putenv('AIPBX_AI_TOKEN_FILE='.$this->token);
    }
    protected function tearDown(): void
    {
        TtsHttp::$transport=null;putenv('AIPBX_AI_TOKEN_FILE');unlink($this->token);$db=getDB();
        $db->prepare('DELETE FROM ai_gateway_tickets WHERE user_id=?')->execute([$this->uid]);
        $db->prepare('DELETE FROM sys_users WHERE id=?')->execute([$this->uid]);
        $db->exec('DELETE FROM sys_settings');$q=$db->prepare('INSERT INTO sys_settings VALUES (?,?)');
        foreach($this->settings as $r)$q->execute([$r['setting_key'],$r['setting_value']]);
        $db->exec("DELETE FROM sys_plugins WHERE slug IN ('ailocal','aicloud','ai-assistant','ai-knowledge')");
        foreach($this->plugins as $r)$db->prepare('INSERT INTO sys_plugins (`'.implode('`,`',array_keys($r)).'`) VALUES ('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r));
        $_SESSION=$this->session;
    }
    private function transport(?callable $before=null): void
    {
        TtsHttp::$transport=function($method,$url,$headers,$body)use($before){
            $this->assertStringEndsWith('/v1/gateway/execute',$url);
            $this->assertStringNotContainsString('test-provider-secret',$body);
            $d=json_decode($body,true);if($before)$before($d);
            $c=AiGateway::resolve($d['request_id'],hash('sha256',$body));
            $this->assertSame('test-provider-secret',$c['connection']['api_key']);
            return ['status'=>200,'type'=>'application/json','body'=>json_encode(['protocol_version'=>1,'request_id'=>$d['request_id'],'status'=>'succeeded','output'=>'OK'])];
        };
    }
    public function testReferenceAndCleanup(): void
    {
        $this->transport();$this->assertSame('OK',AiGateway::chat('cloud:nvidia','Hi','panel'));
        $this->assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM ai_gateway_tickets')->fetchColumn());
    }
    public function testCloudRevocation(): void
    {
        $this->transport(fn()=>setSystemSetting('privacy_block_cloud_ai','1'));
        $this->expectExceptionMessage('policy_blocked');AiGateway::chat('cloud:nvidia','Hi','panel');
    }
    public function testPrincipalRevocation(): void
    {
        $this->transport(fn()=>getDB()->exec('UPDATE sys_users SET token_epoch=1 WHERE id='.$this->uid));
        $this->expectExceptionMessage('permission_denied');AiGateway::chat('cloud:nvidia','Hi','panel');
    }
    public function testCredentialRotation(): void
    {
        $this->transport(fn()=>setSystemSetting('ai_tts.nvidia.api_key',SecretBox::encrypt('new-secret')));
        $this->expectExceptionMessage('configuration_changed');AiGateway::chat('cloud:nvidia','Hi','panel');
    }
    public function testForgedRole(): void
    {
        getDB()->exec("UPDATE sys_users SET role='user' WHERE id=".$this->uid);
        $this->expectExceptionMessage('permission_denied');AiGateway::chat('cloud:nvidia','Hi','panel');
    }
    public function testTamperAndReplay(): void
    {
        TtsHttp::$transport=function($method,$url,$headers,$body){
            $d=json_decode($body,true);
            try{AiGateway::resolve($d['request_id'],str_repeat('0',64));$this->fail('tamper accepted');}
            catch(AiGatewayException $e){$this->assertSame('permission_denied',$e->errorCode);}
            AiGateway::resolve($d['request_id'],hash('sha256',$body));
            $this->expectExceptionMessage('permission_denied');AiGateway::resolve($d['request_id'],hash('sha256',$body));return [];
        };
        AiGateway::chat('cloud:nvidia','Hi','panel');
    }
    public function testDraftCredentialSealed(): void
    {
        TtsHttp::$transport=function($method,$url,$headers,$body){
            $this->assertStringNotContainsString('draft-secret',$body);$d=json_decode($body,true);
            $sealed=getDB()->query('SELECT draft_sealed FROM ai_gateway_tickets')->fetchColumn();
            $this->assertStringStartsWith('sb1:',$sealed);$this->assertStringNotContainsString('draft-secret',$sealed);
            $c=AiGateway::resolve($d['request_id'],hash('sha256',$body));$this->assertSame('draft-secret',$c['connection']['api_key']);
            return ['status'=>200,'type'=>'application/json','body'=>json_encode(['protocol_version'=>1,'request_id'=>$d['request_id'],'status'=>'succeeded','output'=>'OK'])];
        };
        $this->assertSame('OK',AiGateway::chat('cloud:nvidia','Hi','panel',300,['api_key'=>'draft-secret']));
        $this->assertSame('test-provider-secret',CloudAiService::config('nvidia')['api_key']);
    }
    public function testResolveEmbedOperation(): void
    {
        CloudAiService::save('openai',['api_key'=>'test-openai-secret','model'=>'gpt-4.1-mini']);
        TtsHttp::$transport=function($method,$url,$headers,$body){
            $d=json_decode($body,true);
            $this->assertSame('embed',$d['operation']);
            $c=AiGateway::resolve($d['request_id'],hash('sha256',$body));
            $this->assertSame('test-openai-secret',$c['connection']['api_key']);
            $this->assertStringEndsWith('/embeddings',$c['connection']['url']);
            return ['status'=>200,'type'=>'application/json','body'=>json_encode(['protocol_version'=>1,'request_id'=>$d['request_id'],'status'=>'succeeded','embedding'=>[0.25,0.75],'dimensions'=>2,'resolved_model_ref'=>'cloud:openai','usage'=>[]])];
        };
        $res=AiGateway::embed('cloud:openai','Hello world','knowledge');
        $this->assertSame([0.25,0.75],$res['embedding']);
        $this->assertSame(2,$res['dimensions']);
        $this->assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM ai_gateway_tickets')->fetchColumn());
    }
}
