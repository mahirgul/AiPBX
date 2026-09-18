<?php
/**
 * TwoFactorService (2FA / TOTP Authenticator Servisi)
 * RFC 6238 Time-Based One-Time Password ve tek kullanımlık kurtarma kodları yönetimi.
 */

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class TwoFactorService
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Rastgele Base32 formatında 2FA secret anahtarı üretir (varsayılan 160-bit / 32 karakter).
     */
    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        $maxIndex = strlen(self::BASE32_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, $maxIndex)];
        }
        return $secret;
    }

    /**
     * Base32 dizesini ikili (binary) veriye dönüştürür.
     */
    public static function base32Decode(string $b32): string
    {
        $lut = [
            'A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 'H' => 7,
            'I' => 8, 'J' => 9, 'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 14, 'P' => 15,
            'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19, 'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23,
            'Y' => 24, 'Z' => 25, '2' => 26, '3' => 27, '4' => 28, '5' => 29, '6' => 30, '7' => 31
        ];

        $b32 = strtoupper(rtrim($b32, "=\x20\t\r\n\0"));
        $binary = '';
        $buffer = 0;
        $bitsLeft = 0;
        $len = strlen($b32);

        for ($i = 0; $i < $len; $i++) {
            $c = $b32[$i];
            if (!isset($lut[$c])) {
                continue;
            }
            $buffer = ($buffer << 5) | $lut[$c];
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }

    /**
     * Belirli bir zaman dilimi için 6 haneli TOTP kodunu hesaplar.
     */
    public static function calculateCode(string $secret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $slice = (int)floor($ts / 30);
        $key = self::base32Decode($secret);

        $timeData = pack('N*', 0) . pack('N*', $slice);
        $hmac = hash_hmac('sha1', $timeData, $key, true);

        $offset = ord($hmac[19]) & 0x0F;
        $part = substr($hmac, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Kullanıcının girdiği 6 haneli kodu ± $window (30'ar saniye tolerans) ile doğrular.
     */
    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }

        $baseTime = $timestamp ?? time();
        for ($i = -$window; $i <= $window; $i++) {
            $testTime = $baseTime + ($i * 30);
            $expected = self::calculateCode($secret, $testTime);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 8 adet tek kullanımlık 8 karakterli formatlı (örn. ABCD-EF23) kurtarma kodu üretir.
     * Karışıklığı önlemek için 0/O ve 1/I karakterleri hariç tutulmuştur.
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $max = strlen($chars) - 1;
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $c1 = '';
            $c2 = '';
            for ($j = 0; $j < 4; $j++) {
                $c1 .= $chars[random_int(0, $max)];
                $c2 .= $chars[random_int(0, $max)];
            }
            $codes[] = $c1 . '-' . $c2;
        }

        return $codes;
    }

    /**
     * Kurtarma kodlarını DB'de saklanmak üzere hash'ler.
     */
    public static function hashRecoveryCodes(array $plainCodes): string
    {
        $hashed = [];
        foreach ($plainCodes as $code) {
            $clean = strtoupper(str_replace(['-', ' '], '', trim($code)));
            $hashed[] = password_hash($clean, PASSWORD_DEFAULT);
        }
        return json_encode($hashed);
    }

    /**
     * Kullanıcının girdiği kurtarma kodunu doğrular ve eşleşirse tek kullanımlık olarak tüketir (siler).
     */
    public static function verifyAndConsumeRecoveryCode(int $userId, string $enteredCode): bool
    {
        $clean = strtoupper(str_replace(['-', ' '], '', trim($enteredCode)));
        if (strlen($clean) < 6) {
            return false;
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT two_factor_recovery_codes, username FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['two_factor_recovery_codes'])) {
            return false;
        }

        $hashedList = json_decode($user['two_factor_recovery_codes'], true);
        if (!is_array($hashedList) || empty($hashedList)) {
            return false;
        }

        $matchedIndex = -1;
        foreach ($hashedList as $index => $hash) {
            if (password_verify($clean, $hash)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex >= 0) {
            // Kullanılan kurtarma kodunu listeden çıkar
            unset($hashedList[$matchedIndex]);
            $updatedList = array_values($hashedList);

            $upd = $db->prepare('UPDATE sys_users SET two_factor_recovery_codes = ? WHERE id = ?');
            $upd->execute([json_encode($updatedList), $userId]);

            if (function_exists('writeAuditLog')) {
                writeAuditLog(
                    $userId,
                    'sys_users',
                    $userId,
                    "Kullanıcı '{$user['username']}' kurtarma kodu ile 2FA oturumu açtı. Kalan kurtarma kodu: " . count($updatedList),
                    'login_recovery_code'
                );
            }

            return true;
        }

        return false;
    }

    /**
     * Authenticator uygulamaları (Google Auth, MS Auth vb.) için standart otpauth:// URI'sini üretir.
     */
    public static function getOtpAuthUri(string $username, string $secret, string $issuer = 'AiPBX'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($username);
        $encodedIssuer = rawurlencode($issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Tamamen çevrimdışı (offline) yerel SVG QR Kodu Data URI formatında üretir.
     */
    public static function getQrCodeDataUri(string $otpAuthUri): string
    {
        if (class_exists(QRCode::class)) {
            try {
                return (new QRCode())->render($otpAuthUri);
            } catch (\Exception $e) {
                // Fallback below
            }
        }

        // Fallback: Standart SVG placeholder
        return 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="%23eee"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="%23666">QR Error</text></svg>';
    }

    /**
     * Kullanıcı için 2FA kurulumunu onaylar ve aktifleştirir.
     * @return array{success:bool, error?:string, recovery_codes?:array}
     */
    public static function enableTwoFactor(int $userId, string $secret, string $verifyCode): array
    {
        if (!self::verifyCode($secret, $verifyCode)) {
            return ['success' => false, 'error' => 'Girdiğiniz 6 haneli doğrulama kodu geçersiz. Lütfen tekrar deneyin.'];
        }

        $plainCodes = self::generateRecoveryCodes(8);
        $hashedCodes = self::hashRecoveryCodes($plainCodes);

        $db = getDB();
        $stmt = $db->prepare('UPDATE sys_users SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_recovery_codes = ?, two_factor_confirmed_at = NOW() WHERE id = ?');
        $stmt->execute([$secret, $hashedCodes, $userId]);

        if (function_exists('writeAuditLog')) {
            $uStmt = $db->prepare('SELECT username FROM sys_users WHERE id = ?');
            $uStmt->execute([$userId]);
            $un = $uStmt->fetchColumn() ?: (string)$userId;
            writeAuditLog($userId, 'sys_users', $userId, "Kullanıcı '{$un}' iki faktörlü doğrulamayı (2FA) etkinleştirdi.", 'two_factor_enable');
        }

        return [
            'success' => true,
            'recovery_codes' => $plainCodes,
        ];
    }

    /**
     * Kullanıcı için 2FA'yı devre dışı bırakır.
     * @return array{success:bool, error?:string}
     */
    public static function disableTwoFactor(int $userId, string $password = '', bool $isAdminReset = false): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash, two_factor_enabled FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'Kullanıcı bulunamadı.'];
        }

        if (!$isAdminReset) {
            if (empty($password) || !password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'error' => 'Geçerli parolanızı hatalı girdiniz.'];
            }
        }

        $upd = $db->prepare('UPDATE sys_users SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_confirmed_at = NULL WHERE id = ?');
        $upd->execute([$userId]);

        if (function_exists('writeAuditLog')) {
            $actorId = $_SESSION['user_id'] ?? $userId;
            $msg = $isAdminReset
                ? "Admin tarafından '{$user['username']}' kullanıcısının 2FA doğrulaması sıfırlandı."
                : "Kullanıcı '{$user['username']}' 2FA doğrulamasını devre dışı bıraktı.";
            writeAuditLog($actorId, 'sys_users', $userId, $msg, 'two_factor_disable');
        }

        return ['success' => true];
    }

    /**
     * Kullanıcı için yeni kurtarma kodları üretir (mevcut şifre doğrulaması gerekir).
     * @return array{success:bool, error?:string, recovery_codes?:array}
     */
    public static function regenerateRecoveryCodes(int $userId, string $password): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash, two_factor_enabled FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)$user['two_factor_enabled'] !== 1) {
            return ['success' => false, 'error' => 'İki faktörlü doğrulama aktif değil.'];
        }

        if (empty($password) || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Geçerli parolanızı hatalı girdiniz.'];
        }

        $plainCodes = self::generateRecoveryCodes(8);
        $hashedCodes = self::hashRecoveryCodes($plainCodes);

        $upd = $db->prepare('UPDATE sys_users SET two_factor_recovery_codes = ? WHERE id = ?');
        $upd->execute([$hashedCodes, $userId]);

        if (function_exists('writeAuditLog')) {
            writeAuditLog($userId, 'sys_users', $userId, "Kullanıcı '{$user['username']}' yeni 2FA yedek kurtarma kodları oluşturdu.", 'two_factor_regen_codes');
        }

        return [
            'success' => true,
            'recovery_codes' => $plainCodes,
        ];
    }
}
