# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

## Unreleased

- Trunks: outbound caller ID normalization (keep last N digits + prefix); copy a
  trunk; rename its system name (routes follow); drag-and-drop ordering; the
  edit dialog is split into Media & Fax, Network & NAT, Routing and Advanced.
- Outbound routes: copy, drag-and-drop ordering, duplicate patterns in the same
  group are rejected.
- Roles: new default `user` role (My Phone, chat, own calls); one landing page
  per role for every sign-in method.
- Call recordings are converted to mono 16 kbps MP3 (~8x smaller) and the CDR
  follows the new file.
- My Phone: call settings (DND, phone modes, forwarding, voicemail) have their
  own tab.
- Edit dialogs no longer close on an outside click or Esc, stay anchored at the
  top and widen to fit their tabs.
- Ubuntu 26.04 fixes: the portal can write Asterisk configs and run its root
  helper inside Apache's systemd sandbox; uploaded and Turkish sounds are found
  by Asterisk; portal fail2ban settings are read last; a config that cannot be
  written no longer counts as applied.
- Turkish voicemail prompts renamed to Asterisk's names; the read-only
  `CDR(dst)` is no longer set.
- Documentation moved to `docs/` (GitHub-readable pages).
- Daily backups: `aipbx-backup` dumps the database and configuration every
  night to `/var/backups/aipbx-daily` (14 days, optional rsync copy).
- Security: Asterisk's direct WSS port 8089 listens on loopback only and is
  closed in the firewall; the installer warns about password root SSH logins.
- "Outbound route" as a destination saves correctly and sends the call to the
  chosen route; removing a local network in Asterisk settings takes effect.

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
