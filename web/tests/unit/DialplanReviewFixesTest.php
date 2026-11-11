<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';

/** Dialplan bugs found in the review of 2026-10-25. */
final class DialplanReviewFixesTest extends TestCase
{
    public function testSameQueueTwiceInOneTimeConditionGetsUniqueLabels(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO pbx_time_groups (id, title, time_start, time_end, days_of_week, is_active)
                   VALUES (7931, 'zz_fix_tg', '09:00:00', '18:00:00', '1,2,3,4,5', 1)");
        $db->exec("INSERT INTO pbx_time_conditions (id, title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active)
                   VALUES (7931, 'zz_fix_tc', 7931, 'queue', 'zz_fix_q', 'queue', 'zz_fix_q', 1)");
        try {
            $this->assertTrue(syncTimeCondition(7931));
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_timecondition_7931.conf');
        } finally {
            $db->exec('DELETE FROM pbx_time_conditions WHERE id = 7931');
            $db->exec('DELETE FROM pbx_time_groups WHERE id = 7931');
            @unlink(ASTERISK_PBX_DIR . '/extensions_timecondition_7931.conf');
        }
        preg_match_all('/n\((kayit_var_zz_fix_q[^)]*)\)/', $conf, $m);
        $this->assertCount(2, $m[1], 'both branches record');
        $this->assertCount(2, array_unique($m[1]), 'a repeated label made the second branch jump to the first');
    }

    public function testFaxContextTakesE164AndDefaultTargets(): void
    {
        syncGeneralDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $this->assertStringContainsString('exten => _+X!,1,Set(FAX_RX_EXTEN=${EXTEN})', $conf);
        $this->assertStringContainsString('exten => default,1,Goto(from-trunk-fax,0,1)', $conf);
        // The fax e-mail lookup in the h extension: ${EXTEN} there is "h".
        $this->assertStringContainsString(':${FAX_RX_EXTEN})})})', $conf);
        $this->assertStringNotContainsString('${CDR(did)}:${EXTEN})', $conf);
    }

    public function testCallForwardCodeTakesElevenDigitNumbers(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM pbx_feature_codes WHERE feature_key = 'cf_set' OR code = '*9199'");
            $db->exec("INSERT INTO pbx_feature_codes (feature_key, title, code, is_active) VALUES ('cf_set', 'Test call forwarding', '*9199', 1)");
            syncFeatureCodes();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_featurecodes.conf');
            $this->assertStringContainsString('Read(CFTARGET,,20,', $conf);
            $this->assertStringNotContainsString('Read(CFTARGET,,10,', $conf);
        } finally {
            $db->rollBack();
            syncFeatureCodes();
        }
    }

    public function testInboundTrunkDialplanIncludesCnamHook(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active) VALUES ('testcnamtrunk', 'Test CNAM Trunk', '127.0.0.1', 1)");
            syncInboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf');
            $this->assertStringContainsString('GosubIf($[${DIALPLAN_EXISTS(sub-cnam-lookup,s,1)}]?sub-cnam-lookup,s,1)', $conf);
        } finally {
            $db->rollBack();
            syncInboundDialplan();
        }
    }

    public function testInboundTrunkDidlessToHeaderJumpIsConditional(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active) VALUES ('testtojump', 'Test To Jump Trunk', '127.0.0.1', 1)");
            syncInboundDialplan();
            $s = $this->didlessBlock('testtojump');
            // Only to a number with an inbound route; each header is skipped when absent.
            foreach (['To', 'P-Called-Party-ID', 'Diversion'] as $header) {
                $this->assertStringContainsString("Set(AIPBX_DIALED=\${PJSIP_HEADER(read,{$header})})", $s);
            }
            $this->assertStringContainsString('& ${DIALPLAN_EXISTS(from-trunk-testtojump,${AIPBX_DIALED},1)} & $[${DIALPLAN_EXISTS(from-trunk-inbound,${AIPBX_DIALED},1)}]]?from-trunk-testtojump,${AIPBX_DIALED},1)', $s);
            $this->assertStringNotContainsString('from-internal-pbx-ortak', $s, 'without transit an internal number is no target');
            // No fixed number: nothing else leaves the s path.
            $this->assertSame(3, substr_count($s, '?from-trunk-testtojump,'));
            $this->assertStringContainsString('Goto(from-trunk-testtojump-route,s,1)', $s);
        } finally {
            $db->rollBack();
            syncInboundDialplan();
        }
    }

    public function testDidlessCallUsesTrimTransitAndTheTrunkNumber(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active, did_trim_digits, allow_outbound_routing, no_did_number)
                       VALUES ('testnodid', 'No DID Trunk', '127.0.0.1', 1, 4, 1, '+90312555 0100')");
            syncInboundDialplan();
            $s = $this->didlessBlock('testnodid');
            $this->assertStringContainsString('${DIALPLAN_EXISTS(from-trunk-inbound,${AIPBX_DIALED:-4},1)}', $s);
            $this->assertStringContainsString('${DIALPLAN_EXISTS(from-internal-pbx-ortak,${AIPBX_DIALED},1)}', $s);
            // The trunk's number after the headers, before the s route; stored junk is dropped.
            $this->assertStringContainsString('Goto(from-trunk-testnodid,+903125550100,1)', $s);
            $this->assertLessThan(strpos($s, 'Goto(from-trunk-testnodid,+903125550100,1)'), strrpos($s, 'PJSIP_HEADER(read,Diversion)'));
            $this->assertGreaterThan(strpos($s, 'Goto(from-trunk-testnodid,+903125550100,1)'), strpos($s, 'Goto(from-trunk-testnodid-route,s,1)'));
        } finally {
            $db->rollBack();
            syncInboundDialplan();
        }
    }

    /** The trunk's `exten => s` lines, up to its route context. */
    private function didlessBlock(string $trunk): string
    {
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_inbound.conf');
        $start = strpos($conf, "Inbound call without DID [{$trunk}]");
        $this->assertNotFalse($start);
        $end = strpos($conf, "[from-trunk-{$trunk}-route]", $start);
        return substr($conf, $start, $end - $start);
    }
}
