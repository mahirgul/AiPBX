# Video calls

Video calls work between the web phone, the Android app and desk video phones. They are **off by
default**: video uses many times the bandwidth of audio.

## Switching them on

1. **Admin → Asterisk settings → Video**: *Video calls* on, then the **video codec**, the maximum
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

## Notes

- Video calls are bridged by Asterisk; there is no video conference or recording of the picture.
- While video calls are on, the WebRTC endpoints have no RTP timeout: browsers send audio and video
  over one bundled port and Asterisk would see the video stream as silent and hang up after 30 s.
  Calls of a closed browser or app still end through SIP session timers.
- Video between two WebRTC users goes through the TURN relay like audio when they are behind NAT
  (see [WebRTC, NAT and TURN](webrtc-nat.md)); plan the bandwidth of the relay accordingly.
