# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

## 1.9.5

- **AI → Cloud services** (admin only): the accounts of cloud AI providers in
  one place — Google AI Studio (Gemini, Gemma), OpenAI, Azure, Google Cloud,
  Amazon Polly, ElevenLabs, Deepgram, Groq and OpenRouter — with what each can
  do, a link to get a key, a connection test and this month's use. For each
  job and language (speech and speech to text in Turkish, German, English)
  you choose a local model or a cloud provider; the AI features that follow
  use this choice. A notice reminds that cloud engines send audio and text to
  the provider (KVKK/GDPR). See [Cloud AI services](docs/cloud-ai.md).
- **Cloud speech to text:** OpenAI, Groq (Whisper), Deepgram, Azure,
  ElevenLabs and Gemini appear next to the local models in *AI → Local models
  → Try: speech to text*, to compare them on the same recording.
- **Numbers read correctly in German and English voices:** amounts
  ("1.234,50 €", "$1,234.50"), times ("14:30 Uhr", "2:30 pm"), dates
  ("am 3. Oktober", "12/31/2026"), percentages, phone numbers (read digit by
  digit in their groups) and common abbreviations. The update adds the
  `num2words` package to the AI runtime.
- Cloud TTS: keys of the cloud providers are now changed on *Cloud services*
  only (admin role); the Cloud TTS page shows their status.

## 1.9.4

- **Speech to text on the server (Vosk):** small Turkish, German and English
  models (36–45 MB, Apache-2.0) on *AI → Local models*. A new *Try* panel
  records from the microphone (up to 15 s) or takes a WAV file and shows the
  text. On a 2-core server Turkish is recognised about 5× faster than real
  time; short requests come out well, about 1 word in 4 is wrong on noisy
  telephone audio.
- **Add a voice:** every voice of the public Piper voice list (about 180
  voices, 58 languages) can be found by language and quality and added. Its
  licence is read from the model card; non-commercial and fine-tuned voices
  are marked. Files are pinned and checked like the recommended ones.
- **English voices (Kokoro):** Heart and Michael (US), Emma and George (UK),
  Apache-2.0, very natural. They share one 311 MB model (each further voice
  adds 0.5 MB); about 1.7× faster than real time on a 2-core server, so they
  suit announcements made in advance rather than live calls.

## 1.9.3

- **AI → Local models, several models:** models can now be **run** and
  **stopped** one by one (a stopped model keeps its files and stays stopped
  after a restart). The page shows the memory each running model uses, the
  disk used by models (limit 5 GB), our own speed and memory measurement for
  recommended models, and a warning on models that are not cleared for
  commercial use.
- **Try panel:** speak your own text with any running voice model and keep
  the last results side by side to compare voices.
- **German and English voices (Piper):** German *MLS* (CC BY 4.0, many
  speakers), *Thorsten* and *Kerstin* (marked: fine-tuned from voices with a
  research or non-commercial licence), English *Cori* (public domain, slower
  high-quality voice). About 13× faster than real time on a 2-core server,
  120–150 MB of memory each. The update installs `espeak-ng` on servers that
  have the AI runtime.
- **Cloud TTS:** the "Local models" provider offers every running voice
  model, so announcements can be made in Turkish, German or English.
- The speed test now reads a sentence in the model's own language.
- Known limit: Piper voices read numbers as written (e.g. "1.234,50 Euro"
  comes out as "… Komma fünf null"); write amounts and dates out in words
  for now.

## 1.9.2

- **Local AI models are much lighter:** the runtime now uses ONNX Runtime
  instead of PyTorch — about 160 MB instead of 1.1 GB to download, about
  150–250 MB of memory instead of up to 1 GB, installed in about a minute.
  EMA Lightning speaks about 9–11× faster than real time on a 2-core server
  (3–4× before), with the same voice. The model files come from AiPBX's own
  release `models-ema-lightning-1` and are checked by SHA-256 before use.
  Servers that installed the runtime with 1.9.0–1.9.1 are moved over by the
  update: the runtime is rebuilt and the model downloaded again by itself.
- **AI → Local models:** the page now follows the runtime installation by
  itself; before, it could keep showing "Not installed" until reloaded.

## 1.9.1

- **Fix:** the update to 1.9.0 failed its page check and rolled back on
  servers (PHP 8.5): a deprecation notice of PHP 8.5 (`curl_close()`) was
  counted as an error once the Cloud TTS page asked the local AI service for
  its state. Update straight to 1.9.1; it contains everything from 1.9.0.

## 1.9.0

- **Local AI models** (*AI → Local models*, admin only): AI models that run
  on the PBX itself, so text and audio never leave the server, with no API
  key and no cost. The page installs the local AI runtime once (Python with
  PyTorch for the CPU, about 1 GB, in the background), downloads models after
  their licence is accepted, shows the service's CPU and memory use, and has
  a speed test that tells whether a model is fast enough on this server. The
  service (`aipbx-ai`) listens only on the server itself, runs at a lower CPU
  priority than calls and with a memory limit; it stays off until the runtime
  is installed. See [Local AI models](docs/local-ai.md).
- **EMA Lightning (Turkish text-to-speech):** the first local model
  (Apache-2.0). Once downloaded it appears on *AI → Cloud TTS* as
  "EMA Lightning (local, Turkish)": listening, MP3 download and saving as an
  announcement work as with the cloud providers. On a 2-core server it speaks
  about 3–4× faster than real time.
- **Fix:** announcements could not be uploaded on *Sounds & Announcements*
  (and AI-made ones not saved): the portal had no write access to the custom
  sounds folder. The update fixes the folder's permissions.

## 1.8.2

- **Translations:** the texts added up to 1.8.1 (website widget, network
  services, file storage, Apple push, IVR call flow, brand settings, audit
  log names) are translated into all 21 other portal languages. These are
  machine translations; corrections from native speakers are welcome.

## 1.8.1

- **Admin menu:** *System Update*, the last item of the Admin group, was cut
  off since *File storage* was added (an open group was limited in height).
- **Network services:** DHCP and TFTP are now two separate switches, so
  either one can run alone (DHCP without TFTP, TFTP without DHCP) or both.
  Existing settings are kept.
- **Audit log:** changes to the chat settings and the MS Teams settings, and
  showing a desk phone's admin password, are now recorded. All entries show
  a readable action name (sign-in, reboot, e-mail sent …) instead of an
  internal code.
- **Roles:** the File storage module has its name in the permission list.

## 1.8.0

- **Chat files in S3-compatible storage** (*Admin → File storage*, admin
  only): chat attachments, thumbnails and group pictures can be kept in an
  S3 bucket (AWS S3, MinIO, Wasabi, Backblaze B2, Cloudflare R2 …) instead of
  the local disk, which stays the default. The page tests the connection and
  can move the files already on the disk into the bucket. Downloads still go
  through the portal with the same checks, so the bucket can stay private;
  files keep opening after switching in either direction. Recordings,
  voicemail and faxes stay on the local disk. See
  [File storage](docs/file-storage.md).
- **IVR call flow:** a new button on each IVR shows the whole path of a
  caller as a tree: every key, no input and invalid key, with sub-menus,
  time conditions and announcements opened up in place. Loops and missing
  targets are marked.
- **Brand settings:** an optional dark theme logo, the choice of logo for
  e-mails, and an option to leave the brand name out of the e-mail header
  when the logo already contains it.
- **Voicemail e-mails:** the notification is delivered even when the mail
  script stops early (database unreachable, PHP error), and every
  notification leaves a line in `journalctl -t aipbx-voicemail`.
- **Translations:** ready for Hosted Weblate (see
  [Translating](docs/translating.md)); CI now checks every portal language
  for unknown keys, placeholders and HTML tags.
- **Android app 1.0.57:** after the session is rejected, the sign-in screen
  says why ("session expired") instead of a call history error.

## 1.7.5

- **Website call widget** (*Integrations → Web widgets*, admin only): a
  "Call us" button for any website. Visitors call the company from the
  browser over WebRTC, without a phone or an app, or leave a number for a
  call-back. Each widget works like a trunk with its own number as the DID,
  so routing, queues, time conditions and reports apply; the destination is
  fixed by the administrator. Calls need a one-time token, and allowed
  websites and rate limits are set per widget. The site needs one line of
  code; a WordPress plugin is in `integrations/wordpress`. See
  [Website call widget](docs/web-widgets.md).

## 1.7.4

- **DHCP and TFTP for desk phones** (*PBX → Network services*, roadmap 10):
  *TFTP only* (the page lists the options 66/160/150/42 to set on your own
  DHCP server) or *DHCP for a phone network* on one interface or VLAN, with
  the provisioning URL handed to the phones (option 66/160). Off by default;
  a check for other DHCP servers runs before DHCP is switched on. Leases are
  listed, unknown phones among them appear as waiting phones on *PBX →
  Phones*. TFTP answers only the provisioning *allowed networks* and never
  serves the per-phone configuration. See
  [Network services](docs/network-services.md). The update installs
  `dnsmasq-base` (the service stays off until it is switched on).

## 1.7.3

- **iOS push notifications (APNs):** iPhones now get incoming calls and chat
  messages while the app is closed, like Android with FCM. A call arrives as
  a VoIP push and rings on the CallKit screen; chat messages can be answered
  from the lock screen. Set it up on *Admin → Push* (new APNs section: the
  Apple `.p8` key, Key ID, Team ID, bundle ID, sandbox or production). Android
  (FCM) and iOS (APNs) can be on at the same time. The iOS app has to be
  signed with a push-capable profile; see `ios/README.md`.

## 1.7.2

- **Android app 1.0.56: empty call history and settings after an update**
  (#17). When the server no longer accepts the saved sign-in (expired after a
  long time, password reset, or a server change), the app looked signed in
  but every list stayed empty until it was reinstalled. It now signs out and
  opens the sign-in screen.

## 1.7.1

- **Phone provisioning: Snom, Cisco SPA and Poly** (#27) next to Yealink,
  Grandstream and Fanvil: Snom D3xx/D7xx, Cisco SPA303/50x/525G2 and Poly VVX /
  Edge E, with their key layouts and expansion modules, re-provision and
  (Snom, Cisco) reboot. See [Phones](docs/phones.md); not yet tried on real
  devices of these brands.
- **iOS app** (#26): remove calls from the call history (swipe with Undo,
  clear all), reply to a chat message from its notification, and delete your
  own chat messages, as on Android. iOS shows chat notifications only while
  the app's chat connection is alive (no push for iOS yet).
- **Translations:** the texts added in 1.6.6–1.7.0 (phones, key layouts,
  chat message deletion …) are in all 23 portal languages (#25).

## 1.7.0

- **Phone provisioning** (*PBX → Phones*, #19): add desk phones by MAC
  address or CSV, and they fetch their configuration over HTTPS from a
  per-phone URL: SIP account, server, transport and SRTP, codecs, time and
  language, voicemail key and pickup code, and the **key layout**. The key
  layout of each user (*Extensions → keys*) is drawn like the phone and its
  expansion modules (BLF, speed dial, park, DND …) and can be copied to other
  users. Unknown phones that ask for a configuration appear as *waiting
  phones* and are assigned with one click. *Re-provision* and *reboot* reach
  the phone at once. Yealink, Grandstream and Fanvil for now; every download
  is logged; optional allowed networks and rate limit. See
  [Phones](docs/phones.md). Grandstream and Fanvil settings still need a check
  on real devices: reports are welcome.
- **Delete chat messages** (#15, #21): your own messages, in the web chat and
  the Android app (long press). Everyone sees "This message was deleted", and
  an attached file is removed from the server. An administrator can limit
  deleting to the first N minutes (*Chat → Chat settings*). Android app 1.0.55.
- **Dialplan:** no more "The use of '_.' … is strongly discouraged" warnings
  on every reload (#20).
- **Buttons that did nothing:** in the portal, a form whose action is its
  button (AI TTS *Clear provider*, the new phone and chat buttons) lost that
  button on the way to the server.

## 1.6.6

- **More busy lamps for desk phones:** a BLF key with the value `DND1001`,
  `CF1001` or `QUEUE1001` shows whether 1001 has do-not-disturb on, forwards
  all calls, or is logged in to a queue (and not on a break). On the user's
  own phone the key switches do-not-disturb, cancels the forwarding, or logs
  in to / out of the queues. See [Busy lamps](docs/blf.md) for setting the
  keys on Yealink, Grandstream and Fanvil phones.

## 1.6.5

- **Feature codes missing on new installations:** do-not-disturb (`*78`),
  call forwarding (`*72`/`*73`), group and directed pickup (`*20`/`*21`) and
  call listening (`*90`) were not created on a fresh install (the seed data
  collided with rows the migrations had already written). They are added on
  the next update; existing codes and their settings are not changed.
- **Desk phones: pick up a ringing colleague with the BLF key.** A busy-lamp
  key dials the pickup code and the extension in one go (`*211001`); this
  now picks up the call instead of asking for the number.
- **Desk phones: voicemail lamp.** Phones of users with a voicemail box get
  the message-waiting indication (lamp and count).
- Busy lamps (BLF) were tested end to end: a phone watching an extension
  gets ringing, talking and free states.

## 1.6.4

- **Translations:** the texts added in 1.6.x (e-mail templates and their
  editor, voicemail e-mail settings, "Forgot your password?", show/hide
  password, the sign-in help contact) are in all 23 portal languages (#18).
  The translations are machine-made; corrections are welcome.
- E-mail templates: a link to a `{value}` in a template could be saved as
  `%7Bvalue%7D` with some PHP/libxml versions and was then not filled in.

## 1.6.3

- **Help contact on the sign-in page** (#13): *Brand settings* has an
  optional contact name, e-mail and phone. When set, the sign-in and
  "Forgot your password?" pages show "Need help?" with them.

## 1.6.2

- **Google sign-in returned to the sign-in page** (#9). The session cookie was
  `SameSite=Strict`, so the browser did not send it when Google sent the user
  back: the portal could not check the request and signed nobody in. It is
  `Lax` now (changes still need POST and a CSRF token).
- **"Forgot your password?"** on the sign-in page (#13): a user enters their
  username or e-mail address and gets the *Password reset* e-mail (valid 30
  minutes). The answer is the same whether the account exists or not; at most
  5 requests an hour per network and one e-mail per account every 10 minutes.
  The link always uses the installation's own address. Requests show in the
  audit log (*Sign-in attempts*).
- E-mail templates: in the plain-text version a button's link stands on its
  own line.

## 1.6.1

- **Show the password while typing** (#11): the password fields of the
  sign-in, password reset and password change pages have an eye button.

## 1.6.0

- **E-mail templates** (*Admin → E-Mail → Templates*): the texts of the
  invitation, sign-in link, password reset, voicemail, fax received / delivered
  / not delivered and test e-mails can be edited, per language, with a live
  preview, value buttons (`{name}`, `{caller}` …) and a test send. Every
  e-mail now uses one frame with the logo, name and colour from Brand
  settings. See [E-mail](docs/mail.md#templates).
- **E-mails in the recipient's language.** Portal users get them in their own
  language; fax unit addresses and other recipients without an account in the
  language set on the Templates page (default: the first administrator's).
  The fax e-mails used to be Turkish only, the invitation was in the language
  of the administrator who sent it.
- **Voicemail e-mails** are HTML with the logo, show the caller, time and
  length, and keep the recording as an attachment. If building them ever
  fails, Asterisk's own message is delivered instead.
- **Android app 1.0.54:**
  - *Full-screen incoming calls* (#7): Android 14 and later can switch off
    full-screen notifications for an app, and then a call on a locked phone
    only shows a small notification. The app explains this once and links to
    the setting; *Settings* shows whether it is on.
  - *Remove calls from the call history* (#10): swipe a call away (with
    Undo) or clear the whole history. Only your own list changes; the call
    records on the server, reports and recordings stay.
  - *Reply from a chat notification* (#8) without opening the app.

## 1.5.3

- **Google sign-in settings could not be saved** ("Unknown column
  'updated_at'"), so Google Workspace sign-in could not be set up (#9).
- **Voicemail could not be switched off for an extension** (#2): after
  unticking *Voicemail box enabled* and saving, the box was ticked again.
  The same happened to *Attach recording*.
- **Voicemail settings moved to My Phone → Voicemail**, next to the
  messages, with their own Save button. Saving call forwarding or DND (in the
  portal or the mobile app) used to reset them: the recording attachment
  was switched off and the forward-to-voicemail options were cleared.
- **E-mail notification switch for voicemail**, per user and on the
  extension form. It is on for existing users, so nothing changes until
  someone turns it off.
- **Mobile API: remove calls from your own call history** (one call or all).
  The call records themselves are not deleted: reports, call journeys and
  recordings stay. Used by the next Android app version (#10).

## 1.5.2

- **Updating from the portal failed** (*Admin → System Update*) with "The
  HOME or COMPOSER_HOME environment variable must be set" and rolled back,
  while `sudo aipbx-update` over SSH worked (#3). The update runs as a
  background service that had no HOME; it is set now. Older installations
  can update from the portal again, because the fix is in the new
  version's installer.
- **Android app 1.0.53:** the battery-optimisation request no longer opens
  every time the app starts (#6). It is shown once; later it is under
  *Settings → Battery optimisation*.

## 1.5.1

- **Croatian (Hrvatski) in the Android and iOS apps** (#5). Android app
  1.0.52.
- **Sign-in page:** the language dropdown sits at the bottom of the sign-in
  card on computers too, as on phones, instead of in the page corner.
- App translations are checked automatically on every change (missing
  texts, placeholders, apostrophes).

## 1.5.0

**20 new languages** (#3, #4): German, Russian, French, Spanish,
Azerbaijani, Serbian (Latin), Italian, Portuguese, Dutch, Polish, Ukrainian,
Romanian, Greek, Czech, Hungarian, Bulgarian, Swedish, Danish, Norwegian
Bokmål and Finnish.
- **Web portal:** 23 languages in total, picked from the language dropdown.
- **Android and iOS apps:** the same 20 languages (Croatian follows).
- These are machine translations: corrections from native speakers are very
  welcome, see [docs/translating.md](docs/translating.md).

**Android app 1.0.51**
- A language chosen in Android's own per-app language settings is now picked
  up everywhere: the language button and notifications used to stay in the
  previous language.

**Fixes**
- Phone sign-in page: the language dropdown was 16 px off to the left.

## 1.4.0

**Languages**
- New interface language: **Croatian (Hrvatski)**, fully translated.
- The language is now chosen from a **dropdown**: on the sign-in pages and
  in the user menu (bottom right). New languages appear there by themselves.
- A partly translated language works: missing texts are shown in English.
- Translations are open to everyone: `scripts/i18n.py` exports the missing
  texts, checks a translation (placeholders, HTML) and writes the language
  file. Guide: [docs/translating.md](docs/translating.md).

**Sign-in page**
- Animated background: a moving network and falling SIP / Asterisk log
  lines, in calmer colours in the light theme. It is turned off for users
  who ask their system for reduced motion.

**Dark mode fixes**
- The **Active / Passive** buttons, the incoming-call **Answer** button and
  some other green buttons were almost invisible: the green button style was
  missing.
- The **Incoming / Outgoing / Missed** filters on *My Phone* were white with
  white text.
- **Extensions**: the Active/Passive button was hidden behind the pinned
  Actions column when the table was wider than the screen; it now sits next
  to Edit / Delete.

## 1.3.9

- Sign-in page: the security question and its answer box are on one row.
- Phones: the **English / Türkçe** buttons sit at the bottom of the top
  card, as big as the sign-in button; turned sideways, both cards have the
  same height.

## 1.3.8

- Phone sign-in page: the **English / Türkçe** switch and **Sign in with a
  Passkey** are in the top card, with the Google Play button; the second
  card holds only the sign-in form.

## 1.3.7

**Sign-in page on phones**
- The page scrolls; before, a phone showed only part of it and could not
  scroll, and the logo was cut off at the top.
- Two cards: logo + a single **Get it on Google Play** button, and the
  sign-in form. Side by side when the phone is turned sideways.
- The language switch no longer covers the card on small screens.

**Portal**
- Outline buttons (e.g. *Sign in with a Passkey*, and buttons on about ten
  other pages) were never styled and looked like plain text; they now have
  a coloured border.

## 1.3.6

- The mobile app download buttons (sign-in page on phones, **My Phone →
  Settings**) open the app on **Google Play**. They pointed to `/app.apk`,
  a file that does not exist on new installations.
- Fix for the unit tests of 1.3.4 (the prompt-language change tried to
  edit the real `/etc/asterisk` during tests); no change on servers.

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
