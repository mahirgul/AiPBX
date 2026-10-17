<?php

use PHPUnit\Framework\TestCase;

if (!defined('CC_DISPATCH_ACTIVE')) define('CC_DISPATCH_ACTIVE', true);
require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/api/cc_actions/cc_lib.php';

/**
 * An empty permission group must DENY, not allow.
 *
 * sub-check-dial-permission used to jump to perm_allow when the group was
 * empty or 1. Endpoint channels always carry a group (min 1), so an empty
 * value means "unchecked" — click-to-call Local legs and inbound trunk
 * hairpins sailed through every restriction.
 */
final class PermissionDenyEmptyTest extends TestCase
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

    public function testEmptyGroupIsDenied(): void
    {
        syncPermissions();
        $conf = $this->read('extensions_permissions.conf');

        $start = strpos($conf, "[sub-check-dial-permission]\n");
        $this->assertNotFalse($start, 'sub-check-dial-permission missing');
        $sub = substr($conf, $start, strpos($conf, "\n\n", $start) - $start);

        $this->assertStringContainsString('?perm_deny', $sub, 'empty group must route to perm_deny');
        $this->assertStringContainsString(
            'n(perm_deny),Goto(sub-permission-denied,s,1)',
            $sub,
            'perm_deny must end in the restriction handler'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/=\s*""\s*\|\s*"\$\{PERM_GROUP\}"\s*=\s*"1"\s*\]\?perm_allow/',
            $sub,
            'empty-or-1 fail-open is back'
        );
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
