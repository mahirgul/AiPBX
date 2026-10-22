<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncExtensions.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';
require_once dirname(__DIR__, 2) . '/src/sync/DialplanExtensionBuilder.php';
require_once dirname(__DIR__, 2) . '/src/services/FaxSendService.php';
require_once dirname(__DIR__, 2) . '/src/sip_helper.php';

/**
 * Tests for AiPBX call routing contexts and ingress context hierarchies:
 * - PJSIP per-device contexts (desktop, web, mobile) with client type channel variables
 * - Custom context overrides vs generated PBX context overwrites in sip table
 * - Include chain order in ingress contexts (ortak -> featurecodes -> sub-norm -> outbound)
 * - Group ingress context generation driven by user outbound_group even without routes
 * - Call forwarding (CFU, CFB, CFNA, unreachable) routing through user group desktop context
 * - Outbound fax spool call file routing through sender's group desktop context
 */
final class CallRoutingContextsTest extends TestCase
{
    private PDO $db;
    /** @var string[] */
    private array $createdExtensions = [];
    /** @var int[] */
    private array $createdFaxIds = [];

    protected function setUp(): void
    {
        Fixtures::load();
        $this->db = getDB();
        $this->cleanUp();
        $this->db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['csrf_token']);
    }

    private function cleanUp(): void
    {
        if (!empty($this->createdExtensions)) {
            $inClause = implode(',', array_map(fn(string $ext): string => $this->db->quote($ext), $this->createdExtensions));
            $this->db->exec("DELETE FROM sys_users WHERE extension IN ({$inClause})");
            $this->db->exec("DELETE FROM sip WHERE id IN ({$inClause})");
            $this->createdExtensions = [];
        }

        $this->db->exec("DELETE FROM sys_users WHERE extension LIKE '79%' OR username LIKE 'test_ctx_%'");
        $this->db->exec("DELETE FROM sip WHERE id LIKE '79%'");

        foreach ($this->createdFaxIds as $faxId) {
            $callFile = ASTERISK_CALL_SPOOL . "/fax_{$faxId}.call";
            if (file_exists($callFile)) {
                @unlink($callFile);
            }
            $tmpFile = FAX_OUTGOING_SPOOL . "/.fax_{$faxId}.call.tmp";
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
        $this->createdFaxIds = [];
    }

    /**
     * Helper to create a user and track it for cleanup.
     *
     * @param array<string, mixed> $extra
     */
    private function createUser(string $ext, string $username, array $extra = []): void
    {
        $this->createdExtensions[] = $ext;
        $row = array_merge([
            'username' => $username,
            'password_hash' => '',
            'full_name' => "User {$ext}",
            'extension' => $ext,
            'sip_password' => 'Pass' . $ext . '!',
            'sip_auth_digest' => 1,
            'extension_type' => 'sip',
            'is_active' => 1,
            'role' => 'user',
            'outbound_group' => 1,
        ], $extra);

        $cols = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO sys_users ({$cols}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));
    }

    /**
     * Extracts an INI section body from generated PJSIP config.
     */
    private function extractPjsipSection(string $conf, string $sectionHeader): string
    {
        $quotedHeader = preg_quote($sectionHeader, '/');
        if (preg_match('/' . $quotedHeader . '\n(.*?)(?=\n\[|\z)/s', $conf, $matches)) {
            return $matches[1];
        }
        $this->fail("Section '{$sectionHeader}' not found in pjsip config.");
    }

    /**
     * Extracts a dialplan context body from generated extensions config.
     */
    private function extractDialplanContext(string $conf, string $contextName): string
    {
        $quotedHeader = preg_quote("[{$contextName}]", '/');
        if (preg_match('/' . $quotedHeader . '\n(.*?)(?=\n\[|\z)/s', $conf, $matches)) {
            return $matches[1];
        }
        $this->fail("Context '[{$contextName}]' not found in dialplan config.");
    }

    /**
     * Asserts that expected include statements appear in exact sequential order.
     *
     * @param string[] $expectedIncludes
     */
    private function assertIncludesInOrder(string $contextBody, array $expectedIncludes): void
    {
        $lastPos = -1;
        foreach ($expectedIncludes as $inc) {
            $expectedLine = "include => {$inc}";
            $pos = strpos($contextBody, $expectedLine);
            $this->assertNotFalse($pos, "Expected directive '{$expectedLine}' was not found in context body:\n{$contextBody}");
            $this->assertGreaterThan($lastPos, $pos, "Directive '{$expectedLine}' appeared out of expected order in context body:\n{$contextBody}");
            $lastPos = $pos;
        }
    }

    // =========================================================================
    // 1. PJSIP Endpoints & Per-Device Contexts & Client Types
    // =========================================================================

    public function testPjsipEndpointsDefaultGroupPerDeviceContextsAndClientTypes(): void
    {
        $this->createUser('7901', 'test_ctx_7901', ['outbound_group' => 1]);
        syncAllExtensions();

        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');

        // <ext>-sip -> from-internal-desktop, AIPBX_CLIENT_TYPE=desktop
        $sipBody = $this->extractPjsipSection($conf, '[7901-sip](endpoint-sip)');
        $this->assertStringContainsString("context=from-internal-desktop\n", $sipBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=desktop\n", $sipBody);

        // <ext>-webrtc -> from-internal-web, AIPBX_CLIENT_TYPE=web
        $webBody = $this->extractPjsipSection($conf, '[7901-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=from-internal-web\n", $webBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=web\n", $webBody);

        // <ext>-mob-webrtc -> from-internal-mobile, AIPBX_CLIENT_TYPE=mobile
        $mobBody = $this->extractPjsipSection($conf, '[7901-mob-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=from-internal-mobile\n", $mobBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=mobile\n", $mobBody);
    }

    public function testPjsipEndpointsSpecificGroupPerDeviceContextsAndClientTypes(): void
    {
        $this->createUser('7902', 'test_ctx_7902', ['outbound_group' => 2]);
        syncAllExtensions();

        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');

        // Group 2: <ext>-sip -> from-internal-desktop-2
        $sipBody = $this->extractPjsipSection($conf, '[7902-sip](endpoint-sip)');
        $this->assertStringContainsString("context=from-internal-desktop-2\n", $sipBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=desktop\n", $sipBody);

        // Group 2: <ext>-webrtc -> from-internal-web-2
        $webBody = $this->extractPjsipSection($conf, '[7902-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=from-internal-web-2\n", $webBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=web\n", $webBody);

        // Group 2: <ext>-mob-webrtc -> from-internal-mobile-2
        $mobBody = $this->extractPjsipSection($conf, '[7902-mob-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=from-internal-mobile-2\n", $mobBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=mobile\n", $mobBody);
    }

    public function testPjsipEndpointsCustomContextInSipTableIsKeptForAllThreeDevices(): void
    {
        $this->createUser('7903', 'test_ctx_7903', ['outbound_group' => 3]);
        // Set a custom non-default context in the sip table
        SIPHelper::setSettings('7903', ['context' => 'custom-restricted-ctx']);

        syncAllExtensions();

        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');

        // All three endpoints must keep the custom context stored in sip table
        $sipBody = $this->extractPjsipSection($conf, '[7903-sip](endpoint-sip)');
        $this->assertStringContainsString("context=custom-restricted-ctx\n", $sipBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=desktop\n", $sipBody);

        $webBody = $this->extractPjsipSection($conf, '[7903-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=custom-restricted-ctx\n", $webBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=web\n", $webBody);

        $mobBody = $this->extractPjsipSection($conf, '[7903-mob-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=custom-restricted-ctx\n", $mobBody);
        $this->assertStringContainsString("set_var=AIPBX_CLIENT_TYPE=mobile\n", $mobBody);
    }

    public function testPjsipEndpointsGeneratedOrDefaultContextInSipTableIsOverriddenByGroupContext(): void
    {
        $this->createUser('7904', 'test_ctx_7904', ['outbound_group' => 4]);
        // Setting a generated PBX context (e.g. from-internal-pbx or from-internal-g1) in sip table
        // must NOT override the per-device group ingress contexts.
        SIPHelper::setSettings('7904', ['context' => 'from-internal-pbx']);

        syncAllExtensions();

        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');

        $sipBody = $this->extractPjsipSection($conf, '[7904-sip](endpoint-sip)');
        $this->assertStringContainsString("context=from-internal-desktop-4\n", $sipBody);

        $webBody = $this->extractPjsipSection($conf, '[7904-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=from-internal-web-4\n", $webBody);

        $mobBody = $this->extractPjsipSection($conf, '[7904-mob-webrtc](endpoint-wss)');
        $this->assertStringContainsString("context=from-internal-mobile-4\n", $mobBody);
    }

    // =========================================================================
    // 2. Per-Device Context Include Chain Order
    // =========================================================================

    public function testPerDeviceContextIncludeChainOrderForDefaultAndCustomGroups(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // Check group 1: desktop, mobile, web
        $desktop1 = $this->extractDialplanContext($conf, 'from-internal-desktop-1');
        $this->assertIncludesInOrder($desktop1, [
            'from-internal-pbx-ortak',
            'featurecodes-pbx',
            'sub-norm-desktop-1',
            'from-internal-outbound-1',
        ]);

        $mobile1 = $this->extractDialplanContext($conf, 'from-internal-mobile-1');
        $this->assertIncludesInOrder($mobile1, [
            'from-internal-pbx-ortak',
            'featurecodes-pbx',
            'sub-norm-mobile-1',
            'from-internal-outbound-1',
        ]);

        $web1 = $this->extractDialplanContext($conf, 'from-internal-web-1');
        $this->assertIncludesInOrder($web1, [
            'from-internal-pbx-ortak',
            'featurecodes-pbx',
            'sub-norm-web-1',
            'from-internal-outbound-1',
        ]);
    }

    public function testGroupOneIngressAliasesIncludeGroupOnePerDeviceContexts(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        $desktopAlias = $this->extractDialplanContext($conf, 'from-internal-desktop');
        $this->assertStringContainsString("include => from-internal-desktop-1\n", $desktopAlias);

        $mobileAlias = $this->extractDialplanContext($conf, 'from-internal-mobile');
        $this->assertStringContainsString("include => from-internal-mobile-1\n", $mobileAlias);

        $webAlias = $this->extractDialplanContext($conf, 'from-internal-web');
        $this->assertStringContainsString("include => from-internal-web-1\n", $webAlias);

        $outboundAlias = $this->extractDialplanContext($conf, 'from-internal-outbound');
        $this->assertStringContainsString("include => from-internal-outbound-1\n", $outboundAlias);
    }

    // =========================================================================
    // 3. User Group Ingress Contexts Generated Even Without Outbound Routes
    // =========================================================================

    public function testOutboundContextsGeneratedForUserGroupEvenWithoutOutboundRoutes(): void
    {
        // Outbound group 7 has users, but NO route in pbx_outbound_routes has route_group = 7
        $this->createUser('7907', 'test_ctx_7907', ['outbound_group' => 7]);

        $routeCountForGroup7 = (int) $this->db->query("SELECT COUNT(*) FROM pbx_outbound_routes WHERE route_group = 7")->fetchColumn();
        $this->assertSame(0, $routeCountForGroup7, 'Test precondition: no outbound route should use group 7');

        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // from-internal-outbound-7 must exist
        $this->assertStringContainsString('[from-internal-outbound-7]', $conf);

        // Ingress normalization contexts for group 7 must exist
        $this->assertStringContainsString('[sub-norm-desktop-7]', $conf);
        $this->assertStringContainsString('[sub-norm-mobile-7]', $conf);
        $this->assertStringContainsString('[sub-norm-web-7]', $conf);

        // Per-device contexts for group 7 must exist and preserve the strict include order
        $desktop7 = $this->extractDialplanContext($conf, 'from-internal-desktop-7');
        $this->assertIncludesInOrder($desktop7, [
            'from-internal-pbx-ortak',
            'featurecodes-pbx',
            'sub-norm-desktop-7',
            'from-internal-outbound-7',
        ]);

        $mobile7 = $this->extractDialplanContext($conf, 'from-internal-mobile-7');
        $this->assertIncludesInOrder($mobile7, [
            'from-internal-pbx-ortak',
            'featurecodes-pbx',
            'sub-norm-mobile-7',
            'from-internal-outbound-7',
        ]);

        $web7 = $this->extractDialplanContext($conf, 'from-internal-web-7');
        $this->assertIncludesInOrder($web7, [
            'from-internal-pbx-ortak',
            'featurecodes-pbx',
            'sub-norm-web-7',
            'from-internal-outbound-7',
        ]);
    }

    // =========================================================================
    // 4. Call Forwarding Targets Go Through Group Desktop Context
    // =========================================================================

    public function testCallForwardingBusyAndNoAnswerUseDesktopContextForDefaultGroup(): void
    {
        $this->createUser('7911', 'test_ctx_7911', [
            'outbound_group' => 1,
            'cf_busy_number' => '05551230001',
            'cf_noanswer_number' => '05551230002',
        ]);

        $dialLines = buildExtensionDialLines('7911', $this->db);
        $joinedLines = implode("\n", $dialLines['lines']);

        // Busy forward goes through from-internal-desktop
        $this->assertStringContainsString('Goto(from-internal-desktop,05551230001,1)', $joinedLines);
        // No-answer forward goes through from-internal-desktop
        $this->assertStringContainsString('Goto(from-internal-desktop,05551230002,1)', $joinedLines);
    }

    public function testCallForwardingBusyAndNoAnswerUseDesktopContextForSpecificGroup(): void
    {
        $this->createUser('7912', 'test_ctx_7912', [
            'outbound_group' => 3,
            'cf_busy_number' => '05551230003',
            'cf_noanswer_number' => '05551230004',
        ]);

        $dialLines = buildExtensionDialLines('7912', $this->db);
        $joinedLines = implode("\n", $dialLines['lines']);

        // Group 3 forward targets must go through from-internal-desktop-3
        $this->assertStringContainsString('Goto(from-internal-desktop-3,05551230003,1)', $joinedLines);
        $this->assertStringContainsString('Goto(from-internal-desktop-3,05551230004,1)', $joinedLines);
    }

    public function testCallForwardingUnreachableFallsBackToBusyWhenNoAnswerNotConfigured(): void
    {
        // When only cf_busy_number is set (cf_noanswer_number is empty),
        // offline/unreachable extensions forward to cf_busy_number via desktop context.
        $this->createUser('7913', 'test_ctx_7913', [
            'outbound_group' => 4,
            'cf_busy_number' => '05551230005',
            'cf_noanswer_number' => '',
        ]);

        $dialLines = buildExtensionDialLines('7913', $this->db);
        $joinedLines = implode("\n", $dialLines['lines']);

        $this->assertStringContainsString('Unreachable -> forward on busy: 7913 -> 05551230005', $joinedLines);
        $this->assertStringContainsString('Goto(from-internal-desktop-4,05551230005,1)', $joinedLines);
    }

    public function testCallForwardingUnconditionalUsesDesktopContextForGroup(): void
    {
        $this->createUser('7914', 'test_ctx_7914', [
            'outbound_group' => 2,
            'call_forward_number' => '05551230006',
        ]);

        // 1. In buildExtensionDialLines
        $dialLines = buildExtensionDialLines('7914', $this->db);
        $this->assertTrue($dialLines['terminal']);
        $joinedLines = implode("\n", $dialLines['lines']);
        $this->assertStringContainsString('Goto(from-internal-desktop-2,05551230006,1)', $joinedLines);

        // 2. In buildDndCfCheckLines
        $dndLines = buildDndCfCheckLines('7914', $this->db);
        $this->assertTrue($dndLines['terminal']);
        $joinedDndLines = implode("\n", $dndLines['lines']);
        $this->assertStringContainsString('Goto(from-internal-desktop-2,05551230006,1)', $joinedDndLines);
    }

    // =========================================================================
    // 5. Fax Dial Context Uses Desktop Context of Sender's Group
    // =========================================================================

    public function testFaxDialContextUsesDesktopContextForDefaultGroup(): void
    {
        $this->createUser('7921', 'test_fax_7921', [
            'extension_type' => 'fax',
            'outbound_group' => 1,
        ]);

        $faxId = 999101;
        $this->createdFaxIds[] = $faxId;

        FaxSendService::submitCallFile($faxId, '02129990001', '7921', '/tmp/dummy_test.tif');

        $callFilePath = ASTERISK_CALL_SPOOL . "/fax_{$faxId}.call";
        $this->assertFileExists($callFilePath, 'Fax call file must be created in spool');
        $content = (string) file_get_contents($callFilePath);

        $this->assertStringContainsString("Channel: Local/02129990001@from-internal-desktop/n\n", $content);
        $this->assertStringContainsString("SetVar: FAX_SENDER=7921\n", $content);
        $this->assertStringContainsString("SetVar: FAX_ID={$faxId}\n", $content);
        $this->assertStringContainsString("SetVar: __AIPBX_FAX=1\n", $content, 'the route must know it carries a fax');
    }

    public function testFaxDialContextUsesDesktopContextForSpecificGroup(): void
    {
        $this->createUser('7922', 'test_fax_7922', [
            'extension_type' => 'fax',
            'outbound_group' => 5,
        ]);

        $faxId = 999102;
        $this->createdFaxIds[] = $faxId;

        FaxSendService::submitCallFile($faxId, '02129990002', '7922', '/tmp/dummy_test.tif');

        $callFilePath = ASTERISK_CALL_SPOOL . "/fax_{$faxId}.call";
        $this->assertFileExists($callFilePath, 'Fax call file must be created in spool');
        $content = (string) file_get_contents($callFilePath);

        // Group 5: must route through from-internal-desktop-5
        $this->assertStringContainsString("Channel: Local/02129990002@from-internal-desktop-5/n\n", $content);
        $this->assertStringContainsString("SetVar: FAX_SENDER=7922\n", $content);
        $this->assertStringContainsString("SetVar: FAX_ID={$faxId}\n", $content);
    }

    public function testFaxDialContextFallsBackToSessionUserGroupWhenSenderRowMissing(): void
    {
        // Create an admin/user in group 6
        $this->createUser('7923', 'test_admin_7923', [
            'role' => 'admin',
            'outbound_group' => 6,
        ]);
        $sessionUserId = (int) $this->db->query("SELECT id FROM sys_users WHERE extension = '7923'")->fetchColumn();
        $_SESSION['user_id'] = $sessionUserId;
        $_SESSION['user_role'] = 'admin';

        $faxId = 999103;
        $this->createdFaxIds[] = $faxId;

        // senderExt '8888' does not exist in sys_users; should fall back to session user's outbound_group 6
        FaxSendService::submitCallFile($faxId, '02129990003', '8888', '/tmp/dummy_test.tif');

        $callFilePath = ASTERISK_CALL_SPOOL . "/fax_{$faxId}.call";
        $this->assertFileExists($callFilePath, 'Fax call file must be created in spool');
        $content = (string) file_get_contents($callFilePath);

        $this->assertStringContainsString("Channel: Local/02129990003@from-internal-desktop-6/n\n", $content);
        $this->assertStringContainsString("SetVar: FAX_SENDER=8888\n", $content);
        $this->assertStringContainsString("SetVar: FAX_ID={$faxId}\n", $content);
    }

    public function testFaxDestinationOfPatternDidKeepsTheDialledNumber(): void
    {
        require_once dirname(__DIR__, 2) . '/src/sync/DialplanDestinationBuilder.php';
        // A pattern DID (_X.) passes ${EXTEN}; the fax context needs the real number, not "EXTEN".
        $lines = buildDestinationLines('fax', '', '${EXTEN}');
        $this->assertStringContainsString('Goto(from-trunk-fax,${EXTEN},1)', $lines);
        // A plain DID still goes in as filtered digits
        $this->assertStringContainsString('Goto(from-trunk-fax,02121234567,1)', buildDestinationLines('fax', '', "0212123\n4567"));
    }
}
