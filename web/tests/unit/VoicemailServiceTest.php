<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/VoicemailService.php';

/** Voicemail of the mobile app: marking a message heard moves it like Asterisk does. */
final class VoicemailServiceTest extends TestCase
{
    private string $spool;

    protected function setUp(): void
    {
        $this->spool = sys_get_temp_dir() . '/vmtest-' . bin2hex(random_bytes(4));
        mkdir("{$this->spool}/1001/INBOX", 0775, true);
        mkdir("{$this->spool}/1001/Old", 0775, true);
        foreach (['txt', 'wav', 'WAV'] as $s) {
            file_put_contents("{$this->spool}/1001/INBOX/msg0000.{$s}", $s);
            file_put_contents("{$this->spool}/1001/Old/msg0000.{$s}", 'old');
            file_put_contents("{$this->spool}/1001/Old/msg0001.{$s}", 'old');
        }
    }

    protected function tearDown(): void
    {
        exec('rm -r ' . escapeshellarg($this->spool));
    }

    public function testHeardMovesToTheNextFreeOldNumber(): void
    {
        $this->assertSame('Old:msg0002', VoicemailService::markHeard('1001', 'INBOX:msg0000', $this->spool));
        $this->assertSame([], glob("{$this->spool}/1001/INBOX/*"));
        $this->assertSame('wav', file_get_contents("{$this->spool}/1001/Old/msg0002.wav"));
        $this->assertFileExists("{$this->spool}/1001/Old/msg0002.txt");
        $this->assertFileExists("{$this->spool}/1001/Old/msg0002.WAV");
    }

    public function testAlreadyHeardIsLeftAlone(): void
    {
        $this->assertSame('Old:msg0001', VoicemailService::markHeard('1001', 'Old:msg0001', $this->spool));
    }

    public function testOnlyOwnFoldersAndMessageNames(): void
    {
        foreach (['INBOX:../../1002/INBOX/msg0000', 'tmp:msg0000', 'INBOX:msg0000.txt', 'INBOX:*'] as $id) {
            try {
                VoicemailService::markHeard('1001', $id, $this->spool);
                $this->fail("accepted {$id}");
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $this->assertNull(VoicemailService::audioPath('1001', $id));
        }
        $this->expectException(\Exception::class);
        VoicemailService::deleteFiles('1001', 'INBOX:../msg0000');
    }
}
