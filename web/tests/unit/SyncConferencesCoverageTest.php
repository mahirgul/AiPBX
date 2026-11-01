<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class SyncConferencesCoverageTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $createdConfIds = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->createdConfIds = [];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdConfIds)) {
            $ids = implode(',', array_map('intval', $this->createdConfIds));
            $this->db->exec("DELETE FROM pbx_conferences WHERE id IN ({$ids})");
        }
        $this->db->exec("DELETE FROM pbx_conferences WHERE title LIKE 'zz_cov_%' OR room_number BETWEEN '7921' AND '7929'");
        syncConferences();
    }

    public function testConferenceBasicOptionsAndConfBridgeSettings(): void
    {
        $id = 7921;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, wait_marked, end_marked, announce_join_leave,
                                         announce_user_count, record_conference, mute_on_join, music_on_hold, is_active)
             VALUES (?, '7921', 'zz_cov_conf_7921', 1, 1, 1, 0, 1, 1, 'custom_moh', 1)"
        );
        $stmt->execute([$id]);

        $this->assertTrue(syncConferences());

        $file = ASTERISK_PBX_DIR . '/extensions_conferences.conf';
        $this->assertFileExists($file);
        $conf = (string)file_get_contents($file);

        $this->assertStringContainsString("[app-confbridge-{$id}]", $conf);
        $this->assertStringContainsString("exten => s,1,NoOp(Conference Room {$id}: zz_cov_conf_{$id} - Room Number: 7921)", $conf);
        $this->assertStringContainsString('same => n,Set(CDR(direction)=internal)', $conf);
        $this->assertStringContainsString('same => n,Answer()', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(bridge,record_conference)=yes)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,music_on_hold_class)=custom_moh)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,wait_marked)=yes)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,end_marked)=yes)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,announce_join_leave)=yes)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,announce_user_count)=no)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,startmuted)=yes)', $conf);
        $this->assertStringContainsString("same => n(cf_enter_{$id}),ConfBridge(7921)", $conf);
    }

    public function testConferenceBothAdminAndUserPins(): void
    {
        $id = 7922;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, admin_pin, user_pin, is_active)
             VALUES (?, '7922', 'zz_cov_conf_7922', '4321', '1234', 1)"
        );
        $stmt->execute([$id]);

        syncConferences();

        $file = ASTERISK_PBX_DIR . '/extensions_conferences.conf';
        $this->assertFileExists($file);
        $conf = (string)file_get_contents($file);

        $this->assertStringContainsString('same => n,Read(CONF_PIN,conf-getpin,10,,3,10)', $conf);
        $this->assertStringContainsString("same => n,GotoIf(\$[\"\${CONF_PIN}\" = \"4321\"]?cf_admin_{$id})", $conf);
        $this->assertStringContainsString("same => n,GotoIf(\$[\"\${CONF_PIN}\" = \"1234\"]?cf_user_{$id})", $conf);
        $this->assertStringContainsString('same => n,Playback(conf-invalidpin)', $conf);
        $this->assertStringContainsString('same => n,Hangup()', $conf);

        $this->assertStringContainsString("same => n(cf_admin_{$id}),Set(CONFBRIDGE(user,admin)=yes)", $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(user,marked)=yes)', $conf);
        $this->assertStringContainsString("same => n,Goto(cf_enter_{$id})", $conf);

        $this->assertStringContainsString("same => n(cf_user_{$id}),Set(CONFBRIDGE(user,admin)=no)", $conf);
        $this->assertStringContainsString("same => n,Goto(cf_enter_{$id})", $conf);
        $this->assertStringContainsString("same => n(cf_enter_{$id}),ConfBridge(7922)", $conf);
    }

    public function testConferenceOnlyAdminPin(): void
    {
        $id = 7923;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, admin_pin, user_pin, is_active)
             VALUES (?, '7923', 'zz_cov_conf_7923', '9999', '', 1)"
        );
        $stmt->execute([$id]);

        syncConferences();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_conferences.conf');

        $this->assertStringContainsString("same => n,GotoIf(\$[\"\${CONF_PIN}\" = \"9999\"]?cf_admin_{$id})", $conf);
        $this->assertStringContainsString("same => n(cf_admin_{$id}),Set(CONFBRIDGE(user,admin)=yes)", $conf);
        $this->assertStringNotContainsString("cf_user_{$id}", $conf);
    }

    public function testConferenceOnlyUserPin(): void
    {
        $id = 7924;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, admin_pin, user_pin, is_active)
             VALUES (?, '7924', 'zz_cov_conf_7924', '', '8888', 1)"
        );
        $stmt->execute([$id]);

        syncConferences();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_conferences.conf');

        $this->assertStringContainsString("same => n,GotoIf(\$[\"\${CONF_PIN}\" = \"8888\"]?cf_user_{$id})", $conf);
        $this->assertStringContainsString("same => n(cf_user_{$id}),Set(CONFBRIDGE(user,admin)=no)", $conf);
        $this->assertStringNotContainsString("cf_admin_{$id}", $conf);
    }

    public function testConferenceNoPinDirectEntry(): void
    {
        $id = 7925;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, admin_pin, user_pin, is_active)
             VALUES (?, '7925', 'zz_cov_conf_7925', '', '', 1)"
        );
        $stmt->execute([$id]);

        syncConferences();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_conferences.conf');

        // Without PIN, Read(CONF_PIN) should NOT be generated for room 7925
        $section = substr($conf, (int)strpos($conf, "[app-confbridge-{$id}]"));
        $this->assertStringNotContainsString('Read(CONF_PIN', $section);
        $this->assertStringContainsString("same => n(cf_enter_{$id}),ConfBridge(7925)", $section);
    }

    public function testConferenceVideoModeSfuAndTalker(): void
    {
        $id1 = 7927;
        $id2 = 7928;
        $this->createdConfIds[] = $id1;
        $this->createdConfIds[] = $id2;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, video_mode, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );
        $stmt->execute([$id1, '7927', 'zz_cov_conf_7927', 'sfu']);
        $stmt->execute([$id2, '7928', 'zz_cov_conf_7928', 'follow_talker']);

        syncConferences();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_conferences.conf');

        // SFU room check
        $this->assertStringContainsString("[app-confbridge-{$id1}]", $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(bridge,video_mode)=sfu)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(bridge,video_update_discard)=0)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(bridge,remb_send_interval)=1000)', $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(bridge,remb_behavior)=average)', $conf);

        // Follow-talker room check
        $this->assertStringContainsString("[app-confbridge-{$id2}]", $conf);
        $this->assertStringContainsString('same => n,Set(CONFBRIDGE(bridge,video_mode)=follow_talker)', $conf);
    }

    public function testInactiveConferenceOmittedFromDialplan(): void
    {
        $id = 7926;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, is_active)
             VALUES (?, '7926', 'zz_cov_conf_7926', 0)"
        );
        $stmt->execute([$id]);

        syncConferences();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_conferences.conf');
        $this->assertStringNotContainsString("[app-confbridge-{$id}]", $conf);
    }

    public function testDialplanInjectionResistance(): void
    {
        $id = 7927;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, user_pin, admin_pin, music_on_hold, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $id,
            "7927\n; x",
            "Inject\n same => n,System(touch /tmp/pwned)\n; [evil_conf]",
            "1234\n;x",
            "5678\n;x",
            "moh; System(evil) / *",
        ]);

        syncConferences();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_conferences.conf');

        // Injection prevention assertions
        $this->assertDoesNotMatchRegularExpression('/^\s*same => n,System/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\[evil_conf\]/m', $conf);

        // Room number and PINs strictly sanitized to digits
        $this->assertStringContainsString("ConfBridge(7927)", $conf);
        $this->assertStringContainsString('GotoIf($["${CONF_PIN}" = "5678"]?cf_admin_7927)', $conf);
        $this->assertStringContainsString('GotoIf($["${CONF_PIN}" = "1234"]?cf_user_7927)', $conf);

        // Music on hold stripped of malicious characters
        $this->assertStringContainsString('Set(CONFBRIDGE(user,music_on_hold_class)=mohSystemevil)', $conf);
    }

    public function testAppConfbridgeDispatchContextAndDynamicFallback(): void
    {
        $id = 7928;
        $this->createdConfIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_conferences (id, room_number, title, is_active)
             VALUES (?, '7928', 'zz_cov_dispatch_7928', 1)"
        );
        $stmt->execute([$id]);

        $this->assertTrue(syncConferences());

        $file = ASTERISK_PBX_DIR . '/extensions_conferences.conf';
        $this->assertFileExists($file);
        $conf = (string)file_get_contents($file);

        // Check dispatch context and routing
        $this->assertStringContainsString('[app-confbridge-dispatch]', $conf);
        $this->assertStringContainsString("exten => 7928,1,Goto(app-confbridge-{$id},s,1)", $conf);
        $this->assertStringContainsString('exten => _.,1,NoOp(Dynamic/Ad-hoc Conference Room ${EXTEN}', $conf);
        $this->assertStringContainsString('same => n,ConfBridge(${EXTEN})', $conf);
    }

    public function testConferencePrefixHelperAndFeatureCodeDialplan(): void
    {
        require_once dirname(__DIR__, 2) . '/src/sync/SyncFeatureCodes.php';
        require_once dirname(__DIR__, 2) . '/src/helpers.php';

        // Check helper function
        $prefix = getConferencePrefix();
        $this->assertContains($prefix, ['#9', '*9']);

        // Check feature codes dialplan generation
        syncFeatureCodes();
        $file = ASTERISK_PBX_DIR . '/extensions_featurecodes.conf';
        $this->assertFileExists($file);
        $conf = (string)file_get_contents($file);

        // Should include interactive room prompt and direct room dialplan for conference_join
        $this->assertStringContainsString('conference_join', $conf);
        $this->assertStringContainsString('Goto(app-confbridge-dispatch,${CONF_TARGET},1)', $conf);
        $this->assertStringContainsString('Read(CONF_TARGET,conf-getchannel', $conf);
    }
}
