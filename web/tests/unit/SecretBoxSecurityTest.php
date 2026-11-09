<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/secret_box.php';

final class SecretBoxSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        SecretBox::useKey(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        SecretBox::useKey(null);
    }

    public function testPersistentKeyIsPrivateAndStable(): void
    {
        $dir = sys_get_temp_dir() . '/aipbx-key-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $file = $dir . '/settings.key';
        $method = new ReflectionMethod(SecretBox::class, 'persistentKey');
        try {
            $key = $method->invoke(null, $file);
            $this->assertSame(32, strlen($key));
            $this->assertSame($key, $method->invoke(null, $file));
            $this->assertSame(0600, fileperms($file) & 0777);
        } finally {
            @unlink($file);
            rmdir($dir);
        }
    }

    public function testDamagedKeyIsNotSilentlyReplaced(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aipbx-key-');
        file_put_contents($file, 'damaged-key');
        try {
            try {
                (new ReflectionMethod(SecretBox::class, 'persistentKey'))->invoke(null, $file);
                $this->fail('A damaged key must stop credential encryption');
            } catch (RuntimeException $e) {
                $this->assertSame('damaged-key', file_get_contents($file));
                $this->assertStringContainsString('damaged', $e->getMessage());
            }
        } finally {
            unlink($file);
        }
    }

    public function testUnavailableKeyLocationFailsAndRestoresUmask(): void
    {
        $before = umask();
        try {
            (new ReflectionMethod(SecretBox::class, 'persistentKey'))->invoke(null,
                sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(8)) . '/settings.key');
            $this->fail('An unavailable key must not fall back to an ephemeral key');
        } catch (RuntimeException $e) {
            $this->assertSame($before, umask());
            $this->assertStringContainsString('Cannot open', $e->getMessage());
        }
    }

    public static function damagedEnvelopes(): array
    {
        return [
            'empty' => [''],
            'plaintext' => ['not-encrypted'],
            'unknown version' => ['sb2:YWJj'],
            'invalid base64' => ['sb1:%%%'],
            'missing payload' => ['sb1:'],
            'short nonce' => ['sb1:' . base64_encode(str_repeat('x', 23))],
            'nonce without ciphertext' => ['sb1:' . base64_encode(str_repeat('x', 24))],
            'truncated authentication tag' => ['sb1:' . base64_encode(str_repeat('x', 25))],
        ];
    }

    #[DataProvider('damagedEnvelopes')]
    public function testDamagedEnvelopesNeverReturnCredentials(string $stored): void
    {
        $this->assertSame('', SecretBox::decrypt($stored));
    }

    public function testChangingAnyEncryptedByteRejectsTheCredential(): void
    {
        $stored = SecretBox::encrypt('test-credential');
        $raw = base64_decode(substr($stored, 4), true);
        $this->assertIsString($raw);
        // Exercise both the nonce and every byte of the authenticated ciphertext.
        for ($i = 0; $i < strlen($raw); $i++) {
            $changed = $raw;
            $changed[$i] = chr(ord($changed[$i]) ^ 1);
            $this->assertSame('', SecretBox::decrypt('sb1:' . base64_encode($changed)), "byte {$i}");
        }
        $this->assertSame('test-credential', SecretBox::decrypt($stored));
    }

    public function testEmptyCredentialRemainsEmpty(): void
    {
        $this->assertSame('', SecretBox::encrypt(''));
        $this->assertSame('', SecretBox::mask(''));
    }

    public function testBinaryAndUnicodeCredentialsRoundTrip(): void
    {
        $plain = "şifre-秘密\0\xff\n";
        $this->assertSame($plain, SecretBox::decrypt(SecretBox::encrypt($plain)));
    }

    public function testEnvironmentKeySurvivesResetAndRejectsAnotherKey(): void
    {
        $previous = getenv('AIPBX_SETTINGS_KEY');
        try {
            putenv('AIPBX_SETTINGS_KEY=security-test-key-one');
            SecretBox::useKey(null);
            $stored = SecretBox::encrypt('test-credential');
            SecretBox::useKey(null);
            $this->assertSame('test-credential', SecretBox::decrypt($stored));
            putenv('AIPBX_SETTINGS_KEY=security-test-key-two');
            SecretBox::useKey(null);
            $this->assertSame('', SecretBox::decrypt($stored));
            putenv('AIPBX_SETTINGS_KEY=security-test-key-one');
            SecretBox::useKey(null);
            $this->assertSame('test-credential', SecretBox::decrypt($stored));
        } finally {
            putenv($previous === false ? 'AIPBX_SETTINGS_KEY' : 'AIPBX_SETTINGS_KEY=' . $previous);
            SecretBox::useKey(null);
        }
    }
}
