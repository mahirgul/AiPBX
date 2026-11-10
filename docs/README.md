<p align="center"><img src="logo.png" alt="AiPBX" width="96"></p>

# AiPBX Documentation

Administrator documentation for AiPBX on 64-bit Linux (Ubuntu 26.04 LTS+, Debian 13+, Raspberry Pi OS 64-bit, and Armbian on x86_64 & ARM64). Start with the
[installation guide](../INSTALL.md), then use the pages below while configuring the system.

> 🖥️ **Live demo:** [aipbx.bid/demo](https://aipbx.bid/demo/) shows every page of the portal with made-up data, without installing anything.

> 💡 **Quick Diagnosis:** When something does not work, start with the 🗺️ [system map](system-map.md) (where everything is) and 🛠️ [troubleshooting](troubleshooting.md) (symptom → fix).

## 🚀 Getting started

| Page | What it covers |
|------|----------------|
| 🚀 [Installation & updating](../INSTALL.md) | Requirements, one-line installer, certificates, ports, `aipbx-update` |
| 📦 [Incus or LXD container](incus-lxd.md) | Running the normal install in a system container, networking, snapshots |
| 📺 [Video: Installation](https://www.youtube.com/@aipbx) | Step-by-step setup on YouTube: [English](https://www.youtube.com/watch?v=lH1-k_z5jFE) · [Türkçe](https://www.youtube.com/watch?v=eDHHHW6ZcvQ) · [Deutsch](https://www.youtube.com/watch?v=1QuOM6Rtcbg) |
| 📺 [Video: Menu tour](https://www.youtube.com/@aipbx) | Every screen of the portal on YouTube: [English](https://www.youtube.com/watch?v=xU0BYN6AKE4) · [Türkçe](https://www.youtube.com/watch?v=Teol14NgXbY) · [Deutsch](https://www.youtube.com/watch?v=8whV_DY3ZqQ) |
| 🖥️ [Portal basics](portal.md) | The *Apply* workflow, edit dialogs, ordering lists, copying records |
| 💾 [Backups, recovery & rollback](backup-and-rollback.md) | Reproducible daily backups (`aipbx-backup`), disaster recovery restore commands, and zero-downtime automated upgrade rollback (`aipbx-update`) |
| 🛠️ [CLI & Server commands](cli.md) | Terminal utilities (`aipbx-update`, `aipbx-backup`, `aipbx-restore`, `aipbx-selftest`, `aipbx-cert`, SSO recovery) |
| 🏗️ [Architecture](../ARCHITECTURE.md) | Components, traffic flow, security model |

## 📞 Telephony

| Page | What it covers |
|------|----------------|
| 🌐 [Trunks](trunks.md) | Connection modes, inbound DID trimming, transit routing, outbound caller ID normalization, copy / rename / order |
| 🚫 [Blacklist & Spam blocker](blacklist.md) | Inbound spam blocking, AstDB sub-microsecond lookup, wildcards/patterns, drop/busy/congestion/announcement actions |
| 📤 [Outbound routes](outbound-routes.md) | Dial patterns, number manipulation, trunk failover, route groups, RFC 6442 / RFC 6443 Geolocation emergency headers |
| 🎛️ [Outbound and inbound call normalization](call-normalization.md) | Rules for dialled numbers per device (mobile, desk, web), rules for incoming numbers per trunk, ready-made rules per country, E.164, SIP headers |
| 📥 [Inbound routes (DIDs)](inbound-routes.md) | DID matching, destinations, fax DIDs |
| ⚙️ [PBX Settings](pbx-settings.md) | Every tab of System → PBX Settings and where each is explained |
| 📟 [Extensions](extensions.md) | The extension form, copying a SIP password, per-extension SIP settings (transport, DTMF, SRTP, NAT, codecs) |
| 🛣️ [Transports](transports.md) | Where Asterisk listens for SIP: ports, TCP and TLS, your own transports |
| 🎛️ [Advanced Configuration](advanced-config.md) | Your own Asterisk options, objects and file text that survive regeneration and updates, versions, generated files changed by hand |
| 📠 [Fax users and numbers](fax-users.md) | Fax Units, the number a fax goes out with, several and shared numbers, Fax Mail settings, who can delete |
| 📠 [Fax cover page](fax-cover-page.md) | One cover template for the organisation, logo, preview as a fax |
| 🔢 [How numbers change on a call](number-normalization.md) | The order of every number setting on routes, trunks and normalization pages, with a diagram |
| 💻 [Custom Dialplan](custom-dialplan.md) | Custom Asterisk context injection, syntax validator, internal aggregator, recipe templates (Intercom, Spy, Paging, DISA) |
| ☎️ [Desk phones](phones.md) | Provisioning Yealink, Grandstream, Fanvil, Snom, Cisco SPA and Poly phones over HTTPS, CSV import, waiting phones, phones found on the network, key layouts (BLF, speed dial, park), re-provision, regions, SIP over TLS on port 443 |
| 🔵 [Cisco 7900 / 8800 series](cisco-callmanager.md) | Cisco's enterprise phones (CallManager firmware): the `SEP<MAC>.cnf.xml` AiPBX writes, the firmware, the usecallmanager.nz Asterisk patch and the shared files you provide |
| 🖧 [Network settings](network-settings.md) | The server's network cards, and moving every address after an IP change in one step |
| 🔐 [VPN server and client](vpn.md) | WireGuard or OpenVPN: let phones and laptops reach the PBX through a tunnel, and connect the PBX to another network by uploading a profile |
| 🛡️ [Reverse proxy setup](reverse-proxy.md) | Deploying behind Nginx, Caddy, Traefik, HAProxy, Apache; WebRTC WebSocket proxying, trusted proxies, and SIP/RTP bypass |
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
| 🐧 [Linux OS & kernel tuning](linux-tuning.md) | Network sysctl (RTP buffers, MPTCP disable), SIP ALG blacklist, real-time CPU scheduling, OOM protection, needrestart shielding |

## 🔐 Users and security

| Page | What it covers |
|------|----------------|
| 👥 [Users & roles](users-and-roles.md) | Adding users and invitations, the `admin` account, built-in roles, permissions, *My Phone* |
| 🛡️ [Security](security.md) | Firewall (common and per-card rules), fail2ban, the `aipbx-priv` root helper, Apache sandbox |
| 🖧 [Network ports & firewall](network-ports.md) | Complete matrix of all TCP/UDP ports, public vs LAN scopes, NAT guidelines, UFW/firewalld/iptables recipes |
| 🛡️ [Threat model & attack surface](threat-model.md) | Security architecture, SIP exposure boundaries, WebRTC/TURNS credentials, recording retention, mobile push tokens, and AI least privilege guardrails |
| ⚖️ [GDPR, KVKK & data privacy](privacy.md) | Optional regulatory presets (KVKK, GDPR, CCPA/FCC, HIPAA, PCI-DSS, LGPD), pre-call opt-in/opt-out, periodic beep, retention purge, SAR export, Right to Erasure, and Cloud AI lock |
| 📜 [Certificates](certificates.md) | One certificate for portal, TURNS and SIP-TLS; Let's Encrypt, upload, renewals |
| 💡 [Busy lamps (BLF)](blf.md) | Desk phone keys for colleagues, do-not-disturb, forwarding and queue login; pickup; voicemail lamp |
| ✉️ [E-mail](mail.md) | Mail relay and e-mail templates (fax, voicemail, invitations, password e-mails) |
| 🗄️ [File storage](file-storage.md) | Chat attachments on the local disk or in an S3-compatible bucket (AWS S3, MinIO, Wasabi, Backblaze B2), moving existing files |
| 🔑 [REST API v1 & outbound webhooks](rest-api-v1.md) | Stateless `/api/v1/*` endpoints, OpenAPI 3.1 spec, Bearer API keys (`apbx_...`), scopes, IP allowlists, and HMAC-SHA256 signed webhooks |
| ⏱️ [Scheduled tasks](scheduled-tasks.md) | Sandboxed background shell, PHP and Python scripts on a cron schedule (`aipbx-task`, systemd-run), run history, bell alarms |
| 🛠️ [Troubleshooting](troubleshooting.md) | Changes not applied, calls hung up, sounds not found, SIP captures |

## 🔌 Plugins

Optional applications and integrations bundled with AiPBX (`plugins/`: official plugins and community plugins), enabled on demand under **Integration → Plugin Catalog** (`/plugins`):

| Page | Plugin slug | What it covers |
|------|-------------|----------------|
| 🧩 [Plugin system & developer guide](plugin-development.md) | — | The bundled plugin catalog (`plugins/`), `plg_*` database tables, enabling/disabling integrations (`google-auth`, `ms-teams`, …), and building custom `.zip` plugins |
| 🔑 [Corporate SSO: OpenID Connect & SAML 2.0 (Plugins)](sso.md) | `oidc-auth`, `saml-auth` | Enterprise Single Sign-On (SSO) with Microsoft Entra ID (Azure AD), Okta, Keycloak, Authentik, Google Workspace, and ADFS with JIT auto-provisioning |
| 🤖 [Auto dialer & AMD campaigns (Plugin)](auto-dialer.md) | `auto-dialer` | Automated outbound calling campaigns with Answering Machine Detection (`AMD`), queue free-agent pacing, voicemail drop, and CSV call results |
| 🪪 [Caller Name (CNAM) Lookup (Plugin)](cnam.md) | `cnam` | Inbound caller name lookup: local phonebook & extensions first, then BulkVS, Telnyx, Twilio, Vonage, OpenCNAM, SignalWire, NumVerify, AbstractAPI or Custom URL with 30-day cache |
| 💬 [SMS & MMS Messaging (Plugin)](sms.md) | `sms` | Two-way text and multimedia messaging via Twilio, Vonage, Plivo, Telnyx, Bandwidth, Sinch, VoIP.ms or BulkVS with multi-user team inboxes, contacts, and SSRF-shielded attachments |
| 🎙️ [AI Voicemail Transcription & Summary (Plugin)](ai-voicemail.md) | `ai-voicemail` | Automated Whisper audio transcription and LLM executive summaries, urgency classification, action-item extraction, and PCI-DSS PII masking |
| 🌐 [SIP Provider Templates (Plugin)](provider-templates.md) | `provider-templates` | 1-click SIP trunk creation from community carrier profiles (Twilio, Telnyx, A1, Drei, etc.) with pre-configured PJSIP NAT, timers, and codec flags |
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

## 🗺️ Roadmap & Specifications

| Page | What it covers |
|------|----------------|
| 📜 [SDD Guide & Constitution](specs/README.md) | Specification-Driven Development (SDD) guide, core architectural rules (zero regressions, circuit breakers, pure generators), and living specifications index (Specs 001, 002, 003) |
| 🗺️ [Roadmap](roadmap.md) | Planned work across future waves with detailed requirements and milestone criteria |

Planned work (website call widget, chat message deletion, S3 storage, video calls, local AI models,
busy lamps on desk phones, phone provisioning, DHCP/TFTP …) with a short explanation of each: [Roadmap](roadmap.md).
