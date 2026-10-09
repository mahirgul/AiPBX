<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/FileStorageService.php';

/**
 * Admin → File storage (roadmap item 3): form validation, the secret key
 * handling and what the chat service is sent. The bucket itself is tested in
 * chat/storage_test.go.
 */
final class FileStorageTest extends TestCase
{
    private function s3Form(array $over = []): array
    {
        return $over + [
            'storage_backend' => 's3',
            'storage_s3_endpoint' => 'https://minio.example.com:9000/',
            'storage_s3_region' => 'EU-Central-1',
            'storage_s3_bucket' => 'aipbx-chat',
            'storage_s3_prefix' => '/pbx1/',
            'storage_s3_access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'storage_s3_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'storage_s3_path_style' => '1',
        ];
    }

    public function testLocalNeedsNothingAndKeepsBucketSettings(): void
    {
        $this->assertSame(['storage_backend' => 'local'], FileStorageService::validate(['storage_backend' => 'local'], ''));
    }

    public function testUnknownBackendIsRefused(): void
    {
        $this->expectException(\Exception::class);
        FileStorageService::validate(['storage_backend' => 'ftp'], '');
    }

    public function testS3FormIsNormalised(): void
    {
        $v = FileStorageService::validate($this->s3Form(), '');
        $this->assertSame('https://minio.example.com:9000', $v['storage_s3_endpoint']);
        $this->assertSame('eu-central-1', $v['storage_s3_region']);
        $this->assertSame('pbx1', $v['storage_s3_prefix']);
        $this->assertSame('1', $v['storage_s3_path_style']);
        $this->assertSame([
            'endpoint' => 'https://minio.example.com:9000', 'region' => 'eu-central-1', 'bucket' => 'aipbx-chat',
            'prefix' => 'pbx1', 'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'path_style' => true,
        ], FileStorageService::testPayload($v));

        // AWS: no endpoint, default region, virtual-host addresses.
        $aws = FileStorageService::validate($this->s3Form(['storage_s3_endpoint' => '', 'storage_s3_region' => '', 'storage_s3_path_style' => '']), '');
        $this->assertSame(['', 'us-east-1', '0'], [$aws['storage_s3_endpoint'], $aws['storage_s3_region'], $aws['storage_s3_path_style']]);
    }

    public function testEmptySecretKeepsTheStoredOne(): void
    {
        $v = FileStorageService::validate($this->s3Form(['storage_s3_secret_key' => '']), 'stored-secret');
        $this->assertSame('stored-secret', $v['storage_s3_secret_key']);
        $this->expectException(\Exception::class);
        FileStorageService::validate($this->s3Form(['storage_s3_secret_key' => '']), '');
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function badS3Forms(): array
    {
        return [
            'endpoint with a path' => [['storage_s3_endpoint' => 'https://minio.example.com/bucket']],
            'endpoint with credentials' => [['storage_s3_endpoint' => 'https://user:pass@minio.example.com']],
            'endpoint not http' => [['storage_s3_endpoint' => 'file:///etc/passwd']],
            'bucket too short' => [['storage_s3_bucket' => 'ab']],
            'bucket upper case' => [['storage_s3_bucket' => 'AiPBX']],
            'bucket as an IP' => [['storage_s3_bucket' => '192.168.1.1']],
            'bucket with ..' => [['storage_s3_bucket' => 'a..b']],
            'region' => [['storage_s3_region' => 'eu central']],
            'prefix with ..' => [['storage_s3_prefix' => 'a/../b']],
            'prefix with spaces' => [['storage_s3_prefix' => 'my files']],
            'access key' => [['storage_s3_access_key' => "AK\nIA"]],
            'secret with a newline' => [['storage_s3_secret_key' => "abc\ndef"]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badS3Forms')]
    public function testBadS3FormsAreRefused(array $over): void
    {
        $this->expectException(\Exception::class);
        FileStorageService::validate($this->s3Form($over), '');
    }

    public function testInternalTokenMatchesTheChatService(): void
    {
        // chat/storage_admin.go: hex(HMAC-SHA256(chat secret, "chat-internal:storage")).
        $this->assertSame(hash_hmac('sha256', 'chat-internal:storage', getMobileTokenSecret()), FileStorageService::internalToken());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', FileStorageService::internalToken());
    }

    public function testSaveLocalAndDefaults(): void
    {
        $db = getDB();
        $keys = array_keys(FileStorageService::DEFAULTS);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $backup = $db->prepare("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ($in)");
        $backup->execute($keys);
        $saved = $backup->fetchAll(PDO::FETCH_KEY_PAIR);
        $_SESSION['csrf_token'] = 'test-token';
        try {
            $db->prepare("DELETE FROM sys_settings WHERE setting_key IN ($in)")->execute($keys);
            $this->assertSame(FileStorageService::DEFAULTS, FileStorageService::settings());
            $this->assertSame('', FileStorageService::maskedSecret(FileStorageService::settings()));

            $res = FileStorageService::save(['csrf_token' => 'test-token', 'storage_backend' => 'local']);
            $this->assertTrue($res['success'], $res['error'] ?? '');
            $this->assertSame('local', FileStorageService::settings()['storage_backend']);

            $this->assertFalse(FileStorageService::save(['csrf_token' => 'wrong', 'storage_backend' => 'local'])['success']);
        } finally {
            $db->prepare("DELETE FROM sys_settings WHERE setting_key IN ($in)")->execute($keys);
            $ins = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
            foreach ($saved as $k => $v) {
                $ins->execute([$k, $v]);
            }
        }
    }

    public function testMaskedSecretNeverShowsTheSecret(): void
    {
        SecretBox::useKey(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        try {
            $stored = SecretBox::encrypt('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
            $masked = FileStorageService::maskedSecret(['storage_s3_secret_key' => $stored]);
            $this->assertSame('••••EKEY', $masked);
        } finally {
            SecretBox::useKey(null);
        }
    }
}
