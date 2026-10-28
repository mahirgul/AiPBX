<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/PhoneProvisionService.php';

/**
 * Desk phone provisioning (#19): vendor templates, token and MAC requests,
 * waiting phones, allowed networks, CSV import, key layouts, the fetch log.
 */
final class PhoneProvisionTest extends TestCase
{
    private const EXT = '7701';
    private const EXT2 = '7702';
    /** Random per run (no literal passwords in the repository). */
    private static string $sipPassword = '';
    private static string $adminPassword = '';

    public static function setUpBeforeClass(): void
    {
        self::$sipPassword = 'S' . bin2hex(random_bytes(6)) . '!';
        self::$adminPassword = 'A' . bin2hex(random_bytes(5));
    }

    private PDO $db;
    private int $userId;
    private int $user2Id;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->cleanUp();
        putenv('PORTAL_DOMAIN=pbx.test.example');
        $_SESSION['csrf_token'] = 'phones-test-csrf';

        $ins = $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active)
            VALUES (?, '', ?, ?, 'user', ?, 'sip', ?, 1)");
        $ins->execute(['phonetest1', "Ayşe\nYılmaz", 'p1@example.com', self::EXT, self::$sipPassword]);
        $this->userId = (int) $this->db->lastInsertId();
        $ins->execute(['phonetest2', 'Second User', 'p2@example.com', self::EXT2, 'Other-Secret-2!']);
        $this->user2Id = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        putenv('PORTAL_DOMAIN');
    }

    private function cleanUp(): void
    {
        $this->db->exec('DELETE FROM pbx_phone_fetch_log');
        $this->db->exec('DELETE FROM pbx_phones_waiting');
        $this->db->exec('DELETE FROM pbx_phones');
        $this->db->exec("DELETE FROM sys_users WHERE username IN ('phonetest1', 'phonetest2')");
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'provision\\_%'");
    }

    private function setSetting(string $key, string $value): void
    {
        $this->db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }

    private function addPhone(string $mac, string $model, ?int $userId = null): array
    {
        $id = PhoneProvisionService::upsertPhone(0, $mac, $model, $userId ?? $this->userId);
        return PhoneProvisionService::getPhone($id);
    }

    private function context(string $model, array $keys = []): array
    {
        return [
            'mac' => '001565aabbcc', 'model' => $model, 'extension' => self::EXT,
            'display_name' => "Ali\nlinekey.9.type = 16", 'sip_password' => self::$sipPassword,
            'server' => 'pbx.test.example', 'proxy' => '', 'port' => 5060, 'transport' => 'udp', 'srtp' => 1,
            'codecs' => ['g722', 'pcma'], 'ntp' => 'pool.ntp.org', 'utc_offset' => 180, 'language' => 'tr',
            'voicemail' => '*97', 'pickup_prefix' => '*21', 'admin_password' => self::$adminPassword,
            'keys' => $keys,
        ];
    }

    // ------------------------------------------------------------ templates

    public function testPreviewHidesEveryFormOfThePassword(): void
    {
        $forms = PhoneProvisionService::secretForms(['P@ss&<1234', str_repeat('a', 40), 'abc']);
        $this->assertContains('P@ss&<1234', $forms);
        $this->assertContains('P@ss&amp;&lt;1234', $forms, 'XML-escaped form');
        $this->assertContains(str_repeat('a', 31), $forms, 'cut to 31 (NEC)');
        $this->assertNotContains('abc', $forms, 'too short to mask');
        $lengths = array_map('strlen', $forms);
        $sorted = $lengths;
        rsort($sorted);
        $this->assertSame($sorted, $lengths, 'longest first');
    }

    public function testYealinkRendersAccountKeysAndExpansionModule(): void
    {
        $out = (new YealinkTemplate())->render($this->context('yealink-t46u', [
            ['page' => 0, 'position' => 1, 'type' => 'line', 'target' => '', 'label' => ''],
            ['page' => 0, 'position' => 2, 'type' => 'blf', 'target' => '1002', 'label' => 'Mehmet'],
            ['page' => 1, 'position' => 3, 'type' => 'speeddial', 'target' => '05551234567', 'label' => 'Mobile'],
        ]));
        $this->assertStringStartsWith('#!version:1.0.0.1', $out);
        $this->assertStringContainsString('account.1.user_name = ' . self::EXT, $out);
        $this->assertStringContainsString('account.1.password = ' . self::$sipPassword, $out);
        $this->assertStringContainsString('account.1.sip_server.1.address = pbx.test.example', $out);
        $this->assertStringContainsString('account.1.srtp_encryption = 1', $out);
        $this->assertStringContainsString('voice_mail.number.1 = *97', $out);
        $this->assertStringContainsString('features.pickup.direct_pickup_code = *21', $out);
        $this->assertStringContainsString('local_time.time_zone = +3', $out);
        $this->assertStringContainsString('lang.gui = Turkish', $out);
        $this->assertStringContainsString('static.security.user_password = admin:' . self::$adminPassword, $out);
        $this->assertStringContainsString("linekey.1.type = 15", $out);
        $this->assertStringContainsString("linekey.2.type = 16\nlinekey.2.line = 1\nlinekey.2.value = 1002\nlinekey.2.label = Mehmet", $out);
        $this->assertStringContainsString('linekey.3.type = 0', $out);
        $this->assertStringContainsString('expansion_module.1.key.3.type = 13', $out);
        $this->assertStringContainsString('expansion_module.1.key.3.value = 05551234567', $out);
        $this->assertStringContainsString('account.1.codec.g722.priority = 1', $out);
        $this->assertStringContainsString('account.1.codec.g729.enable = 0', $out);
        // A display name with a line break cannot add a setting of its own.
        $this->assertStringNotContainsString("\nlinekey.9.type = 16", $out);
        $this->assertStringContainsString('account.1.display_name = Alilinekey.9.type = 16', $out);
    }

    public function testGrandstreamRendersPValues(): void
    {
        $ctx = $this->context('grandstream-gxp2170', [
            ['page' => 0, 'position' => 1, 'type' => 'blf', 'target' => '1002', 'label' => 'A&B'],
            ['page' => 0, 'position' => 2, 'type' => 'park', 'target' => '701', 'label' => 'Park'],
        ]);
        $ctx['transport'] = 'tls';
        $ctx['port'] = 5061;
        $out = (new GrandstreamTemplate())->render($ctx);
        $xml = simplexml_load_string($out);
        $this->assertNotFalse($xml, 'valid XML');
        $this->assertSame('001565aabbcc', (string) $xml->mac);
        $c = $xml->config;
        $this->assertSame('pbx.test.example:5061', (string) $c->P47);
        $this->assertSame(self::EXT, (string) $c->P35);
        $this->assertSame(self::$sipPassword, (string) $c->P34);
        $this->assertSame('2', (string) $c->P130);
        $this->assertSame('*97', (string) $c->P33);
        $this->assertSame('9', (string) $c->P57);   // G.722 first
        $this->assertSame('8', (string) $c->P58);   // then PCMA
        $this->assertSame('1', (string) $c->P323);  // key 1: BLF
        $this->assertSame('1002', (string) $c->P303);
        $this->assertSame('A&B', (string) $c->P302);
        $this->assertSame('9', (string) $c->P324);  // key 2: call park
        $this->assertSame('UTC-3', (string) $c->P246);
        $this->assertSame(self::$adminPassword, (string) $c->P2);
    }

    public function testFanvilRendersSectionsAndKeys(): void
    {
        $out = (new FanvilTemplate())->render($this->context('fanvil-x4u', [
            ['page' => 0, 'position' => 1, 'type' => 'blf', 'target' => '1002', 'label' => 'Mehmet'],
            ['page' => 0, 'position' => 2, 'type' => 'dnd', 'target' => '', 'label' => 'DND'],
        ]));
        $this->assertStringStartsWith('<<VOIP CONFIG FILE>>', $out);
        $this->assertStringContainsString("<<END OF FILE>>\n", $out);
        $this->assertStringContainsString('SIP1 Register User :' . self::EXT, $out);
        $this->assertStringContainsString('SIP1 Register Pswd :' . self::$sipPassword, $out);
        $this->assertStringContainsString('SIP1 Register Addr :pbx.test.example', $out);
        $this->assertStringContainsString('SIP1 MWI Num       :*97', $out);
        $this->assertStringContainsString("Fkey1 Type :1\nFkey1 Value :1002@1/b\nFkey1 Title :Mehmet", $out);
        $this->assertStringContainsString("Fkey2 Type :3\nFkey2 Value :F_DND", $out);
        $this->assertStringContainsString('Fkey3 Type :0', $out);
    }

    /** A display name that tries to break out of the XML value. */
    private const HOSTILE_NAME = "Ali\" x=\"1\"<b>&\nx";

    /** Keys used by the phase 2 vendor tests: line, BLF, speed dial, DND and a module key. */
    private function phase2Keys(): array
    {
        return [
            ['page' => 0, 'position' => 1, 'type' => 'line', 'target' => '', 'label' => ''],
            ['page' => 0, 'position' => 2, 'type' => 'blf', 'target' => '1002', 'label' => 'Mehmet'],
            ['page' => 0, 'position' => 3, 'type' => 'dnd', 'target' => '', 'label' => 'DND'],
            ['page' => 1, 'position' => 1, 'type' => 'speeddial', 'target' => '05551234567', 'label' => 'Mobile'],
        ];
    }

    private function hostileContext(string $model): array
    {
        $ctx = $this->context($model, $this->phase2Keys());
        $ctx['display_name'] = self::HOSTILE_NAME;
        return $ctx;
    }

    public function testSnomRendersXmlAccountAndKeys(): void
    {
        $out = (new SnomTemplate())->render($this->hostileContext('snom-d785'));
        $xml = simplexml_load_string($out);
        $this->assertNotFalse($xml, 'valid XML');
        $ps = $xml->{'phone-settings'};
        $this->assertSame(self::EXT, (string) $ps->user_name);
        $this->assertSame(self::$sipPassword, (string) $ps->user_pass);
        $this->assertSame('pbx.test.example', (string) $ps->user_host);
        $this->assertSame('*97', (string) $ps->user_mailbox);
        $this->assertSame('optional', (string) $ps->user_savp);
        $this->assertSame('10800', (string) $ps->utc_offset);
        $this->assertSame(self::$adminPassword, (string) $ps->admin_mode_password);
        // The hostile name stays one value (quotes, tags, & and the line break do nothing).
        $this->assertSame('Ali" x="1"<b>&x', (string) $ps->user_realname);

        $keys = [];
        foreach ($xml->functionKeys->fkey as $k) {
            $keys[(int) $k['idx']] = ['label' => (string) $k['label'], 'value' => (string) $k];
        }
        $this->assertSame('line', $keys[0]['value']);
        $this->assertSame('blf <sip:1002@pbx.test.example>|*21', $keys[1]['value']);
        $this->assertSame('Mehmet', $keys[1]['label']);
        $this->assertSame('keyevent F_DND', $keys[2]['value']);
        $this->assertSame('none', $keys[3]['value']);
        // D785: 24 phone keys, the first D7 module key is idx 24.
        $this->assertSame('speed 05551234567', $keys[24]['value']);
        $this->assertCount(24 + 18 * 3, $keys);
    }

    public function testCiscoSpaRendersFlatProfile(): void
    {
        $ctx = $this->hostileContext('cisco-spa508g');
        $ctx['keys'][] = ['page' => 0, 'position' => 4, 'type' => 'blf', 'target' => '1003', 'label' => 'a;fnc=dnd'];
        $out = (new CiscoSpaTemplate())->render($ctx);
        $xml = simplexml_load_string($out);
        $this->assertNotFalse($xml, 'valid XML');
        $this->assertSame(self::EXT, (string) $xml->User_ID_1_);
        $this->assertSame(self::$sipPassword, (string) $xml->Password_1_);
        $this->assertSame('pbx.test.example:5060', (string) $xml->Proxy_1_);
        $this->assertSame('UDP', (string) $xml->SIP_Transport_1_);
        $this->assertSame('*97', (string) $xml->Voice_Mail_Number);
        $this->assertSame('*21', (string) $xml->Call_Pickup_Code);
        $this->assertSame('GMT+03:00', (string) $xml->Time_Zone);
        $this->assertSame('G722', (string) $xml->Preferred_Codec_1_);
        $this->assertSame('G711a', (string) $xml->Second_Preferred_Codec_1_);
        $this->assertSame(self::$adminPassword, (string) $xml->Admin_Passwd);
        $this->assertSame('Ali" x="1"<b>&x', (string) $xml->Display_Name_1_);
        $this->assertSame('1', (string) $xml->Extension_1_);
        $this->assertSame('Disabled', (string) $xml->Extension_2_);
        $this->assertSame('fnc=blf+sd+cp;sub=1002@$PROXY;ext=1002@$PROXY;nme=Mehmet', (string) $xml->Extended_Function_2_);
        $this->assertSame('fnc=dnd', (string) $xml->Extended_Function_3_);
        // A label cannot add fields to the function string.
        $this->assertSame('fnc=blf+sd+cp;sub=1003@$PROXY;ext=1003@$PROXY;nme=afncdnd', (string) $xml->Extended_Function_4_);
        $this->assertSame('fnc=sd;ext=05551234567@$PROXY;nme=Mobile', (string) $xml->Unit_1_Key_1_);
        $this->assertSame('', (string) $xml->Unit_2_Key_32_);
    }

    public function testPolyRendersMasterAndSettingsFile(): void
    {
        $tpl = new PolyTemplate();
        $ctx = $this->hostileContext('poly-vvx450');
        $ctx['mac'] = '0004f2aabbcc';

        $master = simplexml_load_string($tpl->renderFile('0004f2aabbcc.cfg', $ctx));
        $this->assertNotFalse($master);
        $this->assertSame('phone0004f2aabbcc.cfg', (string) $master['CONFIG_FILES']);
        $this->assertStringNotContainsString(self::$sipPassword, $tpl->renderFile('0004f2aabbcc.cfg', $ctx));

        $xml = simplexml_load_string($tpl->renderFile('phone0004f2aabbcc.cfg', $ctx));
        $this->assertNotFalse($xml, 'valid XML');
        $a = $xml->children()[0]->attributes();
        $this->assertSame(self::EXT, (string) $a['reg.1.auth.userId']);
        $this->assertSame(self::$sipPassword, (string) $a['reg.1.auth.password']);
        $this->assertSame('pbx.test.example', (string) $a['reg.1.server.1.address']);
        $this->assertSame('UDPOnly', (string) $a['reg.1.server.1.transport']);
        $this->assertSame('*97', (string) $a['msg.mwi.1.callBack']);
        $this->assertSame('*21', (string) $a['call.directedCallPickupString']);
        $this->assertSame('10800', (string) $a['tcpIpApp.sntp.gmtOffset']);
        $this->assertSame('1', (string) $a['voice.codecPref.G722']);
        $this->assertSame('0', (string) $a['voice.codecPref.G729_AB']);
        $this->assertSame(self::$adminPassword, (string) $a['device.auth.localAdminPassword']);
        $this->assertSame('Ali" x="1"<b>&x', (string) $a['reg.1.displayName']);
        $this->assertNull($a['x'], 'no attribute injected');
        $this->assertSame('Line', (string) $a['lineKey.1.category']);
        $this->assertSame('BLF', (string) $a['lineKey.2.category']);
        $this->assertSame('1002', (string) $a['attendant.resourceList.1.address']);
        $this->assertSame('normal', (string) $a['attendant.resourceList.1.type']);
        $this->assertSame('DND', (string) $a['lineKey.3.category']);
        $this->assertSame('Unassigned', (string) $a['lineKey.4.category']);
        // VVX 450: 12 phone keys, the first module key is line key 13.
        $this->assertSame('BLF', (string) $a['lineKey.13.category']);
        $this->assertSame('05551234567', (string) $a['attendant.resourceList.2.address']);
        $this->assertSame('automata', (string) $a['attendant.resourceList.2.type']);
    }

    public function testPolyServesBothFilesForItsToken(): void
    {
        $phone = $this->addPhone('0004f2aabbcc', 'poly-vvx450');
        $master = PhoneProvisionService::handleRequest($phone['token'], '0004f2aabbcc.cfg', '192.0.2.10', 'PolycomVVX-VVX_450-UA/6.4');
        $this->assertSame(200, $master['status']);
        $this->assertStringContainsString('CONFIG_FILES="phone0004f2aabbcc.cfg"', $master['body']);
        $settings = PhoneProvisionService::handleRequest($phone['token'], 'phone0004f2aabbcc.cfg', '192.0.2.10', 'PolycomVVX-VVX_450-UA/6.4');
        $this->assertSame(200, $settings['status']);
        $this->assertStringContainsString('reg.1.auth.password="' . self::$sipPassword . '"', $settings['body']);
        $this->assertSame(404, PhoneProvisionService::handleRequest($phone['token'], '000000000000.cfg', '192.0.2.10', '')['status']);
    }

    public function testPhase2VendorsAreGuessedFromUserAgentAndMac(): void
    {
        $this->assertSame('snom', PhoneModels::guessVendor('000413aabbcc', ''));
        $this->assertSame('snom', PhoneModels::guessVendor('aabbccddeeff', 'snomD785/10.1.159.12'));
        $this->assertSame('cisco', PhoneModels::guessVendor('aabbccddeeff', 'Cisco/SPA504G-7.6.2'));
        $this->assertSame('poly', PhoneModels::guessVendor('aabbccddeeff', 'PolycomVVX-VVX_450-UA/6.4.0'));
        $this->assertSame('poly', PhoneModels::guessVendor('0004f2aabbcc', ''));
        $this->assertSame('nec', PhoneModels::guessVendor('0060b9aabbcc', ''));
        $this->assertSame('', PhoneModels::guessVendor('aabbccddeeff', 'HttpConnector/1.0'));
    }

    public function testNecRendersDataFile(): void
    {
        $ctx = $this->hostileContext('nec-dt830');
        $out = (new NecTemplate())->render($ctx);
        $xml = simplexml_load_string($out);
        $this->assertNotFalse($xml, 'valid XML');
        $this->assertSame('DT_PHONE_CONFIG_DATA', $xml->getName());
        $line = $xml->DT_LINE_DATA->attributes();
        $this->assertSame(self::EXT, (string) $line['line.1.extension']);
        $this->assertSame(self::EXT, (string) $line['line.1.authentication.username']);
        $this->assertSame(self::$sipPassword, (string) $line['line.1.authentication.password']);
        $this->assertSame('pbx.test.example', (string) $line['line.1.primary.address']);
        $this->assertSame('5060', (string) $line['line.1.primary.port']);
        // No spaces or special characters allowed: the extension.
        $this->assertSame(self::EXT, (string) $line['line.1.displayname']);
        $this->assertNull($line['x'], 'no attribute injected');
        $this->assertSame('1', (string) $xml->DT_SIP_DATA['sip.transport']);
        $phone = $xml->DT_PHONE_DATA->attributes();
        $this->assertSame('*97', (string) $phone['phone.voicemail.extension']);
        $this->assertSame('1', (string) $phone['phone.audio.g722.pref']);
        $this->assertSame('2', (string) $phone['phone.audio.pcma.pref']);
        // All six codecs ranked, each rank once (the phone rejects the file otherwise).
        $ranks = [];
        foreach (['g722', 'pcma', 'pcmu', 'g729', 'g7221.24kbps', 'g7221.32kbps'] as $c) {
            $ranks[] = (string) $phone["phone.audio.$c.pref"];
        }
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4', '5', '6'], $ranks);
        $net = $xml->DT_PHONE_NETWORK_DATA->attributes();
        $this->assertSame('+3', (string) $net['network.gmt.offset']);
        $this->assertSame('', (string) $net['network.sntp.address'], 'the phone takes no names');
        $this->assertSame('', (string) $net['network.syslog.address']);
        $this->assertSame('0', (string) $xml->DT_PHONE_DST_DATA['dst.start.month'], 'no daylight saving');
        $this->assertSame('0', (string) $xml->DT_PHONE_DST_DATA['dst.time.adjustment']);
        $this->assertNull($xml->DT_PHONE_SECURITY_DATA['security.admin.password'], 'keypad takes digits only');
        $this->assertSame('1', (string) $xml->DT_PHONE_SECURITY_DATA['security.telnet.mode']);
        $this->assertStringNotContainsString(self::$adminPassword, $out);
        $this->assertSame('en_UK', (string) $xml->DT_USER_DATA['user.lang.value'], 'no Turkish on the phone');
        // The groups of NEC's DT700/DT800 data file template, in its order.
        $this->assertSame(['DT_LINE_DATA', 'DT_SIP_DATA', 'DT_PHONE_NETWORK_DATA', 'DT_PHONE_DST_DATA', 'DT_PHONE_SECURITY_DATA',
            'DT_PHONE_DATA', 'DT_PHONE_FEATURE_DATA', 'DT_USER_DATA'], array_map(fn($e) => $e->getName(), iterator_to_array($xml->children(), false)));

        $ctx['ntp'] = '10.23.3.1';
        $ctx['syslog'] = '10.23.3.70';
        $ctx['utc_offset'] = 330;
        $net = simplexml_load_string((new NecTemplate())->render($ctx))->DT_PHONE_NETWORK_DATA->attributes();
        $this->assertSame(['10.23.3.1', '10.23.3.70', '+6'], [(string) $net['network.sntp.address'], (string) $net['network.syslog.address'], (string) $net['network.gmt.offset']]);

        $ctx['transport'] = 'tls';
        $ctx['language'] = 'de';
        $xml = simplexml_load_string((new NecTemplate())->render($ctx));
        $this->assertSame('4', (string) $xml->DT_SIP_DATA['sip.transport']);
        $this->assertSame('de_DE', (string) $xml->DT_USER_DATA['user.lang.value']);
    }

    public function testNecServesItsFile(): void
    {
        $phone = $this->addPhone('0060b9ef609f', 'nec-dt830');
        $this->setSetting('provision_allowed_networks', '192.0.2.0/24');
        // dt-<mac>.cfg is the master file naming the data file.
        $master = PhoneProvisionService::handleRequest(null, 'dt-0060b9ef609f.cfg', '192.0.2.10', '');
        $this->assertSame(200, $master['status']);
        $this->assertStringContainsString('<DT_APPLICATION DATA_FILES="dt-0060b9ef609f-phone.cfg" />', $master['body']);
        $this->assertStringNotContainsString(self::$sipPassword, $master['body']);
        $res = PhoneProvisionService::handleRequest(null, 'dt-0060b9ef609f-phone.cfg', '192.0.2.10', '');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('line.1.authentication.password="' . self::$sipPassword . '"', $res['body']);
        $this->assertSame(200, PhoneProvisionService::handleRequest($phone['token'], 'dt-0060b9ef609f.cfg', '192.0.2.10', '')['status']);
        $this->assertSame(404, PhoneProvisionService::handleRequest(null, 'dt-000000000000.cfg', '192.0.2.10', '')['status']);

        // Unix line ends (the phones read them); the local override file next to it.
        $this->assertStringNotContainsString("\r", $res['body']);
        $this->assertStringNotContainsString("\r", $master['body']);
        $local = PhoneProvisionService::handleRequest(null, 'dt-0060b9ef609f-local.cfg', '192.0.2.10', '');
        $this->assertSame(200, $local['status']);
        $xml = simplexml_load_string($local['body']);
        $this->assertNotFalse($xml);
        $this->assertSame('PHONE_CONFIG', $xml->getName());
        $data = $xml->NEC_Override->DT_PHONE_CONFIG_DATA->attributes();
        $this->assertSame(self::EXT, (string) $data['line.1.extension']);
        $this->assertSame(self::$sipPassword, (string) $data['line.1.authentication.password']);
        $this->assertSame('+3', trim((string) $data['network.gmt.offset']));
        $this->assertSame(['dt-0060b9ef609f.cfg', 'dt-0060b9ef609f-phone.cfg', 'dt-0060b9ef609f-local.cfg'], (new NecTemplate())->exportFiles('0060b9ef609f'));
    }

    /** The local file's <VERSION> rises with each change of the files, and only then. */
    public function testNecLocalVersionRisesOnlyWhenTheFilesChange(): void
    {
        $phone = $this->addPhone('0060b9ef6100', 'nec-dt830');
        $this->setSetting('provision_allowed_networks', '192.0.2.0/24');
        $version = function (): string {
            $local = PhoneProvisionService::handleRequest(null, 'dt-0060b9ef6100-local.cfg', '192.0.2.10', '');
            $this->assertSame(200, $local['status']);
            return (string) simplexml_load_string($local['body'])->VERSION['Version'];
        };
        // Starts above 2, the version the phones in use were set up with.
        $this->assertSame('3', $version());
        // Fetching again, or the other files, changes nothing.
        PhoneProvisionService::handleRequest(null, 'dt-0060b9ef6100-phone.cfg', '192.0.2.10', '');
        $this->assertSame('3', $version());

        // The preview shows the next number without taking it.
        $this->setSetting('provision_ntp', '192.0.2.123');
        $preview = PhoneProvisionService::previewFiles(PhoneProvisionService::getPhone((int) $phone['id']));
        $this->assertStringContainsString('<VERSION Version="4"/>', $preview[2]['content']);
        $this->assertSame('4', $version());
        $this->assertSame('4', $version());
    }

    public static function fileNames(): array
    {
        return [
            ['yealink', '001565aabbcc.cfg', '001565aabbcc'],
            ['yealink', '001565AABBCC.cfg', '001565aabbcc'],
            ['yealink', 'y000000000028.cfg', ''],
            ['yealink', '001565aabbcc-local.cfg', ''],
            ['grandstream', 'cfg000b82112233.xml', '000b82112233'],
            ['grandstream', 'cfg000b82112233', '000b82112233'],
            ['grandstream', 'cfg.xml', ''],
            ['fanvil', '0c383e445566.cfg', '0c383e445566'],
            ['fanvil', 'f0X4U000.cfg', ''],
            ['snom', 'snomD785-000413AABBCC.htm', '000413aabbcc'],
            ['snom', 'snom-000413aabbcc.xml', '000413aabbcc'],
            ['snom', 'snomD785.htm', ''],
            ['cisco', 'spa001122334455.xml', '001122334455'],
            ['cisco', 'spa504G.cfg', ''],
            ['poly', '0004f2aabbcc.cfg', '0004f2aabbcc'],
            ['poly', 'phone0004f2aabbcc.cfg', '0004f2aabbcc'],
            ['poly', '000000000000.cfg', ''],
            ['poly', '0004f2aabbcc-phone.cfg', ''],
            ['nec', 'dt-0060b9ef609f.cfg', '0060b9ef609f'],
            ['nec', 'dt-0060B9EF609F.cfg', '0060b9ef609f'],
            ['nec', 'dt-000000000000.cfg', ''],
            ['nec', 'dt-0060b9ef609f-local.cfg', '0060b9ef609f'],
            ['nec', 'dt-0060B9EF609F-phone.cfg', '0060b9ef609f'],
            ['nec', 'dt-sip.cfg', ''],
            ['cisco-cm', 'SEP001565AABBCC.cnf.xml', '001565aabbcc'],
            ['cisco-cm', 'SEP001565aabbcc.cnf.xml', '001565aabbcc'],
            ['cisco-cm', 'SEP001565AABBCC.cnf', ''],          // not the .cnf.xml these phones ask for
            ['cisco-cm', 'SIPDefault.cnf.xml', ''],           // a shared file, never a phone's
            ['cisco-cm', 'SEP001565AABBCC.cnf.xml.sample', ''],
        ];
    }

    #[DataProvider('fileNames')]
    public function testFileNameMatching(string $vendor, string $file, string $mac): void
    {
        $this->assertSame($mac, PhoneTemplates::forVendor($vendor)->macFromFile($file));
    }

    // ------------------------------------------------------------- requests

    public function testTokenUrlServesConfigAndUpdatesThePhone(): void
    {
        $phone = $this->addPhone('00:15:65:AA:BB:CC', 'yealink-t46u');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $phone['token']);
        $this->assertSame('https://pbx.test.example/provision/' . $phone['token'] . '/', PhoneProvisionService::provisioningUrl($phone));

        $res = PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.10', 'Yealink SIP-T46U 108.86.0.20');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('account.1.password = ' . self::$sipPassword, $res['body']);
        // The SIP server is the installation's domain, never the request's Host header.
        $this->assertStringContainsString('account.1.sip_server.1.address = pbx.test.example', $res['body']);
        // The phone web admin password is generated, kept encrypted and written to the phone.
        $admin = PhoneProvisionService::adminPassword($phone);
        $this->assertSame(16, strlen($admin));
        $this->assertStringNotContainsString($admin, $phone['admin_password']);
        $this->assertStringContainsString('static.security.user_password = admin:' . $admin, $res['body']);

        $after = PhoneProvisionService::getPhone((int) $phone['id']);
        $this->assertNotNull($after['last_fetch_at']);
        $this->assertSame('192.0.2.10', $after['last_ip']);
        $this->assertSame('Yealink SIP-T46U 108.86.0.20', $after['last_user_agent']);
    }

    public function testWrongTokenOrOtherMacGetsNothing(): void
    {
        $phone = $this->addPhone('001565aabbcc', 'yealink-t46u');
        $this->assertSame(404, PhoneProvisionService::handleRequest(str_repeat('a', 40), '001565aabbcc.cfg', '192.0.2.10', '')['status']);
        $this->assertSame(404, PhoneProvisionService::handleRequest('../../etc', '001565aabbcc.cfg', '192.0.2.10', '')['status']);
        $res = PhoneProvisionService::handleRequest($phone['token'], '001565ddeeff.cfg', '192.0.2.10', '');
        $this->assertSame(404, $res['status']);
        $this->assertSame('mac_mismatch', $res['result']);
        $this->assertSame(404, PhoneProvisionService::handleRequest($phone['token'], 'y000000000028.cfg', '192.0.2.10', '')['status']);
    }

    public function testPhoneWithoutExtensionGetsNothing(): void
    {
        $id = PhoneProvisionService::upsertPhone(0, '001565aabbcc', 'yealink-t46u', 0);
        $phone = PhoneProvisionService::getPhone($id);
        $res = PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.10', '');
        $this->assertSame(404, $res['status']);
        $this->assertSame('unassigned', $res['result']);
    }

    public function testMacRequestsNeedAllowedNetworks(): void
    {
        $this->addPhone('001565aabbcc', 'yealink-t46u');
        $res = PhoneProvisionService::handleRequest(null, '001565aabbcc.cfg', '192.168.1.20', '');
        $this->assertSame(403, $res['status'], 'no allowed networks: MAC-based names are refused');

        $this->setSetting('provision_allowed_networks', "192.168.1.0/24\n2001:db8::/32");
        $this->assertSame(200, PhoneProvisionService::handleRequest(null, '001565aabbcc.cfg', '192.168.1.20', '')['status']);
        $this->assertSame(403, PhoneProvisionService::handleRequest(null, '001565aabbcc.cfg', '10.1.1.1', '')['status']);
    }

    public function testAllowedNetworksAlsoGuardTokenUrls(): void
    {
        $phone = $this->addPhone('001565aabbcc', 'yealink-t46u');
        $this->setSetting('provision_allowed_networks', '10.0.0.0/8');
        $this->assertSame(403, PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '203.0.113.5', '')['status']);
        $this->assertSame(200, PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '10.20.30.40', '')['status']);
    }

    public function testUnknownMacLandsInWaitingPhonesAndCanBeAssigned(): void
    {
        $this->setSetting('provision_allowed_networks', '192.168.1.0/24');
        $res = PhoneProvisionService::handleRequest(null, 'cfg000b82112233.xml', '192.168.1.30', 'Grandstream GXP2170 1.0.11.84');
        $this->assertSame(404, $res['status']);
        $this->assertSame('unknown', $res['result']);
        PhoneProvisionService::handleRequest(null, 'cfg000b82112233.xml', '192.168.1.30', 'Grandstream GXP2170 1.0.11.84');

        $waiting = PhoneProvisionService::listWaiting();
        $this->assertCount(1, $waiting);
        $this->assertSame('000b82112233', $waiting[0]['mac']);
        $this->assertSame('grandstream', $waiting[0]['vendor']);
        $this->assertSame(2, (int) $waiting[0]['request_count']);

        $r = PhoneProvisionService::assignWaiting((int) $waiting[0]['id'], 'grandstream-gxp2170', $this->userId, 'phones-test-csrf');
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame([], PhoneProvisionService::listWaiting());
        $res = PhoneProvisionService::handleRequest(null, 'cfg000b82112233.xml', '192.168.1.30', 'Grandstream GXP2170 1.0.11.84');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('<P34>' . self::$sipPassword . '</P34>', $res['body']);
    }

    public function testRateLimit(): void
    {
        $phone = $this->addPhone('001565aabbcc', 'yealink-t46u');
        $this->setSetting('provision_rate_limit', '5');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.50', '')['status']);
        }
        $this->assertSame(429, PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.50', '')['status']);
        $this->assertSame(200, PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.51', '')['status']);
    }

    public function testEveryRequestIsLoggedWithoutPasswords(): void
    {
        $phone = $this->addPhone('001565aabbcc', 'yealink-t46u');
        PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.10', 'Yealink');
        PhoneProvisionService::handleRequest($phone['token'], '001565ddeeff.cfg', '192.0.2.10', 'Yealink');
        PhoneProvisionService::handleRequest(str_repeat('b', 40), '001565aabbcc.cfg', '192.0.2.10', 'Yealink');

        $rows = $this->db->query('SELECT * FROM pbx_phone_fetch_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['served', 'mac_mismatch', 'bad_token'], array_column($rows, 'result'));
        $dump = json_encode($rows) . json_encode($this->db->query('SELECT * FROM sys_audit_log')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString(self::$sipPassword, $dump);
        $this->assertStringNotContainsString(PhoneProvisionService::adminPassword($phone), $dump);
        $this->assertStringNotContainsString($phone['token'], json_encode($rows));
    }

    // ---------------------------------------------------------- CSV / keys

    public function testCsvImport(): void
    {
        $this->addPhone('0c383e445566', 'fanvil-x4u', $this->user2Id);
        $csv = "mac,model,extension\n"
            . "00:15:65:aa:bb:cc,yealink-t46u," . self::EXT . "\n"
            . "000b82112233;Grandstream GXP2170;\n"
            . "0c383e445566,X6U," . self::EXT . "\n"
            . "not-a-mac,yealink-t46u,1\n"
            . "001565000001,Nokia 3310,\n"
            . "001565000002,yealink-t46u,99999\n";
        $r = PhoneProvisionService::importCsv($csv);
        $this->assertSame(2, $r['added']);
        $this->assertSame(1, $r['updated']);
        $this->assertCount(3, $r['errors']);
        $this->assertStringContainsString('5', $r['errors'][0]);

        $phones = array_column(PhoneProvisionService::listPhones(), null, 'mac');
        $this->assertSame('fanvil-x6u', $phones['0c383e445566']['model']);
        $this->assertSame($this->userId, (int) $phones['0c383e445566']['user_id']);
        $this->assertNull($phones['000b82112233']['user_id']);
    }

    public function testModelFromText(): void
    {
        $this->assertSame('yealink-t46u', PhoneProvisionService::modelFromText('yealink-t46u'));
        $this->assertSame('yealink-t46u', PhoneProvisionService::modelFromText('Yealink T46U'));
        $this->assertSame('grandstream-gxp2170', PhoneProvisionService::modelFromText('gxp2170'));
        $this->assertSame('cisco-cm-7962g', PhoneProvisionService::modelFromText('cisco-cm-7962g'));
        $this->assertSame('cisco-cm-7962g', PhoneProvisionService::modelFromText('Cisco 7962G'));
        $this->assertSame('cisco-cm-7962g', PhoneProvisionService::modelFromText('7962G'));
        $this->assertSame('cisco-cm-8861', PhoneProvisionService::modelFromText('Cisco 8861'));
        $this->assertSame('cisco-cm-8861', PhoneProvisionService::modelFromText('8861'));
        $this->assertSame('cisco-spa525g', PhoneProvisionService::modelFromText('Cisco SPA525G2'));
        $this->assertSame('', PhoneProvisionService::modelFromText('T99'));
    }

    public function testKeyLayoutValidationCopyAndRendering(): void
    {
        $r = PhoneProvisionService::saveKeys($this->userId, [
            ['page' => 0, 'position' => 1, 'type' => 'line', 'target' => 'ignored', 'label' => 'Line'],
            ['page' => 0, 'position' => 2, 'type' => 'blf', 'target' => self::EXT2, 'label' => "Second\nUser"],
            ['page' => 0, 'position' => 3, 'type' => '', 'target' => '', 'label' => ''],
        ], 'phones-test-csrf');
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $keys = PhoneProvisionService::getKeys($this->userId);
        $this->assertCount(2, $keys);
        $this->assertSame('', $keys[0]['target']);
        $this->assertSame('SecondUser', $keys[1]['label']);

        $bad = PhoneProvisionService::saveKeys($this->userId, [['page' => 0, 'position' => 1, 'type' => 'blf', 'target' => '10; rm', 'label' => '']], 'phones-test-csrf');
        $this->assertFalse($bad['success']);
        $this->assertCount(2, PhoneProvisionService::getKeys($this->userId), 'a failed save keeps the old layout');

        $copy = PhoneProvisionService::copyKeys($this->userId, [$this->user2Id], 'phones-test-csrf');
        $this->assertTrue($copy['success'], $copy['error'] ?? '');
        $this->assertCount(2, PhoneProvisionService::getKeys($this->user2Id));

        $phone = $this->addPhone('001565aabbcc', 'yealink-t46u');
        $body = PhoneProvisionService::handleRequest($phone['token'], '001565aabbcc.cfg', '192.0.2.10', '')['body'];
        $this->assertStringContainsString("linekey.2.type = 16\nlinekey.2.line = 1\nlinekey.2.value = " . self::EXT2, $body);
    }

    public function testResyncCommandPerVendor(): void
    {
        $y = $this->addPhone('001565aabbcc', 'yealink-t46u');
        $g = $this->addPhone('000b82112233', 'grandstream-gxp2170', $this->user2Id);
        $this->assertSame('pjsip send notify yealink-check-cfg endpoint ' . self::EXT . '-sip', PhoneProvisionService::notifyCommand((int) $y['id'], false));
        $this->assertSame('pjsip send notify yealink-reboot endpoint ' . self::EXT . '-sip', PhoneProvisionService::notifyCommand((int) $y['id'], true));
        $this->assertSame('pjsip send notify grandstream-check-cfg endpoint ' . self::EXT2 . '-sip', PhoneProvisionService::notifyCommand((int) $g['id'], false));
        $this->assertFalse(PhoneProvisionService::resync((int) $g['id'], true, 'phones-test-csrf')['success']);
        $this->assertTrue(PhoneProvisionService::resync((int) $y['id'], false, 'phones-test-csrf')['success']);

        $sn = $this->addPhone('000413aabbcc', 'snom-d785');
        $ci = $this->addPhone('001122334455', 'cisco-spa504g');
        $po = $this->addPhone('0004f2aabbcc', 'poly-vvx450');
        $ep = ' endpoint ' . self::EXT . '-sip';
        $this->assertSame('pjsip send notify snom-check-cfg' . $ep, PhoneProvisionService::notifyCommand((int) $sn['id'], false));
        $this->assertSame('pjsip send notify snom-reboot' . $ep, PhoneProvisionService::notifyCommand((int) $sn['id'], true));
        $this->assertSame('pjsip send notify sipura-check-cfg' . $ep, PhoneProvisionService::notifyCommand((int) $ci['id'], false));
        $this->assertSame('pjsip send notify linksys-cold-restart' . $ep, PhoneProvisionService::notifyCommand((int) $ci['id'], true));
        $this->assertSame('pjsip send notify polycom-check-cfg' . $ep, PhoneProvisionService::notifyCommand((int) $po['id'], false));
        $this->assertFalse(PhoneProvisionService::resync((int) $po['id'], true, 'phones-test-csrf')['success']);

        // Every notify section the templates use exists in pjsip_notify.conf.
        $conf = (string) file_get_contents(dirname(__DIR__, 3) . '/asterisk-config/pjsip_notify.conf');
        foreach (PhoneTemplates::all() as $tpl) {
            foreach (array_filter([$tpl->notifyResync(), $tpl->notifyReboot()]) as $section) {
                $this->assertStringContainsString("[{$section}]", $conf);
            }
        }
    }

    public function testNetworkMatching(): void
    {
        $nets = PhoneProvisionService::parseNetworks("192.168.1.0/24, 10.0.0.5\n2001:db8::/32 bogus 300.1.1.1/8", $bad);
        $this->assertSame(['192.168.1.0/24', '10.0.0.5', '2001:db8::/32'], $nets);
        $this->assertSame(['bogus', '300.1.1.1/8'], $bad);
        $this->assertTrue(PhoneProvisionService::ipInNetworks('192.168.1.254', $nets));
        $this->assertFalse(PhoneProvisionService::ipInNetworks('192.168.2.1', $nets));
        $this->assertTrue(PhoneProvisionService::ipInNetworks('10.0.0.5', $nets));
        $this->assertFalse(PhoneProvisionService::ipInNetworks('10.0.0.6', $nets));
        $this->assertTrue(PhoneProvisionService::ipInNetworks('2001:db8:1::1', $nets));
        $this->assertFalse(PhoneProvisionService::ipInNetworks('not-an-ip', $nets));
        $this->assertTrue(PhoneProvisionService::ipInNetworks('172.16.5.1', ['172.16.0.0/12']));
        $this->assertFalse(PhoneProvisionService::ipInNetworks('172.32.0.1', ['172.16.0.0/12']));
    }

    public function testMacValidation(): void
    {
        $this->assertSame('001565aabbcc', PhoneModels::normalizeMac('00-15-65-AA-BB-CC'));
        $this->assertSame('', PhoneModels::normalizeMac('001565aabb'));
        $this->assertSame('', PhoneModels::normalizeMac('ffffffffffff'));
        $this->assertFalse(PhoneProvisionService::savePhone(['csrf_token' => 'phones-test-csrf', 'mac' => 'xyz', 'model' => 'yealink-t46u'])['success']);
        $this->assertFalse(PhoneProvisionService::savePhone(['csrf_token' => 'phones-test-csrf', 'mac' => '001565aabbcc', 'model' => 'nope'])['success']);
        $this->assertTrue(PhoneProvisionService::savePhone(['csrf_token' => 'phones-test-csrf', 'mac' => '001565aabbcc', 'model' => 'yealink-t46u', 'user_id' => $this->userId])['success']);
        $this->assertFalse(PhoneProvisionService::savePhone(['csrf_token' => 'wrong', 'mac' => '001565aabbcd', 'model' => 'yealink-t46u'])['success']);
    }

    public function testOutboundProxyInEveryTemplate(): void
    {
        $ctx = ['proxy' => '10.23.3.70'] + $this->context('yealink-t46u');
        $this->assertStringContainsString('account.1.outbound_proxy_enable = 1', (new YealinkTemplate())->render($ctx));
        $this->assertStringContainsString('account.1.outbound_proxy.1.address = 10.23.3.70', (new YealinkTemplate())->render($ctx));
        $this->assertStringContainsString('<P48>10.23.3.70:5060</P48>', (new GrandstreamTemplate())->render($ctx));
        $this->assertStringContainsString('SIP1 Proxy Addr    :10.23.3.70', (new FanvilTemplate())->render($ctx));
        $this->assertStringContainsString('10.23.3.70:5060;transport=udp', (new SnomTemplate())->render($ctx));
        $this->assertStringContainsString('Outbound_Proxy_1_', (new CiscoSpaTemplate())->render($ctx));
        $this->assertStringContainsString('reg.1.outboundProxy.address="10.23.3.70"', (new PolyTemplate())->render($ctx));
        $this->assertStringContainsString('10.23.3.70', (new NecTemplate())->render($ctx));
        $this->assertStringContainsString('<outboundProxy>10.23.3.70</outboundProxy>', (new CiscoCallManagerTemplate())->render($ctx));
        $this->assertStringContainsString('<outboundProxyPort>5060</outboundProxyPort>', (new CiscoCallManagerTemplate())->render($ctx));
        // Without a proxy nothing is switched on.
        $this->assertStringContainsString('account.1.outbound_proxy_enable = 0', (new YealinkTemplate())->render($this->context('yealink-t46u')));
    }

    public function testServerAddressesFromSettings(): void
    {
        $before = [PhoneProvisionService::sipServer(), PhoneProvisionService::baseUrl()];
        $set = getDB()->prepare('REPLACE INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
        try {
            $set->execute(['provision_sip_server', '10.23.3.70']);
            $set->execute(['provision_base_url', 'http://10.23.3.70']);
            $this->assertSame('10.23.3.70', PhoneProvisionService::sipServer());
            $this->assertSame('http://10.23.3.70/provision/', NetworkServicesService::provisioningUrl());
            $set->execute(['provision_base_url', '10.23.3.70:8080']);           // no scheme: http
            $this->assertSame('http://10.23.3.70:8080/provision/', NetworkServicesService::provisioningUrl());
        } finally {
            getDB()->exec("DELETE FROM sys_settings WHERE setting_key IN ('provision_sip_server', 'provision_base_url')");
        }
        $this->assertSame($before, [PhoneProvisionService::sipServer(), PhoneProvisionService::baseUrl()]);
    }

    public function testPreviewHidesPasswordsUnlessRevealed(): void
    {
        $files = PhoneProvisionService::previewFiles($this->addPhone('001565aabbcc', 'yealink-t46u'));
        $this->assertSame('001565aabbcc.cfg', $files[0]['name']);
        $this->assertStringContainsString('account.1.user_name = ' . self::EXT, $files[0]['content']);
        $this->assertStringNotContainsString(self::$sipPassword, $files[0]['content']);
        $this->assertStringContainsString('••••••••', $files[0]['content']);
        $shown = PhoneProvisionService::previewFiles($this->addPhone('001565aabbcc', 'yealink-t46u'), true);
        $this->assertStringContainsString(self::$sipPassword, $shown[0]['content']);
        // Poly: both files; a phone without a user gets none.
        $poly = PhoneProvisionService::previewFiles($this->addPhone('0004f2aabbcc', 'poly-vvx450'));
        $this->assertSame(['0004f2aabbcc.cfg', 'phone0004f2aabbcc.cfg'], array_column($poly, 'name'));
        $id = PhoneProvisionService::upsertPhone(0, '001565aabbdd', 'yealink-t46u', 0);
        $this->assertNull(PhoneProvisionService::previewFiles(PhoneProvisionService::getPhone($id)));
    }

    public function testProvisioningAddressNeedsNoScheme(): void
    {
        $s = PhoneProvisionService::settings();
        $s['provision_codecs'] = explode(',', $s['provision_codecs']);
        foreach (['10.23.3.70', '10.23.3.70:8080', 'pbx.example.com', 'HTTP://10.23.3.70', 'https://pbx.example.com:8443/'] as $ok) {
            $r = PhoneProvisionService::saveSettings(['csrf_token' => 'phones-test-csrf', 'provision_base_url' => $ok] + $s);
            $this->assertTrue($r['success'], $ok . ': ' . $r['message']);
        }
        foreach (['10.23.3.70/provision', 'ftp://10.23.3.70', 'http://', '10.23.3.70:'] as $bad) {
            $r = PhoneProvisionService::saveSettings(['csrf_token' => 'phones-test-csrf', 'provision_base_url' => $bad] + $s);
            $this->assertFalse($r['success'], $bad);
        }
    }
}
