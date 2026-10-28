<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * Checks the .conf content the sync generators produce from fixture data.
 *
 * The RISKIEST layer of the system: it generates Asterisk config from the DB
 * and writes it to disk. A regression here hits phone traffic directly.
 *
 * SAFETY: bootstrap.php sets two locks —
 *  - ASTERISK_PBX_DIR points to a temp directory (no writes to the live /etc/asterisk)
 *  - AIPBX_NO_ASTERISK=1 (no reload command reaches the live Asterisk)
 * The first two tests below check that these locks are really in place;
 * without them the other tests would touch production.
 */
final class SyncGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
    }

    private function trunkConf(): string
    {
        $p = ASTERISK_PBX_DIR . '/pjsip_trunks.conf';
        $this->assertFileExists($p, 'trunk config was not generated');
        return (string) file_get_contents($p);
    }

    // --- Safety locks first -------------------------------------------------

    public function testNothingIsWrittenToTheLiveConfigDirectory(): void
    {
        $this->assertNotSame(
            '/etc/asterisk/pbx',
            ASTERISK_PBX_DIR,
            'SAFETY: tests point at the LIVE config directory!'
        );
        $this->assertStringStartsWith(sys_get_temp_dir(), ASTERISK_PBX_DIR);
        // rtp.conf / udptl.conf / voicemail.conf / asterisk.conf writers use this one.
        $this->assertNotSame('/etc/asterisk', ASTERISK_CONF_DIR, 'SAFETY: the tests write into the LIVE /etc/asterisk!');
        $this->assertStringStartsWith(sys_get_temp_dir(), ASTERISK_CONF_DIR);
    }

    public function testNoCommandReachesTheLiveAsterisk(): void
    {
        $this->assertSame('1', getenv('AIPBX_NO_ASTERISK'),
            'SAFETY: the Asterisk CLI lock is not set; tests could trigger a live reload!');

        $r = AsteriskHelper::execCLI('core show version');
        $this->assertStringContainsString('[test-modu]', $r['output'],
            'execCLI really sends commands to the live Asterisk!');
    }

    // --- Generated content ---------------------------------------------------

    public function testTrunkConfHasTheExpectedDirectives(): void
    {
        syncAllTrunks();
        $conf = $this->trunkConf();

        $this->assertStringContainsString('[' . Fixtures::TRUNK_NAME . ']', $conf);
        $this->assertStringContainsString('type=endpoint', $conf);
        $this->assertStringContainsString('type=aor', $conf);
        $this->assertStringContainsString('type=identify', $conf);
        $this->assertStringContainsString('allow=alaw,ulaw', $conf);
        $this->assertStringContainsString('t38_udptl=yes', $conf);
        $this->assertStringContainsString('192.0.2.10', $conf);
        // a lost OPTIONS probe must not take the trunk down for a minute
        $this->assertStringContainsString("qualify_frequency=60\nqualify_timeout=8\n", $conf);
    }

    public function testT38IsNotWrittenWhenOff(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET t38_support = 0 WHERE trunk_name = ?')
               ->execute([Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        // SyncTrunks prints no lines at all while T.38 is off (it does not
        // write "t38_udptl=no") — correct, since it is off by default in
        // Asterisk. What matters is that no "yes" leaks in.
        $this->assertStringNotContainsString('t38_udptl=yes', $conf,
            'T.38 is written as on although it is off');
        $this->assertStringNotContainsString('t38_udptl_ec', $conf);
    }

    public function testGenerationIsIdempotent(): void
    {
        syncAllTrunks();
        $a = md5($this->trunkConf());
        syncAllTrunks();
        $b = md5($this->trunkConf());

        $this->assertSame($a, $b, 'the same data gives two different outputs');
    }

    // --- Enjeksiyon direnci ------------------------------------------------

    public function testMaliciousTitleCannotOpenAConfigSection(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET title = ? WHERE trunk_name = ?')
               ->execute(["Evil\n[hacked]\ntype=endpoint\ncontext=from-internal", Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        // The real security property: no new PJSIP SECTION can be opened. The
        // text appearing as plain text inside a comment line is harmless —
        // line breaks are removed, so "[hacked]" is squeezed into a one-line
        // comment.
        $this->assertDoesNotMatchRegularExpression('/^\[hacked\]/m', $conf,
            'the title field can open a new config SECTION!');
        $this->assertDoesNotMatchRegularExpression('/^type=endpoint\s*$/m',
            preg_replace('/^\[' . Fixtures::TRUNK_NAME . '\]\ntype=endpoint\n/m', '', $conf),
            'the title field can inject an extra type=endpoint line!');
    }

    public function testMaliciousCodecListCannotAddADirective(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET codecs = ? WHERE trunk_name = ?')
               ->execute(["alaw\nmax_contacts=99", Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        $this->assertDoesNotMatchRegularExpression('/^max_contacts=99$/m', $conf,
            'an arbitrary PJSIP directive can be injected through the codec field!');
    }

    public function testMaliciousIpAddressCannotBreakALine(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET ip_address = ? WHERE trunk_name = ?')
               ->execute(["192.0.2.10\ncontext=from-internal", Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        $this->assertDoesNotMatchRegularExpression('/^context=from-internal$/m', $conf,
            'an arbitrary directive can be injected through the IP field!');
    }

    private function endpointsConf(): string
    {
        $p = ASTERISK_PBX_DIR . '/pjsip_endpoints.conf';
        $this->assertFileExists($p, 'endpoints config was not generated');
        return (string) file_get_contents($p);
    }

    public function testExtensionAuthDigestOn(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role) VALUES ('testext1', '', 'Test User 1', '7001', ?, 1, 'sip', 1, 'user')")->execute(['S' . bin2hex(random_bytes(8))]);
            syncAllExtensions();
            $conf = $this->endpointsConf();

            $this->assertStringContainsString('[7001-sip](endpoint-sip)', $conf);
            $this->assertStringContainsString('auth=auth7001-sip', $conf);
            $this->assertStringContainsString('[auth7001-sip](auth-userpass)', $conf);
            $this->assertStringContainsString('[7001-sip-identify]', $conf);
            $this->assertStringContainsString('aors=7001-sip,7001', $conf);
            $this->assertStringContainsString('[7001](aor-dual)', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '7001'")->execute();
        }
    }

    /** A BLF key dials <pickup code><extension> (e.g. *211001) to take the call ringing there. */
    public function testDirectedPickupAcceptsCodePlusExtension(): void
    {
        require_once dirname(__DIR__, 2) . '/src/sync/SyncFeatureCodes.php';
        $db = getDB();
        $had = (bool) $db->query("SELECT COUNT(*) FROM pbx_feature_codes WHERE feature_key = 'pickup_directed'")->fetchColumn();
        try {
            if (!$had) {
                $db->exec("INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active) VALUES ('pickup_directed', 'Directed call pickup', '*21', NULL, 1)");
            }
            syncFeatureCodes();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_featurecodes.conf');
            $this->assertStringContainsString('exten => _*21X.,1,', $conf);
            $this->assertStringContainsString('Set(PICKTARGET=${FILTER(0123456789,${EXTEN:3})})', $conf);
            $this->assertStringContainsString('GotoIf($["${PICKTARGET}" != ""]?pickdir_go)', $conf);
        } finally {
            if (!$had) {
                $db->exec("DELETE FROM pbx_feature_codes WHERE feature_key = 'pickup_directed'");
            }
        }
    }

    /**
     * Star codes hand values to a shell (System). Every one must be digits only:
     * a "." pattern also matches other characters, so "*81;rm -rf /" would
     * otherwise reach the shell as the asterisk user (review of 2026-10-16).
     */
    public function testFeatureCodeShellArgumentsAreDigitsOnly(): void
    {
        require_once dirname(__DIR__, 2) . '/src/sync/SyncFeatureCodes.php';
        $db = getDB();
        $added = [];
        try {
            foreach ([['queue_login', '*81'], ['queue_logout', '*80'], ['queue_pause', '*22'], ['cf_set', '*72'], ['dnd_toggle', '*78']] as [$key, $code]) {
                if (!(bool) $db->query("SELECT COUNT(*) FROM pbx_feature_codes WHERE feature_key = " . $db->quote($key))->fetchColumn()) {
                    $db->prepare('INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active) VALUES (?, ?, ?, NULL, 1)')->execute([$key, $key, $code]);
                    $added[] = $key;
                }
            }
            syncFeatureCodes();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_featurecodes.conf');
            $this->assertStringContainsString('System(', $conf);
            foreach (explode("\n", $conf) as $line) {
                if (!str_contains($line, 'System(')) {
                    continue;
                }
                $bare = (string) preg_replace('/\$\{FILTER\(0-9,\$\{[^}]+\}\)\}/', '', $line);
                $this->assertDoesNotMatchRegularExpression('/\$\{(CALLERID|EXTEN)/', $bare, 'unfiltered value in: ' . $line);
            }
            $this->assertStringContainsString('Set(QID=${FILTER(0-9,${EXTEN:3})})', $conf);
            $this->assertStringContainsString('Set(CFTARGET=${FILTER(0-9,${CFTARGET})})', $conf);
        } finally {
            foreach ($added as $key) {
                $db->prepare('DELETE FROM pbx_feature_codes WHERE feature_key = ?')->execute([$key]);
            }
        }
    }

    /** Desk phones get the voicemail lamp (MWI) when the box is enabled, only on the -sip endpoint. */
    public function testDeskPhoneEndpointHasVoicemailLamp(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $ins = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role, voicemail_enabled) VALUES (?, '', ?, ?, ?, 1, 'sip', 1, 'user', ?)");
            $ins->execute(['testmwi1', 'MWI On', '7011', 'S' . bin2hex(random_bytes(8)), 1]);
            $ins->execute(['testmwi2', 'MWI Off', '7012', 'S' . bin2hex(random_bytes(8)), 0]);
            syncAllExtensions();
            $conf = $this->endpointsConf();
            $section = fn(string $name) => preg_match('/^\[' . preg_quote($name, '/') . '\][^\n]*\n(.*?)(?=^\[|\z)/ms', $conf, $m) ? $m[1] : '';

            $this->assertStringContainsString('mailboxes=7011@default', $section('7011-sip'));
            $this->assertStringNotContainsString('mailboxes=', $section('7011-webrtc'));
            $this->assertStringNotContainsString('mailboxes=', $section('7012-sip'));
        } finally {
            $db->exec("DELETE FROM sys_users WHERE extension IN ('7011', '7012')");
        }
    }

    public function testExtensionAuthDigestOffGeneratesIdentify(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role) VALUES ('testext2', '', 'Test User 2', '7002', '', 0, 'sip', 1, 'user')")->execute();
            syncAllExtensions();
            $conf = $this->endpointsConf();

            $this->assertStringContainsString('[7002-sip](endpoint-sip)', $conf);
            $this->assertStringNotContainsString('auth=auth7002-sip', $conf);
            $this->assertStringNotContainsString('[auth7002-sip](auth-userpass)', $conf);
            $this->assertStringContainsString('[7002-sip-identify]', $conf);
            $this->assertStringContainsString('type=identify', $conf);
            $this->assertStringContainsString('endpoint=7002-sip', $conf);
            // The host part must be limited to the PBX's own addresses (bulgu2.md B2-3).
            // The old pattern (<?sip:7002[@:]) never checked the host; a From header
            // from a foreign host still matched this endpoint.
            $this->assertStringContainsString('match_header=From: /<?sip:7002@(', $conf);
            $this->assertStringNotContainsString('match_header=From: /<?sip:7002[@:]/', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '7002'")->execute();
        }
    }

    /**
     * Two `sys_users` rows can share one extension (an imported account plus one
     * re-created by hand). One object per row makes PJSIP reject the whole file —
     * "configuration contains a duplicate object" — so the reload fails and the box
     * comes up with no endpoints at all: every phone unregisters, not just the
     * duplicated extension. The generator keeps the first row (id ASC, so the
     * winner is stable) and logs the row it skipped.
     */
    public function testDuplicateUserRowsForOneExtensionGenerateOneBlock(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $ins = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role) VALUES (?, '', ?, '7199', ?, 1, 'sip', 1, 'user')");
            $ins->execute(['dupext1', 'Dup Winner', 'S' . bin2hex(random_bytes(8))]);
            $ins->execute(['dupext2', 'Dup Loser', 'S' . bin2hex(random_bytes(8))]);

            syncAllExtensions();
            $conf = $this->endpointsConf();

            $this->assertSame(1, substr_count($conf, '[7199-sip](endpoint-sip)'),
                'the endpoint block was generated more than once');
            $this->assertSame(1, substr_count($conf, '[auth7199-sip](auth-userpass)'),
                'the auth block was generated more than once');
            $this->assertSame(1, substr_count($conf, '[7199-sip-identify]'),
                'the identify block was generated more than once');
            $this->assertSame(1, substr_count($conf, '[7199](aor-dual)'),
                'the AOR was generated more than once');
            // The earliest row wins, so the output does not depend on row order.
            $this->assertStringContainsString('Dup Winner', $conf);
            $this->assertStringNotContainsString('Dup Loser', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '7199'")->execute();
        }
    }

    public function testEmptyIdentifyHostListFallsBackToTheOldWidePattern(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role) VALUES ('testext3', '', 'Test User 3', '7003', '', 0, 'sip', 1, 'user')")->execute();

            // An empty list cannot be forced (the automatic sources always yield
            // localhost), so at least we check the list is really filled and
            // reaches the regex — if it stayed empty the generator would fall
            // back to the old pattern and the test above would turn red, so the
            // two tests complement each other.
            $hosts = pjsipIdentifyHosts();
            $this->assertNotEmpty($hosts, 'with an empty host list the narrowing is never applied');
            $this->assertContains('localhost', $hosts);

            syncAllExtensions();
            $conf = $this->endpointsConf();
            $this->assertMatchesRegularExpression(
                '/match_header=From: \/<\?sip:7003@\([^)]+\)\//',
                $conf
            );
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '7003'")->execute();
        }
    }

    public function testCallForwardingDialplanGeneration(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active, role, cf_busy_number, cf_noanswer_number, cf_noanswer_timeout) VALUES ('testcf1', '', 'Test CF 1', '7003', 'sip', 1, 'user', '7004', '05551234567', 15)")->execute();

            syncGeneralDialplan();
            $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

            $this->assertStringContainsString('exten => 7003,1,NoOp(Direct Call to Extension 7003', $conf);
            $this->assertStringContainsString('Dial(${DIAL_CONTACTS},15,tTb(sub-callee-jb^s^1))', $conf);
            $this->assertStringContainsString('Goto(from-internal-desktop,7004,1)', $conf);
            $this->assertStringContainsString('Goto(from-internal-desktop,05551234567,1)', $conf);
            $this->assertStringContainsString('Call forwarding loop blocked - 7003', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '7003'")->execute();
        }
    }

    public function testIncomingFaxHandsTheFailureReasonToTheProcessor(): void
    {
        syncGeneralDialplan();
        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

        // Filtered: the reason goes into a shell command.
        $this->assertStringContainsString('Set(FAX_ERR=${FILTER(A-Za-z0-9_ .:/,${FAXOPT(error)}: ${FAXOPT(statusstr)})})', $conf);
        $this->assertStringContainsString('"${FAXOPT(status)}" "${FAX_ERR}"))', $conf);
    }

    /**
     * A fax that failed before its first page has no file. The processor used
     * to stop there without a trace; it now records the call as FAILED with
     * the reason. `mysql` is a stub that keeps the SQL it is given.
     */
    public function testFailedFaxWithoutAFileIsRecorded(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx-fax-test-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/mysql", "#!/bin/sh\ncat >> \"$dir/sql\"\n");
        chmod("$dir/mysql", 0755);
        @unlink("$dir/sql");

        $script = dirname(__DIR__, 2) . '/bin/process_incoming_fax.sh';
        $cmd = 'PATH=' . escapeshellarg($dir) . ':/usr/bin:/bin bash ' . escapeshellarg($script)
            . ' /var/spool/asterisk/fax/20261022_104943_05427821260_1.494.tif 9399 05427821260 0 FAILED '
            . escapeshellarg('NO_FAX: no fax tone; $(id)') . ' 2>/dev/null';
        exec($cmd, $out, $rc);
        $sql = (string)@file_get_contents("$dir/sql");
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);

        $this->assertSame(0, $rc);
        $this->assertStringContainsString("VALUES ('9399', '05427821260', 0, '', '', 0, 'FAILED', 'NO_FAX: no fax tone id', 1, 0);", $sql);
    }

    // --- Internal destination numbers ---------------------------------------

    private function internalConf(): string
    {
        $p = ASTERISK_PBX_DIR . '/extensions_internalnumbers.conf';
        $this->assertFileExists($p, 'internal target numbers config was not generated');
        return (string) file_get_contents($p);
    }

    public function testInternalTargetNumberContextIsGenerated(): void
    {
        syncInternalNumbers();
        $c = $this->internalConf();
        $this->assertStringContainsString('[internal-numbers-pbx]', $c);
        $this->assertStringContainsString('exten => 1010,1,NoOp(', $c);
        $this->assertStringContainsString('Set(CDR(direction)=internal)', $c);
    }

    public function testIvrNumberGoesToTheIvrContext(): void
    {
        syncInternalNumbers();
        $ivrId = (int) getDB()->query("SELECT id FROM pbx_ivrs WHERE internal_number = '1010'")->fetchColumn();
        $this->assertStringContainsString("Goto(app-ivr-{$ivrId},s,1)", $this->internalConf());
    }

    public function testHangupActionNumberAppliesItsActionType(): void
    {
        syncInternalNumbers();
        $c = $this->internalConf();
        $this->assertStringContainsString('exten => 1020,1,NoOp(', $c);
        $this->assertStringContainsString('Busy(10)', $c,
            'if it was looked up by id instead of action_key this becomes a plain Hangup()');
    }

    public function testHangupActionWithoutAnnouncementIsNotAnswered(): void
    {
        syncInternalNumbers();
        $c = $this->internalConf();
        $blok = substr($c, strpos($c, 'exten => 1020,1,'));
        $this->assertStringNotContainsString('Answer()', $blok,
            'Answer() on a destination without an announcement means an in-band tone instead of SIP 486');
    }

    public function testAnnouncementTargetIsAnsweredQueueIsNot(): void
    {
        $entries = [
            ['number' => '1091', 'source' => 'announcement', 'dest_type' => 'announcement', 'dest_id' => '1', 'label' => 'Anons', 'row_id' => 1],
            ['number' => '1092', 'source' => 'queue', 'dest_type' => 'queue', 'dest_id' => '1', 'label' => 'Kuyruk', 'row_id' => 1],
        ];
        $c = buildInternalNumbersConf($entries);
        $announcementBlock = substr($c, strpos($c, 'exten => 1091,'), strpos($c, 'exten => 1092,') - strpos($c, 'exten => 1091,'));
        $queueBlock = substr($c, strpos($c, 'exten => 1092,'));
        $this->assertStringContainsString('Answer()', $announcementBlock);
        $this->assertStringNotContainsString('Answer()', $queueBlock,
            'Answer() before Queue() breaks the CDR answered statistics');
    }

    public function testItemWithoutNumberIsNotInTheContext(): void
    {
        getDB()->exec("UPDATE pbx_ivrs SET internal_number = NULL");
        syncInternalNumbers();
        $this->assertStringNotContainsString('exten => 1010,', $this->internalConf());
    }

    public function testContextIsWrittenEvenWithoutNumbers(): void
    {
        getDB()->exec("UPDATE pbx_ivrs SET internal_number = NULL");
        getDB()->exec("UPDATE pbx_hangup_actions SET internal_number = NULL");
        syncInternalNumbers();
        $this->assertStringContainsString('[internal-numbers-pbx]', $this->internalConf(),
            'the context must be written even when empty; otherwise from-internal-pbx-ortak includes a context that does not exist');
    }

    public function testGeneralDialplanIncludesTheNumbersContext(): void
    {
        syncGeneralDialplan();
        $g = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $bas = strpos($g, '[from-internal-pbx-ortak]');
        $son = strpos($g, '[from-internal-pbx]');
        $this->assertNotFalse($bas);
        $this->assertNotFalse($son);
        $ortak = substr($g, $bas, $son - $bas);
        $this->assertStringContainsString('include => internal-numbers-pbx', $ortak,
            'the include must be INSIDE the common context BLOCK');
    }

    // --- rtp.conf: bloke eden stunaddr regresyonu -------------------------

    public function testRtpConfHasNoStunaddrByDefault(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'rtp_stunaddr_enabled'");
        $conf = buildRtpConf();

        // With stunaddr on, Asterisk hit a 3x3 s STUN timeout on every RTP
        // session and WebRTC calls were blocked for ~6 s (measured
        // 2026-09-05, log: stun.c "Attempt 3 ... timed out" 09:16:46, the
        // dialplan starts in the same second).
        $this->assertStringNotContainsString('stunaddr=', $conf,
            'stunaddr must be OFF by default: it blocks WebRTC calls');

    }

    /**
     * The NAT mapping must be its own [ice_host_candidates] section: under
     * [general] Asterisk ignored it and remote WebRTC clients had no audio.
     */
    public function testRtpConfMapsThePublicAddressInItsOwnSection(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key IN ('server_ip', 'pjsip_external_ip')");
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('server_ip', '10.8.0.12'), ('pjsip_external_ip', '203.0.113.7')")->execute();
        try {
            $conf = buildRtpConf();
            $this->assertMatchesRegularExpression('/^\[ice_host_candidates\]\n10\.8\.0\.12 => 203\.0\.113\.7,include_local_address$/m', $conf);
            $general = substr($conf, 0, (int) strpos($conf, '[ice_host_candidates]'));
            $this->assertStringNotContainsString('ice_host_candidates', $general, 'Asterisk ignores it under [general]');

            // No public address configured: no mapping at all (it used to map to 198.51.100.1).
            $db->exec("DELETE FROM sys_settings WHERE setting_key = 'pjsip_external_ip'");
            $db->exec("DELETE FROM pjsipsettings WHERE keyword = 'externip_val'");
            if (portalEnv('EXTERNAL_IP', '') === '') {
                $this->assertStringNotContainsString('[ice_host_candidates]', buildRtpConf());
            }
        } finally {
            $db->exec("DELETE FROM sys_settings WHERE setting_key IN ('server_ip', 'pjsip_external_ip')");
        }
    }

    public function testRtpPrimaryIpIsARealAddress(): void
    {
        $ip = rtpPrimaryIp();
        $this->assertTrue($ip === '' || (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '127.0.0.1'), $ip);
    }

    public function testRtpStunaddrCanBeSwitchedOn(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key = 'rtp_stunaddr_enabled'");
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('rtp_stunaddr_enabled', '1')")->execute();
        try {
            $this->assertStringContainsString('stunaddr=', buildRtpConf(),
                'with the setting at 1, stunaddr must be generated again');
        } finally {
            $db->exec("DELETE FROM sys_settings WHERE setting_key = 'rtp_stunaddr_enabled'");
        }
    }

    // --- Mobile push notification hook tests (layer 1) ----------------------

    public function testPushOffDialplanHasNoPushLines(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['push_provider', 'none']);
        $stmt->execute(['push_enabled', '0']);

        syncGeneralDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

        $this->assertStringNotContainsString('push_dispatcher.php', $conf,
            'with push off the dialplan must not contain push_dispatcher.php');
        $this->assertStringNotContainsString('push_loop', $conf,
            'with push off the dialplan must not contain push_loop');
    }

    public function testPushOnWithMobileDeviceDialplanHasPushLines(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['push_provider', 'fcm']);
        $stmt->execute(['push_enabled', '1']);

        $ext = '3001';
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active, role) VALUES ('user_push_test', 'testhash', 'Push Test User', ?, 'sip', 1, 'user')")->execute([$ext]);
        $userId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO sys_mobile_devices (user_id, extension, fcm_token, push_type, device_id, platform, is_active, created_at, updated_at) VALUES (?, ?, 'valid_fcm_token_unit_test', 'fcm', 'test_dev_01', 'android', 1, NOW(), NOW())")->execute([$userId, $ext]);

        try {
            syncGeneralDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

            $this->assertStringContainsString('push_dispatcher.php', $conf,
                'with push on and a mobile device, push_dispatcher must be called');
            $this->assertStringContainsString('push_loop', $conf,
                'the push wait loop must be generated');
        } finally {
            $db->exec("DELETE FROM sys_mobile_devices WHERE fcm_token = 'valid_fcm_token_unit_test'");
            $db->exec("DELETE FROM sys_users WHERE id = {$userId}");
            $stmt->execute(['push_provider', 'none']);
            $stmt->execute(['push_enabled', '0']);
        }
    }

    public function testPushOnDeviceWithoutTokenGetsNoPushLines(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['push_provider', 'fcm']);
        $stmt->execute(['push_enabled', '1']);

        $ext = '3002';
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active, role) VALUES ('user_push_none', 'testhash', 'Push None User', ?, 'sip', 1, 'user')")->execute([$ext]);
        $userId = (int)$db->lastInsertId();

        // push_type='none' and fcm_token=NULL (case T-16)
        $db->prepare("INSERT INTO sys_mobile_devices (user_id, extension, fcm_token, push_type, device_id, platform, is_active, created_at, updated_at) VALUES (?, ?, NULL, 'none', 'test_dev_02', 'android', 1, NOW(), NOW())")->execute([$userId, $ext]);

        try {
            syncGeneralDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

            $this->assertStringNotContainsString('push_dispatcher.php', $conf,
                'even with push on, push_dispatcher must not be called for a device without token');
            $this->assertStringNotContainsString('push_loop', $conf,
                'even with push on, no push loop for a device without token');
        } finally {
            $db->exec("DELETE FROM sys_mobile_devices WHERE device_id = 'test_dev_02'");
            $db->exec("DELETE FROM sys_users WHERE id = {$userId}");
            $stmt->execute(['push_provider', 'none']);
            $stmt->execute(['push_enabled', '0']);
        }
    }

    public function testOutboundRouteDialHasNoROption(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // D-1: Dial(...) must not have the 'r' option (so early media is not suppressed)
        $this->assertMatchesRegularExpression('/Dial\(PJSIP\/[^,\)]+,\d+,Tb\(sub-callee-jb\^s\^1\)\)/', $conf,
            'the outbound dialplan Dial PJSIP option must be T');
        $this->assertDoesNotMatchRegularExpression('/Dial\([^,\)]+,\d+,[^\)]*r[^\)]*\)/', $conf,
            'the outbound dialplan must not have the r option, which fakes ringing and drowns early media (D-1)');
    }

    public function testCallFromATrunkIsNotSentBackThroughIt(): void
    {
        // A number the PBX behind a trunk routes to us and we route back looped
        // (thousands of calls an hour on production, October 2026).
        $db = getDB();
        $db->prepare("INSERT INTO pbx_outbound_routes (route_name, match_pattern, is_active, route_group, trunks_json) VALUES ('Loop', '_5XXX', 1, 1, ?)")
           ->execute([json_encode([['trunk_name' => Fixtures::TRUNK_NAME, 'callerid_override' => '']])]);
        try {
            syncOutboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
            $t = Fixtures::TRUNK_NAME;
            $this->assertStringContainsString(
                ' same => n,GotoIf($["${CF_HOPS}" = "" & ${REGEX("^PJSIP/' . preg_quote($t, '/') . '-[0-9a-f]+$" ${CHANNEL(name)})}]?hairpin1)', $conf);
            $this->assertMatchesRegularExpression('/n\(hairpin1\),NoOp\([^\n]*\)\n same => n,Set\(DIALSTATUS=CONGESTION\)\n same => n\(afterhp1\),NoOp\(\)/', $conf);
            // the guard comes before the Dial it skips
            $block = substr($conf, strpos($conf, 'exten => _5XXX,1'));
            $this->assertLessThan(strpos($block, 'Dial(PJSIP/'), strpos($block, '?hairpin1)'));
        } finally {
            $db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = 'Loop'");
        }
    }

    public function testSignalingCaptureSwitchAndTheOldPluginFileIsRemoved(): void
    {
        $db = getDB();
        $set = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('signaling_capture', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        // The retired plugin's file sent the capture to 127.0.0.1:9060, where nothing listens.
        file_put_contents(ASTERISK_PBX_DIR . '/hep_plugin_signaling-capture.conf', "[general]\ncapture_address = 127.0.0.1:9060\n");
        try {
            $set->execute(['1']);
            syncSignalingCapture();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/hep_aipbx.conf');
            $this->assertStringContainsString("[general]\nenabled = yes\n", $conf);
            $this->assertStringNotContainsString('capture_address', $conf, 'always the local daemon of hep.conf');
            $this->assertFileDoesNotExist(ASTERISK_PBX_DIR . '/hep_plugin_signaling-capture.conf');

            $set->execute(['0']);
            syncSignalingCapture();
            $this->assertStringContainsString('enabled = no', (string) file_get_contents(ASTERISK_PBX_DIR . '/hep_aipbx.conf'));
        } finally {
            $db->exec("DELETE FROM sys_settings WHERE setting_key = 'signaling_capture'");
        }
    }

    public function testTrunkLimitsAreCheckedInboundAndOutbound(): void
    {
        $db = getDB();
        $t = Fixtures::TRUNK_NAME;
        $db->prepare('UPDATE pbx_trunks SET max_channels = 4, max_calls_per_minute = 30 WHERE trunk_name = ?')->execute([$t]);
        $db->prepare("INSERT INTO pbx_outbound_routes (route_name, match_pattern, is_active, route_group, trunks_json) VALUES ('Lim', '_6XXX', 1, 1, ?)")
           ->execute([json_encode([['trunk_name' => $t, 'callerid_override' => '']])]);
        try {
            syncInboundDialplan();
            $in = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf');
            $this->assertStringContainsString('[sub-trunk-limit]', $in);
            $this->assertStringContainsString(" same => n,Gosub(sub-trunk-limit,s,1({$t},4,30,trunkin))\n same => n,ExecIf(\$[\"\${TRUNK_LIMIT}\" != \"\"]?Hangup(34))", $in);

            syncOutboundDialplan();
            $out = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
            $this->assertStringContainsString(" same => n,Gosub(sub-trunk-limit,s,1({$t},4,30,trunk))", $out);
            $this->assertStringContainsString('?trunkfull1)', $out);
            $this->assertStringNotContainsString('Set(GROUP(trunk)=', $out, 'the subroutine sets the group');

            // No limit: no check at all.
            $db->prepare('UPDATE pbx_trunks SET max_channels = 0, max_calls_per_minute = 0 WHERE trunk_name = ?')->execute([$t]);
            syncInboundDialplan();
            $this->assertStringNotContainsString("Gosub(sub-trunk-limit,s,1({$t},", (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf'));
        } finally {
            $db->prepare('UPDATE pbx_trunks SET max_channels = 0, max_calls_per_minute = 0 WHERE trunk_name = ?')->execute([$t]);
            $db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = 'Lim'");
        }
    }

    public function testSpokenStatusIsPlayedOnlyWhenSwitchedOn(): void
    {
        $db = getDB();
        $set = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role, voicemail_enabled) VALUES ('sptest', '', 'Status Prompt', '7088', ?, 1, 'sip', 1, 'user', 0)")->execute(['S' . bin2hex(random_bytes(8))]);
        try {
            syncGeneralDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
            $this->assertStringContainsString('[sub-status-prompt]', $conf);
            $this->assertStringNotContainsString('Gosub(sub-status-prompt', $conf, 'off by default: tones only');

            foreach (['busy', 'noanswer', 'unavail'] as $case) {
                $set->execute(['status_prompt_' . $case, '1']);
            }
            syncGeneralDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
            $this->assertStringContainsString("Gosub(sub-status-prompt,s,1(busy,7088))\n same => n,Hangup(17)", $conf);
            $this->assertStringContainsString("Gosub(sub-status-prompt,s,1(noanswer,7088))\n same => n,Hangup()", $conf);
            $this->assertStringContainsString("Gosub(sub-status-prompt,s,1(unavail,7088))\n same => n,Hangup()", $conf);
            $this->assertStringContainsString('ExecIf($["${CHANNEL(state)}" != "Up"]?Progress())', $conf, 'early media: a trunk call is not answered');
        } finally {
            $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'status_prompt_%'");
            $db->exec("DELETE FROM sys_users WHERE username = 'sptest'");
        }
    }

    public function testFaxCallsAreNotRecordedAndGetNoJitterBuffer(): void
    {
        // A fax sent from the portal goes through the outbound route like a
        // call. Recording it stores modem noise, and a jitter buffer reshapes
        // the modem's timing; the call file marks it with __AIPBX_FAX=1.
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
        if (str_contains($conf, 'MixMonitor(${REC_FILE})')) {
            $this->assertStringContainsString('GotoIf($["${AIPBX_FAX}" = "1"]?norec)', $conf);
            $this->assertStringContainsString(' same => n(norec),NoOp()', $conf);
        }
        $this->assertStringNotContainsString(" same => n,Set(JITTERBUFFER(adaptive)=default)\n", $conf);

        syncGeneralDialplan();
        $general = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $this->assertStringContainsString('ExecIf($["${AIPBX_FAX}" != "1"]?Set(JITTERBUFFER(adaptive)=default))', $general);
    }

    public function testFaxNumberIsNeverRecorded(): void
    {
        $_SESSION['csrf_token'] = 'test-csrf';
        $res = RouteService::saveDIDRoute([
            'csrf_token' => 'test-csrf',
            'route_id' => 0,
            'did_number' => '7999',
            'title' => 'Recorded fax test',
            'dest_type' => 'fax',
            'dest_id' => '',
            'record_call' => 1,
            'is_active' => 1,
        ]);
        try {
            $this->assertEmpty($res['error'] ?? '');
            syncInboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf');
            $start = strpos($conf, 'exten => 7999,1,');
            $this->assertNotFalse($start);
            $end = strpos($conf, "\n\n", $start);
            $block = substr($conf, $start, $end === false ? null : $end - $start);
            $this->assertStringNotContainsString('MixMonitor', $block);
        } finally {
            getDB()->exec("DELETE FROM pbx_dids WHERE did_number = '7999' AND title = 'Recorded fax test'");
        }
    }

    public function testOutboundRouteHasStatusHandlingAndHangupCause(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // D-2: after Dial there must be a sub-outbound-status jump instead of a bare Hangup
        $this->assertStringContainsString('sub-outbound-status', $conf,
            'the outbound dialplan must have the sub-outbound-status context (D-2)');
        $this->assertStringContainsString('exten => BUSY,1,NoOp(Outbound Status: BUSY', $conf,
            'sub-outbound-status must handle BUSY');
        $this->assertStringContainsString('same => n,Hangup(17)', $conf,
            'BUSY must hang up at once with ISDN cause 17');
        $this->assertDoesNotMatchRegularExpression('/BUSY,1[^\n]+\n\s*same => n,Busy\(/', $conf,
            'BUSY must not call Busy(), which causes a 10 second delay');
        $this->assertStringContainsString('exten => CONGESTION,1,NoOp(Outbound Status: CONGESTION', $conf,
            'CONGESTION must be handled');
        $this->assertStringContainsString('exten => CHANUNAVAIL,1,NoOp(Outbound Status: CHANUNAVAIL', $conf,
            'CHANUNAVAIL must be handled');
        $this->assertStringContainsString('exten => NOANSWER,1,NoOp(Outbound Status: NOANSWER', $conf,
            'NOANSWER must be handled');
    }

    public function testTrunkInboundDialplanGenerationAndDidTrimming(): void
    {
        $db = getDB();
        $db->prepare('UPDATE pbx_trunks SET did_trim_digits = 4, allow_outbound_routing = 1, outbound_route_group = 1 WHERE trunk_name = ?')
           ->execute([Fixtures::TRUNK_NAME]);

        syncInboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf');

        // Does the trunk inbound context exist
        $this->assertStringContainsString('[from-trunk-' . Fixtures::TRUNK_NAME . ']', $conf);
        // Is the DID trimming formula right
        $this->assertStringContainsString('same => n,Set(NORMALIZED_DID=${IF($[${LEN(${EXTEN})} >= 4]?${EXTEN:-4}:${EXTEN})})', $conf);
        $this->assertStringContainsString('same => n,Goto(from-trunk-' . Fixtures::TRUNK_NAME . '-route,${NORMALIZED_DID},1)', $conf);

        // Are the transit outbound route and the internal subscribers included in the route context
        $this->assertStringContainsString('[from-trunk-' . Fixtures::TRUNK_NAME . '-route]', $conf);
        $this->assertStringContainsString('include => from-trunk-inbound', $conf);
        $this->assertStringContainsString('include => from-internal-outbound-1', $conf);
        $this->assertStringContainsString('include => from-internal-pbx-ortak', $conf);
        $this->assertStringContainsString('include => from-trunk-notfound', $conf);
    }

    public function testOutboundDialplanKeepsCallerIdOnTransitCalls(): void
    {
        $db = getDB();
        $db->prepare('UPDATE pbx_trunks SET outbound_caller_id = "08501234567" WHERE trunk_name = ?')
           ->execute([Fixtures::TRUNK_NAME]);

        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // ExecIf condition that keeps the caller number on transit inbound calls and checks the internal CID_EXTERNAL priority
        $this->assertStringContainsString('ExecIf($["${CDR(inbound_trunk)}" = ""]?Set(CALLERID(num)=${IF($["${CID_EXTERNAL}" != ""]?${CID_EXTERNAL}:08501234567)}))', $conf,
            'on trunk-to-trunk transit calls the incoming mobile number must not be replaced by the trunk default CID');
    }

    public function testInboundDidPatternAutoExtensionMatching(): void
    {
        $_SESSION['csrf_token'] = 'test-csrf';
        $res = RouteService::saveDIDRoute([
            'csrf_token' => 'test-csrf',
            'route_id' => 0,
            'did_number' => '98xx',
            'title' => '98XX Extensions',
            'dest_type' => 'extension',
            'dest_id' => '__did__',
            'record_call' => 1,
            'is_active' => 1,
        ]);
        $this->assertEmpty($res['error'] ?? '');

        $saved = getDB()->query("SELECT * FROM pbx_dids WHERE title = '98XX Extensions'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($saved);
        $this->assertSame('98XX', $saved['did_number']);

        $res2 = RouteService::saveDIDRoute([
            'csrf_token' => 'test-csrf',
            'route_id' => 0,
            'did_number' => '78xxx',
            'title' => '78XXX Extensions',
            'dest_type' => 'extension',
            'dest_id' => '__did__',
            'record_call' => 0,
            'is_active' => 1,
        ]);
        $this->assertEmpty($res2['error'] ?? '');

        syncInboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf');

        $this->assertStringContainsString('exten => _98XX,1,NoOp(Inbound call for DID _98XX - 98XX Extensions)', $conf);
        $this->assertStringContainsString('exten => _78XXX,1,NoOp(Inbound call for DID _78XXX - 78XXX Extensions)', $conf);
        $this->assertStringContainsString('same => n,Set(CDR(did)=${EXTEN})', $conf);
        $this->assertStringContainsString('same => n,GotoIf($[${DIALPLAN_EXISTS(from-internal-pbx-ortak,${EXTEN},1)}]?from-internal-pbx-ortak,${EXTEN},1)', $conf);
        $this->assertStringContainsString('same => n,GotoIf($[$[${LEN(${EXTEN})} > 4] & $[${DIALPLAN_EXISTS(from-trunk-inbound,${EXTEN:-4},1)}]]?from-trunk-inbound,${EXTEN:-4},1)', $conf);
        $this->assertStringContainsString('same => n,GotoIf($[$[${LEN(${EXTEN})} > 5] & $[${DIALPLAN_EXISTS(from-trunk-inbound,${EXTEN:-5},1)}]]?from-trunk-inbound,${EXTEN:-5},1)', $conf);
    }
}


