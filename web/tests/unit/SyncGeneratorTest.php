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
        $this->assertFileExists($p, 'trunk config uretilmedi');
        return (string) file_get_contents($p);
    }

    // --- Safety locks first -------------------------------------------------

    public function testCanliConfigDizinineYAZILMIYOR(): void
    {
        $this->assertNotSame(
            '/etc/asterisk/pbx',
            ASTERISK_PBX_DIR,
            'GUVENLIK: testler CANLI config dizinine yonlendirilmis!'
        );
        $this->assertStringStartsWith(sys_get_temp_dir(), ASTERISK_PBX_DIR);
        // rtp.conf / udptl.conf / voicemail.conf / asterisk.conf writers use this one.
        $this->assertNotSame('/etc/asterisk', ASTERISK_CONF_DIR, 'GUVENLIK: testler CANLI /etc/asterisk dizinine yaziyor!');
        $this->assertStringStartsWith(sys_get_temp_dir(), ASTERISK_CONF_DIR);
    }

    public function testCanliAsteriskeKomutGitmiyor(): void
    {
        $this->assertSame('1', getenv('AIPBX_NO_ASTERISK'),
            'GUVENLIK: Asterisk CLI kilidi kurulu degil — testler canli reload tetikleyebilir!');

        $r = AsteriskHelper::execCLI('core show version');
        $this->assertStringContainsString('[test-modu]', $r['output'],
            'execCLI gercekten canli Asterisk\'e komut gonderiyor!');
    }

    // --- Generated content ---------------------------------------------------

    public function testTrunkConfBeklenenDirektifleriIcerir(): void
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
    }

    public function testT38KapaliysaHicYazilmaz(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET t38_support = 0 WHERE trunk_name = ?')
               ->execute([Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        // SyncTrunks prints no lines at all while T.38 is off (it does not
        // write "t38_udptl=no") — correct, since it is off by default in
        // Asterisk. What matters is that no "yes" leaks in.
        $this->assertStringNotContainsString('t38_udptl=yes', $conf,
            'T.38 kapali oldugu halde config\'e acik yaziliyor');
        $this->assertStringNotContainsString('t38_udptl_ec', $conf);
    }

    public function testUretimIdempotent(): void
    {
        syncAllTrunks();
        $a = md5($this->trunkConf());
        syncAllTrunks();
        $b = md5($this->trunkConf());

        $this->assertSame($a, $b, 'ayni veriden iki farkli cikti uretiliyor');
    }

    // --- Enjeksiyon direnci ------------------------------------------------

    public function testKotuNiyetliBaslikConfBolumuAcamaz(): void
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
            'baslik alanindan yeni bir config BOLUMU acilabiliyor!');
        $this->assertDoesNotMatchRegularExpression('/^type=endpoint\s*$/m',
            preg_replace('/^\[' . Fixtures::TRUNK_NAME . '\]\ntype=endpoint\n/m', '', $conf),
            'baslik alanindan fazladan bir type=endpoint satiri enjekte edilebiliyor!');
    }

    public function testKotuNiyetliKodekListesiDirektifEkleyemez(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET codecs = ? WHERE trunk_name = ?')
               ->execute(["alaw\nmax_contacts=99", Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        $this->assertDoesNotMatchRegularExpression('/^max_contacts=99$/m', $conf,
            'kodek alanindan keyfi PJSIP direktifi enjekte edilebiliyor!');
    }

    public function testKotuNiyetliIpAdresiSatirBolemez(): void
    {
        getDB()->prepare('UPDATE pbx_trunks SET ip_address = ? WHERE trunk_name = ?')
               ->execute(["192.0.2.10\ncontext=from-internal", Fixtures::TRUNK_NAME]);
        syncAllTrunks();
        $conf = $this->trunkConf();

        $this->assertDoesNotMatchRegularExpression('/^context=from-internal$/m', $conf,
            'IP alanindan keyfi direktif enjekte edilebiliyor!');
    }

    private function endpointsConf(): string
    {
        $p = ASTERISK_PBX_DIR . '/pjsip_endpoints.conf';
        $this->assertFileExists($p, 'endpoints config uretilmedi');
        return (string) file_get_contents($p);
    }

    public function testExtensionAuthDigestEtkin(): void
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

    public function testExtensionAuthDigestKapaliysaIdentifyUretilir(): void
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

    public function testIdentifyHostListesiBossaEskiGenisDeseneDusulur(): void
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
            $this->assertNotEmpty($hosts, 'host listesi bos kalirsa daraltma hic uygulanmaz');
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

    public function testCallForwardingDialplanUretimi(): void
    {
        $db = getDB();
        try {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
            $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active, role, cf_busy_number, cf_noanswer_number, cf_noanswer_timeout) VALUES ('testcf1', '', 'Test CF 1', '7003', 'sip', 1, 'user', '7004', '05551234567', 15)")->execute();

            syncGeneralDialplan();
            $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

            $this->assertStringContainsString('exten => 7003,1,NoOp(Direct Call to Extension 7003', $conf);
            $this->assertStringContainsString('Dial(${DIAL_CONTACTS},15,tTb(sub-callee-jb^s^1))', $conf);
            $this->assertStringContainsString('Goto(from-internal-pbx,7004,1)', $conf);
            $this->assertStringContainsString('Goto(from-internal-pbx,05551234567,1)', $conf);
            $this->assertStringContainsString('Call forwarding loop blocked - 7003', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '7003'")->execute();
        }
    }

    // --- Internal destination numbers ---------------------------------------

    private function internalConf(): string
    {
        $p = ASTERISK_PBX_DIR . '/extensions_internalnumbers.conf';
        $this->assertFileExists($p, 'dahili hedef numarasi config uretilmedi');
        return (string) file_get_contents($p);
    }

    public function testDahiliHedefNumarasiContextiUretilir(): void
    {
        syncInternalNumbers();
        $c = $this->internalConf();
        $this->assertStringContainsString('[internal-numbers-pbx]', $c);
        $this->assertStringContainsString('exten => 1010,1,NoOp(', $c);
        $this->assertStringContainsString('Set(CDR(direction)=internal)', $c);
    }

    public function testIvrNumarasiIvrContextineGotoEder(): void
    {
        syncInternalNumbers();
        $ivrId = (int) getDB()->query("SELECT id FROM pbx_ivrs WHERE internal_number = '1010'")->fetchColumn();
        $this->assertStringContainsString("Goto(app-ivr-{$ivrId},s,1)", $this->internalConf());
    }

    public function testCagriSonlandirmaNumarasiActionTypeUygular(): void
    {
        syncInternalNumbers();
        $c = $this->internalConf();
        $this->assertStringContainsString('exten => 1020,1,NoOp(', $c);
        $this->assertStringContainsString('Busy(10)', $c,
            'action_key yerine id ile arandiysa burasi duz Hangup() olur');
    }

    public function testAnonssuzCagriSonlandirmaCevaplanmaz(): void
    {
        syncInternalNumbers();
        $c = $this->internalConf();
        $blok = substr($c, strpos($c, 'exten => 1020,1,'));
        $this->assertStringNotContainsString('Answer()', $blok,
            'anonsu olmayan hedefte Answer() SIP 486 yerine bant ici ton demektir');
    }

    public function testAnonsHedefiCevaplanirKuyrukCevaplanmaz(): void
    {
        $entries = [
            ['number' => '1091', 'source' => 'announcement', 'dest_type' => 'announcement', 'dest_id' => '1', 'label' => 'Anons', 'row_id' => 1],
            ['number' => '1092', 'source' => 'queue', 'dest_type' => 'queue', 'dest_id' => '1', 'label' => 'Kuyruk', 'row_id' => 1],
        ];
        $c = buildInternalNumbersConf($entries);
        $anonsBlok = substr($c, strpos($c, 'exten => 1091,'), strpos($c, 'exten => 1092,') - strpos($c, 'exten => 1091,'));
        $kuyrukBlok = substr($c, strpos($c, 'exten => 1092,'));
        $this->assertStringContainsString('Answer()', $anonsBlok);
        $this->assertStringNotContainsString('Answer()', $kuyrukBlok,
            'Queue() oncesi Answer() CDR cevaplanma istatistigini bozar');
    }

    public function testNumarasizVarlikContexteGirmez(): void
    {
        getDB()->exec("UPDATE pbx_ivrs SET internal_number = NULL");
        syncInternalNumbers();
        $this->assertStringNotContainsString('exten => 1010,', $this->internalConf());
    }

    public function testHicNumaraYokkenBileContextYazilir(): void
    {
        getDB()->exec("UPDATE pbx_ivrs SET internal_number = NULL");
        getDB()->exec("UPDATE pbx_hangup_actions SET internal_number = NULL");
        syncInternalNumbers();
        $this->assertStringContainsString('[internal-numbers-pbx]', $this->internalConf(),
            'bos da olsa context yazilmali; yoksa from-internal-pbx-ortak var olmayan bir context include eder');
    }

    public function testGenelDialplanNumaraContextiniIncludeEder(): void
    {
        syncGeneralDialplan();
        $g = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $bas = strpos($g, '[from-internal-pbx-ortak]');
        $son = strpos($g, '[from-internal-pbx]');
        $this->assertNotFalse($bas);
        $this->assertNotFalse($son);
        $ortak = substr($g, $bas, $son - $bas);
        $this->assertStringContainsString('include => internal-numbers-pbx', $ortak,
            'include, ortak context BLOGUNUN ICINDE olmali');
    }

    // --- rtp.conf: bloke eden stunaddr regresyonu -------------------------

    public function testRtpConfVarsayilanOlarakStunaddrIcermez(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'rtp_stunaddr_enabled'");
        $conf = buildRtpConf();

        // With stunaddr on, Asterisk hit a 3x3 s STUN timeout on every RTP
        // session and WebRTC calls were blocked for ~6 s (measured
        // 2026-09-05, log: stun.c "Attempt 3 ... timed out" 09:16:46, the
        // dialplan starts in the same second).
        $this->assertStringNotContainsString('stunaddr=', $conf,
            'stunaddr varsayilan olarak KAPALI olmali — WebRTC cagrilarini bloke ediyor');

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
            $this->assertMatchesRegularExpression('/^\[ice_host_candidates\]\ninclude_local_address=yes\n10\.8\.0\.12 => 203\.0\.113\.7$/m', $conf);
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

    public function testRtpStunaddrAyarlaAcilabilir(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key = 'rtp_stunaddr_enabled'");
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('rtp_stunaddr_enabled', '1')")->execute();
        try {
            $this->assertStringContainsString('stunaddr=', buildRtpConf(),
                'ayar 1 yapildiginda stunaddr yeniden uretilmeli');
        } finally {
            $db->exec("DELETE FROM sys_settings WHERE setting_key = 'rtp_stunaddr_enabled'");
        }
    }

    // --- Mobile push notification hook tests (layer 1) ----------------------

    public function testPushKapaliysaDialplanPushSatirlariIcermez(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['push_provider', 'none']);
        $stmt->execute(['push_enabled', '0']);

        syncGeneralDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

        $this->assertStringNotContainsString('push_dispatcher.php', $conf,
            'push kapaliyken dialplan push_dispatcher.php icermemeli');
        $this->assertStringNotContainsString('push_loop', $conf,
            'push kapaliyken dialplan push_loop icermemeli');
    }

    public function testPushAcikkenVeMobilCihazVarkenDialplanPushSatirlariIcerir(): void
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
                'push acikken ve mobil cihaz varken push_dispatcher cagirilmali');
            $this->assertStringContainsString('push_loop', $conf,
                'push bekleme dongusu uretilmeli');
        } finally {
            $db->exec("DELETE FROM sys_mobile_devices WHERE fcm_token = 'valid_fcm_token_unit_test'");
            $db->exec("DELETE FROM sys_users WHERE id = {$userId}");
            $stmt->execute(['push_provider', 'none']);
            $stmt->execute(['push_enabled', '0']);
        }
    }

    public function testPushAcikkenJetonsuzCihazDialplanPushSatirlariUretmez(): void
    {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['push_provider', 'fcm']);
        $stmt->execute(['push_enabled', '1']);

        $ext = '3002';
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active, role) VALUES ('user_push_none', 'testhash', 'Push None User', ?, 'sip', 1, 'user')")->execute([$ext]);
        $userId = (int)$db->lastInsertId();

        // push_type='none' ve fcm_token=NULL (T-16 durumu)
        $db->prepare("INSERT INTO sys_mobile_devices (user_id, extension, fcm_token, push_type, device_id, platform, is_active, created_at, updated_at) VALUES (?, ?, NULL, 'none', 'test_dev_02', 'android', 1, NOW(), NOW())")->execute([$userId, $ext]);

        try {
            syncGeneralDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');

            $this->assertStringNotContainsString('push_dispatcher.php', $conf,
                'push acik olsa bile jetonsuz cihaz icin push_dispatcher cagrilmamali');
            $this->assertStringNotContainsString('push_loop', $conf,
                'push acik olsa bile jetonsuz cihaz icin push dongusu uretilmemeli');
        } finally {
            $db->exec("DELETE FROM sys_mobile_devices WHERE device_id = 'test_dev_02'");
            $db->exec("DELETE FROM sys_users WHERE id = {$userId}");
            $stmt->execute(['push_provider', 'none']);
            $stmt->execute(['push_enabled', '0']);
        }
    }

    public function testGidenRotaDialSecenegiRBarindirmaz(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // D-1: Dial(...) must not have the 'r' option (so early media is not suppressed)
        $this->assertMatchesRegularExpression('/Dial\(PJSIP\/[^,\)]+,\d+,Tb\(sub-callee-jb\^s\^1\)\)/', $conf,
            'giden cagri dialplaninda Dial PJSIP secenegi T olmali');
        $this->assertDoesNotMatchRegularExpression('/Dial\([^,\)]+,\d+,[^\)]*r[^\)]*\)/', $conf,
            'giden cagri dialplaninda yapay zil ureten ve erken medyayi boğan r secenegi bulunmamali (D-1)');
    }

    public function testGidenRotaDurumYonetimiVeHangupCauseIcerir(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // D-2: after Dial there must be a sub-outbound-status jump instead of a bare Hangup
        $this->assertStringContainsString('sub-outbound-status', $conf,
            'giden cagri dialplaninda sub-outbound-status baglami bulunmali (D-2)');
        $this->assertStringContainsString('exten => BUSY,1,NoOp(Outbound Status: BUSY', $conf,
            'sub-outbound-status BUSY durumu yonetilmeli');
        $this->assertStringContainsString('same => n,Hangup(17)', $conf,
            'BUSY durumunda ISDN cause 17 ile aninda sonlandirilmali');
        $this->assertDoesNotMatchRegularExpression('/BUSY,1[^\n]+\n\s*same => n,Busy\(/', $conf,
            'BUSY durumunda 10 saniyelik gecikmeye yol acan Busy() cagrisi bulunmamali');
        $this->assertStringContainsString('exten => CONGESTION,1,NoOp(Outbound Status: CONGESTION', $conf,
            'CONGESTION durumu yonetilmeli');
        $this->assertStringContainsString('exten => CHANUNAVAIL,1,NoOp(Outbound Status: CHANUNAVAIL', $conf,
            'CHANUNAVAIL durumu yonetilmeli');
        $this->assertStringContainsString('exten => NOANSWER,1,NoOp(Outbound Status: NOANSWER', $conf,
            'NOANSWER durumu yonetilmeli');
    }

    public function testTrunkInboundDialplanUretimiVeDidKirpma(): void
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

    public function testOutboundDialplanTransitCagrilardaCallerIdKorur(): void
    {
        $db = getDB();
        $db->prepare('UPDATE pbx_trunks SET outbound_caller_id = "08501234567" WHERE trunk_name = ?')
           ->execute([Fixtures::TRUNK_NAME]);

        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        // ExecIf condition that keeps the caller number on transit inbound calls and checks the internal CID_EXTERNAL priority
        $this->assertStringContainsString('ExecIf($["${CDR(inbound_trunk)}" = ""]?Set(CALLERID(num)=${IF($["${CID_EXTERNAL}" != ""]?${CID_EXTERNAL}:08501234567)}))', $conf,
            'Trunk-to-Trunk transit cagrilarinda gelen cep numarasi trunk varsayilan CID ile ezilmemeli');
    }
}


