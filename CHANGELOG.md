# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

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
