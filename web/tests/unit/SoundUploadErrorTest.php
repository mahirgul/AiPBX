<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/services/SoundService.php';

/**
 * A file over PHP's upload limit used to be dropped with no message: the MOH
 * upload "did nothing" (issue #164). Every upload error is now reported.
 */
final class SoundUploadErrorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'snd';
    }

    private static function file(int $error): array
    {
        return ['name' => 'music.mp3', 'type' => 'audio/mpeg', 'tmp_name' => '', 'error' => $error, 'size' => 0];
    }

    public function testMohFileOverTheLimitSaysSo(): void
    {
        $res = SoundService::uploadMOHFile(['moh_class' => 'default'], ['moh_audio' => self::file(UPLOAD_ERR_INI_SIZE)], 'snd');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString(ini_get('upload_max_filesize'), (string) $res['error']);
    }

    public function testMohWithoutAFileIsAnErrorNotSilence(): void
    {
        $res = SoundService::uploadMOHFile(['moh_class' => 'default'], [], 'snd');
        $this->assertFalse($res['success']);
        $this->assertNotSame('', (string) ($res['error'] ?? ''));
    }

    public function testAnnouncementUploadErrorIsReported(): void
    {
        $res = SoundService::uploadAnnouncement(['csrf_token' => 'snd', 'sound_name' => 'hello'], ['audio_file' => self::file(UPLOAD_ERR_PARTIAL)]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('(' . UPLOAD_ERR_PARTIAL . ')', (string) $res['error']);
    }
}
