# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

## 1.3.5

**Install / update**
- An update no longer fails and rolls back when Asterisk does not come up
  on the first restart but starts a moment later by itself; the installer
  waits up to 20 seconds for it.

## 1.3.4

Fixes found while going through user feedback (#1).

**Portal**
- Users without admin rights had no bottom bar on **My Phone** (their start
  page), so no logout, language or theme buttons: a pop-up on that page was
  missing a closing tag and swallowed the bar.
- **My Phone** shows the role name ("User") instead of the internal key
  ("user").
- The sign-in pages have an **English / Türkçe** switch in the top-right
  corner, and the sign-in button has a label.
- **Password change required:** the user can now set the new password right
  on that page. Before, the only option was a link by e-mail, which locked
  out users without an e-mail address or when mail was not working. An
  invitation now asks for a new password only if the e-mail was really sent.
- **Appearance → Branding:** uploading a logo failed with "could not be
  saved" on normal installations: the upload folder was missing. Installs
  and updates create it.

**Phone prompts**
- **PBX Settings → System Default Language** never took effect: the portal
  could not write `asterisk.conf`, failed silently and still asked for an
  Asterisk restart. It is written correctly now. (Restart Asterisk after
  changing it.)

## 1.3.3

**E-mail**
- Sending through a mail server that needs a login (SMTP authentication)
  never worked: the username and password were not saved for postfix,
  although the page said "saved". The mail server then refused every
  message ("Relay access denied"). They are saved now; if that fails, the
  page shows an error.
- Port 465 ("SSL") works: postfix now uses TLS from the first byte there.
- **Send Test E-Mail** waits up to 20 seconds for the real result and shows
  the mail server's answer, e.g. `535 Authentication credentials invalid`
  or `Sender address rejected`. Before, it said "queued" even when the
  message was rejected a second later.

**Music on hold**
- Callers on hold or waiting in a queue heard silence: the "default" music
  class pointed to an empty folder, so Asterisk dropped it. Installs and
  updates now link Ubuntu's stock music into it, as long as the folder has
  no music of its own.

## 1.3.2

**Install / update**
- Updates no longer run apt when every system package is already
  installed (system package updates are left to the OS). On servers that
  cannot reach the Ubuntu mirrors — e.g. behind a proxy that stalls port 80
  — an update used to hang for many minutes in `apt-get update` and could
  then fail on a package download and roll back. Only packages a new release
  adds are installed, with short network timeouts.

## 1.3.1

**Android app 1.0.50**
- The QR code scanner no longer turns the screen sideways on phones; it
  follows the device orientation like the rest of the app.

**Server**
- Static analysis fixes (no change in behaviour).

## 1.3.0

English is now the default language of the whole system; Turkish stays one
click away.

**Web portal**
- The portal, its login page and every message are in English by default.
  Switch with the TR / EN button in the user menu (bottom left) — each
  user's choice is remembered. Everything is translated: pages, buttons, tables,
  pop-ups, notifications, e-mails (password reset, invitations) and the
  softphone, chat and call-center panels.
- Built-in roles (Admin, Viewer, Agent, Queue Manager, ...) are shown
  in the selected language. Roles you create keep the name you gave them.
- Existing installations keep their data and language: users who already
  use Turkish stay in Turkish.

**Phone prompts (new installs)**
- New installations speak English prompts. Turkish prompts can be added any
  time on Sounds → Asterisk Sound Packs (or at install time with
  `AIPBX_TR_SOUNDS=yes`). An update no longer switches an installation's
  prompt language.

**Mobile apps**
- The server answers the apps in the app's language: error messages and
  contact role names are English or Turkish to match the app. The Android
  diagnostic report is in English. Android app 1.0.49.

**Fixes**
- System Update, Sound Packs and the mail settings page showed an error
  after the action had actually succeeded ("Call to undefined function
  writeAuditLog").
- Read-only admins were re-checked: they can view everything but cannot
  change anything.

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
