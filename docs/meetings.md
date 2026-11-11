# Video meetings and guest invitations

Since **1.60.0**, **Meetings** (`/meetings`) schedules browser meetings backed by Asterisk ConfBridge.
This is separate from a [one-to-one video call](video-calls.md). Guests can join through an HTTPS
link without installing an app or having a portal account.

## Schedule a meeting

1. Sign in with a role allowed to access the Meetings module. Creating or editing needs its **edit**
   permission; cancelling needs **delete** permission under **Roles**.
2. Create a meeting with a title, start and end time. Check the server's configured timezone before
   sending invitations. The end must be after the start.
3. Choose a video mode, participant limit and the guest access setting. If used, set participant
   and host PINs separately; do not send the host PIN to ordinary guests.
4. Save the meeting. Its associated conference configuration is synchronized on save, so an invited
   guest does not have to wait for an administrator to press Apply.
5. Use the row's **Copy Link**, **Invite Guest** or **Download .ics** actions. Email delivery needs
   working [mail settings](mail.md); the calendar download can also be distributed separately.

| Video mode | Purpose |
|------------|---------|
| SFU Gallery | Multiple video streams for a browser gallery |
| Follow-Talker | Switches the displayed video to the active speaker, including suitable SIP video phones |
| None | Audio-only conference |

The configured participant limit is 2–100; it is an admission limit, not a performance guarantee.
Size the server and network for the number of media streams and test the intended codec/devices.

## Join as a guest

Open the shared `/meet/<token>` link or the individual link from the invitation email. The lobby
shows the meeting and its current availability. Allow microphone/camera access in the browser and
join when the room is open. HTTPS, WebSocket signaling and the WebRTC media path must be reachable;
see [Reverse proxy setup](reverse-proxy.md) and [WebRTC, NAT and TURN](webrtc-nat.md).

The join window opens **30 minutes before** the scheduled start and closes **60 minutes after**
the scheduled end. Outside that window, or after cancellation, new joins are refused. Do not rely
on the scheduled end time as a forced disconnection of participants already connected.

The shared room link is accepted for anonymous access only when general guest access is enabled.
Individual invitation tokens remain explicit invitations. Signed-in staff use a separate join key.
A guest link grants guest access, not host/moderator rights; knowing a room number alone does not
authorize browser guest access to a scheduled meeting.

Treat shared and individual invitation URLs as access credentials: share them with intended
participants and remove tokens before posting screenshots or diagnostic logs publicly.

## Verify before sending invitations

Use two browsers, with one signed out of the portal. Check that the intended guest link opens,
microphone/camera permissions work, participants can hear each other, and video remains connected
beyond the first minute. Test from the external network guests will actually use; a working portal
page does not prove TURN/media connectivity.

If an invitation email does not arrive, inspect [mail delivery](mail.md). If the lobby refuses entry,
check the schedule, cancellation status, guest setting and which link was sent. For missing audio,
use the [WebRTC troubleshooting checklist](webrtc-nat.md#troubleshooting).
