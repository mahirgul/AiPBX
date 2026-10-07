<?php
/**
 * PasskeyService (FIDO2 / WebAuthn service)
 * Manages passwordless or biometric security keys (Touch ID, Face ID, Windows Hello, YubiKey).
 */

if (is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

class PasskeyService
{
    /**
     * Returns the current domain (RP ID), without the port number.
     */
    public static function getRpId(): string
    {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host)[0]);
        }
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        return $host;
    }

    /**
     * Prepares the WebAuthn server object.
     */
    public static function getWebAuthn(): WebAuthn
    {
        $rpName = function_exists('getSystemSetting') ? getSystemSetting('brand_title', 'AiPBX') : 'AiPBX';
        $rpId = self::getRpId();

        $formats = ['android-key', 'android-safetynet', 'apple', 'fido-u2f', 'none', 'packed', 'tpm'];
        return new WebAuthn($rpName, $rpId, $formats, true);
    }

    /**
     * Creates the challenge and parameters sent to the browser for a new passkey registration.
     * @return stdClass
     */
    public static function getRegisterArgs(int $userId, string $username, string $displayName): stdClass
    {
        $webAuthn = self::getWebAuthn();

        // Exclude the passkeys already registered (prevents registering the same device again)
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
            60,      // 60 second timeout
            true,    // Resident key (discoverable / username-less sign-in)
            'required',  // Biometric / PIN required: a passkey alone signs in without a password
            null,    // platform or cross-platform
            $excludeIds
        );

        // Keep the challenge in the session
        $_SESSION['webauthn_reg_challenge'] = $webAuthn->getChallenge()->getBinaryString();
        $_SESSION['webauthn_reg_user_id'] = $userId;

        return $createArgs->publicKey;
    }

    /**
     * Verifies the passkey registration response from the browser and stores it in the database.
     * @return array{success:bool, error?:string, id?:int}
     */
    public static function processRegister(
        int $userId,
        string $clientDataJSON,
        string $attestationObject,
        string $deviceName = 'Passkey'
    ): array {
        if (empty($_SESSION['webauthn_reg_challenge']) || empty($_SESSION['webauthn_reg_user_id'])) {
            return ['success' => false, 'error' => t('srv_passkey.err_reg_session')];
        }

        if ((int)$_SESSION['webauthn_reg_user_id'] !== $userId) {
            return ['success' => false, 'error' => t('srv_passkey.err_user_mismatch')];
        }

        $challenge = $_SESSION['webauthn_reg_challenge'];

        try {
            // Convert the base64 data from the browser to binary/raw form
            $rawClientDataJSON = base64_decode($clientDataJSON, true);
            if ($rawClientDataJSON === false || !str_starts_with(trim($rawClientDataJSON), '{')) {
                $rawClientDataJSON = $clientDataJSON;
            }

            $rawAttestationObject = base64_decode($attestationObject, true);
            if ($rawAttestationObject === false) {
                $rawAttestationObject = $attestationObject;
            }

            $webAuthn = self::getWebAuthn();
            $data = $webAuthn->processCreate(
                $rawClientDataJSON,
                $rawAttestationObject,
                $challenge,
                true,  // requireUserVerification — parmak izi/PIN olmadan kaydedilemez
                true,  // requireUserPresent
                false, // failIfRootMismatch (lenient for self-signed/local keys)
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
                writeAuditLog($userId, 'sys_user_passkeys', $insertedId, "User '{$un}' added a passkey: {$cleanDeviceName}", 'passkey_register');
            }

            return ['success' => true, 'id' => $insertedId];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => sprintf(t('srv_passkey.err_register'), $e->getMessage())];
        }
    }

    /**
     * Creates the request parameters sent to the browser for a passkey sign-in.
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
                if ($raw === false) {
                    $raw = base64_decode(strtr($cid, '-_', '+/'), true);
                }
                if ($raw !== false) {
                    $allowedCredentials[] = $raw;
                }
            }
        }

        // With empty $allowedCredentials it works in resident-key mode (the device lists the registered accounts)
        $getArgs = $webAuthn->getGetArgs(
            $allowedCredentials,
            60,          // 60 sn timeout
            true, true, true, true, true, // usb, nfc, ble, hybrid, internal
            'required'   // requireUserVerification
        );

        $_SESSION['webauthn_auth_challenge'] = $webAuthn->getChallenge()->getBinaryString();
        return $getArgs->publicKey;
    }

    /**
     * Verifies the passkey sign-in response from the browser and signs the user in.
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
            return ['success' => false, 'error' => t('srv_passkey.err_timeout')];
        }

        // The challenge is single-use: it is dropped on a failed attempt too
        // (it used to be deleted only on success, so the same challenge could be retried).
        $challenge = $_SESSION['webauthn_auth_challenge'];
        unset($_SESSION['webauthn_auth_challenge']);

        // Build both the standard base64 and the URL-safe base64 variants
        $stdBase64 = strtr($credentialIdBase64, '-_', '+/');
        $pad = strlen($stdBase64) % 4;
        if ($pad > 0) {
            $stdBase64 .= str_repeat('=', 4 - $pad);
        }
        $b64Url = rtrim(strtr($credentialIdBase64, '+/', '-_'), '=');

        $db = getDB();
        $stmt = $db->prepare('
            SELECT p.id as passkey_id, p.user_id, p.credential_id, p.public_key, p.counter, p.device_name,
                   u.id, u.username, u.full_name, u.role, u.extension, u.theme_preference,
                   u.language_preference, u.is_active, u.must_reset_password
            FROM sys_user_passkeys p
            JOIN sys_users u ON p.user_id = u.id
            WHERE p.credential_id = ? OR p.credential_id = ?
        ');
        $stmt->execute([$stdBase64, $b64Url]);
        $passkey = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$passkey) {
            return ['success' => false, 'error' => t('srv_passkey.err_unknown')];
        }

        if ((int)$passkey['is_active'] !== 1) {
            return ['success' => false, 'error' => t('srv_passkey.err_inactive')];
        }

        try {
            // Convert the base64 data from the browser to binary/raw form
            $rawClientDataJSON = base64_decode($clientDataJSON, true);
            if ($rawClientDataJSON === false || !str_starts_with(trim($rawClientDataJSON), '{')) {
                $rawClientDataJSON = $clientDataJSON;
            }
            $rawAuthenticatorData = base64_decode($authenticatorData, true) ?: $authenticatorData;
            $rawSignature = base64_decode($signature, true) ?: $signature;

            // Platform keys (Android, Apple, Windows Hello) return signCount = 0.
            // When the device sends signCount = 0, the counter is not compared (null is passed).
            $prevCounter = (int)$passkey['counter'];
            try {
                $authObj = new \lbuchs\WebAuthn\Attestation\AuthenticatorData($rawAuthenticatorData);
                if ($authObj->getSignCount() === 0) {
                    $prevCounter = null;
                }
            } catch (\Exception $e) {
                // ignore
            }

            $webAuthn = self::getWebAuthn();
            $webAuthn->processGet(
                $rawClientDataJSON,
                $rawAuthenticatorData,
                $rawSignature,
                $passkey['public_key'],
                $challenge,
                $prevCounter,
                // A passkey replaces the password and 2FA: merely having the key
                // must not be enough (a stolen security key alone must not sign in).
                true,  // requireUserVerification
                true   // requireUserPresent
            );

            // Update the counter: store it only when a hardware key (YubiKey etc.) returns an increasing counter;
            // keep 0 for platform keys that return 0 (no artificial increment).
            $signCount = $webAuthn->getSignatureCounter();
            $newCounter = ($signCount !== null && $signCount > 0) ? $signCount : 0;

            $upd = $db->prepare('UPDATE sys_user_passkeys SET counter = ?, last_used_at = NOW() WHERE id = ?');
            $upd->execute([$newCounter, $passkey['passkey_id']]);

            // Sign-in succeeded: create the session
            session_regenerate_id(true);

            $_SESSION['user_id'] = $passkey['user_id'];
            $_SESSION['username'] = $passkey['username'];
            $_SESSION['full_name'] = $passkey['full_name'];
            $_SESSION['user_role'] = $passkey['role'];
            $_SESSION['extension'] = $passkey['extension'];
            $_SESSION['last_activity'] = time();
            $_SESSION['theme'] = $passkey['theme_preference'] ?? 'light';
            $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$passkey['language_preference'] ?? '']))
                ? $passkey['language_preference']
                : DEFAULT_UI_LANGUAGE;

            unset($_SESSION['webauthn_auth_challenge']);
            unset($_SESSION['captcha_num1'], $_SESSION['captcha_num2']);

            // Log the successful sign-in
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($clientIp, $passkey['username'], 'SUCCESS');
            }
            if (function_exists('writeAuditLog')) {
                writeAuditLog($passkey['user_id'], 'sys_users', $passkey['user_id'], "User '{$passkey['username']}' signed in with a passkey ({$passkey['device_name']}).", 'passkey_login');
            }

            $redirect = roleHomePath($passkey['role']);

            return ['success' => true, 'redirect' => $redirect];
        } catch (\Exception $e) {
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($clientIp, $passkey['username'] ?? 'UNKNOWN_PASSKEY', 'FAILED');
            }
            return ['success' => false, 'error' => sprintf(t('srv_passkey.err_verify'), $e->getMessage())];
        }
    }

    /**
     * Returns the user's registered passkeys.
     */
    public static function getUserPasskeys(int $userId): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, credential_id, counter, device_name, created_at, last_used_at FROM sys_user_passkeys WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Deletes a passkey.
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
            writeAuditLog($userId, 'sys_user_passkeys', $passkeyId, "User deleted the passkey '{$row['device_name']}'.", 'passkey_delete');
        }

        return true;
    }
}
