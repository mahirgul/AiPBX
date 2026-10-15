# AiPBX iOS app

- **Platform:** iOS 16.0+ (iPhone and iPad)
- **Built with:** SwiftUI, WebKit (JsSIP WebRTC engine), CallKit, URLSession WebSocket, Combine
- **Bundle ID:** `com.mhrgl.AiPBX`
- **Build:** GitHub Actions (`macos-14`, Xcode 15/16)

A native iOS client for an AiPBX server: calls over WebRTC, chat, call history and the company
directory. It talks only to the user's own PBX. It is behind the Android app in features.

## Screens (five tabs)

1. **Keypad:** 3x4 DTMF keypad and dialling, SIP registration status (connected, connecting, not
   connected); the call screen (`ActiveCallView`) with mute, keypad, speaker, hold and a call timer;
   iOS system call and lock screen integration (`CallKit`, `AudioSessionManager`).
2. **History:** the PBX call records (`/api/mobile/call_history.php`), filters for all, missed,
   incoming and outgoing, call back with one tap.
3. **Contacts:** the PBX directory (`/api/mobile/contacts.php`) with presence and role badges;
   call or chat with one tap.
4. **Chat:** direct and group chats over `URLSessionWebSocketTask` (messages, typing, presence),
   group management, filters for all, direct and groups.
5. **PBX:** do-not-disturb (`/api/mobile/features.php`), call forwarding (always, busy, no answer)
   with ring time (10–45 s), the in-app log viewer (`LogViewerView`, `AppLogManager`) with export
   through the share sheet.

## Push notifications (APNs + PushKit VoIP)

The iOS counterpart of Android's FCM layer. After sign-in the app registers two tokens with the PBX
through `/api/mobile/fcm_token.php` (`push_type = apns`):

- **APNs token:** chat messages, being added to a group, the test notification. The notification's
  **Reply** field works without opening the app.
- **PushKit VoIP token:** incoming calls. The call rings at once on the CallKit screen; in the
  background the app renews its session and registers with SIP, and the PBX sends the INVITE once it
  sees the mobile registration. If "Answer" is pressed before the INVITE arrives, the call is answered
  as soon as it does.

The session (server + 30-day token) is kept in the Keychain; when iOS starts the app for a push while
it is closed, it signs in again through `/api/mobile/refresh.php`. Signing out removes the tokens
from the server.

**What Apple requires (entered in the portal, *Admin → Push → APNs*):**

1. Apple Developer → Keys → a **.p8 key** with "Apple Push Notifications service (APNs)"; its
   **Key ID** and the **Team ID**.
2. The **bundle ID** the app is signed with (default `com.mhrgl.AiPBX`); the App ID needs the *Push
   Notifications* capability.
3. **Environment:** *Sandbox* for builds signed with a development profile, *Production* for App
   Store / TestFlight / ad hoc / enterprise.

The unsigned IPA of CI has no `aps-environment` entitlement; to receive pushes the app must be
re-signed with a profile that has the push capability (free-account AltStore/Sideloadly signatures
usually cannot).

## Automatic build (GitHub Actions)

`.github/workflows/ios-build.yml` builds the app on GitHub's macOS runners (`macos-14`):

- **Triggers:** a push to `ios/**`, or *Run workflow* by hand.
- **Artifacts:**
  - `AiPBX-unsigned.ipa`: installs on devices through TrollStore, AltStore, Sideloadly or an
    enterprise MDM.
  - `AiPBX-Simulator.zip`: an `.app` to drag into the Xcode simulator.

## Building on a Mac

```bash
git clone https://github.com/mahirgul/AiPBX.git
cd AiPBX/ios
brew install xcodegen && xcodegen generate   # optional: AiPBX.xcodeproj is in the repository
open AiPBX.xcodeproj
```
