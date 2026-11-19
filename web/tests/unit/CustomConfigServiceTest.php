<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/CustomConfigService.php';

final class CustomConfigServiceTest extends TestCase
{
    private string $backupPjsipCustom = '';
    private bool $hadPjsipCustom = false;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['csrf_token'] = 'custom-cfg-csrf';
        $_SESSION['user_id'] = 1;

        $path = ASTERISK_PBX_DIR . '/pjsip_zz_custom.conf';
        if (is_file($path)) {
            $this->hadPjsipCustom = true;
            $this->backupPjsipCustom = (string) file_get_contents($path);
        }
    }

    protected function tearDown(): void
    {
        $path = ASTERISK_PBX_DIR . '/pjsip_zz_custom.conf';
        if ($this->hadPjsipCustom) {
            file_put_contents($path, $this->backupPjsipCustom);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    public function testEnsureFilesAndRejectsDirectives(): void
    {
        CustomConfigService::ensureFilesExist();
        foreach (CustomConfigService::FILES as $meta) {
            $this->assertFileExists(ASTERISK_PBX_DIR . '/' . $meta['filename']);
        }

        $bad1 = CustomConfigService::save('pjsip', "#include \"/etc/shadow\"\n", 'custom-cfg-csrf');
        $this->assertFalse($bad1['success']);

        $bad2 = CustomConfigService::save('pjsip', "#exec /bin/sh\n", 'custom-cfg-csrf');
        $this->assertFalse($bad2['success']);

        // The administrator's choice: AMI users and the like are allowed, the portal's own AMI login is not.
        $this->assertNull(CustomConfigService::validateContent("[crm]\nsecret=x\nread=all\nwrite=all\n", 'manager'));
        $this->assertNull(CustomConfigService::validateContent("[general]\nmailcmd=/usr/sbin/sendmail -t\n", 'voicemail'));
        if (defined('AMI_USER') && AMI_USER !== '') {
            $this->assertNotNull(CustomConfigService::validateContent('[' . AMI_USER . "]\nsecret=x\n", 'manager'));
        }

        $badKey = CustomConfigService::save('nonexistent', "; ok\n", 'custom-cfg-csrf');
        $this->assertFalse($badKey['success']);
    }

    public function testSaveAndReadCustomConfig(): void
    {
        $body = "; Custom transport override\n[custom-test](!)\ntype=endpoint\n";
        $res = CustomConfigService::save('pjsip', $body, 'custom-cfg-csrf');
        $this->assertTrue($res['success'], $res['error'] ?? '');

        $readBack = CustomConfigService::read('pjsip');
        $this->assertSame($body, $readBack);
        $this->assertTrue(CustomConfigService::hasNonCommentLines($readBack));
        $this->assertFalse(CustomConfigService::hasNonCommentLines("; only comment\n\n"));

        $extRes = CustomConfigService::save('extensions', "[from-custom-test]\nexten => s,1,Hangup()\n", 'custom-cfg-csrf');
        $this->assertTrue($extRes['success'], $extRes['error'] ?? '');
        $this->assertStringContainsString('[from-custom-test]', CustomConfigService::read('extensions'));
    }
}
