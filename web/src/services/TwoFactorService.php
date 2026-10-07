<?php
/**
 * TwoFactorService (2FA / TOTP authenticator service)
 * Manages RFC 6238 time-based one-time passwords and single-use recovery codes.
 */

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class TwoFactorService
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generates a random Base32 2FA secret (160-bit / 32 characters by default).
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
     * Converts a Base32 string to binary data.
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
     * Computes the 6-digit TOTP code for a given time step.
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
     * Verifies the 6-digit code the user entered with ± $window (30 seconds each) tolerance.
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
     * Generates 8 single-use, formatted 8-character recovery codes (e.g. ABCD-EF23).
     * 0/O and 1/I are left out to avoid confusion.
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
     * Hashes the recovery codes for storage in the DB.
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
     * Verifies a recovery code the user entered and, on a match, consumes (deletes) it as single-use.
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
            // Remove the used recovery code from the list
            unset($hashedList[$matchedIndex]);
            $updatedList = array_values($hashedList);

            $upd = $db->prepare('UPDATE sys_users SET two_factor_recovery_codes = ? WHERE id = ?');
            $upd->execute([json_encode($updatedList), $userId]);

            if (function_exists('writeAuditLog')) {
                writeAuditLog(
                    $userId,
                    'sys_users',
                    $userId,
                    "User '{$user['username']}' signed in with a 2FA recovery code. Recovery codes left: " . count($updatedList),
                    'login_recovery_code'
                );
            }

            return true;
        }

        return false;
    }

    /**
     * Builds the standard otpauth:// URI for authenticator apps (Google Auth, MS Auth etc.).
     */
    public static function getOtpAuthUri(string $username, string $secret, string $issuer = 'AiPBX'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($username);
        $encodedIssuer = rawurlencode($issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Generates a fully offline, local SVG QR code as a data URI.
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
     * Confirms and enables the 2FA setup for a user.
     * @return array{success:bool, error?:string, recovery_codes?:array}
     */
    public static function enableTwoFactor(int $userId, string $secret, string $verifyCode): array
    {
        if (!self::verifyCode($secret, $verifyCode)) {
            return ['success' => false, 'error' => t('srv_2fa.err_code')];
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
            writeAuditLog($userId, 'sys_users', $userId, "User '{$un}' enabled two-factor authentication (2FA).", 'two_factor_enable');
        }

        return [
            'success' => true,
            'recovery_codes' => $plainCodes,
        ];
    }

    /**
     * Disables 2FA for a user.
     * @return array{success:bool, error?:string}
     */
    public static function disableTwoFactor(int $userId, string $password = '', bool $isAdminReset = false): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash, two_factor_enabled FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => t('srv_2fa.err_user')];
        }

        if (!$isAdminReset) {
            if (empty($password) || !password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'error' => t('srv_2fa.err_password')];
            }
        }

        $upd = $db->prepare('UPDATE sys_users SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_confirmed_at = NULL WHERE id = ?');
        $upd->execute([$userId]);

        if (function_exists('writeAuditLog')) {
            $actorId = $_SESSION['user_id'] ?? $userId;
            $msg = $isAdminReset
                ? "2FA of user '{$user['username']}' was reset by an admin."
                : "User '{$user['username']}' disabled 2FA.";
            writeAuditLog($actorId, 'sys_users', $userId, $msg, 'two_factor_disable');
        }

        return ['success' => true];
    }

    /**
     * Generates new recovery codes for a user (requires the current password).
     * @return array{success:bool, error?:string, recovery_codes?:array}
     */
    public static function regenerateRecoveryCodes(int $userId, string $password): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash, two_factor_enabled FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)$user['two_factor_enabled'] !== 1) {
            return ['success' => false, 'error' => t('srv_2fa.err_not_enabled')];
        }

        if (empty($password) || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => t('srv_2fa.err_password')];
        }

        $plainCodes = self::generateRecoveryCodes(8);
        $hashedCodes = self::hashRecoveryCodes($plainCodes);

        $upd = $db->prepare('UPDATE sys_users SET two_factor_recovery_codes = ? WHERE id = ?');
        $upd->execute([$hashedCodes, $userId]);

        if (function_exists('writeAuditLog')) {
            writeAuditLog($userId, 'sys_users', $userId, "User '{$user['username']}' created new 2FA recovery codes.", 'two_factor_regen_codes');
        }

        return [
            'success' => true,
            'recovery_codes' => $plainCodes,
        ];
    }
}
