<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/RegionSettingsService.php';

class RegionSettingsTest extends TestCase
{
    public function testZonesAndTonesAreOnlyFromTheLists(): void
    {
        $this->assertTrue(RegionSettingsService::validTimezone('America/Chicago'));
        $this->assertTrue(RegionSettingsService::validTimezone('UTC'));
        foreach (['', 'Mars/Base', '../../etc/passwd', 'Europe/Vienna; reboot'] as $bad) {
            $this->assertFalse(RegionSettingsService::validTimezone($bad), $bad);
        }
        $this->assertTrue(RegionSettingsService::validTone('tr'));
        $this->assertTrue(RegionSettingsService::validTone('us'));
        foreach (['', 'xx', 'TR', 'us; x'] as $bad) {
            $this->assertFalse(RegionSettingsService::validTone($bad), $bad);
        }
    }

    public function testASaveWithoutAValidTokenChangesNothing(): void
    {
        $r = RegionSettingsService::save(['csrf_token' => 'wrong', 'system_timezone' => 'UTC', 'tone_country' => 'us']);
        $this->assertFalse($r['success']);
    }

    public function testTheTonesOfTurkeyAreInTheRepository(): void
    {
        $conf = (string) file_get_contents(__DIR__ . '/../../../conf/asterisk/indications.conf');
        $this->assertStringContainsString('[tr]', $conf);
        $this->assertStringContainsString('busy = 450/500,0/500', $conf);
    }
}
