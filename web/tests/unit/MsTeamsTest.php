<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/auth.php';
require_once '/var/www/html/src/repositories/MsTeamsRepository.php';
require_once '/var/www/html/src/services/MsTeamsService.php';

final class MsTeamsTest extends TestCase
{
    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('Testler yalnizca asterisk_test uzerinde kosmali');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $db = getDB();
        $db->exec('DELETE FROM teams_user_mappings');
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'teams_%'");
    }

    public function testDefaultSettingsContainExpectedKeys(): void
    {
        $settings = MsTeamsRepository::currentSettings();
        $this->assertArrayHasKey('teams_enabled', $settings);
        $this->assertArrayHasKey('teams_domain', $settings);
        $this->assertArrayHasKey('teams_sip_port', $settings);
        $this->assertArrayHasKey('teams_webhook_enabled', $settings);
        $this->assertArrayHasKey('teams_webhook_url', $settings);
        $this->assertSame('5061', $settings['teams_sip_port']);
    }

    public function testSaveAndRetrieveSettings(): void
    {
        $data = [
            'teams_enabled' => '1',
            'teams_domain'  => 'sbc.testdomain.com',
            'teams_sip_port' => '5061',
            'teams_sbc_name' => 'AiPBX-Test-SBC',
        ];

        $saved = MsTeamsRepository::saveSettings($data);
        $this->assertTrue($saved);

        $current = MsTeamsRepository::currentSettings();
        $this->assertSame('1', $current['teams_enabled']);
        $this->assertSame('sbc.testdomain.com', $current['teams_domain']);
        $this->assertSame('AiPBX-Test-SBC', $current['teams_sbc_name']);
    }

    public function testUserMappingCrud(): void
    {
        // 1. Ekleme
        $newMapping = [
            'extension'              => '201',
            'teams_upn'              => 'ahmet@firma.com',
            'phone_number'           => '+902129990021',
            'direct_routing_enabled' => '1',
            'notes'                  => 'Satis Muduru',
        ];

        $res = MsTeamsRepository::saveUserMapping($newMapping);
        $this->assertTrue($res['success']);
        $this->assertGreaterThan(0, $res['id']);
        $id = $res['id'];

        // 2. Bulma
        $found = MsTeamsRepository::findUserMapping($id);
        $this->assertNotNull($found);
        $this->assertSame('201', $found['extension']);
        $this->assertSame('ahmet@firma.com', $found['teams_upn']);

        // 3. Güncelleme
        $updateData = [
            'id'                     => $id,
            'extension'              => '201',
            'teams_upn'              => 'ahmet.yeni@firma.com',
            'phone_number'           => '+902129990022',
            'direct_routing_enabled' => '1',
            'notes'                  => 'Genel Mudur',
        ];
        $updateRes = MsTeamsRepository::saveUserMapping($updateData);
        $this->assertTrue($updateRes['success']);

        $updated = MsTeamsRepository::findUserMapping($id);
        $this->assertSame('ahmet.yeni@firma.com', $updated['teams_upn']);

        // 4. Silme
        $deleted = MsTeamsRepository::deleteUserMapping($id);
        $this->assertTrue($deleted);
        $this->assertNull(MsTeamsRepository::findUserMapping($id));
    }

    public function testUserMappingValidation(): void
    {
        // Boş dahili
        $res1 = MsTeamsRepository::saveUserMapping([
            'extension' => '',
            'teams_upn' => 'user@firma.com',
        ]);
        $this->assertFalse($res1['success']);

        // Geçersiz e-posta
        $res2 = MsTeamsRepository::saveUserMapping([
            'extension' => '202',
            'teams_upn' => 'gecersiz-eposta',
        ]);
        $this->assertFalse($res2['success']);
    }

    public function testDirectRoutingServiceValidation(): void
    {
        // FQDN boşken aktif edilemez
        $res1 = MsTeamsService::saveDirectRoutingSettings([
            'teams_enabled' => '1',
            'teams_domain'  => '',
        ]);
        $this->assertFalse($res1['success']);

        // Geçersiz port
        $res2 = MsTeamsService::saveDirectRoutingSettings([
            'teams_enabled'  => '1',
            'teams_domain'   => 'sbc.firma.com',
            'teams_sip_port' => '999999',
        ]);
        $this->assertFalse($res2['success']);

        // Başarılı kayıt
        $res3 = MsTeamsService::saveDirectRoutingSettings([
            'teams_enabled'       => '1',
            'teams_domain'        => 'sbc.firma.com',
            'teams_sip_port'      => '5061',
            'teams_tls_cert_path' => '/etc/asterisk/keys/cert.pem',
            'teams_tls_key_path'  => '/etc/asterisk/keys/key.pem',
        ]);
        $this->assertTrue($res3['success']);
    }

    public function testWebhookServiceValidation(): void
    {
        // Webhook aktifken URL zorunlu
        $res1 = MsTeamsService::saveWebhookSettings([
            'teams_webhook_enabled' => '1',
            'teams_webhook_url'     => '',
        ]);
        $this->assertFalse($res1['success']);

        // HTTP yerine HTTPS olmalı
        $res2 = MsTeamsService::saveWebhookSettings([
            'teams_webhook_enabled' => '1',
            'teams_webhook_url'     => 'http://webhook.insecure.com',
        ]);
        $this->assertFalse($res2['success']);

        // Başarılı kayıt
        $res3 = MsTeamsService::saveWebhookSettings([
            'teams_webhook_enabled'     => '1',
            'teams_webhook_url'         => 'https://firma.webhook.office.com/webhookb2/test',
            'teams_notify_missed_calls' => '1',
            'teams_notify_voicemail'    => '1',
        ]);
        $this->assertTrue($res3['success']);
    }

    public function testPowerShellScriptGenerator(): void
    {
        $settings = [
            'teams_domain'   => 'sbc.firma.com',
            'teams_sip_port' => '5061',
        ];
        $mappings = [
            [
                'extension'              => '101',
                'teams_upn'              => 'veli@firma.com',
                'phone_number'           => '+902125550011',
                'direct_routing_enabled' => 1,
            ],
        ];

        $script = MsTeamsService::generatePowerShellScript($settings, $mappings);

        $this->assertStringContainsString('Connect-MicrosoftTeams', $script);
        $this->assertStringContainsString("New-CsOnlinePSTNGateway -Fqdn 'sbc.firma.com'", $script);
        $this->assertStringContainsString('-SipSignalingPort 5061', $script);
        $this->assertStringContainsString("Grant-CsOnlineVoiceRoutingPolicy -Identity 'veli@firma.com'", $script);
        $this->assertStringContainsString("Set-CsPhoneNumberAssignment -Identity 'veli@firma.com' -PhoneNumber '+902125550011'", $script);
    }

    public function testRbacMappingForTeamsModule(): void
    {
        $moduleKey = getModuleKeyForPage('ms_teams.php');
        $this->assertSame('ms_teams', $moduleKey);

        $modules = RoleRepository::modulesDefinition();
        $this->assertArrayHasKey('ms_teams', $modules);
        $this->assertSame('Entegrasyonlar', $modules['ms_teams']['group']);

        $groups = RoleRepository::groupSlugs();
        $this->assertArrayHasKey('Entegrasyonlar', $groups);
        $this->assertSame('integrations', $groups['Entegrasyonlar']);
    }
}
