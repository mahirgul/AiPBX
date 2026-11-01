# Spec 004: Web Video Meetings & Guest Conference Scheduling System

- **Status:** In Progress
- **Author:** Mahir Gül
- **Target Release:** v1.50.38
- **Date:** 2026-11-01

---

## 1. Problem & Goal (What & Why)

### 1.1 Problem Statement
1. **Audio-Only Legacy Conferencing**: AiPBX's `/conferences` interface currently only handles basic voice ConfBridge rooms. In modern work environments, teams require video meetings with gallery/grid view, active speaker detection, and screen sharing.
2. **Scheduled Meetings & Calendar Gaps**: Users have no built-in way to schedule future video meetings for specific dates and times, or share meeting invites with calendar (`.ics`) attachments.
3. **No External/Guest Access**: Anyone without an extension or portal account is currently locked out of PBX conferences. Organizations must rely on third-party services (Zoom, Google Meet, Teams) to host meetings with clients, vendors, or external partners.
4. **Asterisk ConfBridge Video Architecture**: Asterisk 20/22 natively supports Selective Forwarding Unit (SFU) mode (`video_mode=sfu`) and Active Speaker mode (`video_mode=follow_talker`), but endpoints need `max_audio_streams` and `max_video_streams` expanded, and ConfBridge bridge profiles configured.

### 1.2 Proposed Solution
- **Web Video Meetings Engine (`/meetings`)**:
  - Dedicated scheduling & meeting room management interface under **PBX → Video Meetings** (`/meetings`).
  - Scheduling system supporting start time, duration, room name, password protection, and video mode selection (SFU WebRTC vs Active Speaker Talker).
- **Public Guest Access via Magic Link (`/meet/{token}`)**:
  - Secure, time-bounded cryptographic token per meeting/guest.
  - Pre-join hardware lobby (microphone/camera selection, audio meter, self-preview).
  - WebRTC in-browser conference joining without installing any software or registering an extension.
- **Automated Email Invites with iCalendar (.ics)**:
  - Built-in transactional template in `MailTemplateService` sending meeting invitations with one-click "Join Meeting" button and standard `.ics` calendar file for Google Calendar, Outlook, and Apple Calendar.
- **Asterisk ConfBridge SFU & WebRTC Multi-Stream Support**:
  - Bridge profile generation with `video_mode=sfu`, `video_update_discard=0`, and `remb_send_interval=1000`.
  - PJSIP `[endpoint-wss]` configuration supporting `max_audio_streams=10` and `max_video_streams=10`.

---

## 2. User Stories & Acceptance Criteria (DoD)

### User Stories
- As an **Administrator or User**, I want to schedule a video conference for a specific date/time and invite participants via email so that meetings are organized and attendees receive calendar invites.
- As an **External Guest (Non-Subscriber)**, I want to click a link received by email and join a video conference directly in my browser without having to create an account or install an app.
- As a **Meeting Participant**, I want to see all participants in a gallery grid (SFU mode), toggle my camera and microphone, and share my screen.

### Acceptance Criteria (Definition of Done)
- [ ] **AC-1:** Database migration creates `pbx_meetings` and `pbx_meeting_invites` tables.
- [ ] **AC-2:** ConfBridge generator (`SyncConferences.php`) writes `CONFBRIDGE(bridge,video_mode)=sfu` (or `follow_talker`), `video_update_discard=0`, and `remb_send_interval=1000`.
- [ ] **AC-3:** PJSIP WebRTC endpoint generator (`SyncExtensions.php`) defines `max_audio_streams=10` and `max_video_streams=10`.
- [ ] **AC-4:** Meeting management view (`/meetings`) allows scheduling, editing, cancelling, and viewing upcoming/past meetings with CSRF and RBAC protection.
- [ ] **AC-5:** Public meeting gateway (`/meet/{token}`) provides pre-join camera/mic checks, lobby screen, and WebRTC room connection with clean fallback.
- [ ] **AC-6:** E-mail invitation service dispatches branded HTML email with `.ics` calendar attachment via `MailTemplateService`.
- [ ] **AC-7:** 100% translation key parity across all 22 supported languages (`web/lang/*.php`).
- [ ] **AC-8:** Automated unit tests pass with 100% green coverage, 0 PHPStan errors, and clean smoke test.

---

## 3. Technical Design (How)

### 3.1 Database Schema
```sql
CREATE TABLE `pbx_meetings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `meeting_token` VARCHAR(64) NOT NULL UNIQUE,
  `title` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `host_user_id` INT NULL,
  `room_number` VARCHAR(32) NOT NULL,
  `pin` VARCHAR(32) NULL,
  `scheduled_start` DATETIME NOT NULL,
  `scheduled_end` DATETIME NOT NULL,
  `video_mode` ENUM('sfu', 'follow_talker', 'none') DEFAULT 'sfu',
  `max_participants` INT DEFAULT 25,
  `allow_guests` TINYINT(1) DEFAULT 1,
  `status` ENUM('scheduled', 'in_progress', 'completed', 'cancelled') DEFAULT 'scheduled',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_meeting_token` (`meeting_token`),
  INDEX `idx_scheduled_start` (`scheduled_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pbx_meeting_invites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `meeting_id` INT NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NULL,
  `guest_token` VARCHAR(64) NOT NULL UNIQUE,
  `status` ENUM('invited', 'accepted', 'joined', 'declined') DEFAULT 'invited',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`meeting_id`) REFERENCES `pbx_meetings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 Services & Controllers
- `MeetingService.php`: Core business logic, scheduling, validation, token generation, invite dispatching, and iCalendar `.ics` generation.
- `MeetingController.php`: Backoffice UI for `/meetings` (list, schedule, cancel, copy link, resend invite).
- `MeetingLobbyController.php`: Public guest endpoint `/meet/{token}` handling lobby entry, session verification, and guest SIP credentials.

---

## 4. Verification & Testing
- Unit tests: `MeetingServiceTest.php` covering meeting lifecycle, token validation, ICS generation, and guest access limits.
- PHPStan analysis with 0 errors.
- Smoke tests covering `/meetings` and `/meet/{token}`.
