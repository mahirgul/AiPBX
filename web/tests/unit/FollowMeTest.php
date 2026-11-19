<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/ExtensionService.php';

final class FollowMeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    private function user(string $ext, array $extra): string
    {
        $row = array_merge([
            'username' => "fm_{$ext}", 'password_hash' => '', 'full_name' => "FM {$ext}", 'extension' => $ext,
            'sip_password' => "Pass{$ext}!", 'sip_auth_digest' => 1, 'extension_type' => 'sip', 'is_active' => 1,
            'role' => 'user', 'outbound_group' => 1, 'cf_noanswer_timeout' => 15,
        ], $extra);
        $this->db->prepare('INSERT INTO sys_users (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
            ->execute(array_values($row));
        return implode("\n", buildExtensionDialLines($ext, $this->db)['lines']);
    }

    public function testSequentialRingsTheListAfterTheExtensionWithConfirmation(): void
    {
        $d = $this->user('7951', ['followme_numbers' => '05321234567,7952', 'followme_ring_time' => 25, 'outbound_group' => 3]);
        $this->assertStringContainsString(',15,tTb(', $d, 'the extension rings for its no-answer time first');
        $this->assertSame(2, substr_count($d, 'Gosub(fm_seq_'), 'from no answer and from unreachable');
        $this->assertStringContainsString('Set(__FM_CTX=from-internal-desktop-3)', $d);
        $this->assertStringContainsString('Dial(Local/05321234567@followme-confirm/n,25,t)', $d);
        $this->assertStringContainsString('Dial(Local/7952@followme-confirm/n,25,t)', $d);
        $this->assertStringContainsString('GotoIf($["${FM_ACTIVE}" = "1"]?fm_ret_', $d, 'a Follow Me leg does not start another one');
    }

    public function testParallelAddsTheListToTheExtensionDial(): void
    {
        $d = $this->user('7953', ['followme_numbers' => '+491701234567', 'followme_mode' => 'parallel', 'followme_confirm' => 0]);
        $this->assertStringContainsString('Local/+491701234567@from-internal-desktop/n)', $d);
        $this->assertStringNotContainsString('followme-confirm', $d);
        $this->assertStringNotContainsString('Gosub(fm_seq_', $d);
        // The list is added before the "no device" check: an offline extension still rings the mobile.
        $this->assertLessThan(strpos($d, 'GotoIf($["${DIAL_CONTACTS}" = ""]'), strpos($d, '@from-internal-desktop/n)'));
    }

    public function testNoListNoFollowMe(): void
    {
        $d = $this->user('7954', []);
        $this->assertStringNotContainsString('FM_', $d);
    }

    public function testFormValues(): void
    {
        $this->assertSame([], ExtensionService::followMeColumns(['full_name' => 'x']), 'the API leaves Follow Me as it is');
        $c = ExtensionService::followMeColumns(['followme_numbers' => '0532 123 45 67; 1005, 1005', 'followme_mode' => 'parallel', 'followme_ring_time' => 500]);
        $this->assertSame('05321234567,1005', $c['followme_numbers']);
        $this->assertSame('parallel', $c['followme_mode']);
        $this->assertSame(120, $c['followme_ring_time']);
        $this->assertSame(1, $c['followme_confirm']);
        $this->assertNull(ExtensionService::followMeColumns(['followme_numbers' => ''])['followme_numbers']);

        $this->expectException(Exception::class);
        ExtensionService::followMeColumns(['followme_numbers' => '1,2,3,4,5,6']);
    }

    public function testConfirmContextIsGenerated(): void
    {
        syncGeneralDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $this->assertStringContainsString('[followme-confirm]', $conf);
        $this->assertStringContainsString('Read(FM_OK,followme/options,1,,2,5)', $conf);
        $this->assertStringContainsString('Set(GOSUB_RESULT=CONTINUE)', $conf);
    }
}
