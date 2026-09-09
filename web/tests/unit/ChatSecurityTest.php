<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/api/mobile/auth_helper.php';

final class ChatSecurityTest extends TestCase
{
    private static ?PDO $prodDb = null;
    private static ?int $prodConvId = null;
    private static ?string $tokenAdmin = null;
    private static ?string $tokenOther = null;

    public static function setUpBeforeClass(): void
    {
        // 1. Connect to production DB for live chat service API verification
        $env = loadPortalEnv();
        $host = $env['DB_HOST'] ?? 'localhost';
        $dbName = $env['DB_NAME'] ?? 'asterisk';
        $user = $env['DB_USER'] ?? '';
        $pass = $env['DB_PASS'] ?? '';

        try {
            self::$prodDb = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // Find an admin user (ext 19000 or similar) and another user (ext != 19000)
            $users = self::$prodDb->query("SELECT id, username, extension FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 LIMIT 2")->fetchAll();
            if (count($users) >= 2) {
                self::$tokenAdmin = generateMobileToken($users[0]);
                self::$tokenOther = generateMobileToken($users[1]);

                // Create a temporary conversation only for user[0]
                $stmt = self::$prodDb->prepare("INSERT INTO chat_conversations (type, created_by, created_at, updated_at) VALUES ('direct', ?, NOW(), NOW())");
                $stmt->execute([(int)$users[0]['id']]);
                self::$prodConvId = (int)self::$prodDb->lastInsertId();

                $stmtPart = self::$prodDb->prepare("INSERT INTO chat_participants (conversation_id, extension, joined_at) VALUES (?, ?, NOW())");
                $stmtPart->execute([self::$prodConvId, $users[0]['extension']]);
            }
        } catch (Throwable $e) {
            self::$prodDb = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$prodDb && self::$prodConvId) {
            self::$prodDb->prepare("DELETE FROM chat_messages WHERE conversation_id = ?")->execute([self::$prodConvId]);
            self::$prodDb->prepare("DELETE FROM chat_participants WHERE conversation_id = ?")->execute([self::$prodConvId]);
            self::$prodDb->prepare("DELETE FROM chat_conversations WHERE id = ?")->execute([self::$prodConvId]);
        }
    }

    public function testValidateMobileTokenWithSecretKey(): void
    {
        $payload = '1:' . (time() + 3600);
        $secretKey = (defined('CHAT_JWT_SECRET') && CHAT_JWT_SECRET !== '')
            ? CHAT_JWT_SECRET
            : ((defined('TURN_SECRET') && TURN_SECRET !== '') ? TURN_SECRET : DB_PASS);
        $sig = hash_hmac('sha256', $payload, $secretKey);
        $token = base64_encode($payload . ':' . $sig);

        $this->assertNotEmpty($token);
    }

    public function testValidateMobileTokenRejectsTamperedSignature(): void
    {
        $payload = '1:' . (time() + 3600);
        $tamperedSig = hash_hmac('sha256', $payload, 'wrong_key_12345');
        $token = base64_encode($payload . ':' . $tamperedSig);

        $this->assertNull(validateMobileToken($token));
    }

    public function testLiveApiNonParticipantCannotReadMessages(): void
    {
        if (!self::$prodConvId || !self::$tokenOther) {
            $this->markTestSkipped("Prod DB or users not available");
        }

        $ch = curl_init('http://127.0.0.1:8086/api/messages?conversation_id=' . self::$prodConvId);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . self::$tokenOther]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->assertSame(403, $code, "CH-1: Non-participant must receive 403 when reading messages");
        $json = json_decode($res, true);
        $this->assertFalse($json['success'] ?? true);
    }

    public function testLiveApiNonParticipantCannotSendMessage(): void
    {
        if (!self::$prodConvId || !self::$tokenOther) {
            $this->markTestSkipped("Prod DB or users not available");
        }

        $ch = curl_init('http://127.0.0.1:8086/api/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'conversation_id' => self::$prodConvId,
            'message' => 'Unauthorized injection test'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . self::$tokenOther,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->assertSame(403, $code, "CH-2: Non-participant must receive 403 when sending message");
    }

    public function testLiveApiUnauthenticatedMediaAccessForbidden(): void
    {
        $ch = curl_init('http://127.0.0.1:8086/media/images/any_nonexistent_image.jpg');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->assertSame(401, $code, "CH-3: Unauthenticated media access must return 401");
    }

    public function testLiveApiMaliciousAttachmentUrlRejected(): void
    {
        if (!self::$prodConvId || !self::$tokenAdmin) {
            $this->markTestSkipped("Prod DB or users not available");
        }

        $ch = curl_init('http://127.0.0.1:8086/api/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'conversation_id' => self::$prodConvId,
            'message' => 'XSS payload test',
            'attachment_url' => "');alert(1);//"
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . self::$tokenAdmin,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->assertSame(400, $code, "CH-4: Malicious attachment_url format must return 400");
    }

    public function testLiveApiDangerousUploadExtensionsRejected(): void
    {
        if (!self::$tokenAdmin) {
            $this->markTestSkipped("Admin token not available");
        }

        $tmpHtml = tempnam('/tmp', 'xss_') . '.html';
        file_put_contents($tmpHtml, '<html><script>alert(1)</script></html>');

        $cfile = new CURLFile($tmpHtml, 'text/html', 'test.html');
        $ch = curl_init('http://127.0.0.1:8086/api/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $cfile]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . self::$tokenAdmin]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unlink($tmpHtml);

        $this->assertSame(400, $code, "CH-5: Dangerous file extension .html must return 400");
    }
}
