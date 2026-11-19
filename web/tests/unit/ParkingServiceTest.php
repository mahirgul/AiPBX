<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/ParkingService.php';

final class ParkingServiceTest extends TestCase
{
    private PDO $db;
    private array $origConfig;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->origConfig = ParkingService::getConfig();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['csrf_token'] = 'parking-csrf';
        $_SESSION['user_id'] = 1;
    }

    protected function tearDown(): void
    {
        $this->db->prepare("
            UPDATE pbx_parking SET
                is_active = ?, parkext = ?, slot_start = ?, slot_end = ?,
                parking_time = ?, findslot = ?, comeback_to_origin = ?,
                comeback_dial_time = ?, timeout_dest_type = ?, timeout_dest_id = ?,
                moh_class = ?, parked_play = ?
            WHERE id = 1
        ")->execute([
            (int) $this->origConfig['is_active'],
            (string) $this->origConfig['parkext'],
            (int) $this->origConfig['slot_start'],
            (int) $this->origConfig['slot_end'],
            (int) $this->origConfig['parking_time'],
            (string) $this->origConfig['findslot'],
            (int) $this->origConfig['comeback_to_origin'],
            (int) $this->origConfig['comeback_dial_time'],
            $this->origConfig['timeout_dest_type'],
            $this->origConfig['timeout_dest_id'],
            (string) $this->origConfig['moh_class'],
            (string) $this->origConfig['parked_play'],
        ]);
        $this->db->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'parking'");
    }

    public function testSaveValidationAndConflictChecks(): void
    {
        // Parkext inside slot range
        $r1 = ParkingService::save([
            'csrf_token' => 'parking-csrf',
            'is_active' => '1',
            'parkext' => '705',
            'slot_start' => 701,
            'slot_end' => 710,
            'parking_time' => 45,
            'comeback_dial_time' => 30,
        ]);
        $this->assertFalse($r1['success']);

        // Too many slots (>50)
        $r2 = ParkingService::save([
            'csrf_token' => 'parking-csrf',
            'is_active' => '1',
            'parkext' => '700',
            'slot_start' => 701,
            'slot_end' => 760,
            'parking_time' => 45,
            'comeback_dial_time' => 30,
        ]);
        $this->assertFalse($r2['success']);

        // Conflict with existing extension 1000
        $r3 = ParkingService::save([
            'csrf_token' => 'parking-csrf',
            'is_active' => '1',
            'parkext' => '1000',
            'slot_start' => 701,
            'slot_end' => 710,
            'parking_time' => 45,
            'comeback_dial_time' => 30,
        ]);
        $this->assertFalse($r3['success']);
    }

    public function testSaveAndBuildResParkingAndDialplanConf(): void
    {
        $res = ParkingService::save([
            'csrf_token' => 'parking-csrf',
            'is_active' => '1',
            'parkext' => '700',
            'slot_start' => 701,
            'slot_end' => 710,
            'parking_time' => 60,
            'findslot' => 'first',
            'comeback_to_origin' => '0',
            'comeback_dial_time' => 25,
            'timeout_dest_type' => 'hangup',
            'timeout_dest_id' => 'normal',
            'moh_class' => 'default',
            'parked_play' => 'both',
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');

        $cfg = ParkingService::getConfig();
        $this->assertSame(1, (int) $cfg['is_active']);
        $this->assertSame('700', (string) $cfg['parkext']);
        $this->assertSame('Call parking (700 / 701–710)', str_replace('700 / 701–710', '700 / 701–710', (string) internalNumberOwner('705')));
        $this->assertNotNull(internalNumberOwner('700'));
        $this->assertNull(internalNumberOwner('700', 'parking'));

        $resConf = buildResParkingConf($cfg);
        $this->assertStringContainsString("parkext => 700", $resConf);
        $this->assertStringContainsString("parkpos => 701-710", $resConf);
        $this->assertStringContainsString("parkingtime => 60", $resConf);
        $this->assertStringContainsString("findslot => first", $resConf);
        $this->assertStringContainsString("comebacktoorigin = no", $resConf);
        $this->assertStringContainsString("parkedplay = both", $resConf);

        $extConf = buildParkingDialplanConf($cfg);
        $this->assertStringContainsString('[pbx-parking-timeout]', $extConf);
        $this->assertStringContainsString('exten => _X.,1,Goto(pbx-parking-timeout,s,1)', $extConf);

        $inactiveRes = buildResParkingConf(['is_active' => 0]);
        $this->assertStringNotContainsString('[default]', $inactiveRes);
    }

    public function testParkedCallsAreReadFromAsterisk22Output(): void
    {
        $out = "Parking Lot: default\n--------\nParking Extension   :  700\n\nParked Calls\n------------\n"
            . "  Space               :  701\n  Channel             :  PJSIP/1001-0000001a\n  Parker Dial String  :  PJSIP/1002\n\n"
            . "  Space               :  702\n  Channel             :  PJSIP/trunk-0000001b\n  Parker Dial String  :  PJSIP/1003\n";
        $this->assertSame([
            ['space' => '701', 'channel' => 'PJSIP/1001-0000001a', 'from' => 'PJSIP/1002'],
            ['space' => '702', 'channel' => 'PJSIP/trunk-0000001b', 'from' => 'PJSIP/1003'],
        ], ParkingService::parseParkedCalls($out));
        $this->assertSame([], ParkingService::parseParkedCalls("You don't have permissions to run 'parking show default' command"));
    }
}
