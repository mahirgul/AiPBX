<?php

declare(strict_types=1);

/**
 * Conference calls started from a group chat. The chat service opens the call
 * (table chat_calls) and hands the room to the members; Asterisk asks here
 * whether a caller may enter and says when someone left.
 */
final class ChatCallService
{
    /** Dial prefix of a chat room: the apps call "cc<room>". */
    public const DIAL_PREFIX = 'cc';

    /** An open call nobody closed (Asterisk restarted) is not joined after this. */
    public const STALE_SECONDS = 21600;

    /** @return array{audio: int, video: int} participant limits */
    public static function limits(): array
    {
        return [
            'audio' => max(2, min(100, (int) getSystemSetting('chat_call_max_audio', '25'))),
            'video' => max(2, min(25, (int) getSystemSetting('chat_call_max_video', '8'))),
        ];
    }

    /** Extension of a PJSIP endpoint name ("1001-sip", "1001-webrtc", "1001-mob-webrtc"). */
    public static function extensionOfEndpoint(string $endpoint): string
    {
        return preg_match('/^([0-9]{2,10})-(sip|webrtc|mob-webrtc)$/', $endpoint, $m) ? $m[1] : '';
    }

    /**
     * May this endpoint enter this room now? "JOIN:<limit>:<video mode>" or "DENY".
     * The endpoint is the one Asterisk authenticated, never a number the caller sent.
     */
    public static function joinCheck(string $room, string $endpoint): string
    {
        $ext = self::extensionOfEndpoint($endpoint);
        if ($room === '' || $ext === '') {
            return 'DENY';
        }
        $st = getDB()->prepare(
            'SELECT c.id, c.video FROM chat_calls c
             JOIN chat_conversations v ON v.id = c.conversation_id AND v.is_deleted = 0
             JOIN chat_participants p ON p.conversation_id = c.conversation_id AND p.extension = ? AND p.left_at IS NULL
             WHERE c.room = ? AND c.ended_at IS NULL AND c.started_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $st->execute([$ext, $room, self::STALE_SECONDS]);
        $call = $st->fetch(PDO::FETCH_ASSOC);
        if (!$call) {
            return 'DENY';
        }
        $video = !empty($call['video']);
        return 'JOIN:' . self::limits()[$video ? 'video' : 'audio'] . ':' . ($video ? 'follow_talker' : 'none');
    }

    /**
     * Someone is in ($parties counts them) or has left the room. The call ends
     * when the last one left; the chat service then writes "call ended" to the group.
     */
    public static function parties(string $room, int $parties): void
    {
        $db = getDB();
        if ($parties > 0) {
            $db->prepare('UPDATE chat_calls SET peak_parties = GREATEST(peak_parties, ?) WHERE room = ? AND ended_at IS NULL')
                ->execute([$parties, $room]);
            return;
        }
        $end = $db->prepare('UPDATE chat_calls SET ended_at = NOW() WHERE room = ? AND ended_at IS NULL');
        $end->execute([$room]);
        if ($end->rowCount() > 0) {
            self::tellChat($room);
        }
    }

    private static function tellChat(string $room): void
    {
        require_once __DIR__ . '/FileStorageService.php';
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 2,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nX-AiPBX-Internal: " . FileStorageService::internalToken() . "\r\n",
            'content' => json_encode(['room' => $room]),
        ]]);
        @file_get_contents('http://127.0.0.1:8086/api/internal/call/ended', false, $ctx);
    }
}
