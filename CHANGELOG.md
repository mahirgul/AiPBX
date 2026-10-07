# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

## 1.2.0

Mobile apps in English by default, with Turkish selectable.

**Android and iOS apps**
- The apps open in English by default — also on a Turkish phone — until
  Turkish is picked. Pick the language on the server / login screen
  (🌐 English / Türkçe) or in Settings; it is kept after signing out.
- Android: switching applies at once; Android 13+ also lists English and
  Turkish in the phone's per-app language settings. Every text of the app
  (about 380) is translated, including notifications and error messages.
- iOS: switching rebuilds the screens at once, no restart. Permission texts
  (microphone, camera, photos) follow the phone's language, as iOS requires.
- Error messages that come from the server are shown as the server sends
  them.

## 1.1.0

Installation fixes and Asterisk sound packs. Supported system: Ubuntu 26.04 LTS.

**Install / update**
- The installer now stops at the very start, before installing anything, on
  systems older than Ubuntu 26.04 (the PHP dependencies need PHP 8.4+, the
  portal targets Asterisk 22). On Ubuntu 24.04 it used to fail half-way.
- Composer errors are shown and stop the install, instead of a later
  "vendor/bin/phinx" error.
- Turkish prompts no longer stop the install silently after
  "cp: warning: behavior of -n is non-portable" (#1): they are downloaded
  from the sounds-tr-1.0.0 release and verified; if that fails the install
  warns and goes on. `AIPBX_TR_SOUNDS=no` skips them.
- The chat service is built even when the install directory belongs to
  another user, and a build error is shown instead of a false "started".
- Release tags deleted on GitHub are pruned on update, so an old tag is never
  offered as an "update".

**Sounds**
- New Sounds → Asterisk Sound Packs tab: install or remove Asterisk's official
  core / extra prompts (en, en_AU, en_GB, en_NZ, es, fr, it, ja, ru, sv), the
  opsound hold music and AiPBX's Turkish prompts, per language and format
  (wav, ulaw, alaw, gsm, g722, sln16). Packages are checksum-verified and
  removing deletes only the files the pack installed.

**Chat**
- Leaving a direct chat can no longer hand its admin rights to the other
  person (who could then add a third user and expose the history).
- Deleted groups are closed: former members can no longer read, post or
  download attachments.
- Previews, titles and push texts are cut by characters, not bytes (no broken
  Turkish characters).

## 1.0.0

First stable release of AiPBX — an open-source IP PBX management portal for
Asterisk 22 on Ubuntu 26.04 LTS, with WebRTC, chat and Android/iOS apps.
Install: `curl -fsSL https://raw.githubusercontent.com/mahirgul/AiPBX/main/install.sh | sudo bash`

**PBX**
- Extensions (SIP desk phones, WebRTC and mobile on one number), calling
  permission groups, voicemail, boss–secretary groups, ring groups,
  conference rooms, queues with static and dynamic agents, feature codes.
- Trunks (IP or registration, caller ID normalization, channel limits,
  ordered fallback), outbound route groups, DID routing, time conditions with
  holidays, multi-level IVR, internal numbers for any destination.
- Safe apply: changes wait on the Apply page; generated configuration is
  written atomically and rolled back if Asterisk rejects it.
- Live dashboard, call recordings as compact MP3, call reports with the full
  call journey, PDF/Excel export.

**Turkish prompts**
- Every Asterisk core prompt plus voicemail, digits, conference, queue and
  directory prompts in Turkish, in one voice, so calls never switch to English.
- Shipped as `asterisk-core-sounds-tr` packages in six formats (wav, ulaw,
  alaw, gsm and wideband g722/sln16): Asterisk plays the file matching the
  call's codec, and any Asterisk server can use the packages.

**Call center**
- Agent desk and wallboard, breaks with reasons applied in Asterisk,
  listen / whisper / barge, transfers that keep the caller connected, queue
  report centre.

**Fax**
- T.38 / G.711 inbound and outbound fax, compose in the browser or upload a
  PDF, resend, e-mail delivery per DID.

**WebRTC, chat and apps**
- Browser softphone with TURNS on 443 for strict networks.
- 1-to-1 and group chat with files, presence and read receipts.
- Android and iOS apps: calls, chat, QR or Google sign-in, push.

**Security**
- Two-factor authentication, passkeys, optional Google sign-in, role-based
  permissions, brute-force protection, certificates page (Let's Encrypt,
  upload or self-signed), firewall and fail2ban from the portal, nightly
  backups, `aipbx-update` with automatic rollback.

**Integrations and AI**
- Microsoft Teams Direct Routing and channel notifications.
- Cloud TTS (Google, Amazon Polly, Azure, ElevenLabs, OpenAI) to create
  announcements.
