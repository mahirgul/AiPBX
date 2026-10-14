<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/RecordingStorage.php';

/** Call recordings in the S3 bucket (#69): keys, the CDR marker and the setting. */
final class RecordingStorageTest extends TestCase
{
    public function testKeysStayBelowTheMonitorFolder(): void
    {
        $root = realpath(MONITOR_STORAGE_PATH) ?: null;
        if ($root === null || !is_writable($root)) {
            $this->markTestSkipped('no writable monitor folder here');
        }
        $f = $root . '/test_rs_' . getmypid() . '.mp3';
        file_put_contents($f, 'x');
        try {
            $this->assertSame('recordings/' . basename($f), RecordingStorage::keyFor($f));
        } finally {
            unlink($f);
        }
        $this->assertSame('', RecordingStorage::keyFor('/etc/passwd'));
        $this->assertSame('', RecordingStorage::keyFor($root . '/../../etc/passwd'));
    }

    public function testRemoteMarker(): void
    {
        $this->assertTrue(RecordingStorage::isRemote('s3:recordings/2026/a.mp3'));
        $this->assertSame('recordings/2026/a.mp3', RecordingStorage::key('s3:recordings/2026/a.mp3'));
        $this->assertFalse(RecordingStorage::isRemote('/var/spool/asterisk/monitor/a.mp3'));
        $this->assertFalse(RecordingStorage::isRemote(null));
    }

    public function testRecordingsGoToTheBucketOnlyWithS3(): void
    {
        $v = FileStorageService::validate(['storage_backend' => 'local', 'recordings_storage' => 's3'], '');
        $this->assertSame('local', $v['recordings_storage'], 'no bucket in use: recordings stay here');
        $v = FileStorageService::validate([
            'storage_backend' => 's3', 'recordings_storage' => 's3', 'storage_s3_bucket' => 'pbx-files',
            'storage_s3_access_key' => 'AKIAEXAMPLE', 'storage_s3_secret_key' => 'secret',
        ], '');
        $this->assertSame('s3', $v['recordings_storage']);
        $v = FileStorageService::validate([
            'storage_backend' => 's3', 'storage_s3_bucket' => 'pbx-files',
            'storage_s3_access_key' => 'AKIAEXAMPLE', 'storage_s3_secret_key' => 'secret',
        ], '');
        $this->assertSame('local', $v['recordings_storage']);
    }
}
