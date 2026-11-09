<?php
/**
 * Encryption for credentials stored in the database (cloud API keys).
 *
 * A database dump alone does not reveal them: the key lives outside the
 * database — AIPBX_SETTINGS_KEY in /etc/ai-pbx.env when set, otherwise a
 * random key file created on first use in /var/lib/aipbx (www-data, 0600).
 * Losing the key only means re-entering the credentials.
 */
final class SecretBox
{
    private const PREFIX = 'sb1:';
    public const KEY_FILE = '/var/lib/aipbx/settings.key';

    private static ?string $key = null;

    /** For tests: use a fixed key instead of the environment / key file. */
    public static function useKey(?string $key): void
    {
        self::$key = $key;
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $env = function_exists('portalEnv') ? (string) portalEnv('AIPBX_SETTINGS_KEY', '') : '';
        if ($env !== '') {
            return self::$key = hash_hkdf('sha256', $env, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'aipbx-settings');
        }
        return self::$key = self::persistentKey(self::KEY_FILE);
    }

    /** Never encrypt with an ephemeral key: stored credentials would be lost. */
    private static function persistentKey(string $file): string
    {
        $existing = @file_get_contents($file);
        if (is_string($existing) && strlen($existing) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $existing;
        }
        $old = umask(0077);
        try {
            $fp = @fopen($file, 'c+');
            if ($fp === false) {
                throw new RuntimeException('Cannot open the settings encryption key.');
            }
            try {
                if (!flock($fp, LOCK_EX)) {
                    throw new RuntimeException('Cannot lock the settings encryption key.');
                }
                $raw = stream_get_contents($fp);
                if ($raw === false) {
                    throw new RuntimeException('Cannot read the settings encryption key.');
                }
                if (strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    return $raw;
                }
                if ($raw !== '') {
                    throw new RuntimeException('The settings encryption key is damaged.');
                }
                $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                if (fwrite($fp, $raw) !== strlen($raw) || !fflush($fp) || !fsync($fp)) {
                    throw new RuntimeException('Cannot persist the settings encryption key.');
                }
                return $raw;
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        } finally {
            umask($old);
        }
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Plain text, or '' when the value cannot be decrypted (other key, damaged). */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? '' : $plain;
    }

    /** What the page shows instead of a secret: "••••1234". */
    public static function mask(string $plain): string
    {
        return $plain === '' ? '' : '••••' . (strlen($plain) > 8 ? substr($plain, -4) : '');
    }
}
