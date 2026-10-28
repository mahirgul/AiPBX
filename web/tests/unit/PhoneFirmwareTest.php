<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/services/PhoneProvisionService.php';
require_once dirname(__DIR__, 2) . '/src/services/PhoneFirmwareService.php';
require_once dirname(__DIR__, 2) . '/src/services/phones/CiscoFirmware.php';

/**
 * The automatic SCCP → SIP switch for the Cisco 7900 series.
 *
 * Two claims carry the weight here:
 *
 *  - testAMissingFileSetIsRecordedNotRepairedAndNeverCalledReady(): a phone
 *    pointed at a load the server cannot serve sits at "Upgrading" and is off
 *    the network, so a half-copied firmware package must be visible before the
 *    phone reboots, not after.
 *  - testALoadIsArmedForItsOwnModelOnly(): the switch acts on a model that names
 *    its own load. The single fallback field is a human's statement about the
 *    phone in front of them; an automatic run pointing a 7941 at a 7962's load
 *    is exactly the mistake the per-model list exists to prevent.
 */
final class PhoneFirmwareTest extends TestCase
{
    private const LOAD = 'SIP42.9-4-2SR3-1S';
    /** The component list inside the real 7942/7962 9.4(2)SR3 package manifest. */
    private const COMPONENTS = [
        'jar42sip.9-4-2ES26.sbn',
        'cnu42.9-4-2ES26.sbn',
        'apps42.9-4-2ES26.sbn',
        'dsp42.9-4-2ES26.sbn',
        'cvm42sip.9-4-2ES26.sbn',
    ];

    private string $dir;

    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        $db->exec('TRUNCATE TABLE pbx_phones');
        $db->exec('TRUNCATE TABLE pbx_phones_waiting');
        $db->exec("DELETE FROM sys_users WHERE username LIKE 'fwtest%'");
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'provision_cisco%'");
        $db->exec('SET FOREIGN_KEY_CHECKS=1');

        $this->dir = sys_get_temp_dir() . '/aipbx-fw-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        putenv('AIPBX_TFTP_DIR');
    }

    // ------------------------------------------------------------ fixtures

    /**
     * A firmware package in $this->dir: the `.loads` manifest and its payload.
     *
     * The manifest is written the way the real one looks — a signed descriptor
     * with the component names readable inside it — because that is the only
     * part this code reads.
     */
    private function release(string $load = self::LOAD, array $components = self::COMPONENTS, array $omit = []): void
    {
        $body = "\x00\x01SIP\xff\xfe" . implode("\x00", $components) . "\x00\x00SIGNED";
        file_put_contents($this->dir . '/' . $load . '.loads', $body);
        foreach ($components as $name) {
            if (in_array($name, $omit, true)) {
                continue;
            }
            file_put_contents($this->dir . '/' . $name, 'firmware');
        }
    }

    private function user(string $extension = '7662'): int
    {
        getDB()->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active)
             VALUES (?, 'x', 'Test User', ?, 'sip', 1)"
        )->execute(['fwtest' . $extension, $extension]);
        return (int) getDB()->lastInsertId();
    }

    /** A phone row as the page would have created it (no switch, no side effects). */
    private function phone(string $model = 'cisco-cm-7962g', int $userId = 0): int
    {
        // A fresh MAC per row: the table allows one phone per address.
        $mac = sprintf('001565%06x', random_int(0, 0xffffff));
        getDB()->prepare(
            'INSERT INTO pbx_phones (mac, model, user_id, token, admin_password) VALUES (?, ?, ?, ?, ?)'
        )->execute([$mac, $model, $userId ?: null, bin2hex(random_bytes(10)), '']);
        return (int) getDB()->lastInsertId();
    }

    private function setting(string $key, string $value): void
    {
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    }

    // --------------------------------------------------- the file set check

    public function testACompleteLoadIsReady(): void
    {
        $this->release();
        $check = CiscoFirmware::check(self::LOAD, $this->dir);

        $this->assertSame('ready', $check['state']);
        $this->assertSame([], $check['missing']);
        $this->assertSame(count(self::COMPONENTS), $check['components'],
            'every component the manifest names is counted, none twice');
    }

    public function testAMissingComponentIsNamed(): void
    {
        $this->release(self::LOAD, self::COMPONENTS, ['dsp42.9-4-2ES26.sbn']);
        $check = CiscoFirmware::check(self::LOAD, $this->dir);

        $this->assertSame('missing', $check['state']);
        $this->assertSame(['dsp42.9-4-2ES26.sbn'], $check['missing']);
    }

    public function testAMissingManifestIsNamedByItsOwnFile(): void
    {
        $check = CiscoFirmware::check(self::LOAD, $this->dir);

        $this->assertSame('missing', $check['state']);
        $this->assertSame([self::LOAD . '.loads'], $check['missing'],
            'the file the phone asks for first is the one named');
    }

    public function testAManifestWithNothingReadableIsNotCalledReady(): void
    {
        file_put_contents($this->dir . '/' . self::LOAD . '.loads', random_bytes(512));
        $check = CiscoFirmware::check(self::LOAD, $this->dir);

        $this->assertNotSame('ready', $check['state'],
            'a manifest this code cannot read must not be reported as verified');
        $this->assertSame('unreadable', $check['state']);
    }

    public function testALoadNameCannotBeAPath(): void
    {
        $this->assertFalse(CiscoFirmware::validLoad('../../etc/shadow'));
        $this->assertFalse(CiscoFirmware::validLoad(''));
        $this->assertTrue(CiscoFirmware::validLoad(self::LOAD));

        // check() refuses it before touching the filesystem.
        $check = CiscoFirmware::check('../../etc/shadow', $this->dir);
        $this->assertSame('unreadable', $check['state']);
        $this->assertSame([], $check['missing']);
    }

    // ------------------------------------------------------- which phones

    public function testOnlyThe7900SeriesNeedsTheSwitch(): void
    {
        $this->assertTrue(PhoneFirmwareService::needsSwitch('cisco-cm-7942g'));
        $this->assertTrue(PhoneFirmwareService::needsSwitch('cisco-cm-7975g'));
        $this->assertFalse(PhoneFirmwareService::needsSwitch('cisco-cm-8841'),
            'the 8800 enterprise firmware is SIP already');
        $this->assertFalse(PhoneFirmwareService::needsSwitch('cisco-spa504g'));
        $this->assertFalse(PhoneFirmwareService::needsSwitch('yealink-t46u'));
    }

    public function testTheModelListRejectsTyposAndKeepsGoodLines(): void
    {
        $bad = [];
        $loads = PhoneFirmwareService::parseLoads(
            "# comment\ncisco-cm-7962g=SIP42.9-4-2SR3-1S\n\ncisco-cm-8841=SIP88.9\nnosuchmodel=X\ncisco-cm-7941g=../../bad\ncisco-cm-7942g",
            $bad
        );

        $this->assertSame(['cisco-cm-7962g' => self::LOAD], $loads);
        $this->assertCount(4, $bad, 'an 8800, an unknown model, a path and a line without = are all refused');
    }

    // ------------------------------------------------------------- arming

    public function testALoadIsArmedForItsOwnModelOnly(): void
    {
        $this->release();
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=' . self::LOAD);
        $this->setting('provision_cisco_load', 'SIP41.9-4-1');   // a human's fallback, not a model statement

        $armed = PhoneFirmwareService::arm($this->phone('cisco-cm-7962g'), $this->dir);
        $this->assertSame(PhoneFirmwareService::ARMED, $armed['state']);
        $this->assertSame(self::LOAD, $armed['load']);

        $other = PhoneFirmwareService::arm($this->phone('cisco-cm-7941g'), $this->dir);
        $this->assertSame('', $other['state'], 'a model with no line of its own is not switched automatically');

        $state = PhoneFirmwareService::state($this->phone('cisco-cm-8841'));
        $this->assertSame('', $state['state'], 'an 8800 has no SCCP load to leave');
    }

    public function testAMissingFileSetIsRecordedNotRepairedAndNeverCalledReady(): void
    {
        $this->release(self::LOAD, self::COMPONENTS, ['apps42.9-4-2ES26.sbn']);
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=' . self::LOAD);
        $id = $this->phone();

        $result = PhoneFirmwareService::arm($id, $this->dir);
        $this->assertSame(PhoneFirmwareService::INCOMPLETE, $result['state']);
        $this->assertSame(['apps42.9-4-2ES26.sbn'], $result['missing']);

        $state = PhoneFirmwareService::state($id);
        $this->assertSame(PhoneFirmwareService::INCOMPLETE, $state['state']);
        $this->assertSame('apps42.9-4-2ES26.sbn', $state['note'], 'the missing file is named on the phone row');
        $this->assertSame(self::LOAD, $state['load'], 'the load itself is still written: a phone on SCCP is dead either way');

        // The gap is closed by copying the file in, and re-arming says so.
        file_put_contents($this->dir . '/apps42.9-4-2ES26.sbn', 'firmware');
        $this->assertSame(PhoneFirmwareService::ARMED, PhoneFirmwareService::arm($id, $this->dir)['state']);
        $this->assertSame('', PhoneFirmwareService::state($id)['note']);
    }

    public function testArmingIsIdempotentAndTheFetchSticks(): void
    {
        $this->release();
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=' . self::LOAD);
        $id = $this->phone();

        $this->assertTrue(PhoneFirmwareService::arm($id, $this->dir)['changed']);
        $this->assertFalse(PhoneFirmwareService::arm($id, $this->dir)['changed'], 'an armed phone is left alone');

        PhoneFirmwareService::noteFetched($id);
        $this->assertSame(PhoneFirmwareService::FETCHED, PhoneFirmwareService::state($id)['state']);

        $again = PhoneFirmwareService::arm($id, $this->dir);
        $this->assertSame(PhoneFirmwareService::FETCHED, $again['state'], 'a fetch is not undone by the next run');
        $this->assertFalse($again['changed']);
    }

    public function testChangingTheLoadReArmsAPhoneThatAlreadyFetched(): void
    {
        $this->release();
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=' . self::LOAD);
        $id = $this->phone();
        PhoneFirmwareService::arm($id, $this->dir);
        PhoneFirmwareService::noteFetched($id);

        $this->release('SIP42.9-4-3');
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=SIP42.9-4-3');

        $result = PhoneFirmwareService::arm($id, $this->dir);
        $this->assertSame('SIP42.9-4-3', $result['load']);
        $this->assertSame(PhoneFirmwareService::ARMED, $result['state'], 'a new load has to be taken again');
    }

    // ------------------------------------------------------------ wiring

    public function testProvisioningArmsTheSwitch(): void
    {
        $this->release();
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=' . self::LOAD);
        putenv('AIPBX_TFTP_DIR=' . $this->dir);   // the folder phones actually fetch from

        $id = PhoneProvisionService::upsertPhone(0, '00:15:65:aa:bb:cc', 'cisco-cm-7962g', $this->user());

        $state = PhoneFirmwareService::state($id);
        $this->assertSame(PhoneFirmwareService::ARMED, $state['state']);
        $this->assertSame(self::LOAD, $state['load']);
    }

    public function testTheLoadReachesThePhoneFile(): void
    {
        $this->release();
        $this->setting('provision_cisco_loads', 'cisco-cm-7962g=' . self::LOAD);
        $userId = $this->user();
        $id = PhoneProvisionService::upsertPhone(0, '00:15:65:aa:bb:cc', 'cisco-cm-7962g', $userId);

        $phone = PhoneProvisionService::getPhone($id);
        $xml = PhoneTemplates::forModel('cisco-cm-7962g')
            ->render(PhoneProvisionService::buildContext($phone, PhoneProvisionService::userRow($userId)));

        $this->assertStringContainsString('<deviceProtocol>SIP</deviceProtocol>', $xml);
        $this->assertStringContainsString('<loadInformation>' . self::LOAD . '</loadInformation>', $xml,
            'the switch is the load name in the file the phone already fetches');
    }

    public function testTheSettingsFormStoresTheModelListAndRefusesATypo(): void
    {
        $_SESSION = ['csrf_token' => 'fw-test-csrf', 'user_id' => null];
        $base = ['csrf_token' => 'fw-test-csrf', 'provision_codecs' => PhoneProvisionService::CODECS]
            + PhoneProvisionService::settings();

        // $base already carries the stored value (empty here), so the form's
        // own fields go on the left of + to win.
        $res = PhoneProvisionService::saveSettings([
            'provision_cisco_loads' => "cisco-cm-7962g=" . self::LOAD . "\n\ncisco-cm-7975g=SIP75.9-4-2SR3-1S",
        ] + $base);
        $this->assertTrue($res['success'], 'the form saves: ' . ($res['error'] ?? ''));
        $stored = (string) getSystemSetting('provision_cisco_loads');
        $this->assertSame("cisco-cm-7962g=" . self::LOAD . "\ncisco-cm-7975g=SIP75.9-4-2SR3-1S", $stored,
            'blank lines are dropped and the rest is stored in a known form');

        // handleAction() reports an error rather than throwing (that is how the
        // page shows it), so the failure is in the result.
        $bad = PhoneProvisionService::saveSettings(['provision_cisco_loads' => 'cisco-cm-7962g=not a load'] + $base);
        $this->assertFalse($bad['success'], 'a load name that is not a file name must be refused');
        $this->assertStringContainsString('cisco-cm-7962g=not a load', (string) $bad['error'], 'the refused line is quoted back');
        $this->assertSame($stored, (string) getSystemSetting('provision_cisco_loads'), 'a refused save changes nothing');
    }

    public function testSavingSettingsArmsExistingCisco7900Phones(): void
    {
        $this->release();
        putenv('AIPBX_TFTP_DIR=' . $this->dir);
        $id = $this->phone('cisco-cm-7962g');
        $this->assertSame('', PhoneFirmwareService::state($id)['state']);

        $_SESSION = ['csrf_token' => 'fw-test-csrf', 'user_id' => null];
        $base = ['csrf_token' => 'fw-test-csrf', 'provision_codecs' => PhoneProvisionService::CODECS]
            + PhoneProvisionService::settings();
        $res = PhoneProvisionService::saveSettings([
            'provision_cisco_loads' => 'cisco-cm-7962g=' . self::LOAD,
        ] + $base);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame(PhoneFirmwareService::ARMED, PhoneFirmwareService::state($id)['state']);
        $this->assertSame(self::LOAD, PhoneFirmwareService::state($id)['load']);
    }

    public function testAFleetWithoutTheModelListIsNotSwitched(): void
    {
        $this->release();
        $this->setting('provision_cisco_load', self::LOAD);   // fallback only
        $id = $this->phone();

        $result = PhoneFirmwareService::arm($id, $this->dir);

        $this->assertSame('', $result['state']);
        $this->assertSame('', PhoneFirmwareService::state($id)['state'],
            'a single global load is not a statement about a model, so nothing is armed');
    }
}
