<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../audit_log.php';
require_once __DIR__ . '/../internal_numbers.php';
require_once __DIR__ . '/ConferenceService.php';
require_once __DIR__ . '/MailTemplateService.php';
require_once __DIR__ . '/ForgotPasswordService.php';

/**
 * Service for Video Meetings & Conference Scheduling.
 * Manages scheduled meetings, guest magic links, invitations, and iCalendar (.ics) generators.
 */
class MeetingService
{
    /** A meeting can be joined this long before its scheduled start. */
    public const JOIN_EARLY_SECONDS = 1800;
    /** A meeting can be joined this long after its scheduled end, for meetings that run over. */
    public const JOIN_LATE_SECONDS = 3600;

    /**
     * Get list of meetings with optional status filter.
     *
     * @return list<array<string, mixed>>
     */
    public static function getMeetings(?string $status = null): array
    {
        $db = getDB();
        if ($status !== null) {
            $stmt = $db->prepare('SELECT m.*, u.full_name AS host_name,
                    (SELECT COUNT(*) FROM pbx_meeting_invites WHERE meeting_id = m.id) AS invite_count,
                    (SELECT c.admin_pin FROM pbx_conferences c WHERE BINARY c.room_number = BINARY m.room_number LIMIT 1) AS host_pin
                FROM pbx_meetings m
                LEFT JOIN sys_users u ON m.host_user_id = u.id
                WHERE m.status = ?
                ORDER BY m.scheduled_start DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $db->query('SELECT m.*, u.full_name AS host_name,
                    (SELECT COUNT(*) FROM pbx_meeting_invites WHERE meeting_id = m.id) AS invite_count,
                    (SELECT c.admin_pin FROM pbx_conferences c WHERE BINARY c.room_number = BINARY m.room_number LIMIT 1) AS host_pin
                FROM pbx_meetings m
                LEFT JOIN sys_users u ON m.host_user_id = u.id
                ORDER BY m.scheduled_start DESC');
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get meeting by ID or meeting token.
     */
    public static function getMeeting(int|string $identifier): ?array
    {
        $db = getDB();
        if (is_numeric($identifier)) {
            $stmt = $db->prepare('SELECT m.*, u.full_name AS host_name, u.email AS host_email,
                    (SELECT c.admin_pin FROM pbx_conferences c WHERE BINARY c.room_number = BINARY m.room_number LIMIT 1) AS host_pin
                FROM pbx_meetings m
                LEFT JOIN sys_users u ON m.host_user_id = u.id
                WHERE m.id = ?');
            $stmt->execute([(int)$identifier]);
        } else {
            $stmt = $db->prepare('SELECT m.*, u.full_name AS host_name, u.email AS host_email,
                    (SELECT c.admin_pin FROM pbx_conferences c WHERE BINARY c.room_number = BINARY m.room_number LIMIT 1) AS host_pin
                FROM pbx_meetings m
                LEFT JOIN sys_users u ON m.host_user_id = u.id
                WHERE m.meeting_token = ?');
            $stmt->execute([(string)$identifier]);
        }
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Get invites for a specific meeting.
     *
     * @return list<array<string, mixed>>
     */
    public static function getInvites(int $meetingId): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM pbx_meeting_invites WHERE meeting_id = ? ORDER BY id ASC');
        $stmt->execute([$meetingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Resolve a guest or meeting token.
     * Returns meeting info and invite details if guest token was used.
     *
     * @return array{meeting: array<string, mixed>, invite: ?array<string, mixed>, role: string}|null
     */
    public static function resolveToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $db = getDB();

        // 1. Check if token is a guest magic token
        $stmt = $db->prepare('SELECT i.*, m.meeting_token, m.title, m.room_number, m.pin,
                m.scheduled_start, m.scheduled_end, m.video_mode, m.status AS meeting_status,
                m.allow_guests
            FROM pbx_meeting_invites i
            JOIN pbx_meetings m ON i.meeting_id = m.id
            WHERE i.guest_token = ?');
        $stmt->execute([$token]);
        $guestRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($guestRow) {
            $meeting = self::getMeeting((int)$guestRow['meeting_id']);
            if (!$meeting || $meeting['status'] === 'cancelled') {
                return null;
            }
            return [
                'meeting' => $meeting,
                'invite' => $guestRow,
                'role' => 'guest',
            ];
        }

        // 2. Check if token is meeting_token
        $meeting = self::getMeeting($token);
        if ($meeting && $meeting['status'] !== 'cancelled') {
            return [
                'meeting' => $meeting,
                'invite' => null,
                'role' => 'member',
            ];
        }

        return null;
    }

    /**
     * Whether a meeting can be joined now: open, not_started, ended or cancelled.
     *
     * @param array<string, mixed> $meeting
     */
    public static function joinState(array $meeting, ?int $now = null): string
    {
        if (($meeting['status'] ?? '') === 'cancelled') {
            return 'cancelled';
        }
        $now ??= time();
        $start = strtotime((string)($meeting['scheduled_start'] ?? ''));
        $end = strtotime((string)($meeting['scheduled_end'] ?? ''));
        if ($start !== false && $now < $start - self::JOIN_EARLY_SECONDS) {
            return 'not_started';
        }
        if ($end !== false && $now > $end + self::JOIN_LATE_SECONDS) {
            return 'ended';
        }
        return 'open';
    }

    /**
     * Join key of a signed-in portal user: lets staff into a meeting that takes no guests,
     * where the meeting link alone is not enough.
     *
     * @param array<string, mixed> $meeting
     */
    public static function staffJoinKey(array $meeting): string
    {
        return 's_' . substr(hash_hmac('sha256', 'staff|' . (string)($meeting['meeting_token'] ?? ''), getMeetingGuestSipSecret()), 0, 32);
    }

    /**
     * The token the browser sends with its INVITE, checked again by joinCheck() for that call.
     *
     * @param array<string, mixed> $meeting
     */
    public static function sipJoinToken(array $meeting, string $pageToken, string $role, bool $signedIn): string
    {
        return ($role === 'member' && $signedIn) ? self::staffJoinKey($meeting) : $pageToken;
    }

    /**
     * Asterisk's check for a browser participant: may this token enter this room now?
     * Every /meet page shares one SIP password, so this is what ties a caller to one meeting.
     *
     * @return int Participant limit of the meeting, 0 when the call must be refused.
     */
    public static function joinCheck(string $token, string $room): int
    {
        if ($token === '' || $room === '') {
            return 0;
        }
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM pbx_meetings WHERE room_number = ? AND status <> "cancelled"');
        $stmt->execute([$room]);
        $invite = $db->prepare('SELECT COUNT(*) FROM pbx_meeting_invites WHERE meeting_id = ? AND guest_token = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $meeting) {
            if (self::joinState($meeting) !== 'open') {
                continue;
            }
            $allowed = hash_equals(self::staffJoinKey($meeting), $token)
                || (!empty($meeting['allow_guests']) && hash_equals((string)$meeting['meeting_token'], $token));
            if (!$allowed) {
                $invite->execute([(int)$meeting['id'], $token]);
                $allowed = (int)$invite->fetchColumn() > 0;
            }
            if ($allowed) {
                return max(1, (int)$meeting['max_participants']);
            }
        }
        return 0;
    }

    /**
     * Schedule or update a meeting.
     *
     * @param array<string, mixed> $input
     * @param int|null $hostUserId
     * @return array{success: bool, meeting_id?: int, error?: string}
     */
    public static function saveMeeting(array $input, ?int $hostUserId = null): array
    {
        $id = (int)($input['id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => t('meetings.err_title_required')];
        }

        $desc = trim((string)($input['description'] ?? ''));
        $startStr = trim((string)($input['scheduled_start'] ?? $input['starts_at'] ?? ''));
        $endStr = trim((string)($input['scheduled_end'] ?? $input['ends_at'] ?? ''));

        $startTs = strtotime($startStr);
        $endTs = $endStr !== '' ? strtotime($endStr) : ($startTs ? $startTs + 3600 : false);

        if (!$startTs || !$endTs || $endTs <= $startTs) {
            return ['success' => false, 'error' => t('meetings.err_invalid_dates')];
        }

        $start = date('Y-m-d H:i:s', $startTs);
        $end = date('Y-m-d H:i:s', $endTs);

        $videoMode = (string)($input['video_mode'] ?? 'sfu');
        if (!in_array($videoMode, ['sfu', 'follow_talker', 'none'], true)) {
            $videoMode = 'sfu';
        }

        $pin = preg_replace('/[^0-9]/', '', trim((string)($input['pin'] ?? $input['user_pin'] ?? ''))) ?? '';
        $hasHostPinInput = array_key_exists('host_pin', $input) || array_key_exists('admin_pin', $input);
        $hostPin = preg_replace('/[^0-9]/', '', trim((string)($input['host_pin'] ?? $input['admin_pin'] ?? ''))) ?? '';
        $allowGuests = array_key_exists('allow_guests', $input) ? (empty($input['allow_guests']) ? 0 : 1) : 1;
        $maxParticipants = max(2, min(100, (int)($input['max_participants'] ?? 25)));

        $db = getDB();

        if ($id > 0) {
            $existing = self::getMeeting($id);
            if (!$existing) {
                return ['success' => false, 'error' => t('meetings.err_not_found')];
            }
            $roomNumber = (string)($existing['room_number'] ?? '');
            if (!$hasHostPinInput) {
                $hostPin = (string)($existing['host_pin'] ?? '');
            }
            $stmt = $db->prepare('UPDATE pbx_meetings SET
                title = ?, description = ?, pin = ?, scheduled_start = ?, scheduled_end = ?,
                video_mode = ?, max_participants = ?, allow_guests = ?
                WHERE id = ?');
            $stmt->execute([$title, $desc, $pin, $start, $end, $videoMode, $maxParticipants, $allowGuests, $id]);
            $meetingId = $id;
            writeAuditLog('pbx', 'meeting', (string)$meetingId, "Updated video meeting {$title} (ID: {$meetingId})", 'update', $hostUserId);
        } else {
            $token = 'm_' . bin2hex(random_bytes(16));
            $customRoom = preg_replace('/[^0-9]/', '', (string)($input['room_number'] ?? '')) ?? '';
            if ($customRoom !== '') {
                // A meeting writes its title and PINs into the room, so it never takes over a
                // conference room or another meeting's room.
                if (!self::roomIsFree($customRoom)) {
                    return ['success' => false, 'error' => sprintf(t('meetings.err_room_in_use'), $customRoom)];
                }
                $owner = internalNumberOwner($customRoom, 'conference', self::conferenceIdForRoom($customRoom));
                if ($owner !== null) {
                    return ['success' => false, 'error' => sprintf(t('internal_number.err_used'), $customRoom, $owner)];
                }
                $roomNumber = $customRoom;
            } else {
                $roomNumber = '';
                for ($attempt = 0; $attempt < 30; $attempt++) {
                    $candidate = '8' . str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
                    if (self::roomIsFree($candidate) && internalNumberOwner($candidate, 'conference', self::conferenceIdForRoom($candidate)) === null) {
                        $roomNumber = $candidate;
                        break;
                    }
                }
                if ($roomNumber === '') {
                    return ['success' => false, 'error' => t('meetings.err_no_free_room')];
                }
            }
            $stmt = $db->prepare('INSERT INTO pbx_meetings (
                meeting_token, title, description, host_user_id, room_number, pin,
                scheduled_start, scheduled_end, video_mode, max_participants, allow_guests, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "scheduled")');
            $stmt->execute([
                $token, $title, $desc, $hostUserId, $roomNumber, $pin,
                $start, $end, $videoMode, $maxParticipants, $allowGuests
            ]);
            $meetingId = (int)$db->lastInsertId();
            writeAuditLog('pbx', 'meeting', (string)$meetingId, "Created video meeting {$title} (Token: {$token})", 'create', $hostUserId);
        }

        // Keep pbx_conferences in sync so ConfBridge dialplan and bridge profile exist.
        // No join announcements: ConfBridge records each person's name and plays the prompts
        // before they enter the bridge, which kept video away for over 30 seconds.
        if ($roomNumber !== '') {
            $confStmt = $db->prepare('SELECT id FROM pbx_conferences WHERE room_number = ? LIMIT 1');
            $confStmt->execute([$roomNumber]);
            $confId = $confStmt->fetchColumn();
            if ($confId) {
                $db->prepare('UPDATE pbx_conferences SET title = ?, user_pin = ?, admin_pin = ?, video_mode = ?, max_video_streams = 10, is_active = 1, announce_join_leave = 0, announce_user_count = 0 WHERE id = ?')
                   ->execute([$title, $pin, $hostPin, $videoMode, $confId]);
            } else {
                $db->prepare('INSERT INTO pbx_conferences (room_number, internal_number, title, user_pin, admin_pin, video_mode, max_video_streams, is_active, announce_join_leave, announce_user_count) VALUES (?, ?, ?, ?, ?, ?, 10, 1, 0, 0)')
                   ->execute([$roomNumber, $roomNumber, $title, $pin, $hostPin, $videoMode]);
                $confId = (int)$db->lastInsertId();
            }
            markPendingSync('conferences', 'conference', (int)$confId, "{$title} ({$roomNumber})", 'update', $hostUserId);
            markPendingSync('internal_numbers', 'conference', (int)$confId, "{$title} ({$roomNumber})", 'update', $hostUserId);
            // The link goes out right after saving, and a room missing from the dialplan refuses
            // its guests: the room goes live now, without waiting for an administrator's Apply.
            try {
                syncConferences();
            } catch (\Throwable $e) {
                error_log('meetings: room ' . $roomNumber . ' is waiting for Apply: ' . $e->getMessage());
            }
        }

        // Process invites if emails provided
        $invitesRaw = (string)($input['invite_emails'] ?? $input['invites_emails'] ?? '');
        if ($invitesRaw !== '') {
            $emails = preg_split('/[\s,;]+/', $invitesRaw) ?: [];
            foreach ($emails as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    self::addInvite($meetingId, $email);
                }
            }
        }

        return ['success' => true, 'meeting_id' => $meetingId, 'message' => t('meetings.msg_saved')];
    }

    /**
     * Free for a new meeting: no conference room with this number, or only the deactivated room
     * of cancelled meetings.
     */
    private static function roomIsFree(string $room): bool
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT is_active FROM pbx_conferences WHERE room_number = ? LIMIT 1');
        $stmt->execute([$room]);
        $isActive = $stmt->fetchColumn();
        $stmt = $db->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(status <> "cancelled"), 0) AS live FROM pbx_meetings WHERE room_number = ?');
        $stmt->execute([$room]);
        $meetings = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'live' => 0];
        if ((int)$meetings['live'] > 0) {
            return false;
        }
        if ($isActive === false) {
            return true;
        }
        return (int)$isActive === 0 && (int)$meetings['total'] > 0;
    }

    private static function conferenceIdForRoom(string $room): int
    {
        $stmt = getDB()->prepare('SELECT id FROM pbx_conferences WHERE room_number = ? LIMIT 1');
        $stmt->execute([$room]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Add invite to a meeting and dispatch invitation email.
     *
     * @return array{success: bool, invite_id?: int, guest_token?: string, error?: string}
     */
    public static function addInvite(int $meetingId, string $email, string $name = ''): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => t('meetings.err_invalid_email')];
        }

        $db = getDB();
        // Check if already invited
        $stmt = $db->prepare('SELECT id, guest_token FROM pbx_meeting_invites WHERE meeting_id = ? AND email = ?');
        $stmt->execute([$meetingId, $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $guestToken = (string)$existing['guest_token'];
            $inviteId = (int)$existing['id'];
        } else {
            $guestToken = 'g_' . bin2hex(random_bytes(16));
            $ins = $db->prepare('INSERT INTO pbx_meeting_invites (meeting_id, email, name, guest_token, status) VALUES (?, ?, ?, ?, "invited")');
            $ins->execute([$meetingId, $email, $name, $guestToken]);
            $inviteId = (int)$db->lastInsertId();
        }

        // Send email
        self::sendInviteEmail($meetingId, $email, $guestToken, $name);

        return ['success' => true, 'invite_id' => $inviteId, 'guest_token' => $guestToken, 'message' => t('meetings.msg_invite_sent')];
    }

    /**
     * Cancel a meeting.
     */
    public static function cancelMeeting(int $meetingId, ?int $userId = null): bool
    {
        $db = getDB();
        $meeting = self::getMeeting($meetingId);
        $stmt = $db->prepare('UPDATE pbx_meetings SET status = "cancelled" WHERE id = ?');
        $ok = $stmt->execute([$meetingId]);
        if ($ok) {
            if ($meeting && !empty($meeting['room_number'])) {
                $roomNumber = (string)$meeting['room_number'];
                $activeStmt = $db->prepare('SELECT COUNT(*) FROM pbx_meetings WHERE room_number = ? AND status != "cancelled" AND id != ?');
                $activeStmt->execute([$roomNumber, $meetingId]);
                if ((int)$activeStmt->fetchColumn() === 0) {
                    $confStmt = $db->prepare('SELECT id, title FROM pbx_conferences WHERE room_number = ? LIMIT 1');
                    $confStmt->execute([$roomNumber]);
                    $confRow = $confStmt->fetch(PDO::FETCH_ASSOC);
                    if ($confRow && !ConferenceService::isReferencedByRouting((int)$confRow['id'])) {
                        $db->prepare('UPDATE pbx_conferences SET is_active = 0 WHERE id = ?')->execute([(int)$confRow['id']]);
                        markPendingSync('conferences', 'conference', (int)$confRow['id'], "{$confRow['title']} ({$roomNumber})", 'delete', $userId);
                        markPendingSync('internal_numbers', 'conference', (int)$confRow['id'], "{$confRow['title']} ({$roomNumber})", 'delete', $userId);
                    }
                }
            }
            writeAuditLog('pbx', 'meeting', (string)$meetingId, "Cancelled video meeting ID {$meetingId}", 'delete', $userId);
        }
        return $ok;
    }

    /**
     * Build absolute public meeting URL for a token.
     */
    public static function getMeetingUrl(string $token): string
    {
        $baseUrl = rtrim(ForgotPasswordService::portalUrl(), '/');
        return "{$baseUrl}/meet/{$token}";
    }

    /**
     * Build iCalendar (.ics) content for the meeting.
     */
    public static function generateIcs(array $meeting, ?string $joinUrl = null): string
    {
        $joinUrl = $joinUrl ?: self::getMeetingUrl((string)($meeting['meeting_token'] ?? ''));
        $title = preg_replace('/[^\x20-\x7E]/', '', (string)$meeting['title']);
        $desc = preg_replace('/[^\x20-\x7E]/', '', (string)($meeting['description'] ?? ''));
        $startUtc = gmdate('Ymd\THis\Z', strtotime((string)$meeting['scheduled_start']));
        $endUtc = gmdate('Ymd\THis\Z', strtotime((string)$meeting['scheduled_end']));
        $createdUtc = gmdate('Ymd\THis\Z', strtotime((string)($meeting['created_at'] ?? 'now')));
        $uid = $meeting['meeting_token'] . '@' . (portalEnv('PORTAL_DOMAIN') ?: 'aipbx.local');

        return "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//AiPBX//Video Meetings//EN\r\n" .
            "CALSCALE:GREGORIAN\r\n" .
            "METHOD:REQUEST\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:{$uid}\r\n" .
            "DTSTAMP:{$createdUtc}\r\n" .
            "DTSTART:{$startUtc}\r\n" .
            "DTEND:{$endUtc}\r\n" .
            "SUMMARY:{$title}\r\n" .
            "DESCRIPTION:{$desc}\\n\\nJoin Link: {$joinUrl}\r\n" .
            "URL:{$joinUrl}\r\n" .
            "STATUS:CONFIRMED\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n";
    }

    /**
     * Send email invitation with .ics attachment.
     */
    public static function sendInviteEmail(int $meetingId, string $toEmail, string $token, string $name = ''): bool
    {
        $meeting = self::getMeeting($meetingId);
        if (!$meeting) {
            return false;
        }

        $joinUrl = self::getMeetingUrl($token);
        $brand = (string)getSystemSetting('brand_title', 'AiPBX');
        $lang = MailTemplateService::languageFor($toEmail);

        $vars = [
            'name' => $name !== '' ? $name : $toEmail,
            'title' => (string)$meeting['title'],
            'start_time' => (string)$meeting['scheduled_start'],
            'end_time' => (string)$meeting['scheduled_end'],
            'host_name' => (string)($meeting['host_name'] ?? $brand),
            'join_link' => $joinUrl,
            'pin' => ($meeting['pin'] ?? '') !== '' ? (string)$meeting['pin'] : '-',
            'brand' => $brand,
        ];

        $blocks = MailTemplateService::inLanguage($lang, fn() => [
            'join_button' => MailTemplateService::buttonBlock($joinUrl, t('meetings.btn_join_meeting')),
        ]);

        $ics = self::generateIcs($meeting, $joinUrl);
        $tmpIcs = tempnam(sys_get_temp_dir(), 'aipbx_ics_');
        $attachments = [];
        if ($tmpIcs !== false) {
            file_put_contents($tmpIcs, $ics);
            $attachments[] = [
                'path' => $tmpIcs,
                'name' => 'meeting.ics',
                'type' => 'text/calendar',
            ];
        }

        try {
            $res = MailTemplateService::send('meeting_invite', $toEmail, $lang, $vars, $blocks, $attachments);
            return !empty($res['success']);
        } finally {
            if ($tmpIcs !== false && is_file($tmpIcs)) {
                @unlink($tmpIcs);
            }
        }
    }
}
