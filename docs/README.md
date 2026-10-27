<p align="center"><img src="logo.png" alt="AiPBX" width="96"></p>

# AiPBX Documentation

Administrator documentation for AiPBX on Ubuntu 26.04 LTS. Start with the
[installation guide](../INSTALL.md), then use the pages below while configuring the system.

> 💡 **Quick Diagnosis:** When something does not work, start with the 🗺️ [system map](system-map.md) (where everything is) and 🛠️ [troubleshooting](troubleshooting.md) (symptom → fix).

## 🚀 Getting started

| Page | What it covers |
|------|----------------|
| 🚀 [Installation & updating](../INSTALL.md) | Requirements, one-line installer, certificates, ports, `aipbx-update` |
| 📺 [Video: Installation](https://www.youtube.com/@aipbx) | Step-by-step setup on YouTube: [English](https://www.youtube.com/watch?v=lH1-k_z5jFE) · [Türkçe](https://www.youtube.com/watch?v=eDHHHW6ZcvQ) · [Deutsch](https://www.youtube.com/watch?v=1QuOM6Rtcbg) |
| 📺 [Video: Menu tour](https://www.youtube.com/@aipbx) | Every screen of the portal on YouTube: [English](https://www.youtube.com/watch?v=xU0BYN6AKE4) · [Türkçe](https://www.youtube.com/watch?v=Teol14NgXbY) · [Deutsch](https://www.youtube.com/watch?v=8whV_DY3ZqQ) |
| 🖥️ [Portal basics](portal.md) | The *Apply* workflow, edit dialogs, ordering lists, copying records |
| 🏗️ [Architecture](../ARCHITECTURE.md) | Components, traffic flow, security model |

## 📞 Telephony

| Page | What it covers |
|------|----------------|
| 🌐 [Trunks](trunks.md) | Connection modes, inbound DID trimming, transit routing, outbound caller ID normalization, copy / rename / order |
| 📤 [Outbound routes](outbound-routes.md) | Dial patterns, number manipulation, trunk failover, route groups, multiple PBXes with different number formats |
| 🎛️ [Call normalization](call-normalization.md) | Pre-filter rules per client origin (Mobile, Desk, Web), E.164 normalization, trunk prefix stripping |
| 📥 [Inbound routes (DIDs)](inbound-routes.md) | DID matching, destinations, fax DIDs |
| ☎️ [Desk phones](phones.md) | Provisioning Yealink, Grandstream, Fanvil, Snom, Cisco SPA and Poly phones over HTTPS, CSV import, waiting phones, phones found on the network, key layouts (BLF, speed dial, park), re-provision |
| 🔵 [Cisco 7900 / 8800 series](cisco-callmanager.md) | Cisco's enterprise phones (CallManager firmware): the `SEP<MAC>.cnf.xml` AiPBX writes, the firmware, the usecallmanager.nz Asterisk patch and the shared files you provide |
| 🖧 [Network settings](network-settings.md) | The server's addresses, the addresses desk phones get, and moving everything after an IP change in one step |
| 📡 [Network services](network-services.md) | DHCP (option 66/150/160) TFTP and read-only FTP for desk phones on a phone network or VLAN, leases, the options for your own DHCP server, an NTP server and phone logs (syslog) by IP or MAC |
| 🩺 [Health checks](health.md) | The server watches itself (services, trunks, disk, certificate, backup …), alarm e-mails, the daily summary with yesterday's calls, `aipbx-selftest` |
| 🎙️ [Call recordings](recordings.md) | Where recordings live, automatic MP3 conversion, playback |
| 📊 [Reports](reports.md) | Call reports with trunks and direction, Queue Report Centre, PDF and Excel export |
| 🔊 [Sounds & languages](sounds.md) | Custom sounds, the Turkish prompt packages (six formats), regenerating a prompt |
| 🌍 [Translating](translating.md) | Interface languages, adding or correcting a translation with `scripts/i18n.py`, mobile app texts, prompts in other languages |
| 📹 [Video calls](video-calls.md) | Video between the web phone, the Android app and desk video phones; the one video codec; bandwidth |
| 🎧 [WebRTC, NAT and TURN](webrtc-nat.md) | Browser and app audio across NAT, external IP, TURNS on 443, troubleshooting silent calls |
| 🗄️ [External databases & IVR data steps](external-databases.md) | Read-only MySQL/PostgreSQL connections, encrypted credentials, real-time IVR caller/DTMF data lookups and call branching |
| 🎚️ [PBX fine tuning & maintenance](pbx-tuning.md) | Jitter buffer, SIP protocol timers (T1, B, Max-Forwards), 10-minute SIP debug, safe night restart window, spoken status messages |

## 🔐 Users and security

| Page | What it covers |
|------|----------------|
| 👥 [Users & roles](users-and-roles.md) | Built-in roles, landing pages, permissions, *My Phone* |
| 🛡️ [Security](security.md) | Firewall, fail2ban, the `aipbx-priv` root helper, Apache sandbox |
| ⚖️ [GDPR, KVKK & data privacy](privacy.md) | Optional regulatory presets (KVKK, GDPR, CCPA/FCC, HIPAA, PCI-DSS, LGPD), pre-call opt-in/opt-out, periodic beep, retention purge, SAR export, Right to Erasure, and Cloud AI lock |
| 📜 [Certificates](certificates.md) | One certificate for portal, TURNS and SIP-TLS; Let's Encrypt, upload, renewals |
| 💡 [Busy lamps (BLF)](blf.md) | Desk phone keys for colleagues, do-not-disturb, forwarding and queue login; pickup; voicemail lamp |
| ✉️ [E-mail](mail.md) | Mail relay and e-mail templates (fax, voicemail, invitations, password e-mails) |
| 🗄️ [File storage](file-storage.md) | Chat attachments on the local disk or in an S3-compatible bucket (AWS S3, MinIO, Wasabi, Backblaze B2), moving existing files |
| 🔑 [REST API v1 & outbound webhooks](rest-api-v1.md) | Stateless `/api/v1/*` endpoints, OpenAPI 3.1 spec, Bearer API keys (`apbx_...`), scopes, IP allowlists, and HMAC-SHA256 signed webhooks |
| ⏱️ [Scheduled tasks](scheduled-tasks.md) | Sandboxed background shell, PHP and Python scripts on a cron schedule (`aipbx-task`, systemd-run), run history, bell alarms |
| 🛠️ [Troubleshooting](troubleshooting.md) | Changes not applied, calls hung up, sounds not found, SIP captures |

## 🔌 Plugins

Optional applications and integrations bundled with AiPBX (`plugins/`: official plugins and community plugins), enabled on demand under **Integrations → Plugins** (`/plugins`):

| Page | Plugin slug | What it covers |
|------|-------------|----------------|
| 🧩 [Plugin system & developer guide](plugin-development.md) | — | The bundled plugin catalog (`plugins/`), `plg_*` database tables, enabling/disabling integrations (`google-auth`, `ms-teams`, …), and building custom `.zip` plugins |
| 🤖 [Auto dialer & AMD campaigns (Plugin)](auto-dialer.md) | `auto-dialer` | Automated outbound calling campaigns with Answering Machine Detection (`AMD`), queue free-agent pacing, voicemail drop, and CSV call results |
| 🌐 [Website call widget (Plugin)](web-widgets.md) | `web-widgets` | "Call us" button for websites (WebRTC in the browser), call-back form, allowed websites and limits, WordPress plugin |
| ☁️ [Cloud AI services (Plugin)](cloud-ai.md) | `ai-cloud`, `ai-voicemail` | Accounts of cloud AI providers (Gemini/Gemma, OpenAI, Azure, Deepgram, Groq, NVIDIA NIM, Qwen, any OpenAI-compatible service), cloud speech to text, language models, voicemail transcription |
| 🗣️ [Cloud TTS (Plugin)](cloud-tts.md) | `ai-tts` | Text to speech with Google, Amazon Polly, Azure, ElevenLabs or OpenAI; saving announcements |
| 🧠 [Local AI models (Plugin)](local-ai.md) | `ai-models` | AI models on the PBX itself (EMA Lightning Turkish TTS): the runtime, downloading models, the `aipbx-ai` service and its API |
| 🎛️ [AI applications (Plugin)](ai-apps.md) | `ai-apps` | AI features with their own numbers that take calls: announcements with values (caller, lookup address) |
| ✨ [Dialplan editor / AI assistant (Community plugin, @shido6)](assistant.md) | `ai-assistant` | Plain-language PBX changes with a preview before apply, the audit trail, undo, and the Echo box that answers questions from this server's own data |
| 📡 [Signaling capture](signaling.md) | PBX settings → Signaling | What `res_hep` sends to the local `aipbx-signaling` daemon, the `pbx_signaling_events` table, and retention (off by default) |
| 📮 [Voicemail](voicemail.md) | PBX settings → Voicemail | Mailboxes, listening (\*97/\*98, portal, apps, e-mail), message limits and PIN rules |
| 📈 [Call quality](call-quality.md) | Reports → Call quality | Packet loss in both directions and jitter per trunk and extension from RTCP; a health check alarms when audio is lost |

## 📱 Mobile apps

The 🤖 **Android** and 🍎 **iOS** apps are built by GitHub Actions and attached to each
[GitHub Release](https://github.com/mahirgul/AiPBX/releases). Users sign in with their portal
username and password (plus 2FA when enabled), by scanning the QR code on *My Phone*, or with the
link in their invitation e-mail.

## 🗺️ Roadmap

Planned work (website call widget, chat message deletion, S3 storage, video calls, local AI models,
busy lamps on desk phones, phone provisioning, DHCP/TFTP …) with a short explanation of each: [Roadmap](roadmap.md).
