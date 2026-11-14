# Video calls

One-to-one video calls work between the web phone, the Android app and desk video phones. They are **off by
default**: video uses many times the bandwidth of audio.

## Switching them on

1. **System → PBX Settings → Video**: *Video calls* on, then the **video codec**, the maximum
   resolution and frame rate. *Apply* the change.
2. Each user needs the **video** phone mode (*Users* or *My Phone → Call settings*); it is on by
   default.

| Video codec | Use it when |
|-------------|-------------|
| **VP8** (default) | Calls between browsers and the mobile apps. Every browser and every Android phone can do it. |
| **H.264** | You also have desk video phones (Yealink, Grandstream GXV, Fanvil …), which mostly speak only H.264. Browsers handle it; the Android app needs a phone that can encode H.264, otherwise it talks without video. |

There is only **one** codec for the whole PBX on purpose. Asterisk passes video through without
converting it, and a call whose two sides settled on different video codecs is hung up by Asterisk
("Don't know how to send format … packets"). With one codec everywhere a device that lacks it simply
talks without video.

## Using them

- **Web phone:** the camera button next to the dial field calls with video. An incoming video call
  shows *Video* next to *Answer*. During the call a window shows the other side with the own
  camera in the corner, with full screen and a camera on/off button in the top bar.
- **Android app (1.0.65+):** tap a colleague in *Contacts* or *History* and choose *Video call*; an
  incoming video call has a *Video* button next to *Answer*. During the call: camera on/off and
  front/back camera; the speaker is switched on.
- Answering with *Answer* (audio) still shows the caller's picture if they send one.
- Without a camera, with the camera busy or the permission refused, the call goes on with audio.

## Group calls from the chat

A **group** chat has two buttons next to the group info: a voice call and a video call (the video
button shows only where video calls are on). Pressing one opens a conference for that group and
posts a *call started* card to the chat. The other members press **Join** on the card; nobody's
phone rings.

- Only current members of the group are let in. The PBX checks every caller itself, so knowing the
  room is not enough.
- Video shows whoever is speaking at the moment (one picture, switching with the speaker). For a
  gallery of all participants use [Video meetings](meetings.md).
- The room closes when the last one leaves, and the chat shows that the call ended and how long it
  took. While it is open, pressing a call button again joins the same call.
- At most 8 take part in a video call and 25 in a voice call.
- In the portal the call is made with the web phone; with the phone mode set to desk phone the
  buttons ask you to switch. In the **Android app** (when `chat_calls` is advertised by the
  server), group chats show voice/video call buttons in the header, an active-call banner, and
  **Join** on the chat card. Outside guests cannot join: for them there are meetings.
- Group calls are not recorded.

## Notes

- One-to-one calls are bridged by Asterisk; their video is not recorded. Multi-party video is available
  separately through [Video meetings](meetings.md), with SFU Gallery and Follow-Talker conference modes.
- While video calls are on, the WebRTC endpoints have no RTP timeout: browsers send audio and video
  over one bundled port and Asterisk would see the video stream as silent and hang up after 30 s.
  Calls of a closed browser or app still end through SIP session timers.
- Video between two WebRTC users goes through the TURN relay like audio when they are behind NAT
  (see [WebRTC, NAT and TURN](webrtc-nat.md)); plan the bandwidth of the relay accordingly.
