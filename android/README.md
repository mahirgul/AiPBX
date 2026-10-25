# AiPBX Android app

<p align="left">
  <a href="https://play.google.com/store/apps/details?id=com.mhrgl.AiPBX" target="_blank" rel="noopener">
    <img src="https://play.google.com/intl/en_us/badges/static/images/badges/en_badge_web_generic.png" alt="Get it on Google Play" height="54">
  </a>
</p>

- **Package:** `com.mhrgl.AiPBX` (debug builds: `com.mhrgl.AiPBX.debug`)
- **Android:** 8.0 (API 26) to 16 (API 36)
- **Version:** `versionName` / `versionCode` in `app/build.gradle.kts`
- **Distribution:** Google Play (CI uploads releases to the production track), or the signed
  APK directly

A native Android client for an AiPBX server: calls (audio and video) over WebRTC, chat, voicemail,
call history and the company directory. It talks only to the user's own PBX; push through Firebase
is optional.

## Screens (`DialerActivity`, five tabs)

1. **Keypad:** dialling, DTMF; the call screen (`CallActivity`) has mute, speaker, hold, transfer,
   camera and a button that hides the controls in a video call. Incoming calls wake the locked screen
   (`IncomingCallActivity`).
2. **History:** the server's call records (`/api/mobile/call_history.php`), filters for all, missed,
   incoming and outgoing; call or chat from a row.
3. **Contacts:** the PBX directory with presence (online/offline) and the device's contacts.
4. **Chat:** direct and group chats over the chat service's WebSocket, photos and files, group
   management.
5. **PBX:** do-not-disturb, call forwarding, voicemail, the in-app log viewer (`LogViewerActivity`)
   for troubleshooting.

## How calls reach the phone

- **SIP engine:** `engine/SipWebRtcEngine.kt` runs JsSIP (`assets/phone_engine.html`,
  `assets/jssip.min.js`) in a headless WebView; it registers as `<extension>-mob-webrtc` over
  `wss://<server>/ws`.
- **Staying connected:** `service/PbxForegroundService.kt` keeps the engine connected in a
  foreground service. A watchdog (every 2 minutes, an `AlarmManager` alarm that also fires in Doze,
  `ResurrectionReceiver`) reconnects when the app has not been registered for 45 s and refreshes the
  registration. Exempting the app from battery optimisation (`util/BatteryPrompt.kt`) makes this
  reliable.
- **Push (optional):** with Firebase configured on the server (*System → Push*),
  `AiPbxFirebaseMessagingService` wakes the app for calls and messages when it is not connected.
  Without it, the foreground service connection is the only path (the default).

## Build

Requirements: Android SDK 36, JDK 17; the Gradle wrapper is included.

```bash
./gradlew testReleaseUnitTest      # unit tests (including EdgeToEdgeComplianceTest)
./gradlew assembleDebug            # app/build/outputs/apk/debug/app-debug.apk
./gradlew assembleRelease          # signed release APK
./gradlew bundleRelease            # signed AAB for Google Play
bin/tls_audit.sh                   # checks that no TLS bypass came back (Play policy)
bin/edge_to_edge_audit.sh          # checks Android 15 edge-to-edge compliance & deprecations
```

## Android 15 (API 35+) Edge-to-Edge Standards

Android 15 enforces edge-to-edge display by default and deprecates explicit bar color and decor fitting APIs. To permanently satisfy Google Play Console policies:

1. **`BaseActivity` Pattern:** All UI screens inherit from `com.mhrgl.aipbx.ui.BaseActivity`.
2. **Zero-Parameter `enableEdgeToEdge()`:** Invoked automatically by `BaseActivity.onCreate()`. Never pass custom parameters (like `SystemBarStyle.dark/light(...)`).
3. **No Deprecated APIs:** Never call `Window.setDecorFitsSystemWindows`, `WindowCompat.setDecorFitsSystemWindows`, `setStatusBarColor`, or `setNavigationBarColor`.
4. **Window Insets:** Root layouts must call `applyEdgeToEdgeInsets(rootView, consumeIme = true/false)` to prevent system bars or keyboards from overlapping UI elements.
5. **Continuous Verification:** `EdgeToEdgeComplianceTest` and `bin/edge_to_edge_audit.sh` strictly guard the codebase against regressions.

Release signing reads `keystore.properties` (not in git). `sdk.dir` goes into `local.properties`.

### Releasing

1. Raise `versionCode` and `versionName` in `app/build.gradle.kts`.
2. Update `play-release-notes.json` ("What's new" on Google Play; `version` must equal
   `versionName`).
3. Merge to `main`: the *Build Android App & Deploy* workflow builds, signs and uploads to the Play
   alpha track.

Texts: `app/src/main/res/values*/strings.xml` (23 languages); `scripts/check_mobile_lang.py` checks
that every language has every string.

## Layout

```
android/
├── app/build.gradle.kts            # version, SDK levels, dependencies
├── app/src/main/
│   ├── AndroidManifest.xml         # permissions, services, deep link aipbx://login
│   ├── assets/phone_engine.html    # JsSIP engine (WebView)
│   └── java/com/mhrgl/aipbx/
│       ├── data/                   # ApiClient (REST), AppPreferences, ChatWebSocketManager
│       ├── engine/                 # SipWebRtcEngine (WebView bridge to JsSIP)
│       ├── model/                  # data classes
│       ├── service/                # PbxForegroundService, push, notifications, boot, watchdog
│       ├── ui/                     # activities and adapters
│       └── util/                   # logging, battery/full-screen prompts, search, L10n
├── app/src/test/                   # unit tests
├── bin/tls_audit.sh                # TLS bypass check
├── play-release-notes.json         # Play "What's new" text per version
├── server_api/                     # old copy of the mobile API; the live one is web/api/mobile/
└── *.py, *.png                     # Play store screenshots and graphics
```
