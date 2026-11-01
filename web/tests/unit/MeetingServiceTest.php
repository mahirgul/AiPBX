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

        // Verify audit log has proper entity_id and action
        $auditRow = $this->db->query("SELECT * FROM sys_audit_log WHERE entity_type = 'meeting' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($auditRow);
        $this->assertSame((string)$meetingId, (string)$auditRow['entity_id']);
        $this->assertSame('create', (string)$auditRow['action']);

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
            'starts_at'    => date('Y-m-d H:i:s', time() + 600),
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

    private function meeting(array $over = []): array
    {
        $res = MeetingService::saveMeeting($over + [
            'title' => 'Join check',
            'scheduled_start' => date('Y-m-d H:i:s', time() - 60),
            'scheduled_end' => date('Y-m-d H:i:s', time() + 3600),
            'room_number' => '8991',
        ]);
        $this->assertTrue($res['success'], (string)($res['error'] ?? ''));
        $this->createdMeetingIds[] = (int)$res['meeting_id'];
        return MeetingService::getMeeting((int)$res['meeting_id']);
    }

    public function testMeetingDoesNotTakeOverAnExistingConferenceRoom(): void
    {
        $this->db->exec("INSERT INTO pbx_conferences (room_number, internal_number, title, user_pin, admin_pin, is_active) VALUES ('8992', '8992', 'Board room', '1234', '9999', 1)");
        $res = MeetingService::saveMeeting(['title' => 'Other', 'scheduled_start' => '2030-01-01 10:00:00', 'room_number' => '8992']);
        $this->assertFalse($res['success']);

        $row = $this->db->query("SELECT title, user_pin, admin_pin, is_active FROM pbx_conferences WHERE room_number = '8992'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Board room', $row['title']);
        $this->assertSame('1234', $row['user_pin']);
        $this->assertSame('9999', $row['admin_pin']);
        $this->assertSame(1, (int)$row['is_active']);
    }

    public function testRoomOfALiveMeetingIsNotSharedButACancelledOneIsReused(): void
    {
        $first = $this->meeting(['room_number' => '8993']);
        $again = MeetingService::saveMeeting(['title' => 'Second', 'scheduled_start' => '2030-01-01 10:00:00', 'room_number' => '8993']);
        $this->assertFalse($again['success']);

        MeetingService::cancelMeeting((int)$first['id']);
        $this->meeting(['room_number' => '8993', 'title' => 'Third']);
    }

    public function testJoinCheckTiesATokenToItsOwnRoom(): void
    {
        $m = $this->meeting(['max_participants' => 7]);
        $this->assertSame(7, MeetingService::joinCheck($m['meeting_token'], '8991'));
        $this->assertSame(0, MeetingService::joinCheck($m['meeting_token'], '8994'), 'another room');
        $this->assertSame(0, MeetingService::joinCheck('m_wrong', '8991'));
        $this->assertSame(0, MeetingService::joinCheck('', '8991'));
    }

    public function testJoinCheckRefusesCancelledAndClosedMeetings(): void
    {
        $m = $this->meeting();
        // PHP's clock, as saveMeeting writes them: the database may be in another time zone.
        $schedule = function (int $startOffset, int $endOffset) use ($m): void {
            $this->db->prepare('UPDATE pbx_meetings SET scheduled_start = ?, scheduled_end = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s', time() + $startOffset), date('Y-m-d H:i:s', time() + $endOffset), (int)$m['id']]);
        };

        $schedule(86400, 172800);
        $this->assertSame(0, MeetingService::joinCheck($m['meeting_token'], '8991'), 'not started');

        $schedule(-259200, -172800);
        $this->assertSame(0, MeetingService::joinCheck($m['meeting_token'], '8991'), 'ended');

        $schedule(0, 3600);
        $this->assertGreaterThan(0, MeetingService::joinCheck($m['meeting_token'], '8991'));
        MeetingService::cancelMeeting((int)$m['id']);
        $this->assertSame(0, MeetingService::joinCheck($m['meeting_token'], '8991'), 'cancelled');
    }

    public function testNoGuestMeetingTakesInvitesAndStaffButNotTheLink(): void
    {
        $m = $this->meeting(['allow_guests' => 0]);
        $this->assertSame(0, MeetingService::joinCheck($m['meeting_token'], '8991'));
        $this->assertGreaterThan(0, MeetingService::joinCheck(MeetingService::staffJoinKey($m), '8991'));

        $this->db->prepare('INSERT INTO pbx_meeting_invites (meeting_id, email, guest_token, status) VALUES (?, ?, ?, "invited")')
            ->execute([(int)$m['id'], 'guest@example.com', 'g_test']);
        $this->assertGreaterThan(0, MeetingService::joinCheck('g_test', '8991'));

        $this->assertSame(MeetingService::staffJoinKey($m), MeetingService::sipJoinToken($m, $m['meeting_token'], 'member', true));
        $this->assertSame($m['meeting_token'], MeetingService::sipJoinToken($m, $m['meeting_token'], 'member', false));
        $this->assertSame('g_test', MeetingService::sipJoinToken($m, 'g_test', 'guest', true));
    }

    public function testJoinWindow(): void
    {
        $m = ['status' => 'scheduled', 'scheduled_start' => '2030-01-01 10:00:00', 'scheduled_end' => '2030-01-01 11:00:00'];
        $start = (int)strtotime($m['scheduled_start']);
        $end = (int)strtotime($m['scheduled_end']);
        $this->assertSame('not_started', MeetingService::joinState($m, $start - MeetingService::JOIN_EARLY_SECONDS - 1));
        $this->assertSame('open', MeetingService::joinState($m, $start - MeetingService::JOIN_EARLY_SECONDS));
        $this->assertSame('open', MeetingService::joinState($m, $end + MeetingService::JOIN_LATE_SECONDS));
        $this->assertSame('ended', MeetingService::joinState($m, $end + MeetingService::JOIN_LATE_SECONDS + 1));
        $this->assertSame('cancelled', MeetingService::joinState(['status' => 'cancelled'] + $m, $start));
    }

    public function testLobbyTitleCannotBreakOutOfTheScript(): void
    {
        require_once dirname(__DIR__, 2) . '/src/controllers/MeetingLobbyController.php';
        $m = $this->meeting(['title' => '</script><script>alert(1)</script>', 'room_number' => '8994']);
        ob_start();
        \MeetingLobbyController::index((string)$m['meeting_token']);
        $html = (string)ob_get_clean();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }
}
