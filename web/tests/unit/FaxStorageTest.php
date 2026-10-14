<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/FaxStorage.php';

/** Faxes in the S3 bucket (#69): keys, types, the setting, local removal. */
final class FaxStorageTest extends TestCase
{
    public function testKeysTypesAndSetting(): void
    {
        $this->assertSame('', FaxStorage::keyFor('/etc/passwd'));
        $this->assertSame('application/pdf', FaxStorage::contentType('/var/www/faxes/in/fax_1.pdf'));
        $this->assertSame('image/tiff', FaxStorage::contentType('/var/www/faxes/in/fax_1.TIF'));
        $v = FileStorageService::validate([
            'storage_backend' => 's3', 'faxes_storage' => 's3', 'storage_s3_bucket' => 'pbx-files',
            'storage_s3_access_key' => 'AKIAEXAMPLE', 'storage_s3_secret_key' => 'secret',
        ], '');
        $this->assertSame('s3', $v['faxes_storage']);
        $this->assertSame('local', $v['recordings_storage'], 'each kind is chosen on its own');
        $this->assertSame('local', FileStorageService::validate(['storage_backend' => 'local', 'faxes_storage' => 's3'], '')['faxes_storage']);
    }

    public function testRemoveAndLocalForFilesOnTheDisk(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'fax');
        $this->assertSame($f, FaxStorage::local($f), 'a local file is used as it is');
        FaxStorage::remove($f);
        $this->assertFileDoesNotExist($f);
        $this->assertNull(FaxStorage::local($f));
        $this->assertNull(FaxStorage::local('s3:recordings/x.mp3'), 'only fax keys are brought back');
        FaxStorage::remove('');
        FaxStorage::remove(null);
    }
}
