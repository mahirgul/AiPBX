<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/sync/DialplanBuilders.php';
require_once dirname(__DIR__, 2) . '/src/services/TrunkService.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';

final class TrunkCarrierNormalizationTest extends TestCase
{
    public function testBuildTrunkCarrierDialLineWithNoRules(): void
    {
        $line = buildTrunkCarrierDialLine(null);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${AIPBX_DIAL_TARGET})', $line);

        $lineEmpty = buildTrunkCarrierDialLine(['strip' => '', 'add' => '']);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${AIPBX_DIAL_TARGET})', $lineEmpty);
    }

    public function testBuildTrunkCarrierDialLineWithStripAndAdd(): void
    {
        // Strip '+90' (length 3) and prepend '0'
        $line = buildTrunkCarrierDialLine(['strip' => '+90', 'add' => '0']);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${AIPBX_DIAL_TARGET})', $line);
        $this->assertStringContainsString('ExecIf($["${AIPBX_TRUNK_DIAL:0:3}" = "+90"]?Set(AIPBX_TRUNK_DIAL=0${AIPBX_TRUNK_DIAL:3}))', $line);
    }

    public function testBuildTrunkCarrierDialLineWithAddOnly(): void
    {
        // Prepend '00' without stripping
        $line = buildTrunkCarrierDialLine(['strip' => '', 'add' => '00']);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=00${AIPBX_TRUNK_DIAL})', $line);
    }

    public function testBuildTrunkCarrierDialLineWithStripOnly(): void
    {
        // Strip '0' (length 1) without prepending anything
        $line = buildTrunkCarrierDialLine(['strip' => '0', 'add' => '']);
        $this->assertStringContainsString('ExecIf($["${AIPBX_TRUNK_DIAL:0:1}" = "0"]?Set(AIPBX_TRUNK_DIAL=${AIPBX_TRUNK_DIAL:1}))', $line);
    }

    public function testSaveTrunkPersistsCarrierNormAnd100rel(): void
    {
        $csrf = 'carrier-norm-test-csrf';
        $_SESSION['csrf_token'] = $csrf;

        $trunkName = 'test_carrier_trk';
        $db = getDB();
        $db->exec("DELETE FROM pbx_trunks WHERE trunk_name = '{$trunkName}'");

        $res = TrunkService::saveTrunk([
            'csrf_token' => $csrf,
            'trunk_id' => 0,
            'trunk_name' => $trunkName,
            'title' => 'Test Carrier Trunk',
            'ip_address' => '192.168.100.50',
            'port' => 5060,
            'transport' => 'udp',
            'carrier_strip_prefix' => '+90',
            'carrier_add_prefix' => '0',
            'prack_100rel' => 'required',
            'dtmf_mode' => 'rfc4733',
            'timers' => 'yes',
            'is_active' => 1,
        ]);

        $this->assertTrue($res['success'], 'Save trunk failed: ' . ($res['message'] ?? ''));
        $row = $db->query("SELECT * FROM pbx_trunks WHERE trunk_name = '{$trunkName}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row);
        $this->assertSame('+90', $row['carrier_strip_prefix']);
        $this->assertSame('0', $row['carrier_add_prefix']);
        $this->assertSame('required', $row['prack_100rel']);

        $db->exec("DELETE FROM pbx_trunks WHERE trunk_name = '{$trunkName}'");
    }
}
