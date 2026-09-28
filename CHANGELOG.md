# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → Admin → System Update).

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
