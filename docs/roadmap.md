# Roadmap

Planned work, roughly in the order it is expected. Nothing here is a promise of a date; the
[issues](https://github.com/mahirgul/AiPBX/issues) hold the discussion of each item, and ideas are
welcome there.

| # | Item | Issue | Size |
|---|------|-------|------|
| 1 | [Website call widget](#1-website-call-widget) and WordPress plugin | [#14](https://github.com/mahirgul/AiPBX/issues/14) | large |
| 2 | [Delete chat messages and attachments](#2-delete-chat-messages-and-attachments) | [#15](https://github.com/mahirgul/AiPBX/issues/15) | medium |
| 3 | [S3 storage for chat attachments](#3-s3-storage-for-chat-attachments) | [#15](https://github.com/mahirgul/AiPBX/issues/15) | medium |
| 4 | [Video calls](#4-video-calls) | [#15](https://github.com/mahirgul/AiPBX/issues/15) | large |
| 5 | [Local AI models](#5-local-ai-models-embeddinggemma-2-ema-lightning) (EmbeddingGemma 2, EMA Lightning) | | large, in steps |
| 6 | [iOS app: same features as Android 1.0.54](#6-ios-app-same-features-as-android-1054) | | small |
| 7 | [Dialplan pattern clean-up](#7-dialplan-pattern-clean-up) | | small |
| 8 | [Busy lamps (BLF) and voicemail lamp on desk phones](#8-busy-lamps-blf-and-voicemail-lamp-on-desk-phones): watching 10–100 colleagues on multi-key phones | | medium |
| 9 | [Phone provisioning page](#9-phone-provisioning-page) | | large |
| 10 | [DHCP and TFTP management](#10-dhcp-and-tftp-management) | | medium |

Done recently: phone provisioning with key layouts (1.7.0, [Phones](phones.md)); deleting chat messages and the `_.` dialplan clean-up (1.7.0); busy lamps on desk phones for colleagues, do-not-disturb, forwarding and queue login, BLF pickup and the voicemail lamp (1.6.5–1.6.6, see [Busy lamps](blf.md)); e-mail templates (1.6.0), Android full-screen calls, call history clean-up and
notification reply (1.6.0), show password (1.6.1), Google sign-in fix and "Forgot your password?"
(1.6.2), help contact on the sign-in page (1.6.3), 23 languages for all new texts (1.6.4). See the
[CHANGELOG](../CHANGELOG.md).

---

## 1. Website call widget

**Goal.** A "Call us" button for any website. A visitor clicks it and talks to the company over
WebRTC in the browser, without a phone or an app. A small WordPress plugin then only has to insert
the same code.

**Idea: a widget is a virtual trunk.** Each widget enters the PBX like a call from a trunk, with
its own **number** that acts as the DID. Routing, time conditions, queues and reports therefore
work for widget calls exactly as for calls from a carrier.

**Admin page** (*Integrations → Web widgets*). An administrator can create any number of widgets.
Each one has:

- **Name and number**, e.g. "Sales website", 7001. The number shows in CDR and reports as the
  called number of these calls.
- **Destination**: one fixed target, an extension, ring group, queue, IVR or time condition. The
  visitor never chooses or types a number; the server decides where the call goes.
- **External destination** (off by default): when switched on, the destination may also be an
  external number, e.g. the duty phone of a support team. The call then leaves through a chosen
  outbound route group. This opens the door to toll fraud, so a warning is shown and a daily call
  limit becomes mandatory.
- **Allowed websites**: the domains the widget may run on. Requests from other sites are refused.
- **Limits**: concurrent calls, maximum call length, requests per IP per hour, calls per day.
- **Look**: button text, colour, corner, language, an optional "your name / phone" field (shown as
  the caller name), a message outside office hours.
- **Embed code** with a copy button, one line:

  ```html
  <script src="https://pbx.example.com/widget.js" data-widget="w_8f3k2" async></script>
  ```

- A **Try it** button on the page and a switch that disables the widget at once.

**How a call works.**

1. `widget.js` draws a floating button on the website.
2. On click the browser asks for the microphone. The portal checks the website (Origin header) and
   the limits, then hands out a **SIP account that is valid for a few minutes and only once**,
   created in the realtime PJSIP tables.
3. The browser connects over WebRTC on port 443 (TURNS, as the web phone does).
4. The dialplan context of that account leads only to the widget's number. Copying the code from
   the page lets nobody call anywhere else.
5. A scheduled job removes expired accounts.

**Steps.**

1. Data model and admin page (create/edit, number, embed code).
2. Session API, short-lived SIP accounts, dialplan, limits.
3. `widget.js` user interface for desktop and mobile browsers.
4. "Web widget" as a source in reports, statistics, an abuse log.
5. WordPress plugin: a settings field for the widget code.

**Later:** a call-back form (the visitor leaves a number and the PBX calls them), guest chat,
video.

## 2. Delete chat messages and attachments

The sender can delete a message. Everyone in the conversation then sees "message deleted", and an
attached file is removed from the server's disk. Changes are needed in the chat service (Go), the
web chat and the Android and iOS apps; an administrator setting may limit how long after sending a
message can still be deleted.

## 3. S3 storage for chat attachments

Attachments are stored on the server's disk today. An optional S3-compatible storage (AWS S3,
MinIO, Wasabi, Backblaze B2 …) would keep large files off the PBX: bucket, key and region on an
admin page, a connection test, and a one-time move of existing files. Downloads keep going through
the chat service, so access rules stay the same.

## 4. Video calls

The server already allows the VP8 and H.264 codecs for WebRTC, but the web phone and the apps send
audio only. Needed: camera handling and a video view in the web phone and the apps, switching
between audio and video during a call, and sensible bandwidth limits for mobile networks.

## 5. Local AI models (EmbeddingGemma 2, EMA Lightning)

Run AI models **on the PBX itself**, so audio and recordings never leave the server.

- **EmbeddingGemma 2**: turns audio (or text) into a vector without a transcript first. Uses:
  answering machine detection, routing by what the caller says, emotion and noise detection,
  searching recordings by meaning.
- **EMA Lightning**: Turkish text-to-speech with very low delay.

Before building, check each model's **licence** (commercial use), its **speed on a CPU** (most
PBX servers have no GPU) and its quality on 8 kHz telephone audio. The published figures (for
example the first audio after ~4 ms) have to be measured on our own servers.

**Steps.**

0. **Local AI service.** An `aipbx-ai` service reachable only from the server itself. An
   *AI → Local models* page downloads and removes models, shows their size, asks to accept the
   licence, and shows status, RAM/CPU use and a speed test ("does this run in real time here?").
1. **EMA Lightning as a TTS provider.** "Local: EMA Lightning (Turkish)" next to the cloud
   providers on the *Cloud TTS* page. Listening, MP3 download and saving as an announcement work
   as they do today, with no API key and no cost.
2. **Answering machine detection (EmbeddingGemma 2).** On outgoing calls the first 2–3 seconds
   decide between a person and a machine, and the result goes back to the dialplan as a variable.
   Threshold and length are settings; without the local model Asterisk's own AMD is used.
3. **Announcements with values (EMA Lightning).** A dialplan command reads text with values in
   it ("your balance is {amount}") on the fly; the audio reaches Asterisk over AudioSocket. IVRs
   and time conditions get a "read text" option.
4. **Routing by speech (EmbeddingGemma 2).** A new IVR option type. The administrator names
   intentions and example sentences for each queue ("invoice" → Accounting). The caller's words go
   to the closest intention; when the match is uncertain, the caller gets the keypad menu.
5. **Call analysis and search (EmbeddingGemma 2).** After a call its recording is tagged (anger,
   noise, tones); the tags appear as columns and filters in CDR and queue reports, and supervisors
   can be alerted. Recording vectors are stored (the MariaDB of Ubuntu 26.04 has a vector type),
   and the *Recordings* page can then be searched with text ("customers asking for a refund").
6. **Voice bot.** EMA Lightning is only the voice. A bot also needs speech recognition (STT) and
   a language model that decides what to say, so it comes last.

## 6. iOS app: same features as Android 1.0.54

Android 1.0.54 added removing calls from the call history (swipe, clear all) and replying to a
chat notification. The iOS app needs the same two; full-screen incoming calls already come from
CallKit there.

## 7. Dialplan pattern clean-up

The generated inbound dialplan uses the pattern `_.`, which Asterisk warns about on every reload
("The use of '_.' for an extension is strongly discouraged"). Replacing it with `_X.` (or a
pattern that also accepts `+`) removes the warnings from the log; it has to be checked against
DIDs that start with `+` before the change.

## 8. Busy lamps (BLF) and voicemail lamp on desk phones

**Goal.** Multi-key SIP phones (and their expansion modules) show the state of many colleagues at
once: a receptionist or a manager typically watches 10–15 people, an operator console 40–100. Each
key has the colleague's name and a lamp:

| Lamp | Meaning | Pressing the key |
|------|---------|------------------|
| off / green | free | calls them |
| red | on the phone | calls them (or transfers the current call to them) |
| blinking | their phone rings | picks up that call |
| (optional) | do not disturb / not registered | — |

The message lamp shows new voicemail.

**Today.** Every extension already has an Asterisk *hint* (desk phone, web phone and app together)
in `from-internal-pbx`. Desk phones, however, use the context of their dial permission group, and
whether their subscriptions (SUBSCRIBE) reach those hints has to be tested with a real phone. Desk
phones also get no voicemail lamp: their endpoints have no `mailboxes=`.

**Plan.**

- Set `subscribe_context` (or include `from-internal-pbx`) on desk phone endpoints so a key for
  extension 1001 always finds its hint; test with Yealink, Grandstream and Fanvil (including an
  expansion module) and with a softphone in CI.
- Ringing state with the caller's name, and pickup by pressing the blinking key (directed pickup,
  `*21` + extension, already exists); transfer the current call by pressing a busy or free key.
- Many keys at scale: a phone may watch 100 extensions, and 100 phones × 15 keys means 1,500
  subscriptions. Check the subscription expiry and NOTIFY load and set `device_state_busy_at` so a
  user with several devices shows busy correctly.
- Message lamp: `mailboxes=<ext>@default` on desk phone endpoints, so the phone shows new voicemail
  and the count.
- Lamps for more than extensions: queue login (`*95`), do-not-disturb, call forwarding, a
  day/night (time condition) switch, conference rooms and parked calls, through custom device
  states.
- **Key layout page** per user (*Extensions → keys*): the phone's keys and expansion module pages
  drawn as on the device (by model, item 9), each key with a type (BLF, speed dial, park, line,
  DND …), a target and a label. A layout can be copied to other users or a whole group ("the same
  15 keys for the sales team"). Provisioning (item 9) writes it to the phone, so nobody programs
  keys on the device.
- Until provisioning exists: a short guide for setting a BLF key by hand on the common models
  (e.g. Yealink: *DSS Key → Type BLF → Value 1001*).
- Later: the same busy status in the web phone's and the apps' contact lists.

## 9. Phone provisioning page

**Goal.** A new desk phone works within a minute: plug it in, it fetches its configuration from
AiPBX and registers with the right extension, keys and settings, without anyone typing on it.

**Admin page** (*PBX → Phones*):

- **Phone list:** MAC address, model, assigned extension, IP, firmware, last configuration
  download, registration state.
- **Add a phone:** type (or scan) the MAC, pick the model and the extension, done. **CSV import**
  for many phones at once (MAC, model, extension).
- **Waiting phones:** a phone that asks for a configuration but is not known yet appears here with
  its MAC, model and IP; one click assigns an extension.
- **Model templates** for the common vendors: Yealink, Grandstream, Fanvil, Snom, Cisco SPA,
  Poly. They hold server and transport (UDP or TLS), SRTP, codecs, time zone and NTP, language,
  display name, the voicemail key (`*97`), BLF keys (item 8), a dial plan and the phone's own web
  admin password (generated and kept per phone). An administrator can override settings per model
  or per phone.
- **Actions:** re-provision (a SIP NOTIFY `check-sync`, so the phone reloads its configuration at
  once), reboot, firmware upload per model.

**Delivery and security.** Configurations are served over **HTTPS** by the portal, one URL per
phone with a random token (`/provision/<token>/<file>`), never readable from outside the allowed
networks. They contain the SIP password, so every phone gets its own random password and every
download is logged. Plain TFTP and HTTP serve only the first step for phones that cannot start
with HTTPS (see item 10).

**Later:** SIP plug-and-play (Yealink/Fanvil/Snom phones ask the network for their provisioning
server by multicast; a small listener answers with AiPBX's URL), so even the DHCP option is not
needed.

## 10. DHCP and TFTP management

**Goal.** Phones find the provisioning server by themselves: the DHCP server tells them its
address (option 66), and TFTP serves the files of phones that need it.

**Admin page** (*Admin → Network services*):

- **Off by default.** A second DHCP server on a network breaks it, so it has to be switched on
  deliberately, with a clear warning and a check for another DHCP server already answering.
- **Three modes:**
  1. *TFTP only*: the company's existing DHCP server keeps handing out addresses, and the page
     shows exactly which option to set there (66, 150 for Cisco, 160 for Poly, 43 where needed)
     with the right value.
  2. *DHCP for a phone network*: an interface or VLAN, address range, gateway, DNS, lease time,
     NTP (option 42) and the provisioning URL (option 66).
  3. *Off*.
- **Leases:** MAC, IP, host name and vendor (from the MAC) of every device that got an address.
  Phones among them appear on the provisioning page as waiting phones (item 9).
- Implemented with **dnsmasq** (DHCP and TFTP in one small service), written and restarted through
  the `aipbx-priv` root helper like the other system settings. The firewall opens UDP 67/69 only on
  the chosen interface.
- TFTP serves only the files that need it (boot pointers, firmware), never the per-phone
  configuration with passwords, and only to the configured local networks.

**Order.** Item 9 (provisioning over HTTPS) first, because it is useful on its own with the
company's existing DHCP server; item 10 then makes the start automatic.

