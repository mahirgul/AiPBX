# Roadmap

Planned work, roughly in the order it is expected. Nothing here is a promise of a date; the
[issues](https://github.com/mahirgul/AiPBX/issues) hold the discussion of each item, and ideas are
welcome there.

## Next: after the 1.50 maintenance freeze

Since 1.50 (October 2026) only bug fixes and tests go in. The items below are collected for the time
after it, in three waves: small things with immediate use first, then platform features, then the
large projects.

### Wave 1: small, immediate use — done (1.50.10)

| Item | Where |
|---|---|
| Trunk limits: calls per minute and channels (inbound and outbound together), health alarm *Trunk limits* | Trunks → Routing; [Trunks](trunks.md#limits) |
| Voicemail limits and PIN rules | PBX settings → Voicemail; [Voicemail](voicemail.md) |
| IVR menu repeat on no key; queue position and periodic announcements | IVR and queue forms |
| Spoken busy / no answer / unreachable messages (early media) | PBX settings → Ringtone |
| Night dialling protection | Dial permissions; [Security](security.md#toll-fraud) |

All of them are off (or unchanged) until switched on.

### Wave 2: platform features — done (1.50.11)

| Item | Where |
|---|---|
| Scheduled tasks: sandboxed shell, PHP, Python background scripts | System → Scheduled tasks; [Scheduled tasks](scheduled-tasks.md) |
| External database connections: encrypted MySQL/PostgreSQL storage & test | Integrations → Database connections; [External databases](external-databases.md) |
| IVR data step: caller lookup via external DB or HTTP API | PBX → IVR Data Steps; [IVR data steps](external-databases.md#ivr-data-steps) |
| Carrier format & dialled number normalisation per trunk | Trunks → edit; [Trunks](trunks.md#carrier-dialled-number-format) |
| PBX fine tuning: per-trunk DTMF, session timers, 100rel, T.38, jitter buffer tuning, SIP timers, night restart, SIP debug | PBX settings → Tuning & Ops; [PBX fine tuning](pbx-tuning.md), [Trunks](trunks.md#media-and-signaling) |
| Failed-registration threshold alarm (an Asterisk-side ACL is still open) | Health checks → Failed Registrations; [Health checks](health.md#checks), [PBX tuning](pbx-tuning.md#failed-registration-alarm) |

All of them are off (or unchanged) until switched on.

**Still open from wave 2:** the scheduled tasks' data access (curated views in `aipbx_export`, a MariaDB
user per task, a REST API token per task, encrypted environment variables) — tasks run, but bring their
own credentials for now; number normalisation of dialled numbers by extensions (2.4, first point);
a failed-registration threshold inside Asterisk itself (2.6 has the alarm only). The sections below are
the full plan.

#### 2.1 Scheduled tasks

**Goal.** Administrators run their own shell, PHP or Python scripts on a schedule: nightly CDR export
to an SFTP server, a CRM sync, a custom backup, polling a web service and raising an alert. Replaces
the "plugin cron" idea of #118 with a feature for everyone.

**Page** (*System → Scheduled tasks*, administrators only, the whole feature off by default):
- task: name, description, on/off; schedule as a cron expression with presets (every 5 minutes,
  hourly, nightly 03:00) and a preview of the next five runs; type shell / PHP / Python; the script in
  an editor or uploaded; timeout (default 5 min), memory and CPU limit; network access on/off
  (default off); environment variables, secret ones stored encrypted and masked;
- "Run now" with live output; history per run (start, duration, exit code, last 64 KB of output);
  script versions; a failed run raises an alert in the bell and the health alarm e-mail.

**How it runs.** One dispatcher started every minute (like the health checks) starts the due tasks.
Every run is a `systemd-run` sandbox: an unprivileged user `aipbx-task` (never root, never
www-data), read-only system, only the task's own folder `/var/lib/aipbx/tasks/<id>/` writable,
private `/tmp`, `NoNewPrivileges`, the limits above, no overlapping runs of the same task. Adding or
changing a task asks for the password again (and 2FA when on); every change and run is in the audit
log with the difference.

**Data access.**
- *Read*: curated views in their own schema `aipbx_export`, without any secret (SIP passwords, PINs,
  password hashes, API keys, 2FA). Calls: `calls`, `call_legs`, `missed_calls` (call-back list),
  `calls_daily`, `call_quality`, `recordings`. Call centre: `queue_calls`, `agent_stats`,
  `agent_pauses`, `queue_sla`, `cc_notes`. PBX: `extensions`, `extension_status`, `trunks`, `dids`,
  `routes`, `ivrs`, `queues`, `ring_groups`, `time_conditions`, `conferences`, `phones`. Fax:
  `fax_in`, `fax_out`, `fax_files`. Voicemail: `voicemail`, `voicemail_text`. Chat: `chat_stats`,
  `chat_messages`. System: `health`, `alerts`, `audit`, `logins`, `sip_messages`, `ai_usage`.
  Views with personal data (recordings, notes, transcripts, chat messages, logins, file paths) need
  an extra confirmation per task and every use is audited; their files are mounted read-only.
  Administrators can save their own views as a SELECT over the allowed ones (checked on save).
  Local time and UTC columns side by side.
- *Write*: only the task's own tables in the schema `aipbx_tasks`.
- *Change the PBX*: only through the REST API, with a token per task limited to the chosen scopes,
  so validation, Apply and the audit log work as in the portal.
- Each task has its own MariaDB user; its password changes on every run and is valid only during it;
  at most 2 connections and a query budget per hour. Connection details come as `AIPBX_DB_*`
  variables and a ready client file.

#### 2.2 External database connections

Connections to MySQL / MariaDB and PostgreSQL (MSSQL and Oracle where the PHP drivers are installed),
stored encrypted like plugin secrets, with a "test connection" button, a timeout and the advice to
use a read-only account. Used by the IVR data step and by plugins.

#### 2.3 IVR data step

A step in an IVR that runs a database query (2.2) or an HTTP request and puts the answer into a channel
variable, with branches *found*, *not found* and *timeout*. Asterisk asks the portal on loopback; a hard
limit of 2–3 seconds; HTTP requests go through the same guard as webhooks (no internal addresses).
Example: "enter your customer number" → look up the customer → route VIP customers to their own queue.

#### 2.4 Number normalisation

- Numbers dialled by extensions in different forms (0532…, +90532…, 90532…) become one form before
  route matching; partly done in [Call normalisation](call-normalization.md), to be finished there.
- Per trunk: the form each carrier wants (strip +90, add 0, a prefix) and the caller ID format, on the
  trunk page.

#### 2.5 PBX fine tuning

- Per trunk: DTMF mode, session timers, 100rel, T.38 details.
- Jitter buffer size, or off (today always adaptive with defaults).
- SIP timers (timer T1 / timer B), Max-Forwards.
- Asterisk log level and rotation; a "SIP debug for 10 minutes" button that switches itself off.
- A restart window: restart Asterisk at night when no call is in progress, so library updates take
  effect (needrestart no longer restarts Asterisk since 1.50.7).

#### 2.6 Asterisk ACL

A failed-registration threshold in Asterisk itself (next to Fail2ban), with the result in the health
checks.

### Wave 3: large projects

#### 3.1 Multi-tenant

One AiPBX per tenant in its own LXD/Incus system container, running the normal installer, plus a
separate control panel: create, suspend, update, back up, quotas, health, and an audited "log in as".
Tenants share the server's IP with their own port blocks (SIP 5060 + 10·N, an RTP slice, own TURN
ports, the same port inside and outside so SDP needs no rewriting); web and WSS go through the host's
nginx by host name; extra IPs are used when the server has them. No Kamailio. A soft limit of about 20
tenants per server (a warning that can be overridden). Before it: remove the places that assume port
5060 (QR login, firewall service, installer firewall/fail2ban/coturn, the My phone page, NEC phone
templates).

#### 3.2 Group voice call from chat

A conference room per chat group (hidden number), started from the mobile app; membership is checked
before joining; the group gets a "call started — join" message and a push notification, members join
when they want (no ringing everyone).

#### 3.3 Community plugins

Move `ai-assistant` out of the core into a community plugin, with a migration for installations that
use it.

#### 3.4 Remote TTS server

The "nefes-0.5" voice (Hugging Face) as a remote engine for announcements; it needs an NVIDIA GPU with
16 GB, so it runs on a separate machine and AiPBX calls it like a cloud TTS.

Also waiting: per-domain apply of pending changes (#120).

## Earlier plans

| # | Item | Issue | Size |
|---|------|-------|------|
| 1 | [Website call widget](#1-website-call-widget) and WordPress plugin | [#14](https://github.com/mahirgul/AiPBX/issues/14) | large |
| 2 | [Delete chat messages and attachments](#2-delete-chat-messages-and-attachments) | [#15](https://github.com/mahirgul/AiPBX/issues/15) | medium |
| 3 | [S3 storage for chat attachments](#3-s3-storage-for-chat-attachments): done, see [File storage](file-storage.md) | [#15](https://github.com/mahirgul/AiPBX/issues/15) | done |
| 4 | [Video calls](#4-video-calls): web phone, Android and desk video phones done (1.22), see [Video calls](video-calls.md); iOS open | [#15](https://github.com/mahirgul/AiPBX/issues/15) | large |
| 5 | [Local AI models](#5-local-ai-models-embeddinggemma-2-ema-lightning) (EmbeddingGemma 2, EMA Lightning): steps 0–1 in [Local AI models](local-ai.md) | | large, in steps |
| 6 | [iOS app: same features as Android 1.0.54](#6-ios-app-same-features-as-android-1054) | | small |
| 7 | [Dialplan pattern clean-up](#7-dialplan-pattern-clean-up) | | small |
| 8 | [Busy lamps (BLF) and voicemail lamp on desk phones](#8-busy-lamps-blf-and-voicemail-lamp-on-desk-phones): watching 10–100 colleagues on multi-key phones | | medium |
| 9 | [Phone provisioning page](#9-phone-provisioning-page) | | large |
| 10 | [DHCP and TFTP management](#10-dhcp-and-tftp-management): first version in [Network services](network-services.md) | | medium |
| 11 | [Visual IVR editor](#11-visual-ivr-editor) (later) | [#16](https://github.com/mahirgul/AiPBX/issues/16) | large |
| 12 | [Server address change in one step](#12-server-address-change-in-one-step): done (1.33.0), see [Network settings](network-settings.md) | | done |
| 13 | [Network settings page](#13-network-settings-page): done (1.33.0) except changing the server's own address (netplan) | | medium |
| 14 | [Menu reorganisation](#14-menu-reorganisation): done (1.33.0) | | done |

Done recently: website call widget with the call-back form and a WordPress plugin ([Website call widget](web-widgets.md)); phone provisioning with key layouts (1.7.0, [Phones](phones.md)); deleting chat messages and the `_.` dialplan clean-up (1.7.0); busy lamps on desk phones for colleagues, do-not-disturb, forwarding and queue login, BLF pickup and the voicemail lamp (1.6.5–1.6.6, see [Busy lamps](blf.md)); e-mail templates (1.6.0), Android full-screen calls, call history clean-up and
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

**Checked (2026-10-09).** Both models are Apache-2.0 (commercial use allowed; weights not gated).
EMA Lightning on a 2-core i7-7700 without GPU, on ONNX Runtime (since 1.9.2): 8.6–11× faster
than real time, a 6.4 s sentence in 0.55 s, model load 1.3–2.4 s, about 150–250 MB of memory,
160 MB of runtime (PyTorch in 1.9.0–1.9.1: 3.4–4.5×, up to 1 GB of memory, 1.1 GB of runtime). EmbeddingGemma 2 (740M parameters, ONNX q4/q8 from onnx-community, measured 2026-10-09 with 68
Turkish test phrases made by EMA at 8 kHz): 1.5–2 GB of memory, 0.9 s per 1.8 s clip on 2 cores,
~700 MB download. Recognising six hotel requests **from the audio** only 54–62 % (chance 17 %),
person/machine from the first 2 s 80 %. The **same requests as text** were matched 11 of 12 times.
Conclusion: understanding needs speech-to-text first, then matching on text; EmbeddingGemma's
audio part is dropped (too heavy, too weak on Turkish speech). See "Model catalogue" below.

**Steps.**

0. **Local AI service** *(done in 1.9.0)*. An `aipbx-ai` service reachable only from the server itself. An
   *AI → Local models* page downloads and removes models, shows their size, asks to accept the
   licence, and shows status, RAM/CPU use and a speed test ("does this run in real time here?").
1. **EMA Lightning as a TTS provider** *(done in 1.9.0)*. "Local: EMA Lightning (Turkish)" next to the cloud
   providers on the *Cloud TTS* page. Listening, MP3 download and saving as an announcement work
   as they do today, with no API key and no cost.
2. **Measure EmbeddingGemma 2** on the same server: CPU speed and memory for 2–3 s of audio,
   and how well it tells apart a person from an answering machine and Turkish requests from
   each other on 8 kHz telephone audio (own recordings, voicemail greetings, sample sentences).
   Steps 4–6 and 8 depend on the result.
3. **Audio bridge (AudioSocket)** *(done in 1.10.0)*. `aipbx-ai` receives and sends a call's audio live through
   Asterisk's AudioSocket (already loaded on the servers); the result (chosen destination,
   person/machine, recognised request) goes back to the dialplan as channel variables.
4. **AI applications with their own numbers** *(1.10.0: page, numbers, destination, announcement with values)*. A new page *AI → Applications*. Each application
   has a type, its settings and an internal number; it is a destination like an IVR or a queue
   (DIDs, IVR keys, time conditions) and can be dialled directly. Several applications of the
   same type work side by side, each with its own settings, for example:

   | Number | Application | What it does |
   |--------|-------------|--------------|
   | 7101 | Routing by speech: sales line | asks "how can I help?" and sends the caller to Sales, Accounts or Support |
   | 7102 | Routing by speech: support line | the same model, other requests and queues |
   | 7201 | Announcement with values: balance | "Dear {name}, your balance is {amount}", read on the fly, then a destination |
   | 7202 | Announcement with values: appointment | another template, the same model |
   | 7300 | Voice requests: hotel reception | see step 6 |
   | 7901 | AI test line | speak and hear what was recognised (request, emotion, person/machine) |

   Every application has a limit of simultaneous calls; above it, or when the model is not
   ready, the caller goes to the normal (keypad) flow. The first type is **announcement with
   values** (EMA Lightning only, possible today); its values come from dialplan variables (e.g.
   the caller's number) or from a web address of the customer's own system.
5. **Routing by speech** *(done in 1.23.0 with Vosk/Whisper speech to text, a small local sentence-meaning model (multilingual-e5-small) and, optionally, a cloud language model, instead of EmbeddingGemma)*. Application type, also usable as an IVR option. The
   administrator names requests with a few example sentences each and a destination ("invoice" →
   Accounting). The caller's words go to the closest request; when the match is uncertain, the
   caller gets the keypad menu or the operator.
6. **Voice requests with a scenario** *(done in 1.11.0 with Vosk speech to text and keyword/example matching instead of EmbeddingGemma)*. Application type for
   requests that do not need a person on the line. Example, hotel: the guest calls 7300 from the
   room phone and says "could I get two more towels?". The request is recognised as *towels*; the
   room comes from the calling extension (no need to say it). The scenario of that request runs:
   - an e-mail to housekeeping ("Room 312 — towels, 14:32") with the guest's recording attached,
     so details such as "two" can be heard,
   - and/or a chat message to a staff group (AiPBX app notification), a web request (webhook) to
     the hotel's own system, a transfer to an extension,
   - and a spoken confirmation by EMA ("Your request has been received").

   Unrecognised requests go to reception. Every request is logged (room, request, time, handled).
   The same type serves other places: a clinic (cancelling an appointment), a service desk
   (fault report), a residential site (calling security). Reading details out of the speech
   (quantity, time) needs speech recognition, see step 9.
7. **Answering machine detection (EmbeddingGemma 2).** On outgoing calls (website call-back,
   later campaigns) the first 2–3 seconds decide between a person and a machine; the result goes
   to the dialplan as a variable. Threshold and length are settings; without the local model
   Asterisk's own AMD is used.
8. **Call analysis and search (EmbeddingGemma 2).** After a call its recording is tagged (anger,
   noise); the tags appear as columns and filters in CDR and queue reports, and supervisors can
   be alerted. Recording vectors are stored (MariaDB 11.8 on Ubuntu 26.04 has the VECTOR type,
   checked on the demo), and the *Recordings* page can be searched with text ("customers asking
   for a refund"). Busy and fax tones need no model: Asterisk detects them.
9. **Speech recognition and voice bot.** A local speech-to-text model (e.g. a small Whisper)
   fills in details of requests (quantity, time, wake-up calls) and, with a language model that
   decides what to say, makes a voice bot possible. EMA Lightning is its voice. Comes last.

### Model catalogue and cloud services (decided 2026-10-09)

**Local model catalogue.** Administrators choose, download and try models themselves. Any
Hugging Face model of a **supported kind** can be used; each kind is an engine written once:

| Engine | Job | Models |
|--------|-----|--------|
| EMA Lightning | Turkish speech | 1 (done) |
| Piper | speech, 40+ languages | hundreds of voices (German *thorsten*, *kerstin*: CC0) |
| Kokoro | speech: English, French, Spanish, Italian … (no German) | tens of voices, Apache-2.0 |
| Vosk | speech-to-text, streaming, small (TR 35 MB, DE 45 MB, EN ~40 MB) | 20+ languages, Apache-2.0 |
| Whisper (ONNX) | speech-to-text, 99 languages, heavier, more accurate | several sizes, MIT |
| Text embeddings (ONNX) | matching requests, text search | many |

*AI → Local models* gets: a recommended list (models we measured, with their speed and memory),
a Hugging Face search limited to supported kinds (size, licence, language, downloads), "paste a
model address", several models on disk, **run/stop** (loaded in memory or not) with a memory and
disk overview and warnings, and a **try** panel (type text and listen, compare two voices;
speak or upload audio and read the text). Only ONNX and data files are downloaded (no pickle,
no remote code); the licence is shown and accepted first, non-commercial licences are flagged;
files are recorded with SHA-256; a disk limit (default 5 GB).

Steps: (a) catalogue and several models: download, run, stop, memory/disk *(done in 1.9.3)*;
(b) Piper engine and the speech try panel *(done in 1.9.3)*; (c) Kokoro engine *(done in 1.9.4)*; (d) Hugging Face search and pasted addresses *(Piper voice list done in 1.9.4)*; (e) Vosk
engine and the speech-to-text try panel (microphone or file) *(done in 1.9.4)*; (f) the recommended list with our
measurements; (g) Whisper and text-embedding engines, with the AI applications. Every engine is
measured on the CI VM (speed, memory, quality) before it is recommended.

**Cloud AI services** *(first version in 1.9.5: accounts, test, usage, cloud speech to text, engine per job)*. A separate page *AI → Cloud services*: provider accounts in one place
(API keys encrypted, connection test), each with what it can do — speech, speech-to-text,
understanding (language models), embeddings — for example Google AI Studio (Gemini, **Gemma**),
OpenAI, Azure Speech, Google Cloud Speech, ElevenLabs, Deepgram, Groq, OpenRouter. The keys of
today's *Cloud TTS* page move there; that page stays as the announcement maker.

**An engine per job.** For each job (speech per language, speech-to-text, understanding) the
administrator picks local or cloud from one list, with an optional fallback (cloud unreachable or
over its limit → local). The AI applications use these choices, so switching from local to cloud
needs no other change. Paid services get a usage counter and a monthly limit; choosing a cloud
engine shows that audio and text are sent to that provider (privacy, KVKK/GDPR).

### Ideas from voice-agent products (plan only, 2026-10-10)

Two products seen as examples: **CentraVox** (custom Turkish voice assistant: inbound and
outbound calls, appointments with calendar and clash checks, confirmation and reminder calls,
assistants handing over to each other with the whole conversation, recordings with transcripts,
asking the panel questions; critical promises are spoken by the system, not the language model)
and **talku.ai** (inbound voice agents as a service, attached to an existing PBX through a SIP
address, ~650 ms response, n8n/Zapier, priced per minute). For AiPBX this suggests, in order:
(1) a destination "external AI assistant (SIP)" so any such service can take calls while
recording and CDR stay here; (2) transcripts of call recordings in the CDR, searchable;
(3) an own assistant (cloud language model, local Turkish voice, allowed actions only, hand-over
to a person when unsure); (4) appointments with a calendar and outbound confirmation calls;
(5) several assistants with hand-over. Not scheduled yet.

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

**Admin page** (*Network & devices → Phones*):

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

**Admin page** (*Network & devices → Network services*):

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

## 11. Visual IVR editor

Planned for later. Since 1.8.0 each IVR has a read-only **Call flow** view (the whole path as a
tree: keys, no input, invalid key, sub-menus, time conditions and announcements). The next step
is editing on that view: dragging destinations onto keys, adding sub-menus and announcements in
place, and seeing the changes before Apply. Until then keys are edited in the IVR dialogs.

## 12. Server address change in one step

**Done in 1.33.0**: health check *Server address*, *Network settings → Apply new address* and
`sudo aipbx-readdress`; see [Network settings](network-settings.md). Planned 2026-10-16. Today the server's IP address is written into many places, at install time or
by hand, and nothing notices when it changes (a new DHCP lease, a move to another network, a
cloned VM). Calls then break in ways that are hard to trace: TURN has no audio, desk phones
register to the old address, the portal's certificate no longer matches.

**Where the address lives today**

| Place | Written by | Holds |
|---|---|---|
| `sys_settings.server_ip` | Asterisk settings | RTP `ice_host_candidates` (SyncRtpSettings) |
| `pjsip_external_ip`, `pjsip_local_net` | Asterisk settings | NAT: public address, local networks of every transport |
| `provision_sip_server`, `provision_base_url`, `provision_syslog`, `provision_ntp` | Phones → Provisioning settings | what desk phones are told (SIP server, files, syslog, time) |
| `provision_allowed_networks`, `netsvc_*` | Phones, Network services | allowed networks, DHCP range, gateway, option 66 URL |
| `/etc/turnserver.conf` | `install.sh` | `relay-ip`, `allowed-peer-ip` (1.32.0) |
| self-signed certificate | `install.sh` / `aipbx-cert` | IP in the subject alternative name |
| `aipbx-mdns` unit | `install.sh` | mDNS alias → address |
| the phones themselves | phone menu or DHCP | boot / file server (NEC), until the next provisioning |

**Plan**

1. **Notice it.** A health check compares the addresses of `hostname -I` with the stored ones
   (`server_ip`, coturn `relay-ip`, provisioning addresses) and raises an alarm (mail, banner on
   the portal): "the server's address changed from A to B".
2. **One action applies it everywhere**: *Apply new address* on the portal and
   `sudo aipbx-readdress <old> <new>` on the command line, both through `aipbx-priv`.
   - First a **preview**: every setting and file that holds the old address, and what it becomes.
   - Then: replace it in the settings above; rewrite coturn's `relay-ip` / `allowed-peer-ip`;
     re-issue a self-signed certificate that names the IP; update the mDNS alias; regenerate PJSIP
     transports, RTP, provisioning and TFTP/FTP phone files; DHCP options when DHCP runs here.
   - Reloads that keep calls (PJSIP, dialplan); coturn restarts only at zero calls.
   - Written to the audit log.
3. **Not changed automatically**, shown as a to-do list instead: the public address behind NAT (its
   own field), DNS records, router port forwards, and phones that were set up by hand with the old
   address (Phone logs shows which phones still fetch from it).

## 13. Network settings page

**Done in 1.33.0** (*Network & devices → Network settings*), except the last point: changing the
server's own address, gateway and DNS with a roll-back is still open. Planned 2026-10-16. Network settings are spread over *Asterisk settings* (external IP, local
networks, RTP ports), *Phones → Provisioning settings* (addresses for the phones, allowed networks)
and *Network services*. One page collects them:

- **Shows** interfaces, addresses, gateway, DNS servers, host name / FQDN, the detected public
  address and whether the server is behind NAT, and the networks AiPBX treats as local.
- **Edits** AiPBX's own network settings in one place: public address, local networks, RTP port
  range, the address phones get, allowed networks. The fields move here from the other pages.
- **Optionally the server's own network** (static address or DHCP, gateway, DNS through
  netplan): with an automatic roll-back, as `netplan try` does: the old settings come back after
  2 minutes unless the change is confirmed from the new address. Changing the address here runs
  item 12.

## 14. Menu reorganisation

**Done in 1.33.0** as below (Lines and Panel kept their entries). Planned 2026-10-16, to do together with 12 and 13. The *PBX* group holds 16 entries, network and
desk-phone pages sit between ring groups and IVRs, and *Admin* mixes users, settings and system
pages. Proposed groups:

| Group | Entries |
|---|---|
| Panel | Overview, My phone, Chat, Reports |
| Lines | Trunks, Inbound routes, Outbound routes, Dial permissions |
| PBX | Extensions, IVR, Time plans, Ring groups, Queues, Conferences, Boss–secretary, Hangup actions, Sounds, Feature codes, Active codes |
| **Network & devices** (new) | Network settings (13), Network services, Phones (provisioning), Phone logs |
| Call centre | unchanged |
| Fax | unchanged |
| AI | unchanged |
| Integrations | unchanged |
| Security | Firewall, Fail2ban, Certificates, Audit log |
| System (was Admin) | Users, Roles, Asterisk settings, Appearance, Push, E-mail, Fax e-mail, File storage, Pending changes, System update, Health checks, Help |

Old addresses keep working (the routes do not change, only the menu). The role matrix and the
module keys stay as they are.

