<p align="center"><img src="logo.png" alt="AiPBX" width="96"></p>

# AiPBX Documentation

Administrator documentation for AiPBX on Ubuntu 26.04 LTS. Start with the
[installation guide](../INSTALL.md), then use the pages below while configuring the system.

## Getting started

| Page | What it covers |
|------|----------------|
| [Installation & updating](../INSTALL.md) | Requirements, one-line installer, certificates, ports, `aipbx-update` |
| [Portal basics](portal.md) | The *Apply* workflow, edit dialogs, ordering lists, copying records |
| [Architecture](../ARCHITECTURE.md) | Components, traffic flow, security model |

## Telephony

| Page | What it covers |
|------|----------------|
| [Trunks](trunks.md) | Connection modes, inbound DID trimming, transit routing, outbound caller ID normalization, copy / rename / order |
| [Outbound routes](outbound-routes.md) | Dial patterns, number manipulation, trunk failover, route groups, multiple PBXes with different number formats |
| [Inbound routes (DIDs)](inbound-routes.md) | DID matching, destinations, fax DIDs |
| [Desk phones](phones.md) | Provisioning Yealink, Grandstream, Fanvil, Snom, Cisco SPA and Poly phones over HTTPS, CSV import, waiting phones, key layouts (BLF, speed dial, park), re-provision |
| [Network services](network-services.md) | DHCP (option 66/150/160) and TFTP for desk phones on a phone network or VLAN, leases, the options for your own DHCP server |
| [Website call widget](web-widgets.md) | "Call us" button for websites (WebRTC in the browser), call-back form, allowed websites and limits, WordPress plugin |
| [Call recordings](recordings.md) | Where recordings live, automatic MP3 conversion, playback |
| [Reports](reports.md) | Call reports with trunks and direction, Queue Report Centre, PDF and Excel export |
| [Sounds & languages](sounds.md) | Custom sounds, the Turkish prompt packages (six formats), regenerating a prompt |
| [Cloud TTS](cloud-tts.md) | Text to speech with Google, Amazon Polly, Azure, ElevenLabs or OpenAI; saving announcements |
| [Translating](translating.md) | Interface languages, adding or correcting a translation with `scripts/i18n.py`, mobile app texts, prompts in other languages |
| [WebRTC, NAT and TURN](webrtc-nat.md) | Browser and app audio across NAT, external IP, TURNS on 443, troubleshooting silent calls |

## Users and security

| Page | What it covers |
|------|----------------|
| [Users & roles](users-and-roles.md) | Built-in roles, landing pages, permissions, *My Phone* |
| [Security](security.md) | Firewall, fail2ban, the `aipbx-priv` root helper, Apache sandbox |
| [Certificates](certificates.md) | One certificate for portal, TURNS and SIP-TLS; Let's Encrypt, upload, renewals |
| [Busy lamps (BLF)](blf.md) | Desk phone keys for colleagues, do-not-disturb, forwarding and queue login; pickup; voicemail lamp |
| [E-mail](mail.md) | Mail relay and e-mail templates (fax, voicemail, invitations, password e-mails) |
| [File storage](file-storage.md) | Chat attachments on the local disk or in an S3-compatible bucket (AWS S3, MinIO, Wasabi, Backblaze B2), moving existing files |
| [Local AI models](local-ai.md) | AI models on the PBX itself (EMA Lightning Turkish TTS): the runtime, downloading models, the `aipbx-ai` service and its API |
| [Cloud AI services](cloud-ai.md) | Accounts of cloud AI providers (Gemini/Gemma, OpenAI, Azure, Deepgram, Groq …), cloud speech to text, the engine for each job |
| [AI applications](ai-apps.md) | AI features with their own numbers that take calls: announcements with values (caller, lookup address) |
| [Troubleshooting](troubleshooting.md) | Changes not applied, calls hung up, sounds not found, SIP captures |

## Mobile apps

The Android and iOS apps are built by GitHub Actions and attached to each
[GitHub Release](https://github.com/mahirgul/AiPBX/releases). Users sign in with their portal
username and password (plus 2FA when enabled), by scanning the QR code on *My Phone*, or with the
link in their invitation e-mail.

## Roadmap

Planned work (website call widget, chat message deletion, S3 storage, video calls, local AI models,
busy lamps on desk phones, phone provisioning, DHCP/TFTP …) with a short explanation of each: [Roadmap](roadmap.md).
