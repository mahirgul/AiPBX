<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/VoicemailPolicy.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';

/** PBX settings → Voicemail: limits in voicemail.conf, PIN rules. */
final class VoicemailPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'vm\\_%'");
    }

    public function testPinRules(): void
    {
        $this->assertNull(VoicemailPolicy::pinError('8364', '3001'));
        $this->assertNotNull(VoicemailPolicy::pinError('123', '3001'), 'too short');
        $this->assertNotNull(VoicemailPolicy::pinError('', '3001'));
        $this->assertNotNull(VoicemailPolicy::pinError('3001', '3001'), 'the extension number');

        getDB()->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('vm_pin_not_extension', '0'), ('vm_pin_min', '6')");
        $this->assertNull(VoicemailPolicy::pinError('300100', '300100'), 'rule switched off');
        $this->assertNotNull(VoicemailPolicy::pinError('83641', '3001'), 'now 6 digits');
    }

    public function testRandomPinPassesTheRules(): void
    {
        foreach (range(1, 20) as $_) {
            $pin = VoicemailPolicy::randomPin('3001');
            $this->assertNull(VoicemailPolicy::pinError($pin, '3001'));
            $this->assertGreaterThanOrEqual(6, strlen($pin));
        }
    }

    public function testFormValuesAreClampedAndReachVoicemailConf(): void
    {
        $v = VoicemailPolicy::fromPost(['vm_max_secs' => '99999', 'vm_min_secs' => '-5', 'vm_pin_min' => '2']);
        $this->assertSame(['3600', '0', '4', '0'], [$v['vm_max_secs'], $v['vm_min_secs'], $v['vm_pin_min'], $v['vm_pin_not_extension']]);

        getDB()->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('vm_max_secs', '300'), ('vm_max_logins', '5')");
        $conf = voicemailGeneralConf();
        $this->assertMatchesRegularExpression('/^maxsecs=300$/m', $conf);
        $this->assertMatchesRegularExpression('/^maxlogins=5$/m', $conf);
        $this->assertMatchesRegularExpression('/^minpassword=4$/m', $conf);
    }
}
