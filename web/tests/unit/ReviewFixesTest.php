<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class ReviewFixesTest extends TestCase
{
    /** A language's apostrophe in confirm('...') breaks the handler, and the click goes through unasked. */
    public function testNoTemplatePutsATextInAQuotedConfirm(): void
    {
        $bad = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../templates'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match("/confirm\\('<\\?(php echo|=)/", (string) file_get_contents($file->getPathname())) === 1) {
                $bad[] = $file->getFilename();
            }
        }
        $this->assertSame([], $bad);
    }

    public function testAHelperFailureLeavesTheLocalNetworksAlone(): void
    {
        $db = getDB();
        $before = getSystemSetting(VpnService::LOCAL_NETS_KEY, '[]');
        $nets = (string) NetworkSettingsService::settings()['pjsip_local_net'];
        try {
            setSystemSetting(VpnService::LOCAL_NETS_KEY, '["10.99.0.0/24"]');
            VpnService::syncLocalNetsFrom(['unavailable' => true, 'server' => ['type' => 'off'], 'tunnels' => []]);
            $this->assertSame('["10.99.0.0/24"]', getSystemSetting(VpnService::LOCAL_NETS_KEY, ''));
            $this->assertSame($nets, (string) NetworkSettingsService::settings()['pjsip_local_net']);
        } finally {
            setSystemSetting(VpnService::LOCAL_NETS_KEY, (string) $before);
            $db->exec("DELETE FROM sys_pending_sync WHERE entity_label LIKE '%VPN%'");
        }
    }

    public function testFaxUnitNumberRules(): void
    {
        $db = getDB();
        $this->assertNull(FaxSettingsService::didExtensionError(''));
        $this->assertNotNull(FaxSettingsService::didExtensionError('_9X.'));
        $this->assertNotNull(FaxSettingsService::didExtensionError('19350*'));
        $this->assertNull(FaxSettingsService::didExtensionError('+4312345678901'), 'a long DID');

        $number = '97' . random_int(100, 999);
        $db->prepare("INSERT INTO pbx_ring_groups (name, group_number, numbers_list, internal_number) VALUES (?, ?, '', ?)")->execute(['zz fax test', $number, $number]);
        try {
            $this->assertStringContainsString($number, (string) FaxSettingsService::didExtensionError($number), 'taken by a ring group');
        } finally {
            $db->prepare('DELETE FROM pbx_ring_groups WHERE internal_number = ?')->execute([$number]);
        }
        $this->assertNull(FaxSettingsService::didExtensionError($number), 'free again');
    }

    public function testAFaxUserMayShareTheNumberOfItsUnit(): void
    {
        $db = getDB();
        $number = '96' . random_int(100, 999);
        $name = 'zzfax' . $number;
        $cols = ['username' => $name, 'extension' => $number, 'extension_type' => 'fax', 'full_name' => 'zz fax'];
        foreach ($db->query('SHOW COLUMNS FROM sys_users')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (!isset($cols[$c['Field']]) && $c['Extra'] !== 'auto_increment' && $c['Null'] !== 'YES' && $c['Default'] === null) {
                $cols[$c['Field']] = str_contains($c['Type'], 'int') ? 0 : 'x';
            }
        }
        $db->prepare('INSERT INTO sys_users (`' . implode('`,`', array_keys($cols)) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')')->execute(array_values($cols));
        try {
            $this->assertNull(FaxSettingsService::didExtensionError($number));
            $db->prepare("UPDATE sys_users SET extension_type = 'sip' WHERE username = ?")->execute([$name]);
            $this->assertNotNull(FaxSettingsService::didExtensionError($number), 'a phone extension is taken');
        } finally {
            $db->prepare('DELETE FROM sys_users WHERE username = ?')->execute([$name]);
        }
    }

    public function testAnAbandonedUpdateDoesNotBlockTheNextOne(): void
    {
        require_once __DIR__ . '/../../src/services/SystemUpdateService.php';
        $now = 1800000000;
        $at = fn(int $ago) => date('c', $now - $ago);
        $f = fn(array $s, bool $unit = false) => SystemUpdateService::isAbandoned($s, $now, $unit);

        $this->assertFalse($f(['state' => 'done', 'updated_at' => $at(99999)]));
        $this->assertFalse($f(['state' => 'running', 'updated_at' => $at(99999)], true), 'its unit is alive');
        $this->assertFalse($f(['state' => 'running', 'updated_at' => $at(1800)]), 'a quiet step');
        $this->assertTrue($f(['state' => 'running', 'updated_at' => $at(3700)]));
        $this->assertFalse($f(['state' => 'running', 'waiting_calls' => 2, 'updated_at' => $at(30)]), 'still waiting');
        $this->assertTrue($f(['state' => 'running', 'waiting_calls' => 2, 'updated_at' => $at(300)]), 'wait stopped writing');
    }
}
