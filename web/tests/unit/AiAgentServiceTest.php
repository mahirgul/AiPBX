<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/services/ai/AiAgentService.php';

/** AI → External agents: what is accepted, and what Asterisk gets. */
final class AiAgentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'agent';
        $_SESSION['user_id'] = null;
        $db = getDB();
        $db->exec("DELETE FROM pbx_ai_agents WHERE title LIKE 'zztest%'");
        $db->exec("DELETE FROM pbx_dids WHERE did_number = '7778990'");
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_dids WHERE did_number = '7778990'");
        $db->exec("DELETE FROM pbx_ai_agents WHERE title LIKE 'zztest%'");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'ai_agent'");
    }

    private function agent(array $over = []): array
    {
        return $over + ['title' => 'zztest agent', 'sip_uri' => 'sip:bot@voice.example.com:5070', 'transport' => 'udp',
            'auth_username' => '', 'auth_password' => '', 'max_concurrent' => 3, 'max_minutes' => 10, 'ring_seconds' => 15,
            'handover_dest_type' => 'hangup', 'handover_dest_id' => '', 'fallback_dest_type' => 'hangup', 'fallback_dest_id' => '',
            'is_active' => '1'];
    }

    public function testSipAddresses(): void
    {
        $this->assertSame(['scheme' => 'sip', 'user' => 'bot', 'host' => 'voice.example.com', 'port' => 5070],
            AiAgentService::parseUri('sip:bot@voice.example.com:5070'));
        $this->assertSame(['scheme' => 'sips', 'user' => '+15551234', 'host' => '[2001:db8::1]', 'port' => 0],
            AiAgentService::parseUri('sips:+15551234@[2001:db8::1]'));
        $this->assertSame('', AiAgentService::parseUri('sip:10.0.0.9')['user']);
        // Nothing that could run in the dialplan or cut the Dial line.
        foreach (['sip:${SHELL(id)}@h', 'sip:a@h,1,G(x)', 'sip:a@h;transport=tcp', 'http://h', 'sip:a@h:99999', 'sip:a b@h', 'sip:a@h)'] as $bad) {
            $this->assertNull(AiAgentService::parseUri($bad), $bad);
        }
    }

    public function testValidation(): void
    {
        foreach ([['title' => ''], ['sip_uri' => 'sip:$x@h'], ['transport' => 'ws'], ['auth_username' => 'a;b'],
                  ['auth_username' => 'u', 'auth_password' => "p\nq"], ['fallback_dest_type' => 'X;'], ['fallback_dest_type' => '']] as $bad) {
            try {
                AiAgentService::validate($this->agent($bad));
                $this->fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $row = AiAgentService::validate($this->agent(['sip_uri' => 'sips:bot@h', 'transport' => 'udp', 'max_concurrent' => 999, 'handover_dest_type' => '']));
        $this->assertSame('tls', $row['transport']); // sips: is TLS whatever was chosen
        $this->assertSame(100, $row['max_concurrent']);
        $this->assertSame('', $row['handover_dest_type']);
    }

    public function testPasswordIsEncryptedAndKeptWhenLeftEmpty(): void
    {
        $id = AiAgentService::save($this->agent(['auth_username' => 'acct', 'auth_password' => 'S3cret;x']));
        $stored = AiAgentService::get($id);
        $this->assertNotSame('S3cret;x', $stored['auth_password']);
        $this->assertSame('S3cret;x', AiAgentService::password($stored));

        AiAgentService::save($this->agent(['id' => $id, 'auth_username' => 'acct', 'auth_password' => '']));
        $this->assertSame('S3cret;x', AiAgentService::password(AiAgentService::get($id)));

        // No user name: no password either.
        AiAgentService::save($this->agent(['id' => $id]));
        $this->assertSame('', AiAgentService::password(AiAgentService::get($id)));
    }

    public function testInUseAgentIsNotDeleted(): void
    {
        $id = AiAgentService::save($this->agent());
        getDB()->prepare("INSERT INTO pbx_dids (did_number, title, dest_type, dest_id) VALUES ('7778990', 'zztest', 'ai_agent', ?)")->execute([(string) $id]);
        try {
            AiAgentService::delete($id);
            $this->fail('deleted an agent an inbound route uses');
        } catch (\InvalidArgumentException) {
            $this->assertNotNull(AiAgentService::get($id));
        }
        getDB()->exec("DELETE FROM pbx_dids WHERE did_number = '7778990'");
        AiAgentService::delete($id);
        $this->assertNull(AiAgentService::get($id));
    }

    public function testEndpointTakesNoCallsFromOutside(): void
    {
        $id = AiAgentService::save($this->agent(['transport' => 'tcp', 'auth_username' => 'acct', 'auth_password' => 'pw;1']));
        $conf = buildAiAgentPjsipConf([AiAgentService::get($id)]);
        $this->assertStringContainsString("[aiagent-{$id}]\ntype=endpoint\ntransport=transport-tcp\ncontext=aipbx-ai-agent-xfer-{$id}\nidentify_by=ip\n", $conf);
        $this->assertStringNotContainsString('type=identify', $conf);
        $this->assertStringNotContainsString('vp8', $conf);
        $this->assertStringContainsString("outbound_auth=aiagent-{$id}-auth", $conf);
        $this->assertStringContainsString('password=pw\;1', $conf);
    }

    public function testDialplan(): void
    {
        $id = AiAgentService::save($this->agent(['transport' => 'tcp', 'handover_dest_type' => 'hangup', 'fallback_dest_type' => 'hangup']));
        $conf = buildAiAgentDialplan([AiAgentService::get($id)]);
        $this->assertStringContainsString("[aipbx-ai-agent-{$id}]", $conf);
        $this->assertStringContainsString("GROUP_COUNT(a{$id}@aipbx_ai_agent)} > 3]?fallback", $conf);
        $this->assertStringContainsString("Dial(PJSIP/aiagent-{$id}/sip:bot@voice.example.com:5070;transport=tcp,15,S(600)b(aipbx-ai-agent-headers^s^1(", $conf);
        $this->assertStringContainsString('X-AiPBX-Caller', $conf);
        $this->assertStringContainsString("[aipbx-ai-agent-xfer-{$id}]\nexten => h,1,NoOp(AI agent {$id}: call ended)\nexten => _[0-9A-Za-z+*#]!,1,", $conf);
        // Every REFER ends in the hand-over destination: nothing in the transfer context dials out.
        $xfer = substr($conf, strpos($conf, "[aipbx-ai-agent-xfer-{$id}]"));
        $this->assertStringNotContainsString('Dial(', $xfer);
        $this->assertStringNotContainsString('${EXTEN},', $xfer);

        $this->assertStringContainsString('Goto(aipbx-ai-agent-' . $id . ',s,1)', buildDestinationLines('ai_agent', (string) $id));
        $this->assertSame("; ==========================================================\n; External AI agents (Auto-generated by AI PBX)\n; ==========================================================\n\n; No active AI agents\n",
            buildAiAgentDialplan([]));
    }
}
