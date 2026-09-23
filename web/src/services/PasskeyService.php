<?php
/**
 * PasskeyService (FIDO2 / WebAuthn Servisi)
 * Şifresiz veya biyometrik güvenlik anahtarları (Touch ID, Face ID, Windows Hello, YubiKey) yönetimi.
 */

if (is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

class PasskeyService
{
    /**
     * Geçerli alan adını (RP ID) döner (port numarası temizlenir).
     */
    public static function getRpId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        return $host;
    }

    /**
     * WebAuthn sunucu nesnesini hazırlar.
     */
    public static function getWebAuthn(): WebAuthn
    {
        $rpName = function_exists('getSystemSetting') ? getSystemSetting('brand_title', 'AiPBX') : 'AiPBX';
        $rpId = self::getRpId();

        $formats = ['android-key', 'android-safetynet', 'apple', 'fido-u2f', 'none', 'packed', 'tpm'];
        return new WebAuthn($rpName, $rpId, $formats);
    }

    /**
     * Yeni bir passkey kaydı için tarayıcıya iletilecek challenge ve parametreleri üretir.
     * @return stdClass
     */
    public static function getRegisterArgs(int $userId, string $username, string $displayName): stdClass
    {
        $webAuthn = self::getWebAuthn();

        // Mevcut kayıtlı passkey'leri hariç tut (aynı cihazı tekrar kaydetmeyi önle)
        $existingKeys = self::getUserPasskeys($userId);
        $excludeIds = [];
        foreach ($existingKeys as $key) {
            $raw = base64_decode($key['credential_id'], true);
            if ($raw !== false) {
                $excludeIds[] = $raw;
            }
        }

        $createArgs = $webAuthn->getCreateArgs(
            (string)$userId,
            $username,
            $displayName,
            60,      // 60 saniye zaman aşımı
            true,    // Resident key (keşfedilebilir / username'siz giriş için)
            'preferred', // Biyometrik / PIN tercih edilir
            null,    // platform or cross-platform
            $excludeIds
        );

        // Challenge'ı oturumda sakla
        $_SESSION['webauthn_reg_challenge'] = $webAuthn->getChallenge()->getBinaryString();
        $_SESSION['webauthn_reg_user_id'] = $userId;

        return $createArgs->publicKey;
    }

    /**
     * Tarayıcıdan gelen passkey kayıt yanıtını doğrular ve veritabanına kaydeder.
     * @return array{success:bool, error?:string, id?:int}
     */
    public static function processRegister(
        int $userId,
        string $clientDataJSON,
        string $attestationObject,
        string $deviceName = 'Passkey'
    ): array {
        if (empty($_SESSION['webauthn_reg_challenge']) || empty($_SESSION['webauthn_reg_user_id'])) {
            return ['success' => false, 'error' => 'Geçersiz veya süresi dolmuş kayıt oturumu.'];
        }

        if ((int)$_SESSION['webauthn_reg_user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Kullanıcı oturumu uyuşmazlığı.'];
        }

        $challenge = $_SESSION['webauthn_reg_challenge'];

        try {
            $webAuthn = self::getWebAuthn();
            $data = $webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challenge,
                false, // requireUserVerification
                true,  // requireUserPresent
                false, // failIfRootMismatch (kendi imzalı/yerel anahtarlar için esnek)
                false  // requireCtsProfileMatch
            );

            $credentialId = base64_encode($data->credentialId);
            $credentialPublicKey = $data->credentialPublicKey;
            $signCounter = (int)($data->signatureCounter ?? 0);

            $cleanDeviceName = trim($deviceName);
            if (empty($cleanDeviceName)) {
                $cleanDeviceName = 'Passkey (' . date('d.m.Y H:i') . ')';
            }
            if (mb_strlen($cleanDeviceName) > 100) {
                $cleanDeviceName = mb_substr($cleanDeviceName, 0, 100);
            }

            $db = getDB();
            $stmt = $db->prepare('INSERT INTO sys_user_passkeys (user_id, credential_id, public_key, counter, device_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$userId, $credentialId, $credentialPublicKey, $signCounter, $cleanDeviceName]);
            $insertedId = (int)$db->lastInsertId();

            unset($_SESSION['webauthn_reg_challenge'], $_SESSION['webauthn_reg_user_id']);

            if (function_exists('writeAuditLog')) {
                $uStmt = $db->prepare('SELECT username FROM sys_users WHERE id = ?');
                $uStmt->execute([$userId]);
                $un = $uStmt->fetchColumn() ?: (string)$userId;
                writeAuditLog($userId, 'sys_user_passkeys', $insertedId, "Kullanıcı '{$un}' yeni bir Passkey ekledi: {$cleanDeviceName}", 'passkey_register');
            }

            return ['success' => true, 'id' => $insertedId];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Passkey kaydı doğrulanamadı: ' . $e->getMessage()];
        }
    }

    /**
     * Passkey ile giriş için tarayıcıya iletilecek sorgu parametrelerini üretir.
     * @return stdClass
     */
    public static function getLoginArgs(?string $username = null): stdClass
    {
        $webAuthn = self::getWebAuthn();
        $allowedCredentials = [];

        if (!empty($username)) {
            $db = getDB();
            $stmt = $db->prepare('SELECT p.credential_id FROM sys_user_passkeys p JOIN sys_users u ON p.user_id = u.id WHERE u.username = ? OR u.extension = ?');
            $stmt->execute([$username, $username]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $cid) {
                $raw = base64_decode($cid, true);
                if ($raw !== false) {
                    $allowedCredentials[] = $raw;
                }
            }
        }

        // $allowedCredentials boşsa resident-key modunda çalışır (cihaz kayıtlı hesapları listeler)
        $getArgs = $webAuthn->getGetArgs(
            $allowedCredentials,
            60,          // 60 sn timeout
            true, true, true, true, true, // usb, nfc, ble, hybrid, internal
            'preferred'  // requireUserVerification
        );

        $_SESSION['webauthn_auth_challenge'] = $webAuthn->getChallenge()->getBinaryString();
        return $getArgs->publicKey;
    }

    /**
     * Tarayıcıdan gelen passkey giriş yanıtını doğrular ve oturum açar.
     * @return array{success:bool, error?:string, redirect?:string}
     */
    public static function processLogin(
        string $clientDataJSON,
        string $authenticatorData,
        string $signature,
        string $credentialIdBase64,
        string $clientIp
    ): array {
        if (empty($_SESSION['webauthn_auth_challenge'])) {
            return ['success' => false, 'error' => 'Doğrulama oturumu zaman aşımına uğradı. Lütfen sayfayı yenileyin.'];
        }

        $challenge = $_SESSION['webauthn_auth_challenge'];

        $db = getDB();
        $stmt = $db->prepare('
            SELECT p.id as passkey_id, p.user_id, p.credential_id, p.public_key, p.counter, p.device_name,
                   u.id, u.username, u.full_name, u.role, u.extension, u.theme_preference,
                   u.language_preference, u.is_active, u.must_reset_password
            FROM sys_user_passkeys p
            JOIN sys_users u ON p.user_id = u.id
            WHERE p.credential_id = ?
        ');
        $stmt->execute([$credentialIdBase64]);
        $passkey = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$passkey) {
            return ['success' => false, 'error' => 'Bu Passkey sisteme kayıtlı değil veya kaldırılmış.'];
        }

        if ((int)$passkey['is_active'] !== 1) {
            return ['success' => false, 'error' => 'Kullanıcı hesabınız pasif durumda. Yöneticiye başvurun.'];
        }

        try {
            $webAuthn = self::getWebAuthn();
            $webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $passkey['public_key'],
                $challenge,
                (int)$passkey['counter'],
                false, // requireUserVerification
                true   // requireUserPresent
            );

            // Counter güncelle
            $newCounter = $webAuthn->getSignatureCounter();
            if ($newCounter === null || $newCounter === 0) {
                $newCounter = (int)$passkey['counter'] + 1;
            }

            $upd = $db->prepare('UPDATE sys_user_passkeys SET counter = ?, last_used_at = NOW() WHERE id = ?');
            $upd->execute([$newCounter, $passkey['passkey_id']]);

            // Giriş başarılı: Session oluştur
            session_regenerate_id(true);

            $_SESSION['user_id'] = $passkey['user_id'];
            $_SESSION['username'] = $passkey['username'];
            $_SESSION['full_name'] = $passkey['full_name'];
            $_SESSION['user_role'] = $passkey['role'];
            $_SESSION['extension'] = $passkey['extension'];
            $_SESSION['theme'] = $passkey['theme_preference'] ?? 'light';
            $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$passkey['language_preference'] ?? '']))
                ? $passkey['language_preference']
                : 'tr';

            unset($_SESSION['webauthn_auth_challenge']);

            // Başarılı giriş günlüğe kaydet
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($clientIp, $passkey['username'], 'SUCCESS');
            }
            if (function_exists('writeAuditLog')) {
                writeAuditLog($passkey['user_id'], 'sys_users', $passkey['user_id'], "Kullanıcı '{$passkey['username']}' Passkey ({$passkey['device_name']}) ile giriş yaptı.", 'passkey_login');
            }

            // Role yönlendirmesi
            if ($passkey['role'] === 'admin') {
                $redirect = '/dashboard';
            } elseif ($passkey['role'] === 'cc_agent') {
                $redirect = '/cc-agent';
            } elseif ($passkey['role'] === 'cc_manager') {
                $redirect = '/cc-supervisor';
            } else {
                $redirect = '/fax-inbox';
            }

            return ['success' => true, 'redirect' => $redirect];
        } catch (\Exception $e) {
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($clientIp, $passkey['username'] ?? 'UNKNOWN_PASSKEY', 'FAILED');
            }
            return ['success' => false, 'error' => 'Passkey doğrulanamadı: ' . $e->getMessage()];
        }
    }

    /**
     * Kullanıcıya ait kayıtlı passkey listesini döner.
     */
    public static function getUserPasskeys(int $userId): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, credential_id, counter, device_name, created_at, last_used_at FROM sys_user_passkeys WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Bir passkey kaydını siler.
     */
    public static function deletePasskey(int $userId, int $passkeyId): bool
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, device_name, user_id FROM sys_user_passkeys WHERE id = ? AND user_id = ?');
        $stmt->execute([$passkeyId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $del = $db->prepare('DELETE FROM sys_user_passkeys WHERE id = ? AND user_id = ?');
        $del->execute([$passkeyId, $userId]);

        if (function_exists('writeAuditLog')) {
            writeAuditLog($userId, 'sys_user_passkeys', $passkeyId, "Kullanıcı '{$row['device_name']}' adlı Passkey'ini sildi.", 'passkey_delete');
        }

        return true;
    }
}
