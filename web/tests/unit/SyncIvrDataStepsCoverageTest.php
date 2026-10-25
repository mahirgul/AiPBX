<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class SyncIvrDataStepsCoverageTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $createdStepIds = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->createdStepIds = [];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdStepIds)) {
            $ids = implode(',', array_map('intval', $this->createdStepIds));
            $this->db->exec("DELETE FROM pbx_ivr_data_steps WHERE id IN ({$ids})");
        }
        $this->db->exec("DELETE FROM pbx_ivr_data_steps WHERE name LIKE 'zz_cov_%' OR id BETWEEN 7931 AND 7939");
        syncIvrDataSteps();
    }

    public function testDtmfInputSourceWithPromptFile(): void
    {
        $id = 7931;
        $this->createdStepIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_ivr_data_steps (id, name, prompt_file, input_source, max_digits, input_timeout_sec,
                                            channel_var_name, found_dest_type, found_dest_id, not_found_dest_type,
                                            not_found_dest_id, timeout_dest_type, timeout_dest_id, is_active)
             VALUES (?, 'zz_cov_step_7931', 'custom/acct_prompt.wav', 'dtmf', 12, 8,
                     'ACCT_NUM', 'hangup', '', 'hangup', '', 'hangup', '', 1)"
        );
        $stmt->execute([$id]);

        $this->assertTrue(syncIvrDataSteps());

        $file = ASTERISK_PBX_DIR . '/extensions_ivrdata.conf';
        $this->assertFileExists($file);
        $conf = (string)file_get_contents($file);

        $this->assertStringContainsString("[aipbx-ivr-data-{$id}]", $conf);
        $this->assertStringContainsString("exten => s,1,NoOp(Executing IVR Data Step ID {$id}: zz_cov_step_{$id})", $conf);
        $this->assertStringContainsString('same => n,Answer()', $conf);
        $this->assertStringContainsString('same => n,Read(IVR_INPUT,custom/acct_prompt,12,,,8)', $conf);
        $this->assertStringContainsString('same => n,Set(CURLOPT(httptimeout)=3)', $conf);
        $this->assertStringContainsString("same => n,Set(LOOKUP_RAW=\${CURL(http://127.0.0.1/api/ivr_data_lookup.php?step_id={$id}&input=\${URIENCODE(\${IVR_INPUT})}&caller_id=\${URIENCODE(\${CALLERID(num)})})})", $conf);
        $this->assertStringContainsString('same => n,Set(LOOKUP_STATUS=${CUT(LOOKUP_RAW,|,1)})', $conf);
        $this->assertStringContainsString('same => n,Set(LOOKUP_VAL=${CUT(LOOKUP_RAW,|,2)})', $conf);
        $this->assertStringContainsString('same => n,Set(ACCT_NUM=${LOOKUP_VAL})', $conf);
        $this->assertStringContainsString('same => n,GotoIf($["${LOOKUP_STATUS}" = "found"]?found)', $conf);
        $this->assertStringContainsString('same => n,GotoIf($["${LOOKUP_STATUS}" = "not_found"]?not_found)', $conf);
        $this->assertStringContainsString('same => n,Goto(timeout)', $conf);

        $this->assertStringContainsString("same => n(found),NoOp(IVR Data Step {$id} Found: \${LOOKUP_VAL})", $conf);
        $this->assertStringContainsString("same => n(not_found),NoOp(IVR Data Step {$id} Not Found)", $conf);
        $this->assertStringContainsString("same => n(timeout),NoOp(IVR Data Step {$id} Timeout or Error)", $conf);
    }

    public function testDtmfInputSourceWithoutPromptFile(): void
    {
        $id = 7932;
        $this->createdStepIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_ivr_data_steps (id, name, prompt_file, input_source, max_digits, input_timeout_sec, is_active)
             VALUES (?, 'zz_cov_step_7932', '', 'dtmf', 10, 5, 1)"
        );
        $stmt->execute([$id]);

        syncIvrDataSteps();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_ivrdata.conf');
        $this->assertStringContainsString("[aipbx-ivr-data-{$id}]", $conf);
        // Prompt is empty, so double comma: Read(IVR_INPUT,,10,,,5)
        $this->assertStringContainsString('same => n,Read(IVR_INPUT,,10,,,5)', $conf);
    }

    public function testCallerIdInputSource(): void
    {
        $id = 7933;
        $this->createdStepIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_ivr_data_steps (id, name, input_source, is_active)
             VALUES (?, 'zz_cov_step_7933', 'caller_id', 1)"
        );
        $stmt->execute([$id]);

        syncIvrDataSteps();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_ivrdata.conf');
        $section = substr($conf, (int)strpos($conf, "[aipbx-ivr-data-{$id}]"));
        $this->assertStringContainsString('same => n,Set(IVR_INPUT=${CALLERID(num)})', $section);
        $this->assertStringNotContainsString('Read(IVR_INPUT', $section);
    }

    public function testInactiveStepOmittedFromDialplan(): void
    {
        $id = 7934;
        $this->createdStepIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_ivr_data_steps (id, name, is_active)
             VALUES (?, 'zz_cov_step_7934', 0)"
        );
        $stmt->execute([$id]);

        syncIvrDataSteps();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_ivrdata.conf');
        $this->assertStringNotContainsString("[aipbx-ivr-data-{$id}]", $conf);
    }

    public function testMaxDigitsAndTimeoutBoundsClamping(): void
    {
        $id = 7935;
        $this->createdStepIds[] = $id;

        // max_digits above 30 -> clamped to 30; timeout below 1 -> clamped to 1
        $stmt = $this->db->prepare(
            "INSERT INTO pbx_ivr_data_steps (id, name, input_source, max_digits, input_timeout_sec, is_active)
             VALUES (?, 'zz_cov_step_7935', 'dtmf', 999, -5, 1)"
        );
        $stmt->execute([$id]);

        syncIvrDataSteps();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_ivrdata.conf');
        $this->assertStringContainsString('same => n,Read(IVR_INPUT,,30,,,1)', $conf);
    }

    public function testDialplanInjectionResistance(): void
    {
        $id = 7936;
        $this->createdStepIds[] = $id;

        $stmt = $this->db->prepare(
            "INSERT INTO pbx_ivr_data_steps (id, name, channel_var_name, prompt_file, input_source, is_active)
             VALUES (?, ?, ?, ?, 'dtmf', 1)"
        );
        $stmt->execute([
            $id,
            "Step\n same => n,System(whoami)\n; [evil_ivr]",
            "VAR);System(evil);",
            "../../etc/passwd\n same => n,System(evil)",
        ]);

        syncIvrDataSteps();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_ivrdata.conf');

        // Verify no arbitrary dialplan lines were injected
        $this->assertDoesNotMatchRegularExpression('/^\s*same => n,System/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\[evil_ivr\]/m', $conf);

        // Variable name stripped of punctuation and parentheses
        $this->assertStringContainsString('same => n,Set(VARSystemevil=${LOOKUP_VAL})', $conf);
    }
}
