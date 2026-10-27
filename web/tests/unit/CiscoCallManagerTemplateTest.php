<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/PhoneProvisionService.php';

/**
 * Cisco CallManager-series phones (7900/8800 with Cisco's SIP firmware):
 * the SEP<MAC>.cnf.xml AiPBX writes for them.
 *
 * The two assertions that carry the most weight are
 * testTheDocumentIsWellFormedXml() — the phone rejects the whole file when one
 * element is malformed, which is how a display name with a newline in it would
 * take a phone off the network — and testNoLoadNameIsGuessed(), because writing
 * a load the phone does not have is worse than writing none.
 */
final class CiscoCallManagerTemplateTest extends TestCase
{
    private const EXT = '7662';
    private const SECRET = 'S7e%cret&<x';
    private const ADMIN = 'A1b2c3d4e5f6a7b8';

    private CiscoCallManagerTemplate $tpl;

    protected function setUp(): void
    {
        $this->tpl = new CiscoCallManagerTemplate();
    }

    protected function tearDown(): void
    {
        putenv('AIPBX_TFTP_DIR');
    }

    private function context(string $model = 'cisco-cm-7962g', array $keys = []): array
    {
        return [
            'mac' => '001565aabbcc', 'model' => $model, 'extension' => self::EXT,
            // A display name built to break a careless template: a newline and
            // an XML tag, exactly what a user's full name could contain.
            'display_name' => "Ali\nBak <script>", 'sip_password' => self::SECRET,
            'server' => 'pbx.test.example', 'proxy' => '', 'port' => 5060, 'transport' => 'udp', 'srtp' => 0,
            'codecs' => ['g722', 'pcma'], 'ntp' => 'pool.ntp.org', 'utc_offset' => 180, 'language' => 'tr',
            'voicemail' => '*97', 'pickup_prefix' => '*21', 'cisco_load' => '', 'admin_password' => self::ADMIN,
            'keys' => $keys,
        ];
    }

    private function key(int $position, string $type, string $target = '', string $label = ''): array
    {
        return ['page' => 0, 'position' => $position, 'type' => $type, 'target' => $target, 'label' => $label];
    }

    // ----------------------------------------------------------- file names

    public function testTheFileNamesAreThePhones(): void
    {
        $this->assertSame('SEP001565AABBCC.cnf.xml', $this->tpl->deviceFile('001565aabbcc'));
        $this->assertSame('001565aabbcc', $this->tpl->macFromFile('SEP001565AABBCC.cnf.xml'));
        $this->assertSame('cisco-cm', PhoneModels::guessVendor('aabbccddeeff', 'Cisco-CP7962G/9.4.2'));
        $this->assertSame('cisco-cm', PhoneModels::guessVendor('aabbccddeeff', '', 'SEPAABBCCDDEEFF.cnf.xml'));
        $this->assertSame('cisco', PhoneModels::guessVendor('aabbccddeeff', 'Cisco/SPA504G-7.6.2'));
        $this->assertSame('001565aabbcc', $this->tpl->macFromFile('SEP001565aabbcc.cnf.xml'));
        $this->assertSame('', $this->tpl->macFromFile('SEP001565AABBCC.cnf'));           // not the .cnf.xml
        $this->assertSame('', $this->tpl->macFromFile('SIPDefault.cnf.xml'));           // a shared file
        $this->assertSame('', $this->tpl->macFromFile('SEP001565AABBCC.cnf.xml.bak'));
        $this->assertSame('application/xml; charset=utf-8', $this->tpl->contentType());
    }

    public function testEveryModelOfTheVendorHasATemplate(): void
    {
        foreach (PhoneModels::MODELS as $key => $model) {
            if ($model['vendor'] === 'cisco-cm') {
                $this->assertNotNull(PhoneTemplates::forModel($key), $key);
                $this->assertSame($this->tpl->vendor(), PhoneTemplates::forModel($key)->vendor());
            }
        }
        // The two Cisco vendors are different templates, not one with a flag.
        $this->assertInstanceOf(CiscoSpaTemplate::class, PhoneTemplates::forModel('cisco-spa504g'));
        $this->assertInstanceOf(CiscoCallManagerTemplate::class, PhoneTemplates::forModel('cisco-cm-7962g'));
    }

    // ------------------------------------------------------------- the file

    public function testTheDocumentIsWellFormedXml(): void
    {
        $xml = $this->tpl->render($this->context());
        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'the phone rejects the whole file when one element is malformed');
        $this->assertSame('SIP', (string) $parsed->deviceProtocol);
        // Nothing the user typed became an element of its own.
        $this->assertStringNotContainsString('<script>', $xml);
        $this->assertStringNotContainsString("\nBak ", $xml);
        $this->assertStringContainsString('&lt;script&gt;', $xml);
    }

    public function testALineRegistersWithTheExtensionAndTheSettings(): void
    {
        $xml = $this->tpl->render($this->context());
        $this->assertStringContainsString('<sipPort>5060</sipPort>', $xml);
        $this->assertStringContainsString('<processNodeName>pbx.test.example</processNodeName>', $xml);
        $this->assertStringContainsString('<registerWithProxy>true</registerWithProxy>', $xml);
        // The keyword, not a host name: it points at the callManager member above.
        $this->assertStringContainsString('<proxy>USECALLMANAGER</proxy>', $xml);
        $this->assertMatchesRegularExpression('/<line button="1" lineIndex="1">\s*<featureID>9<\/featureID>/', $xml);
        $this->assertStringContainsString('<name>' . self::EXT . '</name>', $xml);
        $this->assertStringContainsString('<authName>' . self::EXT . '</authName>', $xml);
        $this->assertStringContainsString('<authPassword>' . htmlspecialchars(self::SECRET, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</authPassword>', $xml);
        $this->assertStringContainsString('<messagesNumber>*97</messagesNumber>', $xml);
        $this->assertStringContainsString('<timeZone>Russian Standard/Daylight Time</timeZone>', $xml);
        $this->assertStringContainsString('<name>pool.ntp.org</name>', $xml);
    }

    public function testTheOutboundProxyIsWrittenWhenSet(): void
    {
        $xml = $this->tpl->render(['proxy' => '10.23.3.70'] + $this->context());
        $this->assertStringContainsString('<outboundProxy>10.23.3.70</outboundProxy>', $xml);
        $this->assertStringContainsString('<outboundProxyPort>5060</outboundProxyPort>', $xml);
        // Without a proxy the phone registers straight at the server.
        $this->assertStringNotContainsString('<outboundProxy>', $this->tpl->render($this->context()));
    }

    public function testNoLoadNameIsGuessed(): void
    {
        // Empty setting: the element is absent, so the phone keeps its load.
        $this->assertStringNotContainsString('<loadInformation>', $this->tpl->render($this->context()));
        $withLoad = $this->tpl->render(['cisco_load' => 'SIP42.9-4-2SR3-1S'] + $this->context());
        $this->assertStringContainsString('<loadInformation>SIP42.9-4-2SR3-1S</loadInformation>', $withLoad);
    }

    public function testTheSharedFilesAreReferencedOnlyWhenTheyExist(): void
    {
        $empty = $this->tpl->render($this->context());
        $this->assertStringNotContainsString('<softKeyFile>', $empty);
        $this->assertStringNotContainsString('<dialTemplate>', $empty);

        $dir = sys_get_temp_dir() . '/aipbx-tftp-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/Softkeys.xml', '<softKeyFile/>');
        putenv('AIPBX_TFTP_DIR=' . $dir);
        try {
            $withFiles = $this->tpl->render($this->context());
            $this->assertStringContainsString('<softKeyFile>Softkeys.xml</softKeyFile>', $withFiles);
            $this->assertStringNotContainsString('<dialTemplate>', $withFiles, 'only the files that are there');
        } finally {
            unlink($dir . '/Softkeys.xml');
            rmdir($dir);
        }
    }

    // ------------------------------------------------------------- key layout

    public function testTheKeyLayoutBecomesLineKeys(): void
    {
        $xml = $this->tpl->render($this->context('cisco-cm-7962g', [
            $this->key(1, 'line', '', 'Sales'),
            $this->key(2, 'blf', '7663', 'Support'),
            $this->key(3, 'speeddial', '9000', 'Gate'),
            $this->key(4, 'park', '701', 'Park'),
            $this->key(5, 'dnd'),                       // not written for these phones
        ]));
        $this->assertMatchesRegularExpression('/<line button="1" lineIndex="1">\s*<featureID>9<\/featureID>/', $xml);
        $this->assertMatchesRegularExpression('/<line button="2">\s*<featureID>21<\/featureID>/', $xml);
        $this->assertStringContainsString('<featureLabel>Support</featureLabel>', $xml);
        $this->assertStringContainsString('<contact>7663</contact>', $xml);
        $this->assertMatchesRegularExpression('/<line button="3">\s*<featureID>2<\/featureID>\s*<featureLabel>Gate<\/featureLabel>\s*<speedDialNumber>9000<\/speedDialNumber>/', $xml);
        $this->assertMatchesRegularExpression('/<line button="4">\s*<featureID>2<\/featureID>/', $xml);
        $this->assertStringContainsString('<speedDialNumber>701</speedDialNumber>', $xml);
        $this->assertStringNotContainsString('<line button="5">', $xml, 'a Do Not Disturb key is not written');

        // A BLF key does not register: no credentials on it.
        $blf = substr($xml, (int) strpos($xml, '<line button="2">'));
        $blf = substr($blf, 0, (int) strpos($blf, '</line>'));
        $this->assertStringNotContainsString('<authPassword>', $blf);
        $this->assertStringContainsString('<proxy>USECALLMANAGER</proxy>', $blf);
    }

    public function testAKeyTheModelDoesNotHaveIsLeftOut(): void
    {
        // The 7942G has two programmable keys; a layout drawn for a bigger phone
        // must not write keys this one cannot show.
        $xml = $this->tpl->render($this->context('cisco-cm-7942g', [
            $this->key(1, 'line'), $this->key(2, 'blf', '7663'), $this->key(3, 'speeddial', '9000'), $this->key(6, 'speeddial', '9001'),
        ]));
        $this->assertStringNotContainsString('<line button="3">', $xml);
        $this->assertStringNotContainsString('<line button="6">', $xml);
        $this->assertStringContainsString('<line button="2">', $xml);
    }

    public function testWithNoLayoutThereIsStillAFirstLine(): void
    {
        $xml = $this->tpl->render($this->context());
        $this->assertSame(1, substr_count($xml, '<line button='));
        $this->assertMatchesRegularExpression('/<line button="1" lineIndex="1">\s*<featureID>9<\/featureID>/', $xml);
    }

    public function testPolicyIsEightEightHundredSeriesAware(): void
    {
        // Values the phones' own documentation gives per model family.
        $seven = $this->tpl->render($this->context('cisco-cm-7975g'));
        $this->assertStringContainsString('<callWaiting>3</callWaiting>', $seven);
        $this->assertStringContainsString('<maxNumCalls>50</maxNumCalls>', $seven);
        $this->assertStringContainsString('<callLogBlfEnabled>2</callLogBlfEnabled>', $seven);

        $eight = $this->tpl->render($this->context('cisco-cm-8861'));
        $this->assertStringContainsString('<callWaiting>1</callWaiting>', $eight);
        $this->assertStringContainsString('<maxNumCalls>200</maxNumCalls>', $eight);
        $this->assertStringContainsString('<callLogBlfEnabled>1</callLogBlfEnabled>', $eight);
    }

    public function testTlsChangesTheTransportAndThePort(): void
    {
        $tls = $this->tpl->render(['transport' => 'tls', 'port' => 5061] + $this->context());
        $this->assertStringContainsString('<transportLayerProtocol>2</transportLayerProtocol>', $tls);
        $this->assertStringContainsString('<sipPort>5061</sipPort>', $tls);
        $this->assertStringContainsString('<securedSipPort>5061</securedSipPort>', $tls);
        $udp = $this->tpl->render($this->context());
        $this->assertStringContainsString('<transportLayerProtocol>1</transportLayerProtocol>', $udp);
    }

    public function testAnOffsetWithNoNameGetsTheNearestOne(): void
    {
        $this->assertStringContainsString('<timeZone>India Standard Time</timeZone>',
            $this->tpl->render(['utc_offset' => 330] + $this->context()));
        // +375 has no name of its own: the nearest documented one beats a name
        // the phone may not know at all.
        $this->assertStringContainsString('<timeZone>Central Asia Standard Time</timeZone>',
            $this->tpl->render(['utc_offset' => 375] + $this->context()));
        $this->assertStringContainsString('<timeZone>Eastern Standard/Daylight Time</timeZone>',
            $this->tpl->render(['utc_offset' => -300] + $this->context()));
    }

    public function testResyncHasANotifyAndRebootDoesNot(): void
    {
        $this->assertSame('cisco-check-cfg', $this->tpl->notifyResync());
        $this->assertSame('', $this->tpl->notifyReboot(), 'no NOTIFY reboots these phones');
    }

    // --------------------------------------------------------- the whole path

    public function testThePhoneGetsItsFileThroughTheProvisioningPath(): void
    {
        $this->cleanUp();
        $_SESSION = ['csrf_token' => 'cm-test-csrf', 'user_id' => null];
        putenv('PORTAL_DOMAIN=pbx.test.example');
        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active)
                      VALUES ('cmtest', '', 'CM User', 'cm@example.com', 'user', ?, 'sip', ?, 1)")
           ->execute([self::EXT, self::SECRET]);
        $userId = (int) $db->lastInsertId();
        try {
            PhoneProvisionService::saveSettings([
                'csrf_token' => 'cm-test-csrf',
                'provision_cisco_load' => 'SIP42.9-4-2SR3-1S',
            ] + ['provision_codecs' => PhoneProvisionService::CODECS] + PhoneProvisionService::settings());

            $id = PhoneProvisionService::upsertPhone(0, '00:15:65:AA:BB:CC', 'cisco-cm-7962g', $userId);
            $phone = PhoneProvisionService::getPhone($id);

            $res = PhoneProvisionService::handleRequest($phone['token'], 'SEP001565AABBCC.cnf.xml', '192.0.2.10', '');
            $this->assertSame(200, $res['status']);
            $this->assertStringContainsString('USECALLMANAGER', $res['body']);
            $this->assertStringContainsString('<authPassword>' . htmlspecialchars(self::SECRET, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</authPassword>', $res['body']);
            $this->assertStringContainsString('<loadInformation>SIP42.9-4-2SR3-1S</loadInformation>', $res['body']);

            // Another phone's file name is refused even with a valid token.
            $this->assertSame(404, PhoneProvisionService::handleRequest($phone['token'], 'SEP001565AABBCC.cnf', '192.0.2.10', '')['status']);
            $this->assertSame(404, PhoneProvisionService::handleRequest($phone['token'], 'SEP001565AABBCC.xml', '192.0.2.10', '')['status']);

            // The preview on the page hides the SIP password like every other vendor.
            $files = PhoneProvisionService::previewFiles($phone);
            $this->assertSame('SEP001565AABBCC.cnf.xml', $files[0]['name']);
            $this->assertStringNotContainsString(self::SECRET, $files[0]['content']);
            $this->assertStringContainsString('••••••••', $files[0]['content']);
        } finally {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM pbx_phone_fetch_log');
        $db->exec('DELETE FROM pbx_phones_waiting');
        $db->exec('DELETE FROM pbx_phones');
        $db->exec("DELETE FROM sys_users WHERE username = 'cmtest'");
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'provision\\_%'");
    }
}
