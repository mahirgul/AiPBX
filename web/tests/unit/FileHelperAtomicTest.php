<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/file_helper.php';

final class FileHelperAtomicTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/aipbx_test_filehelper_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
    }

    public function testWriteFileIsAtomicViaRename(): void
    {
        $file = $this->tmpDir . '/atomic_test.conf';
        $initial = "initial content\n";
        $this->assertTrue(FileHelper::writeFile($file, $initial, null, null, 0644));
        $this->assertStringEqualsFile($file, $initial);

        $inodeBefore = fileinode($file);

        $newContent = "new content line 1\nnew content line 2\n";
        $this->assertTrue(FileHelper::writeFile($file, $newContent, null, null, 0644));
        $this->assertStringEqualsFile($file, $newContent);

        $inodeAfter = fileinode($file);
        // Rename replaces the directory entry with a new inode atomically
        $this->assertNotSame($inodeBefore, $inodeAfter, 'Atomic rename must replace directory entry with new file inode');
    }

    public function testSanitizeAudioPath(): void
    {
        $this->assertSame('custom/test', FileHelper::sanitizeAudioPath('/custom/test.wav'));
        $this->assertSame('safe-audio_1', FileHelper::sanitizeAudioPath('../../safe-audio_1.gsm'));
    }
}
