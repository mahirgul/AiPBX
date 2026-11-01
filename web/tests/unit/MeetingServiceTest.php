<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/MeetingService.php';

final class MeetingServiceTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $createdMeetingIds = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->createdMeetingIds = [];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdMeetingIds)) {
            $ids = implode(',', array_map('intval', $this->createdMeetingIds));
            $this->db->exec("DELETE FROM pbx_meeting_invites WHERE meeting_id IN ({$ids})");
            $this->db->exec("DELETE FROM pbx_meetings WHERE id IN ({$ids})");
        }
        $this->db->exec("DELETE FROM pbx_conferences WHERE room_number IN ('8991', '8992', '8993', '8994')");
    }

    public function testSaveMeetingValidation(): void
    {
        // Missing title
        $res = MeetingService::saveMeeting(['title' => '']);
        $this->assertFalse($res['success']);
        $this->assertNotEmpty($res['error']);

        // Invalid dates
        $res = MeetingService::saveMeeting([
            'title' => 'Test Meeting',
            'starts_at' => '2026-11-01 10:00:00',
            'ends_at' => '2026-11-01 09:00:00',
        ]);
        $this->assertFalse($res['success']);
    }

    public function testCreateMeetingAndSyncConfBridge(): void
    {
        $input = [
            'title' => 'Unit Test SFU Conference',
            'description' => 'Testing SFU video conference creation',
            'starts_at' => date('Y-m-d H:i:s', time() + 3600),
            'ends_at' => '', // Optional ends_at -> defaults to starts_at + 1h
            'video_mode' => 'sfu',
            'room_number' => '8991',
            'user_pin' => '1234',
            'host_pin' => '9999',
            'allow_guests' => 0,
            'invite_emails' => 'guest1@example.com, guest2@example.com',
        ];

        $res = MeetingService::saveMeeting($input, 1);
        $this->assertTrue($res['success']);
        $this->assertArrayHasKey('meeting_id', $res);
        $meetingId = (int)$res['meeting_id'];
        $this->createdMeetingIds[] = $meetingId;

        // Verify meeting in DB
        $meeting = MeetingService::getMeeting($meetingId);
        $this->assertNotNull($meeting);
        $this->assertSame('Unit Test SFU Conference', $meeting['title']);
        $this->assertSame('sfu', $meeting['video_mode']);
        $this->assertSame('8991', $meeting['room_number']);
        $this->assertSame('1234', $meeting['pin']);
        $this->assertSame('9999', $meeting['host_pin']);
        $this->assertSame(0, (int)$meeting['allow_guests']);

        // Verify synced conference in pbx_conferences
        $confStmt = $this->db->prepare('SELECT * FROM pbx_conferences WHERE room_number = ?');
        $confStmt->execute(['8991']);
        $conf = $confStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($conf);
        $this->assertSame('sfu', $conf['video_mode']);
        $this->assertSame('1234', $conf['user_pin']);
        $this->assertSame('9999', $conf['admin_pin']);

        // Verify invites were created
        $invites = MeetingService::getInvites($meetingId);
        $this->assertCount(2, $invites);
        $this->assertNotEmpty($invites[0]['guest_token']);
    }

    public function testResolveGuestTokenAndMeetingToken(): void
    {
        $input = [
            'title' => 'Token Resolution Test',
            'starts_at' => date('Y-m-d H:i:s', time() + 1800),
            'ends_at' => date('Y-m-d H:i:s', time() + 3600),
            'video_mode' => 'follow_talker',
            'room_number' => '8992',
        ];
        $res = MeetingService::saveMeeting($input, 1);
        $meetingId = (int)$res['meeting_id'];
        $this->createdMeetingIds[] = $meetingId;

        $meeting = MeetingService::getMeeting($meetingId);
        $this->assertNotNull($meeting);
        $meetingToken = (string)$meeting['meeting_token'];

        // Add a guest invite
        $inviteRes = MeetingService::addInvite($meetingId, 'client@partner.com', 'Client Name');
        $this->assertTrue($inviteRes['success']);
        $guestToken = (string)$inviteRes['guest_token'];

        // Resolve via guest token
        $resolvedGuest = MeetingService::resolveToken($guestToken);
        $this->assertNotNull($resolvedGuest);
        $this->assertSame('guest', $resolvedGuest['role']);
        $this->assertSame('client@partner.com', $resolvedGuest['invite']['email']);
        $this->assertSame('Token Resolution Test', $resolvedGuest['meeting']['title']);

        // Resolve via meeting token
        $resolvedMember = MeetingService::resolveToken($meetingToken);
        $this->assertNotNull($resolvedMember);
        $this->assertSame('member', $resolvedMember['role']);
        $this->assertNull($resolvedMember['invite']);

        // Invalid token returns null
        $this->assertNull(MeetingService::resolveToken('invalid_token_xyz'));
    }

    public function testGenerateIcsCalendar(): void
    {
        $input = [
            'title' => 'Q4 Board Meeting',
            'description' => 'Strategic planning and review',
            'starts_at' => '2026-12-15 14:00:00',
            'ends_at' => '2026-12-15 15:30:00',
            'video_mode' => 'sfu',
            'room_number' => '8993',
        ];
        $res = MeetingService::saveMeeting($input, 1);
        $meetingId = (int)$res['meeting_id'];
        $this->createdMeetingIds[] = $meetingId;

        $meeting = MeetingService::getMeeting($meetingId);
        $this->assertNotNull($meeting);

        $ics = MeetingService::generateIcs($meeting);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Q4 Board Meeting', $ics);
        $this->assertStringContainsString('DESCRIPTION:Strategic planning and review', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function testCancelMeeting(): void
    {
        $input = [
            'title' => 'Cancellation Test Meeting',
            'starts_at' => date('Y-m-d H:i:s', time() + 3600),
            'ends_at' => date('Y-m-d H:i:s', time() + 5400),
            'room_number' => '8994',
        ];
        $res = MeetingService::saveMeeting($input, 1);
        $meetingId = (int)$res['meeting_id'];
        $this->createdMeetingIds[] = $meetingId;

        $inviteRes = MeetingService::addInvite($meetingId, 'guest@cancel.com');
        $guestToken = (string)$inviteRes['guest_token'];

        $ok = MeetingService::cancelMeeting($meetingId, 1);
        $this->assertTrue($ok);

        $meeting = MeetingService::getMeeting($meetingId);
        $this->assertSame('cancelled', $meeting['status']);

        // Token resolution should return null for cancelled meetings
        $this->assertNull(MeetingService::resolveToken($guestToken));

        // Corresponding pbx_conferences row should be deactivated
        $confStmt = $this->db->prepare('SELECT is_active FROM pbx_conferences WHERE room_number = ?');
        $confStmt->execute(['8994']);
        $this->assertSame(0, (int)$confStmt->fetchColumn());
    }

    public function testMeetingInviteEmailTemplateAndGuestSipSecret(): void
    {
        $secret = getMeetingGuestSipSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $secret);

        $sample = MailTemplateService::sampleData('meeting_invite', 'en');
        $rendered = MailTemplateService::render('meeting_invite', 'en', $sample['vars'], $sample['blocks']);
        $this->assertStringContainsString('Weekly Team Sync', $rendered['subject']);
        $this->assertStringContainsString('Weekly Team Sync', $rendered['html']);
        $this->assertStringContainsString('/meet/g_sample_token', $rendered['html']);
    }

    public function testRoomNumberConflictAndConferenceReferenceProtection(): void
    {
        // 1. Find an existing user extension if any, or insert a temporary feature code to test collision
        $ext = $this->db->query("SELECT extension FROM sys_users WHERE extension IS NOT NULL AND extension != '' LIMIT 1")->fetchColumn();
        if ($ext) {
            $res = MeetingService::saveMeeting([
                'title'       => 'Collision Test',
                'starts_at'   => date('Y-m-d H:i:s', time() + 3600),
                'room_number' => (string)$ext,
            ], 1);
            $this->assertFalse($res['success']);
            $this->assertNotEmpty($res['error']);
        }

        // 2. Schedule a meeting on room 8995 and verify ConferenceService::deleteConference blocks deletion while active
        $res2 = MeetingService::saveMeeting([
            'title'       => 'Active Room Protection Test',
            'starts_at'   => date('Y-m-d H:i:s', time() + 3600),
            'room_number' => '8995',
        ], 1);
        $this->assertTrue($res2['success']);
        $meetingId = (int)$res2['meeting_id'];
        $this->createdMeetingIds[] = $meetingId;

        $confStmt = $this->db->prepare('SELECT id FROM pbx_conferences WHERE room_number = ? LIMIT 1');
        $confStmt->execute(['8995']);
        $confId = (int)$confStmt->fetchColumn();
        $this->assertGreaterThan(0, $confId);

        $_SESSION['csrf_token'] = 'test_csrf_token';
        $delRes = ConferenceService::deleteConference($confId, 'test_csrf_token');
        $this->assertFalse($delRes['success']);
        $this->assertStringContainsString('Video meetings', (string)($delRes['error'] ?? ''));

        // After cancelling the meeting, deleting the conference room succeeds
        MeetingService::cancelMeeting($meetingId, 1);
        $delResAfter = ConferenceService::deleteConference($confId, 'test_csrf_token');
        $this->assertTrue($delResAfter['success']);
    }

    public function testMeetingLobbyRendersCleanly(): void
    {
        require_once dirname(__DIR__, 2) . '/src/controllers/MeetingLobbyController.php';

        $res = MeetingService::saveMeeting([
            'title'        => 'Lobby Render Test',
            'starts_at'    => date('Y-m-d H:i:s', time() + 3600),
            'room_number'  => '8994',
            'allow_guests' => 1,
        ], 1);
        $this->assertTrue($res['success']);
        $meetingId = (int)$res['meeting_id'];
        $this->createdMeetingIds[] = $meetingId;

        $meeting = MeetingService::getMeeting($meetingId);
        $this->assertNotNull($meeting);
        $token = (string)$meeting['meeting_token'];

        ob_start();
        MeetingLobbyController::index($token);
        $html = (string)ob_get_clean();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Lobby Render Test', $html);
        $this->assertStringContainsString('/assets/css/fontawesome.min.css', $html);
        $this->assertStringNotContainsString('Fatal error', $html);
    }
}
