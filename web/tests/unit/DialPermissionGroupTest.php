<?php

use PHPUnit\Framework\TestCase;

if (!defined('CC_DISPATCH_ACTIVE')) define('CC_DISPATCH_ACTIVE', true);
require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/api/cc_actions/cc_lib.php';

/**
 * Dial restrictions follow the user on every channel the user starts.
 *
 * sub-check-dial-permission allows an empty group (inbound trunk calls), so
 * a channel started by a user must carry the user's group: click-to-call
 * Local legs did not, and a restricted extension could call barred numbers
 * through the API.
 */
final class DialPermissionGroupTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
    }

    private function read(string $file): string
    {
        $path = ASTERISK_PBX_DIR . '/' . $file;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testEmptyGroupIsAllowedForInboundCalls(): void
    {
        // Calls from a trunk have no group; forwarding them to a mobile or an
        // external destination must keep working.
        syncPermissions();
        $conf = $this->read('extensions_permissions.conf');
        $this->assertMatchesRegularExpression('/GotoIf\(\$\["\$\{PERM_GROUP\}" = "" \| "\$\{PERM_GROUP\}" = "1"\]\?perm_allow\)/', $conf);
    }

    public function testGroupOneStillAllowed(): void
    {
        syncPermissions();
        $conf = $this->read('extensions_permissions.conf');

        $this->assertMatchesRegularExpression(
            '/GotoIf.*PERM_GROUP.*"1".*\?perm_allow/',
            $conf,
            'group 1 (open by default) must still pass'
        );
        $this->assertStringContainsString('n(perm_allow),Return()', $conf);
    }

    public function testOriginateCarriesPermissionGroup(): void
    {
        $cmd = buildOriginateCommand('3001', '00905551234567', 'cc-internal', 4);
        $this->assertStringContainsString('Variable: __PERMISSION_GROUP_ID=4', $cmd);
        $this->assertStringContainsString('Variable: __SAVED_DST=00905551234567', $cmd);
    }

    public function testOriginateGroupNeverEmpty(): void
    {
        // Defensive clamp: 0/negative must become group 1, never an empty value.
        $cmd = buildOriginateCommand('3001', '123', 'cc-internal', 0);
        $this->assertStringContainsString('Variable: __PERMISSION_GROUP_ID=1', $cmd);
        $this->assertDoesNotMatchRegularExpression('/__PERMISSION_GROUP_ID=\r/', $cmd);
    }
}
