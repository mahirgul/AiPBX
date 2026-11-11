<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/PhoneProvisionService.php';
require_once __DIR__ . '/../../src/services/phones/PhoneTemplates.php';

/**
 * A "line" key that names a device-only extension makes the phone register that extension as one
 * more account, next to the user's own line. Only a device-only extension: a key must not make a
 * phone register as another person.
 */
final class PhoneLineAccountsTest extends TestCase
{
    private const OWN = '7410';
    private const DEVICE = '7411';
    private const DEVICE2 = '7412';
    private const PERSON = '7413';
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->cleanUp();
        $ins = $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active, is_device)
            VALUES (?, '', ?, '', 'user', ?, 'sip', ?, 1, ?)");
        $ins->execute(['lineacct_own', 'Own Line', self::OWN, 'Own-Secret-1!', 0]);
        $ins->execute(['lineacct_dev', 'Lobby', self::DEVICE, 'Lobby-Secret-2!', 1]);
        $ins->execute(['lineacct_dev2', 'Door', self::DEVICE2, 'Door-Secret-3!', 1]);
        $ins->execute(['lineacct_person', 'Somebody Else', self::PERSON, 'Person-Secret-4!', 0]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE username LIKE 'lineacct\\_%'");
    }

    /** @return list<array<string, mixed>> */
    private function keys(): array
    {
        return [
            ['page' => 0, 'position' => 1, 'type' => 'line', 'target' => '', 'label' => 'Me'],
            ['page' => 0, 'position' => 2, 'type' => 'line', 'target' => self::DEVICE, 'label' => 'Lobby'],
            ['page' => 0, 'position' => 3, 'type' => 'blf', 'target' => self::PERSON, 'label' => 'BLF'],
            ['page' => 0, 'position' => 4, 'type' => 'line', 'target' => self::DEVICE2, 'label' => 'Door'],
            ['page' => 0, 'position' => 5, 'type' => 'line', 'target' => self::DEVICE, 'label' => 'Lobby again'],
        ];
    }

    private function context(string $model): array
    {
        [$keys, $accounts] = PhoneProvisionService::lineAccounts($this->keys(), $model, self::OWN);
        return [
            'mac' => '001565aabbcc', 'model' => $model, 'extension' => self::OWN,
            'display_name' => 'Own Line', 'sip_password' => 'Own-Secret-1!',
            'server' => 'pbx.test.example', 'proxy' => '', 'port' => 5060, 'transport' => 'udp', 'srtp' => 1,
            'codecs' => ['g722', 'pcma'], 'ntp' => 'pool.ntp.org', 'utc_offset' => 180, 'language' => 'en',
            'voicemail' => '*97', 'pickup_prefix' => '*21', 'admin_password' => 'Admin-1',
            'keys' => $keys, 'accounts' => $accounts,
        ];
    }

    public function testLineKeysOfDeviceExtensionsBecomeAccounts(): void
    {
        [$keys, $accounts] = PhoneProvisionService::lineAccounts($this->keys(), 'yealink-t46u', self::OWN);
        $this->assertSame([2, 3], array_keys($accounts));
        $this->assertSame(self::DEVICE, $accounts[2]['extension']);
        $this->assertSame('Lobby-Secret-2!', $accounts[2]['sip_password']);
        $this->assertSame([1, 2, 1, 3, 2], array_column($keys, 'account'), 'the same extension twice is one account');
    }

    public function testTheModelLimitAndVanishedDevicesFallBackToTheOwnLine(): void
    {
        // The T31G registers two accounts: the second device has no place.
        [$keys, $accounts] = PhoneProvisionService::lineAccounts($this->keys(), 'yealink-t31g', self::OWN);
        $this->assertSame([2], array_keys($accounts));
        $this->assertSame([1, 2, 1, 1, 2], array_column($keys, 'account'));

        // A model with one account, and a device that became a person since the key was saved.
        $this->assertSame([], PhoneProvisionService::lineAccounts($this->keys(), 'fanvil-x4u', self::OWN)[1]);
        $this->db->exec("UPDATE sys_users SET is_device = 0 WHERE extension = '" . self::DEVICE . "'");
        $this->assertSame([self::DEVICE2], array_column(PhoneProvisionService::lineAccounts($this->keys(), 'yealink-t46u', self::OWN)[1], 'extension'));
    }

    public function testALineKeyTakesOnlyADeviceExtension(): void
    {
        $key = fn(string $target): array => [['page' => 0, 'position' => 1, 'type' => 'line', 'target' => $target, 'label' => '']];
        $this->assertSame(self::DEVICE, PhoneProvisionService::validateKeys($key(self::DEVICE))[0]['target']);
        $this->assertSame('', PhoneProvisionService::validateKeys($key(''))[0]['target']);
        // Anything that is no number is dropped, as before this feature.
        $this->assertSame('', PhoneProvisionService::validateKeys($key('ignored'))[0]['target']);
        $this->assertSame('', PhoneProvisionService::validateKeys($key(self::DEVICE . "\nx"))[0]['target']);
        foreach ([self::PERSON, '99999'] as $bad) {
            try {
                PhoneProvisionService::validateKeys($key($bad));
                $this->fail("a line key took '{$bad}'");
            } catch (\Exception $e) {
                $this->assertStringContainsString('device-only', $e->getMessage());
            }
        }
    }

    public function testYealinkWritesTheAccountsAndBindsTheKeys(): void
    {
        $out = (new YealinkTemplate())->render($this->context('yealink-t46u'));
        $this->assertStringContainsString('account.1.user_name = ' . self::OWN, $out);
        $this->assertStringContainsString('account.2.user_name = ' . self::DEVICE, $out);
        $this->assertStringContainsString('account.2.password = Lobby-Secret-2!', $out);
        $this->assertStringContainsString('account.2.sip_server.1.address = pbx.test.example', $out);
        $this->assertStringContainsString('account.3.user_name = ' . self::DEVICE2, $out);
        $this->assertStringNotContainsString('account.4.', $out);
        $this->assertStringNotContainsString('Person-Secret-4!', $out);
        $this->assertStringContainsString("linekey.1.type = 15\nlinekey.1.line = 1\n", $out);
        $this->assertStringContainsString("linekey.2.type = 15\nlinekey.2.line = 2\n", $out);
        $this->assertStringContainsString("linekey.3.type = 16\nlinekey.3.line = 1\n", $out, 'a BLF key stays on the own line');
        $this->assertStringContainsString("linekey.4.type = 15\nlinekey.4.line = 3\n", $out);
    }

    public function testSnomWritesTheIdentitiesAndBindsTheKeys(): void
    {
        $out = (new SnomTemplate())->render($this->context('snom-d785'));
        $this->assertStringContainsString('<user_name idx="1" perm="">' . self::OWN . '</user_name>', $out);
        $this->assertStringContainsString('<user_name idx="2" perm="">' . self::DEVICE . '</user_name>', $out);
        $this->assertStringContainsString('<user_pass idx="2" perm="">Lobby-Secret-2!</user_pass>', $out);
        $this->assertStringContainsString('<user_host idx="3" perm="">pbx.test.example</user_host>', $out);
        $this->assertStringContainsString('<fkey idx="0" context="active" label="Me" perm="">line</fkey>', $out);
        $this->assertStringContainsString('<fkey idx="1" context="2" label="Lobby" perm="">line</fkey>', $out);
        $this->assertStringContainsString('<fkey idx="3" context="3" label="Door" perm="">line</fkey>', $out);
        $this->assertNotFalse(simplexml_load_string($out), 'well-formed XML');
    }

    public function testGrandstreamWritesTheAccounts(): void
    {
        $out = (new GrandstreamTemplate())->render($this->context('grandstream-gxp2170'));
        $xml = simplexml_load_string($out);
        $this->assertNotFalse($xml);
        $p = (array) $xml->config;
        $this->assertSame(self::OWN, (string) $p['P35']);
        $this->assertSame(['1', self::DEVICE, self::DEVICE, 'Lobby-Secret-2!', 'pbx.test.example:5060'],
            [(string) $p['P401'], (string) $p['P404'], (string) $p['P405'], (string) $p['P406'], (string) $p['P402']]);
        $this->assertSame([self::DEVICE2, 'Door-Secret-3!'], [(string) $p['P504'], (string) $p['P506']]);
        $this->assertArrayNotHasKey('P601', $p);
    }

    public function testAPhoneWithoutDeviceLinesIsWrittenAsBefore(): void
    {
        $ctx = $this->context('yealink-t46u');
        $ctx['keys'] = [['page' => 0, 'position' => 1, 'type' => 'line', 'target' => '', 'label' => '', 'account' => 1]];
        $ctx['accounts'] = [];
        $withKey = (new YealinkTemplate())->render($ctx);
        unset($ctx['accounts'], $ctx['keys'][0]['account']);
        $this->assertSame((new YealinkTemplate())->render($ctx), $withKey, 'a context from before this feature renders the same');
        $this->assertStringNotContainsString('account.2.', $withKey);
    }
}
