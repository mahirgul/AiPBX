# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

## 2.2.1

**WebRTC**
- Clients are given TURNS on port 443 (`TURNS_PORT=443`); the built-in nginx
  hands it to coturn on 5349. Calls now get audio on networks that allow only
  443 (hotels, hospitals, guest Wi-Fi, strict corporate networks). Updating
  switches the old default 5349 to 443; clients pick it up at their next
  sign-in or credential refresh.

**Install**
- `sox` is installed explicitly (sound uploads, Cloud TTS announcements).

## 2.2.0

Call-center reporting, certificates from the portal, Cloud TTS and a complete
Turkish prompt set.

**Reports**
- Call reports show the caller, inbound trunk, outbound trunk and dialed
  number; trunk-to-trunk transfers and call direction are visible and
  filterable.
- New Queue Report Centre (Call Center menu): service level, answered /
  abandoned calls, average wait and talk time per queue and per agent; call
  recordings can be played from the queue log.
- Every report can be exported as a branded PDF or as Excel.

**Security**
- New Certificates page: Let's Encrypt, upload your own certificate, or
  self-signed; one certificate serves the portal, TURNS and SIP-TLS and
  renewals are applied automatically.
- User roles: the permission matrix follows the sidebar and the checks the
  pages really make.

**AI**
- New AI → Cloud TTS page: turn text into speech with Google, Amazon Polly,
  Azure, ElevenLabs or OpenAI, download the MP3 or save it as an announcement.
  Google also accepts a service account key. Credentials are stored encrypted.

**Telephony**
- Turkish prompts regenerated in one voice: every Asterisk prompt
  (voicemail, digits, letters, conference, queue, directory, …) is now
  available in Turkish, so calls no longer switch to English halfway. Prompts
  from another PBX that AiPBX never played are removed; updates replace the
  shipped prompts.
- WebRTC: audio works between clients on different networks (NAT mapping,
  TURN relay address).
- TURNS keeps working after a Let's Encrypt renewal (full chain), and TURN
  clients that announce the "stun.turn" ALPN on port 443 reach coturn.

**Portal**
- Files (PDF, Excel, recordings) download instead of opening inside the page.

## 2.1.0

Fixes for Ubuntu 26.04 installations — update recommended. On v2.0.0 the
portal could not write Asterisk configuration ("Apply" changed nothing), could
not run its root helper (firewall, fail2ban, mail, updates) and uploaded or
Turkish sounds were not found, so IVRs and announcements hung up.

**Telephony**
- Trunks: outbound caller ID normalization (keep the last N digits and add a
  prefix, e.g. extension 7840 → 903704187840); copy a trunk; rename its system
  name (routes follow); drag-and-drop order; the edit dialog is split into
  Media & Fax, Network & NAT, Routing and Advanced tabs.
- Outbound routes: copy, drag-and-drop order; a pattern already used in the
  same group is refused.
- "Outbound route" as an inbound/IVR destination: the list loads, the choice is
  saved, and calls go to exactly that route.
- Removing a local network in Asterisk settings now takes effect.
- Call recordings are converted to mono 16 kbps MP3 (about 8x smaller); the CDR
  follows the new file.
- Turkish voicemail prompts renamed to the names Asterisk uses.

**Portal**
- Dashboard: live active calls, channels, queue callers, today's calls and
  per-trunk channel usage.
- New default role `user` (My Phone, chat, own calls); every sign-in method
  opens the right page for the role.
- My Phone: call settings (DND, phone modes, forwarding, voicemail) have their
  own tab.
- Edit dialogs close only with their own buttons, stay anchored at the top and
  widen to fit their tabs.

**Chat**
- Sent / delivered / read ticks (web and Android 1.0.48).
- Online status follows real use: a phone in the pocket or a hidden browser tab
  no longer shows "online"; offline contacts show their last-seen time.
- Android sends "typing…".

**System and security**
- Nightly backup of the database and configuration (`aipbx-backup`,
  `/var/backups/aipbx-daily`, 14 days, optional off-server copy).
- Asterisk's direct WSS port 8089 listens on loopback only and is closed in
  the firewall.
- fail2ban settings from the portal are applied (they were overridden).
- A configuration file that cannot be written no longer counts as applied.
- The installer warns when root can log in over SSH with a password.
- Documentation in `docs/`; end-to-end install/upgrade test (`scripts/e2e`).

**After updating:** existing WAV recordings are converted to MP3 in the
background during the following hours.

## 2.0.0

- Versioning and updates: `aipbx-update` command and a System Update page in the
  portal (backup → update → verification, automatic rollback on failure).
- `install.sh --upgrade`: updates an existing installation without regenerating
  any secrets.
- Security: mobile and chat sessions end when the password changes; two-factor
  authentication on mobile login; Google sign-in checks token audience and
  e-mail verification; command injection via caller ID in incoming fax fixed;
  chat media restricted to the uploader's own files; passkeys require
  biometrics/PIN.
- Call center: breaks are really applied in Asterisk; listen/whisper/barge on
  the board and working; a ringing phone shows as "Ringing"; the board no
  longer overflows on small screens.
- Fresh installs: database built from migrations; CDR/queue logs, TLS/WSS, fax
  send/receive, call recording playback and voicemail permissions fixed.
