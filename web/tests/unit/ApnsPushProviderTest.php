<?php

use PHPUnit\Framework\TestCase;
use App\Services\Push\ApnsPushProvider;
use App\Services\Push\CompositePushProvider;
use App\Services\Push\PushProviderInterface;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/push/PushService.php';
require_once __DIR__ . '/../../src/services/PushSettingsService.php';

/**
 * iOS push (APNs): the ES256 provider token, the request the app receives
 * (chat category for the Reply action, VoIP push for calls) and the routing
 * between FCM and APNs when both channels are on.
 */
final class ApnsPushProviderTest extends TestCase
{
    private static function newEcKey(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        return [$pem, openssl_pkey_get_details($key)['key']];
    }

    private static function rawToDer(string $raw): string
    {
        $int = function (string $i): string {
            $i = ltrim($i, "\x00");
            if (ord($i[0]) & 0x80) {
                $i = "\x00" . $i;
            }
            return "\x02" . chr(strlen($i)) . $i;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    private function provider(string $pem = 'unused'): ApnsPushProvider
    {
        return new ApnsPushProvider('ABC123DEFG', 'TEAM123456', 'com.mhrgl.AiPBX', $pem, 'sandbox');
    }

    public function testJwtIsAValidEs256SignatureWithAppleClaims(): void
    {
        [$pem, $pub] = self::newEcKey();
        // Many runs: a DER integer with a leading zero or a short R/S must still give 64 bytes.
        for ($i = 0; $i < 50; $i++) {
            $jwt = ApnsPushProvider::signJwt($pem, 'ABC123DEFG', 'TEAM123456', 1700000000 + $i);
            [$h, $c, $s] = explode('.', $jwt);
            $raw = base64_decode(strtr($s, '-_', '+/'));
            $this->assertSame(64, strlen($raw));
            $this->assertSame(1, openssl_verify("$h.$c", self::rawToDer($raw), $pub, OPENSSL_ALGO_SHA256));
        }
        $this->assertSame(['alg' => 'ES256', 'kid' => 'ABC123DEFG'], json_decode(base64_decode(strtr($h, '-_', '+/')), true));
        $this->assertSame('TEAM123456', json_decode(base64_decode(strtr($c, '-_', '+/')), true)['iss']);
    }

    public function testChatMessageUsesTheReplyCategoryAndConversationKeys(): void
    {
        [$headers, $body] = $this->provider()->buildRequest([
            'action' => 'new_message',
            'title' => 'Ali',
            'body' => 'Merhaba',
            'conversation_id' => '12',
        ], false);

        $this->assertSame('com.mhrgl.AiPBX', $headers['apns-topic']);
        $this->assertSame('alert', $headers['apns-push-type']);
        $this->assertSame('chat_12', $headers['apns-collapse-id']);
        $this->assertSame('CHAT_MESSAGE', $body['aps']['category']);
        $this->assertSame('conv_12', $body['aps']['thread-id']);
        $this->assertSame(['title' => 'Ali', 'body' => 'Merhaba'], $body['aps']['alert']);
        // ChatNotifications.swift reads conversation_id as a number.
        $this->assertSame(12, $body['conversation_id']);
        $this->assertSame('Ali', $body['chat_title']);
    }

    public function testIncomingCallGoesAsVoipPushToTheVoipTopic(): void
    {
        [$headers, $body] = $this->provider()->buildRequest([
            'action' => 'incoming_call',
            'caller_id' => '1002',
            'caller_name' => 'Veli',
        ], true);

        $this->assertSame('com.mhrgl.AiPBX.voip', $headers['apns-topic']);
        $this->assertSame('voip', $headers['apns-push-type']);
        $this->assertArrayHasKey('apns-expiration', $headers);
        $this->assertSame('{}', json_encode($body['aps']));
        $this->assertSame('1002', $body['caller_id']);
        $this->assertSame('Veli', $body['caller_name']);
    }

    public function testIncomingCallWithoutVoipTokenFallsBackToAnAlert(): void
    {
        [$headers, $body] = $this->provider()->buildRequest([
            'action' => 'incoming_call',
            'caller_id' => '1002',
            'caller_name' => '',
        ], false);

        $this->assertSame('alert', $headers['apns-push-type']);
        $this->assertSame('1002', $body['aps']['alert']['body']);
        $this->assertNotSame('', $body['aps']['alert']['title']);
    }

    public function testDeviceTokenChoice(): void
    {
        $voip = str_repeat('ab', 32);
        $alert = str_repeat('cd', 32);
        $p = $this->provider();
        // A device without any APNs-shaped token is skipped (no network call).
        $this->assertNull($p->sendToDevice(['fcm_token' => 'fcm:APA91b', 'voip_token' => null], ['action' => 'new_message']));
        $this->assertTrue(ApnsPushProvider::looksLikeApnsToken($voip));
        $this->assertTrue(ApnsPushProvider::looksLikeApnsToken($alert));
        $this->assertFalse(ApnsPushProvider::looksLikeApnsToken('dGVzdA:APA91bHPRgkF'));
    }

    public function testCompositeRoutesRawTokensByShapeAndSumsDeliveries(): void
    {
        $fake = function (string $id) {
            return new class($id) implements PushProviderInterface {
                public array $tokens = [];
                public function __construct(private string $id) {}
                public function getIdentifier(): string { return $this->id; }
                public function isConfigured(): bool { return true; }
                public function sendToExtension(string $extension, array $payload = []): array
                {
                    return ['success' => true, 'delivered' => 1, 'failed' => 0, 'errors' => []];
                }
                public function sendToToken(string $token, array $payload = []): array
                {
                    $this->tokens[] = $token;
                    return ['success' => true, 'message' => $this->id];
                }
            };
        };
        $fcm = $fake('fcm');
        $apns = $fake('apns');
        $c = new CompositePushProvider([$fcm, $apns]);

        $this->assertSame('fcm+apns', $c->getIdentifier());
        $this->assertSame('apns', $c->sendToToken(str_repeat('0f', 32))['message']);
        $this->assertSame('fcm', $c->sendToToken('dGVzdA:APA91bHPRgkF')['message']);
        $this->assertSame(2, $c->sendToExtension('1001')['delivered']);
    }

    public function testSaveRejectsApnsWithoutIdsOrWithANonEcKey(): void
    {
        $_SESSION['user_id'] = null;
        $res = PushSettingsService::saveSettings([
            'push_enabled' => '1',
            'push_provider' => 'none',
            'push_apns_enabled' => '1',
            'push_apns_key_id' => 'short',
            'push_apns_team_id' => 'TEAM123456',
        ]);
        $this->assertFalse($res['success']);

        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $rsaPem);
        $res = PushSettingsService::saveSettings([
            'push_enabled' => '1',
            'push_provider' => 'none',
            'push_apns_enabled' => '1',
            'push_apns_key_id' => 'ABC123DEFG',
            'push_apns_team_id' => 'TEAM123456',
            'push_apns_key' => $rsaPem,
        ]);
        $this->assertFalse($res['success']);
    }

    public function testSaveKeepsTheStoredKeyWhenTheFieldIsEmpty(): void
    {
        $db = getDB();
        $_SESSION['user_id'] = null;
        [$pem] = self::newEcKey();
        $base = [
            'push_enabled' => '1',
            'push_provider' => 'none',
            'push_apns_enabled' => '1',
            'push_apns_key_id' => 'abc123defg',
            'push_apns_team_id' => 'TEAM123456',
            'push_apns_environment' => 'sandbox',
        ];

        $res = PushSettingsService::saveSettings($base + ['push_apns_key' => $pem]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $res = PushSettingsService::saveSettings($base);
        $this->assertTrue($res['success'], $res['error'] ?? '');

        $this->assertSame(trim($pem), (string) getSystemSetting('push_apns_key', ''));
        $this->assertSame('ABC123DEFG', (string) getSystemSetting('push_apns_key_id', ''));
        $this->assertSame('sandbox', (string) getSystemSetting('push_apns_environment', ''));

        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'push\\_apns\\_%' OR setting_key = 'push_enabled'");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_id = 'push_settings'");
    }
}
