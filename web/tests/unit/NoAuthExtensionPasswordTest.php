<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncExtensions.php';
require_once dirname(__DIR__, 2) . '/src/sip_helper.php';

/**
 * A desk phone without digest auth and without a password: its web and mobile
 * accounts were written with the password 'Pass<extension>!', which anyone could
 * guess. They now get a random one, kept on the user for the web phone and the app.
 */
final class NoAuthExtensionPasswordTest extends TestCase
{
    private const EXT = '7971';

    protected function setUp(): void
    {
        Fixtures::load();
        $this->cleanUp();
        getDB()->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
        getDB()->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role, outbound_group)
            VALUES ('test_noauth', '', 'No Auth', ?, '', 0, 'sip', 1, 'user', 1)")->execute([self::EXT]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        getDB()->prepare('DELETE FROM sys_users WHERE extension = ?')->execute([self::EXT]);
        getDB()->prepare('DELETE FROM sip WHERE id = ?')->execute([self::EXT]);
    }

    public function testWebAndMobileAccountsGetARandomStoredPassword(): void
    {
        // As after changing the extension's number: its `sip` rows have no secret.
        SIPHelper::setSettings(self::EXT, ['account' => self::EXT, 'auth_digest' => 'no']);
        syncAllExtensions();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');
        $stored = (string) getDB()->query("SELECT sip_password FROM sys_users WHERE extension = '" . self::EXT . "'")->fetchColumn();

        $this->assertStringNotContainsString('Pass' . self::EXT . '!', $conf);
        $this->assertGreaterThanOrEqual(16, strlen($stored));
        foreach (['webrtc', 'mob-webrtc'] as $kind) {
            $this->assertMatchesRegularExpression(
                '/\[auth' . self::EXT . '-' . $kind . '\][^\[]*password=' . preg_quote(confValue($stored), '/') . '\n/',
                $conf
            );
        }
        // The desk phone itself still registers without a password.
        $this->assertStringNotContainsString('[auth' . self::EXT . '-sip]', $conf);

        // The password stays the same on the next Apply.
        syncAllExtensions();
        $this->assertSame($stored, (string) getDB()->query("SELECT sip_password FROM sys_users WHERE extension = '" . self::EXT . "'")->fetchColumn());
    }

    public function testTheWebPhoneGetsTheSecretInUse(): void
    {
        SIPHelper::setSettings(self::EXT, ['account' => self::EXT, 'auth_digest' => 'no', 'secret' => 'Existing-Secret-77']);
        syncAllExtensions();
        $this->assertSame('Existing-Secret-77', (string) getDB()->query("SELECT sip_password FROM sys_users WHERE extension = '" . self::EXT . "'")->fetchColumn());
    }
}
