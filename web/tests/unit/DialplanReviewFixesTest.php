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
        syncFeatureCodes();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_featurecodes.conf');
        if (!str_contains($conf, 'CFTARGET')) {
            $this->markTestSkipped('call forward code not active in this database');
        }
        $this->assertStringContainsString('Read(CFTARGET,,20,', $conf);
        $this->assertStringNotContainsString('Read(CFTARGET,,10,', $conf);
    }
}
