# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → System → System Update).

---

> ℹ️ Releases prior to version 1.70.0 are preserved in git release tags and release history.

---

## 1.77.0

### ✨ New

- **Group calls from the chat:** a group chat has a voice call and a video call button. Pressing one opens a conference for that group and posts a card to the chat; the other members join from the card. Only members of the group are let in (checked by the PBX for every caller), the room closes when the last one leaves and the chat then shows how long the call took. Video shows whoever is speaking. Works in the web chat with the web phone and in the Android app (`chat_calls` in `features.php` advertises server support).
- Group calls stay out of the call history (My Phone, the mobile apps): their room is no number to call back.

## 1.76.0

Fixes from a review of the whole code base. **Read before updating:** dial permission rules are enforced for the first time (until now only a group's default action applied), calls are answered when they enter a queue, and invitation or password-reset links sent before the update stop working.

- **Calls and registrations dropped without an answer:** every dialplan reload (each Apply) left one more internal subscription behind in Asterisk, caused by the unused sample `users.conf`. They piled up until Asterisk restarted, and then a phone's REGISTER or INVITE could get no reply at all ("stasis/pool-control ... reached 500 scheduled tasks" in the log). The update moves the unused file aside (`users.conf.aipbx-unused`); a `users.conf` with users of its own is kept.
- **Dial permission groups:** the rules of a group never matched, and patterns with `+` or `*` broke the check. A dialled number with characters other than digits, `*`, `#` and `+` is refused before the permission check; a call transferred from the Agent Screen now follows the agent's own permission group and outbound routes.
- **Names in the dialplan:** names and titles (extension, route, queue, IVR, DID, fax header and station ID) are written to the dialplan as letters, digits, space and `. _ + -` only; the blacklist patterns are filtered the same way.
- **Queues and ring groups:** a caller waiting in a queue hears the music and announcements (the call is answered on entry); a member's own voicemail no longer takes a queue or ring-group call. In the call list and on the dashboard a queue call counts as answered only when an agent took it, and its talk time leaves the wait out.
- **Boss–secretary filter** also applies when a DID, IVR key, time condition or announcement leads to the boss's extension.
- **Trunks:** a registering trunk whose user name differs from its name can register from any address; a `;` in a SIP password or in the outbound proxy (`;lr`, `;transport=tls`) is kept; a new trunk name needs a letter (a number-only name clashed with an extension).
- **Outbound recording** honours the caller's privacy opt-out and the recording beep setting, and can be switched off: Privacy → **Record outbound calls** (on by default, as before).
- **Accounts:** a role change takes effect at once in open sessions, the REST API session fallback follows password changes too, reset tokens are stored hashed and no longer returned by the API, the e-mailed mobile sign-in link is not offered to accounts with two-factor or single sign-on only, and the 8-character password rule applies on every path.
- **REST API v1** no longer returns conference PINs or a phone's (encrypted) admin password.
- **Extensions without a password** (desk phone without digest auth): their web and mobile accounts could end up with the guessable password `Pass<extension>!` (after the extension's number was changed). They now get a random password, which the web phone and the mobile app also receive.
- **Mobile app sessions:** turning on two-factor authentication ends the account's other sessions, the app's included; a password handed out by the administrator must be changed on the web before the app signs in with it.
- **Sender addresses** in the mail and fax settings must be valid e-mail addresses.
- **Registering trunks with a From domain** register to the trunk's port (a carrier on 5080 was registered to on 5060).
- **Files and PINs:** generated Asterisk files are written 0640; a new night-protection PIN needs 6 digits; a mailbox without a PIN gets a random one instead of the extension number.
- **Privacy:** retention and erasure also remove messages from the voicemail spool; a number shorter than 10 digits matches exactly (as a fragment it matched, and erased, other people's records).
- **Queue log:** the agent's name in the call steps is escaped.
- **Web server:** the server-side folders (`templates`, `config`, `lang`, `docs`, `etc`) and `.md` files are no longer served.
- **Backups:** every update starts with a full backup that is listed on the Backup page (`…-before-update`) and can be restored from there; if it fails, the update does not start. An uploaded dump with triggers, routines or events can be restored; `aipbx-update` prunes only its own backup folders. (The update to this release still runs with the previous updater; the backup is taken from the next update on.)
- A second queue with an existing name gets a clear message instead of a database error.
- **Sound and music-on-hold uploads** ([#164](https://github.com/mahirgul/AiPBX/issues/164)): PHP's upload limit was 2 MB, so a few minutes of music (or a larger plugin package) was refused and the page showed nothing. Uploads may now be up to 64 MB, and a refused file says why. Uploaded WAV, MP3 and other formats are converted for Asterisk as before.

## 1.75.0

- **Callback confirmation:** choose a separate confirmation announcement per queue. It plays after a callback request is saved, then the call ends; failed requests never play the success announcement.
- **Manual queue callbacks:** optionally let callers press 1 while waiting to leave a request using their caller number. Assigned agents see a shared list on the Agent Screen and click Call to ring their own phone first. A completed answered call with any queue agent removes pending requests for the same number, including when the caller rings again; an unanswered call leaves the request pending. Configure a periodic announcement explaining the option. This does not preserve a virtual queue position or call anyone automatically.
- **Complete translations across all 22 languages:** 100% coverage (6219/6219 strings) across all supported portal languages with strict placeholder and markup validation.

## 1.74.0

- **Device-only extensions:** an extension can be a phone without a person (Extensions → **Device only (no portal user)**, off by default) for a lobby, door or meeting-room phone or a shared line. It registers as a SIP phone and is an extension everywhere, but it cannot sign in to the portal or the mobile app by any way, is not listed under System Users and does not appear in chat. An administrator's or your own account cannot be made device only.
- **Users without an extension:** the extension is optional on System Users. Such a user has the portal, but no phone and no chat.
- **A second number on a desk phone:** a **Line** key may carry a device-only extension. The phone registers it as one more account next to the user's own line; calls to it ring on that key and the key calls out from it. Yealink, Snom and Grandstream (accounts 2 to 4, from the vendor's P-value list, not yet confirmed on a phone). Only device-only extensions can be put on a key. The web phone and the mobile app stay on the user's own number.
- **Signed plugin catalog:** the community catalog is used only with a valid Ed25519 signature of the list (`catalog.json.sig`), and a list older than the one in use is refused. Until now the SHA-256 of a package was only as trustworthy as the unsigned list naming it. A refused list is written to the audit log; the last good one stays in use.
- **Docs:** what the WebRTC media path does behind symmetric NAT and on networks that inspect or restrict TLS ([WebRTC & NAT](docs/webrtc-nat.md)); carrier registration and caller reputation for SMS and the Auto Dialer.

## 1.73.5

Fixes for 1.73.0–1.73.4. Updating from 1.72.x is recommended to go straight to this release.

- **Phones can be re-provisioned and restarted again:** since 1.73.0 the portal was no longer allowed to send the SIP NOTIFY for "Resync" and "Reboot" on the Phones page.
- **API v1 click-to-call works again:** `POST /api/v1/calls` was refused by Asterisk since 1.73.0.
- **The portal's Asterisk command list is enforced as intended:** Asterisk matches each allowed command anywhere in what is typed, so 1.73.0's list let a forbidden command through when it contained an allowed phrase. Every entry is now matched from start to end, and a test checks that each command the portal sends is on the list.
- **Plugins with tables no longer turn themselves off:** since 1.73.1 a plugin with a `schema.sql` and a background worker (Auto Dialer) failed on every worker tick, was switched off after five errors and lost its API token, typically right after an update. Existing tables are now recognised without needing extra rights. If Auto Dialer is off after 1.73.1–1.73.4, enable it again under Plugins.
- **Calls without a DID can be routed:** new trunk field **Number for calls without a DID** (Trunks → Inbound call & DID normalization). Before it, AiPBX looks for the dialled number in the `To`, `P-Called-Party-ID` (also `tel:`) and `Diversion` headers and follows the first one with an inbound route, evaluated as-is, after the trunk's DID trim, and after inbound DID normalization rules. A header number without a route is not followed, so a carrier header cannot send a call out through transit routing. 1.73.0–1.73.4 read only `To` and dropped calls whose To header held letters or an unknown number. See [Trunks](docs/trunks.md#calls-without-a-did).
- **Behaviour change kept from 1.73.0, now stated:** when outbound routes exist but all are disabled, outbound calls are off. The first trunk is no longer used as a fallback.
- **Restore:** database migration credentials in an uploaded backup are ignored; the settings key keeps its owner after a restore.
- **Installer:** the progress bar counts the steps in `scripts/install/`, with safe fallback when run through piped curl under `set -euo pipefail`.
- Plugin schema changes and `/etc/asterisk` writes go through the shared privilege helper.

## 1.73.4

- **Robust runtime database credential preservation in restore:** Replaced fragile post-extract shell sed substitution in `aipbx-restore` with pure-Python literal merger `preserve_runtime_db()` using safe atomic replacement via `tempfile.NamedTemporaryFile` (mode 0640 `root:asterisk`), ensuring passwords with special characters (`&`, `/`, `\`, `$`, quotes) are perfectly preserved during untrusted backup restoration. Added regression test coverage.
- **UI style lint fix:** Removed undefined CSS helper class `u-d-block` from Asterisk settings TLS verification hint element, passing all smoke test conventions.

## 1.73.3

- **Harden database user validation & prevent account hijacking from untrusted archives:** Prevented untrusted backup restores in `aipbx-restore` from altering the host system's database runtime credentials (`DB_USER` and `DB_PASS`). Enforced strict reserved-account rejections (`root`, `admin`, `mysql`, `mariadb.sys`, `debian-sys-maint`, `asterisk_odbc`, `asterisk`) in both `aipbx-restore` and the MariaDB installer step (`06-mariadb.sh`).
- **Enforce strict atomic file writing contracts in FileHelper:** Removed non-atomic in-place truncation and flock fallbacks in `FileHelper::writeFile`. When writing to protected `/etc/asterisk` configuration files, `FileHelper` delegates exclusively to `aipbx-priv asterisk-conf write` and fails cleanly without modifying or truncating the target file if the broker fails. Added `FileHelperAtomicTest`.
- **Localization completeness:** Added missing English and Turkish dictionary translations for SIP TLS verification (`asterisk_settings.field_tls_verify_server`, `asterisk_settings.tls_verify_server_help`, `transports.field_verify_server`).

## 1.73.2

- **DDL privilege broker Apache sandbox escape:** Updated `aipbx-priv plugin-schema` to execute transiently via `systemd-run` in system root mount namespace, ensuring Apache's `InaccessiblePaths=-/etc/ai-pbx-migrator.env` sandbox restriction does not block the root privilege broker from accessing migrator credentials during web plugin installations and updates. Added error propagation on plugin schema drop.
- **Atomic configuration write broker for `/etc/asterisk`:** Added `aipbx-priv asterisk-conf write` privilege helper and fallback locking in `FileHelper::writeFile`, allowing unprivileged web processes to safely update `/etc/asterisk/rtp.conf`, `udptl.conf`, and `voicemail.conf` without relaxing directory permissions on `/etc/asterisk` (`0755 root:asterisk`).
- **Restore archive & MariaDB user sanitization:** Hardened `aipbx-restore` to explicitly ignore untrusted `/etc/ai-pbx-migrator.env` archives and strictly validate database user identifiers against `^[A-Za-z0-9_]{1,32}$`.
- **Asterisk CLI permission expansion:** Permitted required operational commands for `www-data` in `cli_permissions.conf` including `moh reload`, `voicemail reload`, `devstate change`, `pjsip set logger`, `module reload`, and `database deltree`.
- **Umask hardening on credentials creation:** Wrapped `/etc/ai-pbx-migrator.env` and `/etc/ai-pbx.env` creation in subshells with `umask 077` to prevent any temporary world-readable permissions during initial file generation.
- **Nginx Stream directive fix & CI workflow paths:** Corrected invalid Nginx stream directive from `ssl_preread_timeout` to `preread_timeout 5s`, surfaced `nginx -t` validation errors during installation, and included `scripts/install/**` in GitHub Actions install test trigger paths.
- **End-to-end SIP TLS server certificate verification:** Added `pjsip_tls_verify_server` setting and `pbx_transports.verify_server` column migration with web UI controls for both standard and custom TLS SIP transports.

## 1.73.1

- **Privileged plugin schema execution via privilege broker:** Fixed an issue in web portal plugin installations and updates where unprivileged web processes (`www-data`) failed to execute plugin `schema.sql` statements due to runtime database DDL restrictions. Unprivileged callers now transparently delegate DDL schema application and table drop operations to `sudo aipbx-priv plugin-schema`, ensuring seamless catalog updates without compromising database privilege isolation.

## 1.73.0

- **Modular installer architecture (`scripts/install/`):** Refactored the monolithic `install.sh` (2,800+ lines) into 15 focused, self-contained step scripts under `scripts/install/` while maintaining full backward-compatibility with remote piped execution (`curl -fsSL ... | sudo bash`) and the `install.sh --upgrade` workflow.
- **Database privilege separation (DDL vs DML):** Separated migrator credentials into `/etc/ai-pbx-migrator.env` (0600 root:root). Hardened runtime portal database access (`/etc/ai-pbx.env`) to DML privileges only (SELECT, INSERT, UPDATE, DELETE). Added `aipbx-priv plugin-schema` broker command and `web/bin/plugin_schema.php` CLI helper for isolated plugin schema migrations.
- **Asterisk CLI whitelisting & socket protection:** Hardened `/etc/asterisk` permissions to `0755 root:asterisk` and installed `/etc/asterisk/cli_permissions.conf` (0640 root:asterisk) with `default_perm = deny`, allowing only whitelisted non-destructive operational commands for `www-data` while blocking `core stop`, `module load`, and shell execution.
- **Edge 443 multiplexer & service sandboxing:** Enforced rate and connection limits (`limit_conn` and `ssl_preread_timeout`) on Nginx Stream 443, configured systemd service sandboxes (`InaccessiblePaths` and `UMask=0027`), secured Composer cache and umask permissions, and enforced TLS certificate verification on SIP trunks.
- **Root log and state isolation:** Added dedicated `aipbx-log-init`, `aipbx-proxy-https`, and `aipbx-system-state` privilege broker commands ensuring all root log and state files cannot be manipulated or hijacked by lower-privileged services.

## 1.72.12

- **Inbound caller name (CNAM) dialplan integration (#159):** Added native `sub-cnam-lookup` hook into inbound trunk dialplans with dynamic `DIALPLAN_EXISTS` evaluation, sub-2s timeout fail-open execution, and caller ID name propagation before call distribution.
- **AI Voicemail transcription lifecycle synchronization:** Subscribed the AI Voicemail plugin to `voicemail.transcribed` instead of preliminary metadata events, guaranteeing completed Whisper speech-to-text transcripts and LLM summaries are reliably captured and displayed.
- **Plugin development security framework & guidelines:** Documented comprehensive security standards (`docs/plugin-development.md`) covering loopback remote address enforcement, private lookup tokens, outbound SSRF prevention on media/webhooks, DNS rebinding mitigations, and database concurrency controls.
- **Caller Name (CNAM) community plugin release (v1.0.0):** Published the official community CNAM plugin in the plugin catalog supporting BulkVS, OpenCNAM, Custom HTTP endpoints, and local extension/phonebook matching with a 30-day query cache to minimize provider lookup costs.

## 1.72.11

- **Security hardening across authentication and credential boundaries:** Fixed a chat authentication token expiry bug where expired tokens were accepted while cached in memory, enforced persistent-only encryption keys in SecretBox to prevent credential loss across service restarts, and closed an outbound dialplan leakage loophole when all configured outbound routes are disabled.
- **Security regression test suites:** Added comprehensive automated regression tests covering token revocation, non-canonical user identifier reuse, secret box key permissions (0600), dialplan isolation, and partial upload cleanup on connection drops.
- **Server CLI and administration guide:** Added dedicated documentation (`docs/cli.md`) covering all native command-line utilities (`aipbx-update`, `aipbx-backup`, `aipbx-restore`, `aipbx-selftest`, `aipbx-cert`, and SSO recovery).
- **BulkVS SMS/MMS provider support:** Added BulkVS to the official community SMS/MMS plugin (v1.2.0) in the plugin catalog with support for outbound messaging and inbound JSON webhooks.

## 1.72.10

- **Cloud STT validation scoping:** Restricted `base_url` and `model` configuration payload attributes strictly to the `openai_compat` provider in Cloud Speech-to-Text services, preserving clean payload schemas for standard providers and ensuring complete test suite compliance.

## 1.72.9

- **Unified tabbed extension modal:** Reorganized the extension edit and create dialog into a modern 5-tab interface (`Basic Settings`, `Call Routing & Forwarding`, `Groups & Pickup`, `Voicemail`, and `Advanced SIP & Media`), adopting the standard portal modal tabs pattern matching trunks and queues.
- **Advanced SIP and RTP media controls for extensions:** Extensions can now configure individual RTP and media parameters directly from the portal, including `direct_media`, `ice_support` (WebRTC / NAT traversal), `media_address` (custom RTP endpoint IP), `media_encryption` (None, SRTP, DTLS), `media_encryption_optimistic`, custom RTP timeout / keepalive intervals, and customized codec priority lists.
- **Queue recording one-way audio stream fix:** Resolved an audio collision in queue recordings where the agent voice stream was silenced while MixMonitor was active, caused by redundant `monitor-format` definitions in Asterisk queue configurations.
- **Registration trunk DID recovery from SIP To header:** Inbound calls arriving on registration trunks via default `s` extension (common with providers like VoIP.ms and Twilio) now parse the original dialed DID from the SIP `To:` header URI, accurately routing calls to configured DID destinations instead of fallback catch-all rules.
- **Early 180 Ringing for queues and ring groups:** Added early `Ringing()` signalling before entering Queues and Ring Groups so external SIP carriers receive timely 180 Ringing indications, avoiding carrier premature dropouts before agents answer.
- **Configurable registration contact user on trunks:** Outbound SIP trunk registrations can now specify an explicit `contact_user` parameter for carriers that require a fixed contact user in REGISTER headers.
- **NAT and audio troubleshooting handbook:** Added comprehensive NAT troubleshooting documentation covering one-way audio diagnostics, RTP symmetric media requirements, and STUN/ICE recommendations.

## 1.72.8

- **Queue supervisor and agent pause consistency:** Agent statuses are now tracked per queue in `/cc-supervisor`, preventing status contamination across queues. Dynamically joining a queue preserves an agent's active pause, and logging out of a single queue does not clear pause state while the agent remains logged into other queues.
- **2FA and forced password reset flow:** Signing in with two-factor authentication now enforces the required password change when `must_reset_password` is set, redirecting directly to the reset screen.
- **Web widgets and external numbers:** Fixed an issue where the destination number of a widget was overwritten by an absent field in the V1 API.
- **Cloud STT and OpenAI-compatible endpoint:** Added `openai_compat` provider support with custom `base_url` and `model` configuration for cloud speech-to-text.
- **Cloud TTS test with unsaved credentials:** The Test button on AI TTS settings now validates against the credentials entered in the form before saving.
- **S3 recordings and voicemails:** Playback on the MyPhone portal and CDR reports now correctly detects recordings stored in S3 buckets.
- **Privacy (KVKK / GDPR) media cleanup:** Data erasure and retention policies now purge faxes (via FaxStorage), call recordings in S3, and archived voicemails.
- **Voicemail inbox count synchronization:** Deleting or marking messages as heard immediately updates the inbox unread counter.
- **Network policy routing rules:** `aipbx-netcfg` now flushes interface-specific ip rules before re-applying, and preserves them during configuration backup and restore.
- **Resent fax files preservation:** `fax_cleanup.sh` avoids removing documents that are still referenced by pending or active resent faxes.
- **PJSIP trunk configuration cleanup:** Prevented duplicate `100rel` entries on endpoints and orphaned T.38 fields.
- **IVR data step DTMF timeout handling:** Dialplan routes empty inputs to the timeout destination, and the service records a timeout status.
- **SPA navigation stability:** Script declarations on MyPhone, Call Center board, conferences, and AI apps now prevent re-declaration errors during dynamic page transitions.

## 1.72.7

- **The update's page test no longer fails on /plugins with the community catalog on.** Creating the catalog's folder warned when it already existed (1.72.5, 1.72.6); the folder is now made only when missing. The page test refuses `@mkdir()` without an `is_dir()` check, as `@` does not hide the warning from it.

## 1.72.6

- **Administrators and queue managers can be queue agents too** (#160). An administrator or a queue manager with an extension now appears on the Agents list of a queue like an agent or a normal user, and gets the Agent Screen once added to one.
- **Certificate region names are checked in DNS and removed when their region is gone.** When the portal's name was in `/etc/hosts` as `127.0.1.1`, `getent` stopped there and the DNS check for region names did not run; DNS is queried directly now, and a region name that was removed is no longer kept when a new one is added.
- **Applying a moved server address on Pending Changes finishes in one click.** Moving the address left the extensions' identify lists waiting for a second Apply; they are written in the same Apply now.
- **The plugin catalog remembers an unreachable GitHub on a fresh server too.** Its folder is made before the empty cache file is written, so `/plugins` does not wait 8 seconds on every visit before the first plugin is installed.

## 1.72.5

- **The one-line install works again** (`curl ... | sudo bash`). 1.72.4's step counter read the script's own file name, which a piped script does not have, and the installer stopped on its first lines. The installer test now runs the start of install.sh from a pipe.
- **Renew now adds new region names to the certificate.** A renewal kept the names the certificate already had, so a SIP name given to a phone region later never arrived; Renew now on the Certificates page makes the certificate again with it, the way it was made (Apache, webroot or Cloudflare DNS). A region name is compared with the portal's name in DNS, not with a loopback line in /etc/hosts.
- **A changed server address also reaches the phones' identify lists** on Apply, and turning on SIP on 443 for a region that already had a name now says to renew the certificate.
- **The last copy buttons work over plain HTTP:** a new user's password, a new API key, the reverse proxy snippets and the meeting link.
- **Safer plugin packages:** files beside the plugin's folder in a zip are left out, a zip with too many entries is refused before it is read, a download stops at its size limit while it arrives, an update puts the old version back if the new one cannot take its place, and a catalog download must hold the version the catalog lists. The catalog list is written whole or not at all.
- **Smaller fixes:** a first nginx list that nginx refuses is emptied instead of left in place, the region form tells an IP address from a name as the server does, and `aipbx-cert` refuses an IPv6 address as the portal's name for Let's Encrypt.

## 1.72.4

- **Turning off SIP over TLS on 443 no longer strands a region's phones.** Their port stayed 443, which only reaches Asterisk while the option is on; it is cleared now and the phones take their transport's port.
- **HTTPS on a region's SIP name reaches the portal.** A browser or a phone fetching its file over HTTPS from the same name was handed to Asterisk; only SIP connections go there now.
- **A region name without DNS no longer costs the portal its certificate.** A region's SIP name joins the Let's Encrypt certificate only once it points where the portal's name points (and at most 98 of them); until then it is left out with a note in the log.
- **Moving to a new server address also moves the public media address.** The preview listed it, but it was not saved.
- **The general SIP server name of desk phones is accepted by Asterisk** like each region's name; changing it waits on Apply.
- **Copy buttons work on a portal opened over plain HTTP** (by IP address on the local network), where the browser has no clipboard access: the text is copied the old way, or you are told to copy it by hand.
- **The Plugins page waits at most 8 seconds for the catalog** once an hour, and no longer on every visit when GitHub cannot be reached. Catalog entries without a version are left out, and a download must hold the plugin asked for.
- **`scripts/check_plugins.py` knows `user_page` and `requires_aipbx`,** so plugins that use them pass.
- **Network settings says where the addresses went:** the PBX's addresses are on PBX Settings → PJSIP & Net and the desk phones' on Provisioning → Regions, with links.
- **Renaming your own account shows at once,** not after the next sign-in. A plugin's user page has its title in your language, and a script posting to it gets JSON back on an error too.
- **Discarding a second address move no longer brings back the first.**
- **Tidier underneath:** 192 texts no code used are gone from all 23 languages; every copy button goes through one helper; pages use only defined styles; the page test also checks the layout's header and footer; the installer counts its own steps; GitHub Actions are pinned to commits; PHPStan 2.3 and PHPUnit 13.4.

## 1.72.3

- **The guides match the portal again.** Addresses that moved to PBX Settings → PJSIP & Net and Provisioning → Regions, the firewall's common and per-card rules, Fax Mail settings, the number a fax goes out with, the fax tone times and how an update checks itself and rolls back are described as they are now, and menu paths that changed are corrected. New guides: Extensions (with each extension's SIP settings) and PBX Settings (every tab); adding users and the `admin` account are explained.
- **Help texts point to the right page.** Five texts (TFTP, phone logs, transports) still sent you to the provisioning or network settings for the allowed networks and the public address; they name Provisioning → Regions and PBX Settings now.
- **Every language is complete again.** The texts added since 1.71.0 (SIP over TLS on 443, the plugin catalog, copying a SIP password, deleting the admin account) were English in the other 22 languages.

## 1.72.2

- **The update's page test no longer fails on /plugins.** With the community catalog on, PHP 8.5 warned that `curl_close()` is deprecated, and the test after the update reported it as an error (the update itself had gone through). The call did nothing since PHP 8.0 and is gone everywhere (webhooks, captcha, AI services, the IVR data step); the page test now refuses it in the code.
- **A tidier installer.** The AiPBX logo at the start, a box with the server and folders, a progress bar with the step number (3/13), ✔ ⚠ ℹ ✖ marks in their colours, and the result in boxes with how long it took. Colours only on a terminal (not in a log file or with NO_COLOR), plain ASCII without a UTF-8 locale; the messages' words are unchanged.

## 1.72.1

- **The installer's admin account can be renamed and deleted** (#156), like any other administrator: the user name is no longer locked, and Delete shows on every account but your own. The last active administrator still cannot be deleted.
- **A fax goes out with the fax account's own number** (#157). The External Call CID of a fax extension was ignored when the trunk had its own outbound number, as a fax sent from the portal did not carry it to the route; it now beats the trunk's number as on a phone call.
- **The menu button sits by the menu and the portal opens with the menu open.** A closed menu is remembered for the browser session only (it was kept for a year, so every page opened closed with the arrow pointing right), and the button moved from 32 to 12 pixels off the menu's edge.

## 1.72.0

- **Community plugins from the catalog, without a .zip** (#152). A switch on Integrations → Plugins lists the community plugins of github.com/mahirgul/aipbx-plugins there; Install and, when a newer version is out, Update fetch the plugin, check it against the catalog's SHA-256 and install it like an upload. Off by default, as it installs code from the internet.
- **A plugin can name the AiPBX it needs.** `"requires_aipbx": "1.71.0"` in plugin.json: an older AiPBX refuses it with that message instead of failing later.

## 1.71.1

- **SIP over TLS on port 443 works when set in the portal.** Saving a region wrote nginx's lists from inside Apache's sandbox, where /etc/nginx is read-only, and the failure went unnoticed: the phones' connections still went to the TURN server. The lists now live in /etc/nginx/aipbx-sip443/, the one folder there the portal may write, a failed write shows an error, and the update writes them again from the regions already saved.
- **Copy a SIP password from the Extensions table** without showing it.

## 1.71.0

- **SIP over TLS on port 443 for a phone region**, for networks that let TLS out only on 443. Its phones get TLS and port 443, and nginx on 443 hands them to Asterisk by the region's own SIP server name (sip1.example.com, sip2.example.com beside the portal's name), so browsers keep TURN on 443; a region without a name of its own lists its phones' addresses instead. Let's Encrypt puts the regions' names on the one certificate.
- **Plugins can give users a page.** A plugin's `user_page` is opened at /apps/<plugin> by the signed-in users it chooses, with its own line in the menu; until now plugin pages were for administrators only. The SMS/MMS plugin (#152) uses it.
- **A region's own SIP server name is accepted by Asterisk.** Phones that used a name other than the server's own were not recognised; the regions' names now join the names an extension answers to (on Apply).

## 1.70.0

- **One look across the portal.** Tabs join the page below them on every screen, and My Phone's are real tabs now. Row buttons are one size, and Save sits at the end of a form on the right, as in a window.
- **Things that had no style now have one.** Message boxes, muted text, spacing and form columns were used on many pages with no rule behind them. Custom Dialplan, Security and Teams windows use the same window as the rest.
- **Dark theme.** Pages that used undefined colours fell back to light boxes; they follow the theme now.
- **Every page title looks the same.** A blue bar on the card's edge, a soft icon tile and the same size, on all pages. On pages with tabs, one card now holds the title, the tabs and the content.
- **Help works one way.** Every page opens its explanation with the "?" button; none stays open, and intro text moved into it.
- **Buttons have one size per place:** titles 32, forms and windows 36, table rows 28. In a title only help, refresh, back and full screen are icons; all others have text.
- **Inside the pages:** about 830 inline styles became shared classes on one scale of sizes, so small differences in spacing and text are gone. Columns stack on a phone.
- **For developers:** `uiPageHeader()` and `uiHelpBoxOpen()` draw titles and help, the page test refuses own versions, and `components.css` is split into `assets/css/ui/`.
- **`/ui-kit`** shows the shared parts on one page for administrators, and the page test refuses the old markup.
- **Phone addresses with the regions.** The general addresses desk phones are given (SIP server, proxy, files, syslog, NTP) and the allowed networks moved from Network settings to Provisioning → Regions, above the regions that start from them; a region's own value still wins. Network settings keeps only the server's own addresses.
- **Fixes found in review.** The 403, blocked and database-down pages asked for a stylesheet that no longer exists; they load the shared ones now. My Phone closed one block too many, which pushed its QR window and the footer out of the page. IVR data steps show their three branches side by side again. Status badges on My Phone, Security, Privacy and Chat stand by the title, and the help button sits with the actions.
- **Safer underneath.** symfony/config and symfony/filesystem 8.1.11 (two advisories; Phinx uses them during updates). The page test now checks that every view closes its blocks, that every u-* class exists, that badges stay by the title and that every /assets/ file named exists; CI also checks dependency advisories and JavaScript syntax.
- **PBX addresses on PBX Settings → PJSIP.** The server's address, the public and media addresses and the local networks moved there from Network settings, next to the other PJSIP settings; Save puts them on Apply as before.
- **A changed server address waits on Apply.** When the server's IP changed outside the portal (a new DHCP lease, a cloned VM), the move of everything that still holds the old address is put on the Apply page by itself, naming what it changes; Apply does it, or Discard keeps the old address. The "Apply the new address" form is gone. The move also failed on a database error after a network card's address was confirmed; it works again.
- **Firewall: common rules and an off switch.** A Common tab holds the rules every network card uses (virtual interfaces with rules of their own, such as a VPN, keep only those); adding, removing or turning one off there applies to all cards, and a card button closes a common rule on that card only. A card's own tab shows only what differs on it: its own rules and the common ones closed there. Every rule can be turned off and on again without deleting it (a custom rule can only be removed). The add form sits in the tab it applies to.
- **Sounds, My Phone and Privacy like the other tabbed pages.** One card holds the page title, the tabs and their content, as on Time Plan; Sounds had its tabs above the title, and My Phone and Privacy had them outside the title card. On Inbound and Outbound Normalization the filter tabs and their table share one card, and Inbound keeps its country presets in the title card like Outbound.
- **One table for every page.** The 101 tables of the portal come from one helper (uiTableOpen/uiTableClose): the same frame, font and borders everywhere, and a column only some users see is one line. The page writes only its rows; the page test refuses a hand-written table.
- **Inbound normalization can be turned off.** Like Outbound, a switch on the Inbound Normalization page stops every rule at once and keeps them; Apply puts it into the dialplan. Both pages draw the switch from one shared row.
- **My Phone and Chat use the shared tabs.** My Phone's tabs switch through the shared tab code (it remembers the tab in the address and for the next visit as before); Chat's Chats / Directory selector is the shared tab row. Their own tab code and styles are gone.
- **Fax detection listens 10 seconds by default** on a voice route that also takes faxes (was 5): some fax machines start their tone late and the call went on to the voice destination first (#153). Routes keep the time already set on them.
- **Trunks listen for a fax tone 10 seconds (was 30)** (#153). Later the call is in a ring group, queue or IVR, where Asterisk has nowhere to send a fax and the caller's fax machine rang people's phones. Trunks on the old default move to 10 with the update and wait on Apply.
- **Send Fax tells who has no fax number** (#154). A user with no fax number saw their phone extension as the sender and got "Sender extension is required" on Send; the page now says an administrator can give them one under Fax Units, and Send is off.

---

### Archive & Previous Series
- 📁 **[AiPBX 1.6 Series (1.60 - 1.61)](CHANGELOG-1.6.md)**
- 📁 **[AiPBX 1.5 Series (1.50)](CHANGELOG-1.5.md)**
- 📁 **[AiPBX 1.0 - 1.34 Archive](CHANGELOG-archive.md)**

