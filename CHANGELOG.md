# AiPBX — Changelog

Each release is published with `scripts/release.sh X.Y.Z`: the section below becomes
the git tag message and the GitHub Release notes, and installations show it as
"What's new" on the **System Update** page. To update an installation:
`sudo aipbx-update` (or portal → System → System Update).

## 1.50.25

- **Admin Documentation & Markdown Engine Hardening:**
  - Fixed UTF-8 multibyte line-splitting flaw in `DocsService`: standard PCRE `\R` without `/u` treated byte `0x85` (the second byte of Greek upsilon `υ` / `\xCF\x85` and similar multibyte characters) as an isolated newline (NEL), inadvertently splitting words across lines. Switched to CommonMark-compliant `/\r\n|\r|\n/`.
  - Prevented infinite loop in `DocsService::render()` when encountering non-table text starting with pipe (`|`) characters by guaranteeing paragraph loop consumption on the first line.
  - Added regression test suite in `web/tests/unit/DocsServiceTest.php` verifying multibyte Greek characters and non-table pipes render correctly without stalling.
- **Database Port Customization & Socket Fallback:**
  - Added support for reading custom database ports via `DB_PORT` in `phinx.php`, `web/config.php`, and `PluginManager.php` (#142).
  - Hardened local Unix socket fallback in `web/config.php` so custom ports (e.g. 3307 or forwarded ports) and remote hosts are not mistakenly forced to `/var/lib/mysql/mysql.sock`.
- **Installer & Minimal Environment Robustness:**
  - Added missing `curl` and `cron` package requirements to `install.sh` for minimal Linux installations, and enabled the `cron` service automatically (#141).
  - Added `docker/web/aipbx-init.sh` first-boot provisioning script (#141).
- **Internationalization (i18n) & Translation Standards:**
  - Standardized Greek translations in `web/lang/el.php` for `provisioning` across discovery and phones management to `παραμετροποίηση` (#138).
  - Added canonical terminology guide to `docs/translating.md` defining standardized terms across all 22 supported languages for core telephony concepts (`provisioning`, `discovery`, `extension`, `trunk`) (#140).

## 1.50.24

- **CDR Reports & Multi-Language Support (i18n Compliance):**
  - Standardized `cdrs` SQL view (`web/db/migrations/20261029010000_standardize_cdrs_view_labels.php`) to generate canonical machine tokens (`fax_in`, `fax_out`, `internal`, `queue_call`, `did:...`) instead of hardcoded Turkish strings.
  - Added `CdrReportRepository::formatRouteLabel()` to dynamically localize route and queue labels in the user's active language across all 23 supported languages in both the web portal and CSV exports, with full backwards compatibility for existing installations.
- **Security & SecretBox Concurrency Hardening:**
  - Implemented atomic file locking (`flock(LOCK_EX)`) during encryption key initialization in `web/src/secret_box.php`, eliminating race conditions when multiple processes start concurrently on uninitialized systems.
  - Hardened Apache HTTP routing configuration in `install.sh` and `web/etc/apache2/aipbx-routing.conf.example` to deny direct access to developer files (`composer.*`, `phpunit.xml`, `phinx.php`, `phpstan.neon`, `.env*`) and the `tests/` directory.
  - Strengthened system Content Security Policy (CSP) header with `base-uri 'self'` and `object-src 'none'`.
- **System Scripts & Linter Polish:**
  - Eliminated ShellCheck warnings and improved robustness in `install.sh`, `conf/sbin/aipbx-sounds`, `conf/sbin/aipbx-update`, `web/bin/process_incoming_fax.sh`, `web/bin/process_outgoing_fax_result.sh`, and `scripts/e2e/` scripts.
  - Enhanced `web/bin/setup-test-db.sh` with automatic `sudo` and environment credential fallback for seamless local test runs.
  - Removed lingering diagnostic `console.log` statements from the production WebRTC header softphone (`web/assets/js/header_phone.js`).
- **Documentation & Navigation Polish:**
  - Fixed 22 broken relative links in root `ROADMAP.md` and repaired links in `.github/pull_request_template.md`.
  - Updated `ARCHITECTURE.md`, `AGENTS.md`, and `docs/translating.md` to cleanly point to decoupled native client repositories ([AiPBX-Android](https://github.com/mahirgul/AiPBX-Android) and [AiPBX-iOS](https://github.com/mahirgul/AiPBX-iOS)).

## 1.50.23

- **Core Stability, Linter Acceleration & Test Automation:**
  - Optimized `web/bin/smoke.php` syntax checker to exclude `.phpstan-cache/` and `.phpunit.cache/` directories, preventing thousands of temporary cache files from being repeatedly scanned and reducing smoke lint execution time from minutes to under 3 seconds.
  - Hardened `SecretBox` credential management to verify write permissions before attempting to write `/var/lib/aipbx/settings.key`, providing an in-memory encryption key fallback for non-root CLI processes, CI environments, and automated test runners without throwing unhandled exceptions or PHP warnings.
- **Background Workers Logging & Hardening:**
  - Added explicit error logging in `web/bin/webhook_worker.php` for CDR and fax event sweep cycles to surface database disconnects and transient polling failures.
  - Enforced shell argument escaping (`escapeshellarg`) for task IDs and triggers in `web/bin/task_dispatcher.php` as part of defense-in-depth background task invocation.
- **UI & Repository Navigation:**
  - Updated web portal persistent footer bar to directly link to the official [AiPBX GitHub Repository](https://github.com/mahirgul/AiPBX).
  - Integrated UpCloud cloud infrastructure partnership recommendations and \$250 promo credits across project documentation.
  - Added project maintainer LinkedIn connectivity.

## 1.50.22

- **Mobile Clients Decoupling & Independent Repositories:**
  - Separated Android and iOS native mobile clients into dedicated standalone repositories ([AiPBX-Android](https://github.com/mahirgul/AiPBX-Android) and [AiPBX-ios](https://github.com/mahirgul/AiPBX-ios)) with independent CI/CD build automation.
  - Removed heavy mobile workflows (`android-build.yml`, `ios-build.yml`, `mobile-lang.yml`) from the core repository, accelerating core CI runs and eliminating redundant mobile builds on server releases.
  - Audited all repositories to guarantee zero secrets, keystores, or private signing credentials in git history.
- **Native Plugin Contract & Manifest Validator (`scripts/check_plugins.py`):**
  - Added standalone plugin layout and manifest validator enforcing `plugin.json` contracts, hooks, and scopes across official and community plugins.
  - Added static code analysis ensuring plugin PHP code does not read undeclared settings keys.
  - Enforced `schema.sql` database security rules (`plg_*` table prefix sandbox and rejection of destructive DDL).
  - Added 22 unit tests (`scripts/test_check_plugins.py`) and integrated into `Web & Chat CI` pipeline.
- **Language Files & Translation Parity Cleanup:**
  - Removed 46 orphan and unused external AI keys across all 23 language files (`web/lang/*.php`).
  - Preserved 100% key parity across all 22 localized languages (5405/5405 keys aligned).

## 1.50.21

- **PJSIP TLS Settings in Admin UI & Config Sync:**
  - Added configurable TLS port (`pjsip_tls_port`, default 5061), TLS SIP domain/FQDN (`pjsip_tls_domain`), and TLS protocol method selection (`pjsip_tls_method`, supporting `tlsv1_2`, `tlsv1_2,tlsv1_3`, and `tlsv1_3`) in PBX Settings -> Asterisk Settings (PJSIP tab).
  - Synchronized TLS transport directives into `pjsip_transports.conf` (`domain=` and `method=`) so TLS registrations cleanly bind to the TLS transport.
  - Mirrored TLS transport values into `pjsipsettings` (`tls_bindport`, `tls_domain`, `tls_method`) and aligned provisioning services.
  - Added full multi-language translations across all 22 supported locales.
  - Added test coverage in `PjsipTransportTest`.
- **Installer SSH advisory fault tolerance (`install.sh`):**
  - Made the post-installation SSH configuration advisory non-fatal (`sshd -T 2>/dev/null || true`), preventing aborted installs on containerized or minimal cloud environments without `sshd` or host keys.
  - Added automated test case in `scripts/test_install_watchdog.sh`.
- **Cisco CallManager firmware documentation i18n:**
  - Completed `phones.cisco_loads_help` guidance across all 18 locales explaining automatic TFTP provisioning and firmware reboot handling.
- **Unit test suite isolation:**
  - Added database settings cleanup in `VoicemailPolicyTest` and `MyPhoneVoicemailSettingsTest` tearDown to prevent test state leakage across full test suite runs.

## 1.50.20

- **Duplicate extension row stability in PJSIP and Voicemail sync:**
  - Added deterministic deduplication (`ORDER BY extension ASC, id ASC`) in `SyncExtensions` and `SyncVoicemail` when multiple user rows share the same extension number (e.g. following CSV imports).
  - Keeps the first created user and logs collisions, preventing Asterisk PJSIP duplicate object errors (`configuration contains a duplicate object`) that caused full endpoint registration drops.
  - Added duplicate extension unit test in `SyncGeneratorTest`.
- **Cisco CallManager firmware translation alignment:**
  - Aligned Turkish `phones.cisco_loads_help` guidance with English documentation, explaining local server TFTP verification and firmware application upon phone reboot.

## 1.50.19

- **Asterisk RTP & Voicemail configuration compliance:**
  - Aligned Asterisk ICE host candidate formatting in `SyncRtpSettings` (`<local_ip> => <external_ip>,include_local_address`), eliminating RTP syntax warnings on reload.
  - Standardized `minsecs=3` and `maxsilence=2` defaults in `VoicemailPolicy` and `SyncVoicemail` to strictly satisfy Asterisk's `maxsilence < minsecs` constraint and prevent empty voicemail warnings.
- **Desk-phone discovery & Cisco CallManager provisioning:**
  - Moved the discovery tick lock file to `/var/lib/aipbx/engine/discovery.lock`, resolving permission failures when executing as `www-data`.
  - Added CSRF validation and array response handling in `PhoneController` discovery settings to prevent `TypeError` during save.
  - Added automatic TFTP file generation on phone approval and improved hyphenated model identification in `PhoneProvisionService`.
- **Installer & system permissions hardening (`install.sh`):**
  - Configured `/etc/asterisk` directory permissions (`02775 asterisk:asterisk`) and `0664` on managed conf files, enabling atomic writes by `www-data`.
  - Added `systemd-journal` group to `www-data` for reading `dnsmasq` DHCP leases via `journalctl`.
  - Added automated normalization of `/etc/asterisk/voicemail.conf` limits during installation and `--upgrade`.
- **Test suite stability & flaky test elimination:**
  - Transitioned `Fixtures::load()` from DDL `TRUNCATE` to DML `DELETE FROM` with `ON DUPLICATE KEY UPDATE` and automatic transaction rollback, eliminating MariaDB metadata lock timeouts and duplicate key violations across full test runs (971/971 clean).

## 1.50.18

- **Network desk-phone discovery & automatic pool provisioning (`/phones` & `engine/`):**
  discovers desk phones on the local network every 5 minutes via DHCP leases, ARP tables,
  `dnsmasq` journal logs, and an optional bounded UDP subnet sweep. Discovered phones can be
  approved or ignored from the **Discovered on the network** panel, or provisioned automatically
  from a configured extension pool (`provision_discovery_pool`) and vendor-to-model map
  (`provision_discovery_models`).
- **Cisco 7900/8800 CallManager-series phone provisioning & SCCP-to-SIP firmware switching:**
  added 11 Cisco 7900/8800 models (`cisco-cm-*`), `SEP<MAC>.cnf.xml` XML configuration generation,
  per-model SCCP-to-SIP firmware load switching (`provision_cisco_loads`), `.loads` manifest file
  verification against `/var/lib/aipbx/tftp`, and firmware state tracking on `pbx_phones`.
- **Health alarm quiet hours & incident deduplication (`/health`):** added configurable quiet hours
  (`HH:MM-HH:MM`, may cross midnight) that hold warning and recovery emails until the window ends
  while still sending critical `fail` alarms immediately, and deduplicated flapping checks within
  `health_repeat_hours`.
- **Installer & CI reliability:** bounded `apt-get` dpkg lock waits (`DPkg::Lock::Timeout`) and
  wrapped `apt-get` and `composer install` in `stream_bounded` with wall-clock and download-progress
  watchdogs; added smoke tests verifying that all admin-only JSON APIs reject non-admin roles with
  HTTP 403 JSON responses; updated all 23 portal languages to 100% translation parity (`5,444` keys).

## 1.50.17

- **Queue recording label uniqueness in multi-branch dialplans:** when multiple branches of a
  time condition routed to the same queue, duplicate priority labels caused Asterisk GotoIf jumps
  to loop back to the first branch. Labels now use an incremental sequence number per invocation.
- **Call forwarding (`*72`) target length:** extended maximum input digit length from 10 to 20 digits,
  preventing truncation of 11-digit Turkish mobile numbers (`05xx...`) or trunk-prefixed external numbers.
- **Incoming fax destination handling:** added matching for E.164 (`+X!`) numbers and `default` targets
  in `[from-trunk-fax]`, preventing dropped faxes routed from IVR or time conditions, and preserved the
  received DID in `${FAX_RX_EXTEN}` across the `h` extension for accurate email notifications.

## 1.50.16

- **Security hardening & input validation:** enforced caller dial permissions and internal channel
  context restrictions on REST API originate actions; added channel name character sanitization on
  hangup requests; restricted cURL protocols and redirects in AI webhooks; enforced audio file extension
  and path traversal validation on prompt files; added 64 KB decompression ceiling to HEP packet parsing;
  restricted cached FCM tokens to mode 0600; enforced admin role check on license acceptance.
- **Dialplan destination & referential integrity:** added deletion safety guards across routes,
  conferences, time conditions, ring groups, IVRs, hangup actions, sounds, AI apps, and queues to prevent
  breaking polymorphic destination references and web widget mappings; automatic cleanup of orphaned
  voicemail AI transcripts, TFTP configs, device keys, and scheduled task directories on deletion.
- **Admin safety & session security:** prevented deactivation or deletion of the last active administrator
  account; immediately invalidated active sessions (token epoch bump) upon user password changes;
  added audit logging for auto-dialer campaign actions.
- **Schema & updater reliability:** resolved database column and table mismatches across auto-dialer
  and privacy compliance modules; updated `aipbx-update` script to dynamically read active database
  credentials from system environment.
- **Multi-language completeness:** eliminated hardcoded strings across role management, Microsoft Teams
  integration, dial permissions, user controller, and push settings; updated all 23 supported language
  translations to 100% parity.

## 1.50.15

- **Privacy & Compliance portal CSRF security and notification fixes:** all POST actions on
  `/privacy` (`apply_profile`, `save_notice`, `save_retention`, `run_purge`, `erase_subject`,
  `save_compliance`) now strictly verify the CSRF security token and return status indicators,
  ensuring action notices are displayed properly.
- **Asterisk SIP diagnostics security:** SIP debug enable/disable and log rotation actions now
  enforce CSRF token verification and record security audit log entries.
- **External database `#` literal protection:** query comment stripping now preserves `#` and `--`
  characters inside quoted SQL string literals, avoiding syntax errors when filtering tags.
- **Scheduled tasks alert cleanup:** deleting a scheduled task resolves any pending failure
  notification in the top bar alert bell.
- **IVR data step routing safeguards:** destination types and identifiers are sanitized upon save,
  and steps are guarded against circular self-loop references.
- **Multi-language completeness:** hardcoded strings across extension service, Asterisk sync
  modules, call center actions, and feature codes were replaced with translated language keys.

## 1.50.14

- **REST API with a portal session:** an administrator signed in to the portal who opened an
  `/api/v1/…` address (other than the OpenAPI file) got an error page instead of the answer; the
  session check was only loaded for the portal pages.
- **Tests:** 119 new tests for the REST API (keys, scopes, IP allowlist, validation, no secrets in
  answers), sign-in (two-factor, recovery codes, passkeys, Google), fail2ban settings, health checks and
  alarm mails, and the time condition, conference, IVR data step and transport configuration.

## 1.50.13

- **No more "restart Asterisk" warnings for harmless library updates.** The health check warned (and
  e-mailed) whenever a system update replaced any library Asterisk uses, e.g. libsystemd or libudev,
  although that changes nothing for calls, and a quiet weekend with no calls kept the warning up.
  Now only security-relevant libraries count (OpenSSL, GnuTLS, the C library, SRTP, curl, libxml2 …),
  and with the night restart window switched on there is no warning at all, only a note. The night
  restart itself also happens only for those libraries.

## 1.50.12

Fixes from a review of the portal.

- **Outbound calls without any outbound route** (a fresh install) went straight to the first trunk with
  no dial permission check, no night protection, no trunk limits and no loop guard. That fallback now
  has the same brakes as a route.
- **Mobile sign-in with Google or a QR code** returned the SIP password and TURN access also to users
  whose extension does not allow the mobile app (password sign-in already refused it): the app could
  register and make calls. All three sign-ins now build the same response, so the rule is in one place.
- **IVR data step:** a query using `:caller_id` or `:input` more than once failed on every call (the
  timeout branch was taken). The HTTP lookup now pins the checked address, like webhooks, so a DNS
  answer that changes after the check cannot reach an internal address.
- **Scheduled tasks, database connections, IVR data steps:** names, script snippets and error messages
  are escaped in the dialogs; the history dialog's texts are translated.
- **REST API:** the `call.started` event of `/api/v1/calls/originate` no longer reports a caller ID that
  was not applied.

## 1.50.11

Platform features of the post-freeze roadmap (wave 2).

- **Scheduled tasks** (System → Scheduled tasks, administrators only, off by default): shell, PHP or
  Python scripts on a cron schedule, started every minute by the root dispatcher (cron) in a systemd
  sandbox as the unprivileged user `aipbx-task` — never root, never the web server; without systemd-run
  or that user a task is not run at all. Only the task's own folder is writable, home folders and devices
  are hidden, memory, CPU and run time are limited, and there is no network unless allowed. Run history
  with exit codes and output, script versions, "Run now" (starts within a minute), every change in the
  audit log, a failed run as an alert in the bell. Data access through curated views and per-task
  credentials is still to come (see the roadmap).
- **External database connections** (Integrations → Database connections, administrators only):
  MySQL/MariaDB and PostgreSQL with the password stored encrypted and a test button. Every session is
  read-only and each statement is limited to 2.5 s, whatever rights the account has; one statement per
  query, no files written on the server. A connection used by an IVR data step cannot be deleted.
- **IVR data step** (IVR page → Data steps, administrators only): during a call, look up the digits
  entered or the caller's number in an external database or with an HTTP request (internal addresses
  refused), put the answer (64 characters at most) into a channel variable and go on to *found*, *not
  found* or *timeout*. A step that is a destination cannot be deleted.
- **Per-trunk number format** (Trunks → edit): strip a prefix (such as +90) and/or prepend one (such as 0
  or a carrier routing prefix) for the dialled number, applied on every trunk a call tries, failover
  included.
- **Fix:** trunk session timers offered "Never", which Asterisk does not know: such a trunk was refused
  and did not work. It is now "No" (existing trunks are corrected); "Required" was added.
- **PBX fine tuning & maintenance** (PBX settings → Tuning & Ops, and Trunks modal):
  - Per-trunk DTMF mode (`rfc4733`, `inband`, `info`, `auto`), session timers (`yes`, `no`, `always`, `never`), 100rel PRACK (`yes`, `no`, `required`), and T.38 parameters.
  - Adaptive jitter buffer size tuning (`sys_settings.jitterbuffer_max_size`, `jitterbuffer_initial_size`), or full disable.
  - SIP timers: timer T1 (RTT estimate), timer B (INVITE timeout), and Max-Forwards limit in PJSIP transports configuration.
  - 10-minute auto-disabling SIP debug button with live countdown badge, and on-demand Asterisk log rotation.
  - Night restart window (e.g. 03:00–05:00): Asterisk is restarted there only when a system update
    replaced libraries it still uses and no call is in progress — never when the call count cannot be
    read, at most once a day.
- **Failed registration alarm** (Health checks → Failed registrations): more failed SIP logins than the
  threshold (default 10 in 15 min, from Asterisk's security log) alarms in the bell and by e-mail.

## 1.50.10

First items of the post-freeze roadmap (wave 1).

- **Trunk limits.** Max calls per minute per trunk is new; max concurrent channels now counts inbound
  calls too (it limited only outbound ones). A call over a limit is refused (inbound: cause 34;
  outbound: the next trunk is tried), and the new health check *Trunk limits* alarms in the bell and
  by e-mail. Stops call loops and toll fraud early.
- **Voicemail settings** (PBX settings → Voicemail): longest and shortest message, messages per
  mailbox, silence cut-off, wrong-PIN attempts, shortest PIN and "PIN may not be the extension". With
  that rule on (default), a mailbox saved without a PIN gets a random one instead of the extension
  number; the tab counts the mailboxes whose PIN still breaks the rules.
- **IVR:** "Repeat the menu when no key is pressed" (per IVR, off for existing ones): "Please try
  again." and the menu again, counted with wrong keys, then the timeout destination.
- **Queues:** the position announcement can be switched off, and the periodic announcement has its
  own frequency (0 = off) and file (until now fixed: every 45 s, the built-in prompts).
- **Spoken status instead of tones** (PBX settings → Ringtone; busy, no answer and unreachable each
  on or off, off by default): "The person at extension 3001 is on the phone. Please try again." in
  the caller's language, played before answering, so trunk calls are not billed.
- **Night dialling protection** (Dial permissions page, off by default): at night and at weekends
  calls to protected prefixes (00, 0900 …) are blocked or need a night PIN; covers transit calls too.

Every one of these can be switched off, and an update changes no behaviour until they are switched on
(the voicemail defaults are what the stock configuration did) — with one exception on purpose: the
voicemail rule "PIN may not be the extension" is on by default. Existing PINs are not touched; it
applies when a PIN is saved and can be switched off under PBX settings → Voicemail.
- **Audit log:** four changes left no trace: Google sign-in settings (the client secret itself is
  never logged, only that it changed), deleting a time group, removing a waiting desk phone, and the
  default e-mail language.

## 1.50.9

Call quality is part of AiPBX, and everything in the alert bell is e-mailed too.

- **Reports → Call quality** (administrators): packet loss in both directions and jitter per trunk and
  extension from the RTCP reports, the poor and fair calls of the last 24 hours, and which way the audio
  is lost ("mostly on the way from the carrier"). Needs signaling capture on (PBX settings →
  Signaling). Idea and first version: @shido6; it was a community plugin for a day.
- **Health check "Call quality"**: a trunk or extension losing audio is an alarm like the others — in
  the alert bell and e-mailed to the health recipients, with a "back to normal" mail.
- **Health check "Other alerts"**: problems plugins report in the alert bell are e-mailed too.

## 1.50.8

An alert bell for administrators, signaling capture built in (and working), and smaller fixes from
the IVR and voicemail check.

- **Alert bell.** The top bar shows administrators what is wrong right now: every health check that
  is warning or failed, problems plugins report, and the alarm path itself — no recipient chosen,
  or the health checks stopped running. Items go away by themselves when fixed; the last 7 days of
  changes are listed below them. It works without e-mail, so a PBX whose mail is not set up still
  shows its problems. Thanks to @shido6, whose notices proposal (#121) gave the idea.
- **Signaling capture is part of AiPBX** (PBX settings → Signaling: on/off, days kept, what arrived).
  It never worked before: the capture daemon misread the HEPv3 format and stored every message as
  "unknown", without its method or RTCP counters, and the signaling-capture plugin sent the capture
  to a port nothing listens on. Both are fixed; the plugin is retired, and an installation that had
  it on keeps capture on. Built by @shido6.
- **Call quality** comes as a community plugin (AiPBX-plugins, `call-quality`): packet loss in both
  directions and jitter per trunk and extension from the RTCP reports, with an alert in the bell.
- **Health checks: call records.** A new check fails when Asterisk has handled calls since it
  started but none reached the call history (the CDR database connection is broken), so a loss
  like the 11 hours of 1.50.7 is noticed within 15 minutes.
- **Health checks: Asterisk on replaced libraries.** Since 1.50.7 needrestart leaves Asterisk
  alone; the Asterisk check now warns when a system update replaced libraries Asterisk still
  uses, so it can be restarted when no calls are in progress.
- **IVR:** a wrong key plays "Invalid choice." before the menu repeats; callers used to hear the
  menu start again with no hint.
- **Voicemail:** a message is stored once, as wav (the stock setting kept three copies: wav49, gsm
  and wav).

## 1.50.7

Fixes found while checking the IVR and voicemail on a production PBX.

- **Call records were lost after automatic system updates.** When unattended-upgrades updated a
  library, needrestart restarted MariaDB and Asterisk together; Asterisk came up first, the CDR
  backend found no database connection and wrote no call records until the next Asterisk
  restart (11 hours on one PBX). The same happened on a reboot. Asterisk now starts after
  MariaDB, and needrestart no longer restarts Asterisk at all: that also dropped the calls in
  progress at 06:35. Asterisk picks up updated libraries on the next AiPBX update or reboot.
- **Trunk loops are cut.** A call that came in on a trunk and matched an outbound route of the
  same trunk was sent straight back; when the PBX behind the trunk routed the number to AiPBX
  again, the call looped, thousands of times an hour. Such a call now counts as congestion on
  that trunk (the next trunk of the route is tried). Call forwarding of an extension to a
  number behind the same trunk still works.
- **Voicemail:** the sample mailboxes of Asterisk's stock `voicemail.conf` (1234 with PIN 4242)
  are disabled; anyone allowed the general voicemail code could log in to them.

## 1.50.6

Updates restart Asterisk only when they have to: most releases now keep calls up.

- **Updates no longer drop calls when nothing Asterisk loads at start changed.** Every update
  used to restart Asterisk, so it waited for an idle PBX. The updater now compares the installed
  code with the release: when the Asterisk configuration files and the installer's
  `ASTERISK_RESTART_EPOCH` are unchanged, Asterisk is only reloaded and the update runs even
  while calls are in progress (verified with a call held across an update). System → System
  Update says beforehand whether the update restarts Asterisk, and why. coturn is restarted only
  when its configuration changed, so WebRTC calls through TURN stay up too.
- **Release notes say whether updating restarts Asterisk.**
- **IVR, inbound routes, time plans:** a queue destination stored by its number (as the REST API
  and the services accept it) showed as "not found" although calls reached the queue.
- **Docs:** the community plugins bundled with AiPBX (`ai-assistant`, `signaling-capture`) were
  described as official; they now name their author.
- **Android:** a release without app changes no longer builds or uploads the app to Google Play;
  the previous APK stays available for download.

## 1.50.5

Fax: uploads work after an update, T.38 can pass the firewall, and outgoing faxes get their
options right. Verified on a live system: two faxes sent to external fax machines.

- **Fax uploads no longer fail with "Permission denied" after an update.** The year directory
  (`/var/www/faxes/sent/2026`) was created without group write, and once an update handed it to
  `asterisk` the portal could not save the uploaded PDF. The installer makes every fax directory
  group-writable, and the portal and the fax processor create new ones that way.
- **T.38 fax could never work through the firewall:** the installer opened the RTP ports but not
  the UDPTL ports T.38 uses (4100–4999/udp), so a carrier that accepted T.38 got no answer.
  They are opened on install, and on upgrade wherever the RTP range is open as installed.
- **Outgoing faxes ran without the audio fallback option:** `SendFAX(…,d,f)` passed the options
  wrongly (Asterisk: "Unrecognized option: ','"); now `df`.

## 1.50.4

Fax sending: works again on Ubuntu 26.04, a fax stuck in the handshake no longer holds the line,
and faxes are neither recorded nor run through a jitter buffer.

- **Sending faxes works again on Ubuntu 26.04.** The new AppArmor profile for Ghostscript
  lets it open files only under HOME, /mnt, /media and /tmp, so every outgoing fax failed at the
  PDF → TIFF conversion (and the TIFF → PDF fallback for received faxes failed the same way).
  The installer now allows the two fax directories in `/etc/apparmor.d/local/gs`.
- **A fax that cannot complete its handshake no longer holds the line.** Both ends used to wait
  for each other for many minutes (seven on a live system). A fax session that has not moved a
  single page two minutes after it started is now hung up and recorded as failed; a fax that
  has sent or received a page is never cut, however long it is.
- **Faxes are not recorded and get no jitter buffer:** a fax sent from the portal went through
  the outbound route like a call (recording = modem noise, and the jitter buffer reshapes the
  modem's timing), and a fax number with "record calls" on was recorded too.

## 1.50.3

Plugins: uploading a custom plugin works again, and the worker and Asterisk follow plugin
changes at once; a sample plugin for plugin authors.

- **Plugins: uploading a custom `.zip` plugin works again.** Every upload failed with "Failed to
  move extracted plugin into plugins directory": the archive was unpacked in Apache's private
  `/tmp`, and a directory cannot be moved from there to `/var/lib/aipbx/plugins`.
- **Plugins: the worker sees plugin changes without a restart.** `aipbx-webhook-worker` kept the
  plugin list it read at start: a plugin enabled or uploaded later got no events and no worker
  ticks until the service restarted, and a disabled one kept getting both.
- **Plugins: disabling or removing a plugin takes its Asterisk configuration out at once**
  (dialplan, PJSIP, HEP reloaded); before, it stayed live until the next Apply.
- **Docs:** a sample plugin that shows every hook, in AiPBX-plugins (`examples/sample-plugin`).

## 1.50.2

Incoming faxes: failed ones are visible, T.38 off for new trunks; trunks no longer drop out for a
minute after one lost probe; webhook, updater and plugin sidebar fixes; documentation diagrams.

- **Updater:** file mode changes no longer count as "Code was modified by hand". Installations on
  1.34.0 – 1.34.2 could not update at all (their installer made `web/bin/aipbx_ops.php`
  executable); on those, run `sudo git -C /opt/aipbx checkout -- web/bin/aipbx_ops.php` once
  (see docs/troubleshooting.md).
- **Incoming faxes that fail are no longer lost without a trace:** a fax that failed before its
  first page left no file and no record. It is now in the Fax Inbox as *FAILED* with Asterisk's
  reason (`FAXOPT(error)`), without view/download buttons; dashboard and daily summary count only
  faxes that arrived. An unwritable fax log no longer drops the database write.
- **T.38 is off for new trunks:** with a carrier that refuses T.38 every incoming fax failed (the
  fallback to audio does not recover: "T.30 Non-ECM carrier not found"); reproduced on a live
  system, where plain G.711 received the same fax. Existing trunks keep their setting; see
  docs/troubleshooting.md, "Incoming faxes fail".
- **Trunks no longer drop out for a minute after one lost probe:** with Asterisk's 3 s
  `qualify_timeout` a 2-3 s network hiccup marked a trunk Unavailable until the next probe, and
  every call to it failed meanwhile ("Could not create dialog to invalid URI '<trunk>'"; about
  100 calls in two weeks on a live system). Trunks are probed with an 8 s timeout.
- **Webhooks:** `fax.received` was never sent (the query used a column that does not exist), and
  once a received fax existed it also stopped the `fax.sent` / `fax.failed` events. Fixed;
  `fax.received` is sent only for faxes that arrived.
- **Signaling capture keeps the RTCP counters:** packet loss (count and RFC 3550 fraction) and
  jitter of the worst report block are stored in `pbx_signaling_events` (`rtcp_*` columns, `NULL`
  when a report does not carry them), so call quality can be queried from the capture the PBX
  already sends (@shido6, #116). Values outside the column range are dropped instead of failing
  the whole batch.
- **Plugins:** the sidebar shows a plugin's page as soon as it is enabled (and drops it when it is
  disabled or removed); it used to need a full page reload.
- **Documentation diagrams** are drawn with Mermaid (`docs/diagrams/`) and shown as SVG on GitHub,
  in the portal's Help page and on aipbx.bid/docs.

## 1.50.1

Call normalization page reworked, presets follow each country's dialling plan, + numbers in
routes and permissions, fixes from a full review, and real call-flow tests.

- **Call normalization page (`/dialplan-rules`) reworked:** call normalization can be switched off
  as a whole (every device then dials numbers as entered); the country is chosen from a list under
  **Settings** and is empty until set — new installations start without any rules (no Turkey
  default); the selected device tab is highlighted; the transformation column shows "Prepend 0"
  instead of "-"; the simulator is a simple table below the rules; unused drag-to-reorder removed
  (the most specific pattern wins, not the row order). Preset rules are English; rules still exactly
  as loaded from the former Turkish presets are renamed to English by a migration.
- **Plugin catalog:** no per-category search/paging boxes; hooks and permissions are folded under
  "Technical details".
- Translations: texts that were still English in the other languages are translated.
- **Country presets follow each country's dialling plan:** `country_calling_codes.json` lists the
  national and international prefixes of 104 countries (0/00 in Turkey and most of Europe, 1/011 in
  the US and Canada, 8/810 in Russia, no national prefix in Italy or Spain, 0/0011 in Australia, …).
  Presets used to assume 0/00 everywhere; countries whose plan is not listed get no presets.
- **+ numbers:** outbound route and calling permission patterns may contain `+` (the mobile app sends
  contacts as +E.164; without a normalization rule such calls matched no route, and a route for +
  numbers was not covered by permission rules).
- **Review fixes:** faxes to a pattern DID (`_X.`) reached the literal extension "EXTEN"; the
  "extension = called DID" destination was offered in IVRs and time conditions too (only inbound DID
  routes can use it); the AI voicemail plugin listened for an event nothing emits (`voicemail.received`)
  and never ran; an auto-dialer campaign's caller ID only applied to the answered leg, not to the call
  the callee sees; the auto-dialer dialplan is written with rollback like every other config.
- **Android:** declining a call before the app had connected to its service could crash on Android 8+;
  a chat room closed while it was being created kept its notifications silenced; the QR scanner's
  padding grew on every layout pass. **iOS:** the caller's number is shown when there is no display name.
- Tests: real call-flow checks in the e2e run (a SIP sink records what reaches the carrier) and unit
  tests for the per-device call routing contexts.
- Installer: Apache's PHP runs with `pcre.jit=0` (`99-aipbx.ini`); Ubuntu's apache2.service denies
  executable memory, so the PCRE JIT failed and logged a warning for every new Apache process.

## 1.50.0

**Bridge release.** Later releases update only installations that run 1.50.0 or newer; an older
installation updates to 1.50.0 first (from 1.50.0 on, `aipbx-update` does this step by itself).
The Android app now has the same version number as the server.

- **Call normalization fixes:** the desk phone rule "strip the outside-line 0" (`_00.`), switched on by
  the 1.34.4 update, turned international numbers into national ones (`0049…` → `049…`) — also for
  faxes and call forwarding. It is now off (on installations where it was not changed) and loads
  switched off with the defaults. Turkey-only rules (`+90` → `0`, `5XXXXXXXXX` → `05…`) are switched
  off on installations that are not set up for Turkey; load the defaults for your country on
  **Trunks → Call Normalization**. The help text explains that the most specific pattern wins, not the row order.
- **Plugins:** an uploaded plugin can no longer show itself as "Official"; the badge depends on where
  the plugin is installed, not on its manifest.
- **Installer & updater:** a failing Asterisk configuration sync is reported instead of being ignored;
  `install.sh` records the installed version (`/var/lib/aipbx/installed-version`); a release can name
  the oldest version it updates (`UPGRADE_FROM`) and `aipbx-update` goes through that bridge release first.
- **Android app 1.50.0:**
  - Version name and code come from the server version (versionCode `1500000`); Google Play gets the
    build of each published release.
  - No Android 15-deprecated window APIs in the app any more (also not inside libraries), so Google
    Play's edge-to-edge warnings do not come back; CI checks the built app. Android 15+ is
    edge-to-edge as before; Android 8–14 shows the regular status bar.
  - Call screens keep the status bar readable in light mode; unused chat screens and the unused
    `MANAGE_OWN_CALLS` permission were removed.
- Auto-dialer: PHP 8.5 `str_getcsv()` deprecation fixed.

## 1.34.8

Android 15+ (targetSdk 35/36) Edge-to-Edge architectural compliance, eradication of deprecated window APIs, centralized `BaseActivity` pattern, and automated CI/test compliance guardrails (v1.0.81).

- **Permanent BaseActivity Architectural Pattern:** Established `com.mhrgl.aipbx.ui.BaseActivity` centralizing standard zero-parameter `enableEdgeToEdge()` invocation prior to `super.onCreate()` and automatic context localization wrapping via `L10n.wrap()`. All 10 application UI activities migrated to inherit from `BaseActivity`.
- **Eradication of Deprecated Window APIs:** Removed legacy `WindowCompat.setDecorFitsSystemWindows(win, false)` from `ImageViewerDialog` and stripped deprecated custom `SystemBarStyle.dark(...)` parameters from `IncomingCallActivity`, `CallActivity`, and `QrCaptureActivity` to eliminate Google Play Console edge-to-edge policy warnings.
- **Unified WindowInsets Padding:** Standardized `applyEdgeToEdgeInsets(targetView, consumeIme)` across all screens to ensure UI elements, controls, and buttons correctly accommodate system bars, display cutouts, and software keyboards without overlapping or clipping.
- **Automated Regression Guardrails:** Implemented `EdgeToEdgeComplianceTest` (JUnit) and `android/bin/edge_to_edge_audit.sh` enforcing that all activities extend `BaseActivity`, no deprecated edge-to-edge/window APIs exist in source files, and XML themes maintain compliance. Added Rule #4 to `AGENTS.md` and updated `ARCHITECTURE.md`.

## 1.34.7

Global country calling codes directory, dynamic country badge & autocomplete, and reliable preset loader execution on the Call Normalization page (`/dialplan-rules`).

- **Global Country Calling Codes Directory (`web/config/country_calling_codes.json`):** Integrated standard ITU-T E.164 country calling code database with localized country names in Turkish and English, ISO 3166 alpha-2 identifiers, and national flags.
- **Telecom Preset Form Action Fix:** Resolved form action dispatching in `DialplanRuleController` and `index.php` so clicking "Load Recommended Presets" with any country code (such as Austria `+43`, Germany `+49`, etc.) reliably replaces existing rules and updates `sys_settings` without being skipped by `handlePost`.
- **Dynamic Country Name & Flag Display:** Connected a live country identifier badge and `<datalist>` autocomplete to the country code input on `/dialplan-rules`, dynamically showing the country name and flag (e.g. `🇦🇹 Avusturya`) as the user types or selects a code.
- **Localized Rule Preset Titles:** Configured preset generator in `DialplanRuleService` to embed localized country names into generated normalization rule names (e.g. `Masaüstü: E.164 Avusturya (+43 -> 0)`).

## 1.34.6

CSRF token handling and real-time simulator AJAX resilience on the Call Normalization page (`/dialplan-rules`).

- **Global Head CSRF Meta Tag:** Added `<meta name="csrf-token">` to the global application layout (`app_header.php`), providing standard CSRF token discovery across all frontend AJAX components.
- **Resilient Frontend CSRF Token Discovery:** Implemented `getCsrfToken()` in `dialplan_rules.js` checking `window.CSRF_TOKEN`, `<meta name="csrf-token">`, and form input fallbacks; dispatched `X-CSRF-Token` HTTP headers on all live simulation and table reorder requests.
- **Safe In-Memory Simulation Endpoint:** Hardened the simulation endpoint in `DialplanRuleController` to safely handle authenticated admin requests and eliminated duplicate initial simulation calls on DOMContentLoaded.

## 1.34.5

Country-code driven telecom presets, unified multi-device real-time dialplan simulator, Android configurable `+` prefix toggle (v1.0.80), and mobile i18n synchronization across 22 languages.

- **Country-Code Driven Telecom Presets (`/dialplan-rules`):** Added a dedicated country-code configuration field on the Call Normalization page (`+ [ 90 ]`, saved in `sys_settings`) that dynamically generates and loads tailored E.164 normalization rules (`_+<CC>.` -> `0`, `_+X.` -> `00`) for desktop, mobile, and web clients based on the specified country code.
- **Unified Multi-Device Live Number Simulator:** Redesigned the Live Number Test Simulator to eliminate client dropdown selection and render real-time normalization results for all client types (Desktop, Mobile, and Web) simultaneously stacked side-by-side/vertically with matched patterns, regex breakdowns, and transformation paths.
- **Configurable `+` Prefix in Mobile App (Android v1.0.80, build 80):** Added a user-configurable toggle in Android dialer settings (`sendPlusPrefix`, default: `true`). When enabled, preserves `+` (e.g. `+90...`) so server-side normalization rules handle country-code formatting; when disabled, automatically converts `+` to `00` for legacy trunk compatibility.
- **Mobile Translation Coverage (22 Languages):** Synchronized Android `pref_send_plus` settings strings across all 21 localized XML resources (`check_mobile_lang.py` 100% clean).
- **Telephony & Fax Script Hardening:** Fixed tab-delimited parsing in outgoing fax result processor (`process_outgoing_fax_result.sh`) and hardened Phinx migration rollback.

## 1.34.4

Universal Ingress Call Normalization Rules (`/dialplan-rules`), Android configurable `+` prefix toggle (v1.0.80), route group context isolation, web dialer / call forwarding / fax outbound normalization, transactional DML safety, and pattern specificity simulation.

- **Ingress Call Normalization Rules (`/dialplan-rules`):** Pre-filters and transforms outbound numbers at ingress before reaching trunk outbound routes based on client device type (`desktop`, `mobile`, `web`). Internal extensions (`101`), queues, IVRs, and feature codes (`*97`, etc.) remain strictly isolated and unaffected.
- **Configurable `+` Prefix in Mobile App (Android v1.0.80, build 80):** Added a user-configurable toggle in Android dialer settings (`sendPlusPrefix`, default: `true`). When enabled, preserves `+` (e.g. `+90...`) so server-side normalization rules handle country-code formatting; when disabled, automatically converts `+` to `00` for legacy trunk compatibility.
- **Route Group Context Isolation (V3-01):** Fixed route group context assignment so subscribers with `outbound_group > 1` properly route into their dedicated device contexts (`from-internal-desktop-N`, `from-internal-mobile-N`, `from-internal-web-N`) rather than being pinned to group 1.
- **DML Permission Safety & Preset Loader (V3-02):** Replaced `TRUNCATE TABLE` with transactional `DELETE FROM pbx_dialplan_rules` wrapped in PDO rollback safety, allowing production installations with least-privilege DML-only DB users to load telecom presets without requiring `DROP` privileges.
- **Web Softphone & CRM Normalization Presets (V3-03):** Seeded complete web dialplan normalization rules (`_+90.`, `_+X.`, `_5XXXXXXXXX`) and desktop international rules in Phinx migrations and unified single-source preset definitions.
- **Real Outbound Call Path Normalization (V3-04):** Extended normalization pipeline to web dialer and CRM AMI originates (`from-internal-web[-N]`), call forwarding (`from-internal-desktop[-N]`), and native fax sending. Preserved `+` in call forwarding target destinations.
- **Pattern Specificity Matching Simulator (V3-07):** Upgraded the live number normalization simulator to score Asterisk pattern specificity (exact literal characters > range subsets > wildcards), aligning preview results with Asterisk dialplan engine evaluation.
- **Pattern Validation & Security Constraints (V3-10):** Added validation checks for Asterisk pattern syntax (dialplan prefix `_`, balanced and non-empty brackets, character set constraints, wildcard tail placement), strip ranges (0..32), and duplicate pattern detection per device type.
- **23-Language Portal i18n & Documentation (V3-08, V3-09):** Synchronized all new simulation and error strings across all 23 interface languages (`lint_lang.php` 100% clean); updated `docs/call-normalization.md` with accurate single-pass ingress pre-filter architecture and circuit breaker documentation.

## 1.34.3

Auto-dialer loopback callback fix, official vs. community plugin architecture, mobile admin footer responsiveness, universal E.164 dialing normalization, and full 23-language i18n synchronization.

- **Auto-Dialer Telephony Loopback Callback:** added `/api/plugins/` exception to Port 80 Apache vhost in `install.sh` and live configuration, preventing 301 HTTPS redirects on Asterisk `CURL()` calls and ensuring real-time `dial_failed`, `answered`, and `hangup` event delivery to the PBX database without stuck dialing recovery drops.
- **Plugin System & Author Metadata:** added first-class distinction between Official (`type: official`) and Community (`type: community`) plugins, visual badge indicators in the catalog, manifest author metadata (`author`, `author_url`) with safe HTTP/HTTPS URL scheme validation and enum verification, and dedicated standalone plugins repository.
- **Inbound DID Pattern Routing & Dynamic Extension Target:** documented Asterisk pattern wildcards (`_`, `X`, `Z`, `N`, `.`) and dynamic destination dispatching (`__did__`) to route full blocks of DIDs directly to matching extensions in `docs/inbound-routes.md`.
- **Mobile Web Portal UX & Responsive Footer:** streamlined admin footer bar by removing redundant live clock element, responsive wrapping into two clean lines on mobile screens without overflowing or cramping, and safe area inset support.
- **23-Language Portal i18n Synchronization:** added missing badge and attribution keys (`plugins.badge_official`, `plugins.badge_community`, `common.by`) across all 23 supported interface languages, restoring a 100% clean CI lint status (`lint_lang.php`).
- **Android Universal E.164 Dialpad Normalization:** replaced legacy country-specific SIM ISO normalization assumptions with standard international E.164 (`+` → `00`) prefix dialing, contacts layout alignment with chat, and permission state hardening.

## 1.34.2

Security and reliability hardening, Android v1.0.78 release, mobile 22-language translation synchronization, multi-call busy handling, and GitHub Sponsors integration.

- **Security & Media Handling:** authenticated chat attachment download with Bearer token validation, temporary file hash naming, and sandboxed `FileProvider` URI generation; preserved whitespace characters in user passwords on mobile login; universal E.164 phone number dialpad normalization (`+` → `00`) replacing country-specific SIM assumptions.
- **Mobile Reliability & Multi-Call Handling (Android v1.0.78, build 78):** rejected second incoming SIP calls with `486 Busy Here` when already active on a call; added exception safety guards against `ForegroundServiceStartNotAllowedException` during background resurrection and notification dispatch; added empty caller name fallback handling in incoming call notifications; isolated chat typing indicator state per active conversation; cleaned up FCM push tokens and local state on logout.
- **Diacritics & Accent Normalization:** comprehensive Unicode NFD accent and diacritic stripping (including Croatian/Serbian `đ/Đ` normalization) across contacts and chat search indexing.
- **Mobile i18n Synchronization:** synchronized missing call forwarding strings (`cf_always`, `cf_noanswer`) across all 22 supported mobile languages.
- **GitHub Sponsors & Open Source Support:** enabled GitHub Sponsors configuration (`.github/FUNDING.yml`) for `@mahirgul`, added Sponsor badge and documentation section in `README.md`, and integrated sponsor links in the PBX portal layout.

## 1.34.1

Optional Global Privacy & Telecom Compliance (`/privacy`), live AGI/AI PII & PCI-DSS redaction, universal table filtering & pagination, mobile web UX, modular service architecture, and cross-platform mobile v1.0.74 release.

- **Optional Global Privacy & Telecom Compliance (`/privacy` — KVKK / GDPR / CCPA / HIPAA / PCI-DSS / LGPD):** built-in, 100% opt-in privacy module (all rules disabled by default) with one-click regulatory presets, inbound pre-call disclosure announcements, Opt-Out (`Press 9 for unrecorded call`) and Explicit Consent Opt-In (`Press 1 to allow recording`), FCC periodic recording beep (`MixMonitor B(15)`), live AGI/AI transcript PII & Luhn payment card redaction (`[REDACTED-CARD]`), automated nightly data retention purge (`privacy_purge.php`) and dual-DB CDR PII anonymization (`REDACTED`), Subject Access Request (SAR) JSON export, Right to Erasure (`PRIVACY_ERASURE`), and Cross-Border Cloud AI Lock.
- **Open Source License & Telecom Disclaimer (Web, CLI, Android & iOS v1.0.74):** added explicit Emergency Calling (112/911/999), Toll Fraud, and GDPR/KVKK Recording & AI Privacy notice to `LICENSE`, `install.sh`, a one-time first-login admin acceptance modal across all 23 portal languages, and a first-launch acceptance screen on the Android and iOS mobile apps (`v1.0.74`, build `74`).
- **Portal UX, Audit Log & Modular Architecture:** universal live filtering, column sorting, and pagination (`datatable_enhancer.js`) across `/system-users`, `/trunks`, and all multi-record tables; responsive mobile drawer and table layouts; language switcher preserves clean routes and active sidebar state; Dialplan Editor (`/assistant`) packaged as an opt-in plugin (`ai-assistant`); modularized `ApiV1Controller`, `DialplanService`, `PrivacyService`, and `PhoneProvisionService`.
- **Installer, Updater & Backup Hardening:** `install.sh` symlinks `/usr/local/bin/privacy_purge.php`; `aipbx-update` runs post-upgrade `smoke.php` verification as `www-data`; `aipbx-backup` includes uploaded plugins (`var/lib/aipbx/plugins`).

## 1.34.0

REST API v1, Outbound Webhooks, First-Class Plugin System, Dialplan Editor & SIP Signaling Capture (**@shido6**, #114), Android & iOS improvements, and security/reliability hardening across the entire stack.

- **REST API v1 (`/api/v1/*`) & API Keys (`/api-keys`):** stateless REST API with 100+ endpoints and OpenAPI 3.1 specification (`/api/v1/openapi.json`), scoped Bearer tokens (`apbx_...`), IPv4/IPv6 CIDR allowlists, per-token rate limiting, secret-column sanitization (`sip_password`, `auth_password`, `voicemail_pin`), and allowlisted public system settings.
- **Outbound Webhooks (`/webhooks`):** HMAC-SHA256 signed event delivery with background retry daemon (`aipbx-webhook-worker.service`), composite CDR/Fax sweep cursors, delivery logs, and DNS-pinned SSRF protection.
- **First-Class Plugin System (`/plugins`):** modular integration catalog (`google-auth`, `ms-teams`, `web-widgets`, `ai-cloud`, `ai-tts`, `ai-apps`, `ai-models`, `ai-voicemail`, `ai-assistant`, `signaling-capture`), custom `.zip` plugin installer, encrypted secret settings, bounded KV storage, public plugin endpoints (`/api/plugins/<slug>/*`), and a 5-error **Safe Mode** circuit breaker.
- **Dialplan editor (`/assistant`) & SIP signaling capture (`signaling/`):** plain-language PBX change planner with dry-run preview before apply, safety validation, audit trail and one-click undo (`pbx_ai_changes`), plus the Echo diagnostic reporter and opt-in loopback HEPv3 capture daemon (`aipbx-signaling.service` → `pbx_signaling_events`) (@shido6, #114).
- **Mobile apps (Android & iOS):** fixed in-app language switching across all 23 languages on Android (Play App Bundle language split disabled, `L10n.wrap` context attachment across all Activities, `LocaleManager` sync, and tab state preservation); iOS `CallKitManager` fulfills `CXStartCallAction` on outgoing calls and scopes WebView media capture permission to the local app bundle.
- **Installer, Updater & Security Hardening:** `aipbx-update` passes `-c safe.directory` to `git`, re-execs from `/run`, and verifies `aipbx-webhook-worker`; atomic file writes in `aipbx-update` and `aipbx-sounds`; sticky-bit protection on `/var/lib/aipbx` (`1755`) and `/var/log/aipbx` (`1750`); `mysql.Config.FormatDSN()` in `aipbx-chat`; bounded cloud LLM/STT responses and serialized model downloads in `aipbx_ai`.

## 1.33.4

Fixes from a full security and reliability review across the web portal, dialplan, chat, local AI, mobile apps and system helpers, plus three fixes by **@tomck** (#113).

- **Dial permissions in priority order:** group rules now run as an ordered `REGEX()` chain so the first matching rule in priority order wins instead of Asterisk's most-specific-match; a prefix rule also matches the bare prefix itself (@tomck, #113).
- **Ring groups (`random`):** the `random` strategy now picks a target per call in the dialplan (`RAND()`) instead of freezing a sync-time shuffle that rang everybody at once (@tomck, #113).
- **Direct chat reopen check:** reopening an existing direct conversation enforces the same active, non-fax user check as creating a new one (@tomck, #113).
- **Boss–secretary & call forwarding:** blind and attended transfers from a secretary or whitelisted extension reach the boss without looping back to the secretary; a secretary's personal voicemail no longer intercepts the boss's call (`__SKIP_VM`); call-forwarding legs carry the forwarding extension's permission group and outbound route group (`from-internal-gN`).
- **Sign-in & account security:** passkey and Google sign-in honour mandatory password resets (`/force-reset`) and build redirect/RP URLs from the configured portal domain instead of `Host` / `X-Forwarded-Host` headers; passkey registration and deletion require POST + CSRF; QR sign-in status checks are scoped to the signed-in user; WebRTC and mobile login only hand out SIP credentials when the corresponding phone mode (`web` / `mobil`) is enabled.
- **Call center & CDRs:** call notes and CDR deletions verify that the call belongs to the agent's extension unless the user has full CDR access; active-call draft notes are claimed with a CSRF header check.
- **MS Teams & webhooks:** test webhooks validate that the target resolves to a public IPv4/IPv6 address and pin resolution (`CURLOPT_RESOLVE`); PowerShell script generation strips control characters and escapes single quotes.
- **Chat & local AI hardening:** internal chat endpoints require `X-AiPBX-Internal`; group leave and last-admin checks run transactionally; S3 upload failures roll back cleanly; local AI serialises model downloads, checks magic bytes before extracting archives, bounds cloud LLM/STT responses and cleans up temporary WAV files.
- **System helpers (`aipbx-priv`, `aipbx-cert`, `aipbx-update`):** Postfix SASL credentials are staged through `/var/lib/aipbx/sasl_passwd` (`0600`) and validated before `postmap`; custom certificates verify key match before staging; update status files are written atomically without following symlinks.
- **Mobile apps (Android & iOS):** server setup, deep links and QR sign-in require `https://` and ask for confirmation before switching to a different PBX server; Android encrypts stored tokens and SIP passwords with `EncryptedSharedPreferences`.

## 1.33.3

Fixes from a third code review.

- **Configuration injection:** a fax destination (IVR, time condition, ring
  group, announcement fallback), a queue recording format, a voicemail e-mail
  address and a ring-group caller-name prefix were written into Asterisk's
  configuration as typed. A line break in them could add lines of its own,
  including commands run by Asterisk. Each is now limited to what it can
  contain; a comma in a name no longer shifts the voicemail fields.
- **My phone:** the "Call" button of the call history put the caller's number
  into the page unescaped; a crafted caller ID could run script in the
  browser. Every inline button now passes its values safely (`jsArg()`),
  which also fixes confirmation dialogs whose translation has an apostrophe.
- **Sign-in tokens:** the mobile and chat tokens are no longer signed with the
  database password when no token key is set (the service refuses to start
  instead); a QR code can be used only once even when scanned twice at the
  same moment. The tokens get their own key (`CHAT_JWT_SECRET`, added by the
  update; they were signed with the TURN key): **every mobile app and the web
  chat sign in again once after this update.**
- **Chat:** a direct chat can be started only with an active chat user (not
  any number, not a fax-only user); internal errors are logged instead of
  being sent to the app; the web page no longer replaces the safe
  `escapeHtml()` with a weaker one.
- Hardening: the command-line tools in `web/bin` refuse to run over HTTP (the
  folder is already closed by the web server); the extension list in the page
  header is encoded for a script block.

## 1.33.2

Fixes from a second code review, and two follow-ups by **@tomck** (#110).

- **Call restrictions now work on phones:** the dial-permission group of a
  user never reached the phone's endpoint, so every phone, softphone and web
  phone call was unrestricted, whatever group the user was in. A user in a
  disabled or deleted group has no restriction (as before).
- **Certificates:** the certificate tool (root) wrote its status through a
  fixed temporary file in a folder the web server can write; a planted link
  could make it overwrite another file. A failed copy of the certificate is
  now reported as an error instead of "OK".
- **Chat:** an image that claims a huge size gets no thumbnail (it could
  exhaust the memory of the chat service); the sign-in cookie is only used
  for media, so another website cannot open a chat connection or call the
  chat API as the signed-in user; expired sign-in cache entries are dropped.
- **Local AI:** the model list no longer fails while a model is added or
  removed.
- **Fax:** a failed database update is logged as an error instead of
  "Updated"; the settings file is read without running it as a script (no
  more "PBX: command not found" in the fax logs).
- **Dial permissions:** a rule is deleted only within its own group; a SIP
  password reset needs at least 6 characters, as on the Extensions page
  (@tomck, #110).
- Hardening: voicemail takes only digits as the extension number; network
  addresses for TFTP/FTP/syslog/NTP/DHCP are checked strictly
  (0-255, /0-32); the sync lock folder is open only to the PBX's own users.

## 1.33.1

Fixes from two code reviews. Thanks to **@tomck** for the review and fixes in
#107 / #108.

- **SIP password:** a password changed on the Extensions page or by a reset
  now reaches Asterisk. Before, the old password stayed valid and the mobile
  app, which got the new one, could not register (@tomck).
- **Call restrictions:** click-to-call (call center) and the external members
  of a ring group now follow the caller's restrictions; click-to-call could
  reach barred numbers (@tomck). Inbound calls forwarded to a mobile or an
  external destination work as before.
- **Ring groups:** a 6-digit extension in a ring group is rung as an extension,
  not dialled out; a ring group used by an IVR, time condition, announcement
  or fallback can no longer be deleted (@tomck).
- **Local AI:** removing a model waits for a running synthesis or
  transcription; it could crash the AI service during a call.
- **Phones:** the file preview also hides passwords with `&`, `<` or `>` and
  NEC's shortened password; phones imported from CSV get their TFTP/FTP files
  at once instead of within 5 minutes.
- **Update and scripts:** command-line scripts exit with an error when the
  database is unreachable (they reported success); a rolled-back update also
  restores `/etc/asterisk` from its backup; configuration sync locks are
  shared by the portal, cron and root (`/var/lib/aipbx/sync-locks`).
- **HTTP:** the redirect to HTTPS checks the resolved path (hardening; such
  requests were already refused).
- Translations: new texts can be added in English first; the other languages
  show English until they are translated.

## 1.33.0

Network settings and a tidier menu:

- **Network settings page** (*Network & devices → Network settings*): shows the
  server's interfaces, addresses, gateway, DNS and host name, and keeps AiPBX's
  own network settings in one place: server address, public address, local
  networks, and the addresses desk phones are told (SIP server, file server,
  syslog, time server, allowed networks). These fields moved here from
  *Asterisk settings* and *Phones*.
- **Server address change in one step:** a new health check notices when the
  server's IP address no longer matches the settings, coturn, the mDNS name or
  the self-signed certificate. *Apply new address* (or
  `sudo aipbx-readdress OLD NEW`) shows every place first, then changes them
  all: settings, PJSIP and RTP (reload, calls stay up), coturn (restarted when
  no call is running), mDNS, the self-signed certificate and the phone files.
  Changing the server's own address from the portal is still planned.
- **Menu:** new groups *Network & devices* (Network settings, Network services,
  Phones, Phone logs) and *Security* now also holds the audit log; *Admin* is
  now *System*. Page addresses did not change.
- **Asterisk settings:** the local networks are now checked before they are
  written to the PJSIP configuration.

## 1.32.0

Security fixes from a code review (2026-10-16):

- **Star codes:** the queue, break and call-forwarding codes passed the dialled
  digits to a shell command unfiltered; a SIP user could run commands as the
  `asterisk` user by dialling e.g. `*81` followed by other characters. Every value
  is now reduced to digits first.
- **Backups:** the nightly backup now includes the settings key
  (`/var/lib/aipbx/settings.key`), without which the secrets stored in the
  database (cloud AI/TTS keys, S3, mail) cannot be read after a restore, plus
  uploaded certificates and the AI service token.
- **TURN:** coturn relays only to this server; private and link-local networks
  are refused (TURN credentials also go to visitors of websites with the call
  widget). After the server gets a new IP address, run `install.sh --upgrade`.
- **Outbound routes:** reordering routes by drag and drop now asks for *Apply*
  (Asterisk kept the old order until then); reordering trunks is logged.
- **Chat service:** deleting a message could deadlock the database pool under
  load; push notifications for large groups started one PHP process per member
  at once, now at most 8 at a time with a time limit.
- **Android 1.0.71:** the session key is no longer kept in the plain-text
  fallback file and no longer written to the app's log.

## 1.31.1

- *PBX → Phones*: the "Recent configuration requests" list is gone; the same
  requests (and FTP, TFTP, DHCP) are on *PBX → Phone logs*, linked from the page.
  The HTTPS tab there shows the results translated.

## 1.31.0

- **Phone logs in one place:** *PBX → Phone logs* has tabs for Syslog, HTTPS
  (provisioning requests), FTP (logins and downloads), TFTP (files sent or not
  found) and DHCP, each filtered by the phone's IP or MAC. A failed FTP login
  shows at once that the phone's FTP user or password is wrong.
- **FTP for desk phones needs a password:** anonymous login and `NECSDT700`
  without a password are gone; the folder holds the phones' files with their SIP
  passwords. `NECSDT700` logs in with NEC's default password `NECSdt700` (what the
  phones send) or one set under *PBX → Network services*. Anonymous login can be
  switched on there for phones that need it (not recommended). The upgrade writes
  the FTP configuration again, so the change applies at once.
- **TFTP:** a warning next to the switch: TFTP has no logins, anybody on the
  allowed networks can download the folder's files.

## 1.30.1

- **NEC phones:** the local override file now carries a version number that rises
  by one whenever the phone's files change (`<VERSION Version="n"/>`). The phones
  ignore a changed file with an unchanged number, so a new setting never reached
  them. Unchanged files keep their number. The files use Unix line ends (LF).
- **Android 1.0.70:** after a video call a phone could stop receiving calls (it
  still showed "connected") until the app was restarted; the app now keeps its
  connection and restarts its SIP engine by itself if it gets stuck. An incoming
  video call can be answered with video from the notification, and the call
  history has a video button (#83).
- Documentation: "The mobile app does not ring" in Troubleshooting.

## 1.30.0

- **Admin → Help:** the documentation is now inside the portal, for administrators
  only. It is read from the server's own disk (`/opt/aipbx/docs`), so it works on
  installations without internet access; with a search over all documents.
- **System map** (`docs/system-map.md`, the first page of Help): where everything is
  on an AiPBX server — services, ports, logs, files, the *Apply* flow from the
  database to the Asterisk files, database tables, scheduled jobs, root tools,
  debugging recipes and a "where is the code of this feature" table.
- **One documentation for everywhere:** `docs/` is shown unchanged on GitHub, in the
  portal and on [aipbx.bid/docs](https://aipbx.bid/docs). The documentation is in
  English only.
- **English code:** comments, messages, test names and identifiers across the web
  portal, mobile apps, chat service and scripts are now English, and the main files
  explain why they exist, so that anyone can follow the code. The portal's own
  texts stay translated into all 23 languages; destination names in the dialplan
  editors are now translated too.
- Lists: the page size parameter is `size` (old `boyut` links still work).

## 1.29.1

- **NEC:** the data file no longer lists the phone's language menu; each entry made the
  phone fetch a language file it could not find at every boot.

## 1.29.0

- **Fix (NEC):** `dt-<mac>.cfg` is now the phone's *master file* and names the
  settings file `dt-<mac>-phone.cfg`. The phones read AiPBX's settings file under the
  master name as a master file without data files, so only the local override file
  was applied: daylight saving (the clock stayed one hour ahead), codec order,
  language and telnet never reached them. Three files per phone now (master, data,
  local override), all in the TFTP/FTP folder and in *Show the phone's file*.
- **Android 1.0.69:** after the PBX restarts, or while the phone sleeps, the app
  connects again by itself; before, a phone could stay off the PBX (calls did not
  ring) until the app was opened. **1.0.68:** in a video call a button next to hang
  up hides the call buttons.
- Push: the unused UnifiedPush value is gone; only FCM (and APNs) count as push.

## 1.28.1

- **Fix (NEC):** a DT710 showed the time one hour ahead: it kept the daylight saving
  rule of an earlier file although AiPBX switches it off. The file now also sets the
  daylight saving adjustment to 0.

## 1.28.0

- **NTP server** (*Network services → NTP server (time)*): phones take their time
  from AiPBX (UDP 123, allowed networks only), also when the phone network has no
  internet. The server's own chrony serves it and keeps a time while its internet
  sources are unreachable. In DHCP mode option 42 names this server when no NTP
  server is entered. Enter this server's IP as *NTP server* under *Phones →
  Provisioning settings* (NEC phones take an IP only).
- **NEC phones:** the data file is written with Windows line ends (CRLF), as NEC's
  own files, and AiPBX also writes `dt-<mac>-local.cfg`, the phone's local override
  file, in the phone's own format with the same values (served over HTTP and written
  to the TFTP/FTP folder, shown in *Show the phone's file*).
- See [Network services → NTP server](docs/network-services.md#ntp-server-time).

## 1.27.1

- *Network services → TFTP files* shows the folder on the server
  (`/var/lib/aipbx/tftp`), which TFTP and FTP both serve.

## 1.27.0

- **Phone logs** (*PBX → Phone logs*): desk phones' syslog, received on this server
  and followed live in the browser. Filter by **IP or MAC address** (a MAC finds every
  address the phone was seen at; NEC phones name their MAC in each line), by text and
  by level. Switch the receiver on under *Network services → Syslog (phone logs)*: UDP
  514 is opened to the provisioning allowed networks only. Set this server as *Syslog
  server* under *Phones → Provisioning settings* (written to NEC phones).
- The log is kept in `/var/log/aipbx-phones/` (about 100 MB at most); the health check
  reports a stopped receiver.
- See [Network services → Syslog](docs/network-services.md#syslog-phone-logs).

## 1.26.0

- **NEC phone files rebuilt on NEC's template:** a DT710 rejected AiPBX's file as a
  whole ("configuration is invalid") and restarted. The file now follows NEC's data
  file template group by group and keeps every value within the phone's schema: NTP
  and syslog only as IP addresses, the extension as display name, all six codecs
  ranked, whole-hour time zone without daylight saving, TLS as transport 4. The admin
  password is no longer written (the phone's menu takes digits only); telnet stays on.
- **Syslog server** (*PBX → Phones → Provisioning settings*): where phones send their
  log; written to NEC phones.
- See [Desk phones → Vendor notes](docs/phones.md).

## 1.25.2

- The **provisioning address** (*PBX → Phones → Provisioning settings*) no longer
  needs `http://`: a bare IP or name (`10.23.3.70`, `10.23.3.70:8080`) is enough and
  means HTTP; `https://…` still works.
- **Fix:** with the DHCP server on, an `http://` provisioning address was left out
  of DHCP options 66/160 (only `https://` was sent); now both are.

## 1.25.1

- **Show the phone's file** (*PBX → Phones*, the file icon in a phone's row): a
  dialog shows exactly what AiPBX writes for that phone, one tab per file, with a
  copy button. Passwords are hidden; users who may edit phones can show them
  (written to the audit log).
- See [Desk phones → What is written to the phone](docs/phones.md#what-is-written-to-the-phone).

## 1.25.0

- **Server addresses for phone networks without DNS** (*PBX → Phones →
  Provisioning settings → Server addresses*): the **SIP server**, an **outbound
  proxy** and the **provisioning address** (where phones fetch their files) can be
  IP addresses. The proxy is written for Yealink, Grandstream, Fanvil, Snom, Poly,
  Cisco SPA and NEC; the provisioning address is used for the phones' URLs and DHCP
  option 66/160. Empty fields keep the domain as before.
- Phone files under `/provision/` are answered over plain **HTTP** too, for phones
  that cannot use HTTPS to an IP address; the allowed networks and per-phone tokens
  still apply.
- **Phone files in the TFTP/FTP folder** (option in the same dialog): for phones
  that read their configuration over TFTP or FTP (NEC), every assigned phone's file
  is written into the folder of *Network services* and kept current.
- **Android 1.0.67:** the header shows the number and name only, without
  "Extension:".
- See [Desk phones → Server addresses](docs/phones.md#server-addresses-networks-without-dns).

## 1.24.1

- **Fix:** on *PBX → Phones* the settings button (*Provisioning settings*) opened
  the web phone's microphone/speaker dialog instead, so the **allowed networks** —
  which TFTP needs before it can be switched on under *Network services* — could not
  be entered.

## 1.24.0

- **More cloud language models** (*AI → Cloud services*): **NVIDIA NIM**
  (build.nvidia.com: Llama, Nemotron, Qwen, DeepSeek …), **Qwen** (Alibaba Cloud
  Model Studio, international or mainland endpoint) and **OpenAI-compatible (own
  address)** for any service with the OpenAI chat API — DeepSeek, Mistral,
  Together, or an Ollama/vLLM server on another machine. Each has a *Model* field;
  *Test* sends one tiny request, so a wrong model name shows at once. They can be
  the *Understanding* of routing by speech and voice requests, and the new
  **language model** engine per language. Large models stay in the cloud; the PBX
  runs only small ones. See [Cloud AI services](docs/cloud-ai.md#language-models).
- **Voicemail as text** (*AI → Cloud services → Voicemail as text*): a new
  voicemail is written down with the speech-to-text engine of the PBX's language
  (a small local model or a cloud provider) and, if wanted, summarised in one
  sentence by the language model ("John Smith wants to move tomorrow's 10:00
  meeting to Friday"). It appears in the voicemail e-mail (`{ai_text}`), the app
  notification (Android 1.0.66), *My Phone → Voicemail* and the apps' voicemail
  list. Nothing holds back the e-mail or the notification when the engine fails.
  See [Voicemail as text](docs/cloud-ai.md#voicemail-as-text).
- *My Phone → Voicemail*: an unknown caller was shown in Turkish in every language.

## 1.23.0

- **Routing by speech** (*AI → Applications*, new type): a switchboard that listens.
  "Hello, which department would you like to speak to?" — the caller answers in
  their own words and is put through. For each destination: a name, keywords, two
  or three sentences callers would say, and the target. When the caller is not
  understood, they are asked again and then sent to the application's destination
  (the keypad IVR or the operator). See
  [AI applications → Routing by speech](docs/ai-apps.md#routing-by-speech).
- **Understanding** of voice requests and routing, per application:
  - *Keywords + local sentence meaning* (default): with the new small local model
    **multilingual-e5-small** (*AI → Local models*, 130 MB, ~3 ms per sentence)
    the example sentences are compared by meaning, so "can I pay by credit card?"
    finds *Accounting* without the word "invoice". On 40 Turkish requests that
    avoided the keywords: 21 put through correctly and none wrongly (keywords
    alone: 6).
  - *Keywords only*.
  - *Cloud model*: a language model set up under *AI → Cloud services* (Google AI /
    Gemini, OpenAI, Groq, OpenRouter) decides, and also recognises callers who
    want something else entirely. If it does not answer within 6 s, the local
    matching decides, so no call is lost.
- *AI → Local models*: the speed test is offered only for voices.

## 1.22.0

- **Video calls** ([#15](https://github.com/mahirgul/AiPBX/issues/15)) between the web
  phone, the Android app (1.0.65) and desk video phones. Switch them on under
  *Admin → Asterisk settings → Video* and choose the video codec: **VP8** for
  browsers and apps, **H.264** if you also have desk video phones. The web phone
  gets a video call button next to the dial field and *Video* on an incoming video
  call; the app offers *Video call* in Contacts and History. During the call: the
  other side's picture with the own camera in the corner, camera on/off, full
  screen (web) and front/back camera (app). Without a camera the call goes on with
  audio. See [Video calls](docs/video-calls.md).
- One video codec for the whole PBX: Asterisk does not convert video and would hang
  up a call whose two sides use different video codecs.
- While video calls are on, WebRTC endpoints have no RTP timeout (Asterisk sees
  bundled video as silent and hung video calls up after 30 s); the web phone now
  uses SIP session timers like the apps, so calls of a closed browser still end.
- **Voicemail notifications** ([#75](https://github.com/mahirgul/AiPBX/issues/75)): every
  new message writes one line saying whether the app was reached and how push went
  (`journalctl -t aipbx-voicemail`); Firebase errors are shown in full. The
  notification itself needs Android app 1.0.63 or newer. See
  [Troubleshooting](docs/troubleshooting.md#no-notification-for-a-new-voicemail-in-the-app).
- **Android 1.0.64**: tapping a call in the history asks *Call* or *Chat* (as in
  Contacts); colleagues get a chat button next to the call button.
- Google Play now shows the real "What's new" text of each app version.
- The incoming call banner of the web phone and the app's contact count were partly
  in Turkish in every language; missing texts of the S3 storage page translated.

## 1.21.0

- **Voicemail archive in the S3 bucket** ([#69](https://github.com/mahirgul/AiPBX/issues/69)):
  *Admin → File storage → Archive heard voicemail older than (days)*. Messages that
  were already listened to and are older than that move to the bucket and leave
  the PBX; they stay listed as *Archive* under *My Phone → Voicemail* and in the
  apps, where they can be played and deleted. New messages are never moved. See
  [File storage](docs/file-storage.md#voicemail-archive).
- *My Phone → Voicemail*: the folder labels of heard (and archived) messages are
  shown as badges again.

## 1.20.0

- **Faxes in an S3 bucket** ([#69](https://github.com/mahirgul/AiPBX/issues/69)): tick
  *Faxes in the bucket too* on *Admin → File storage*. The PDF and TIFF of received
  and sent faxes move to the bucket an hour after the fax finished and leave the
  PBX's disk. Viewing, downloading, sending again and deleting work as before;
  the retention period deletes them in the bucket too. See
  [File storage](docs/file-storage.md#faxes-in-the-bucket).
- **Fix: fax retention and the stuck-fax sweep did nothing.** Both scripts logged
  in to the database with `/root/.my.cnf`, which the installer never wrote, so old
  faxes were never deleted (*Fax settings → retention days*) and outgoing faxes
  that never finished stayed "pending" for ever. They now log in like the other
  root jobs. On the first night old faxes beyond the retention period are removed.

## 1.19.0

- **Call recordings in an S3 bucket** ([#69](https://github.com/mahirgul/AiPBX/issues/69)):
  with an S3-compatible bucket on *Admin → File storage*, tick *Call recordings in
  the bucket too*. Finished recordings (MP3) are uploaded within minutes and leave
  the PBX's disk; older ones follow in batches. Playing, downloading and deleting
  work as before and the bucket stays private (files go through the PBX). When the
  bucket cannot be reached, recordings stay on the disk until it can. See
  [Call recordings](docs/recordings.md#in-an-s3-bucket).

## 1.18.0

- **New voicemail notification in the Android app (1.0.63)**
  ([#46](https://github.com/mahirgul/AiPBX/issues/46)): when a voicemail arrives the
  app shows "Voicemail — caller · length"; tapping it opens the mailbox and the
  badge on the voicemail button updates. It arrives over the app's connection to
  the PBX, and by push (Firebase / APNs) when push is set up, so also when the app
  is not running. Asterisk calls `voicemail_notify.php` (voicemail
  `externnotify`) when a mailbox gets a new message.

## 1.17.2

- **Translations:** health checks, FTP users and the extra DHCP options in all 21
  other portal languages.

## 1.17.1

- **Health checks — trunks:** a trunk whose reachability Asterisk has not checked
  yet (qualify off, or right after Asterisk starts) is no longer counted as
  reachable; the result says how many are not checked yet.

## 1.17.0

- **Health checks** (*Admin → Health checks*): the server watches itself — Asterisk,
  database, web portal, chat, trunks, disk, memory, e-mail sending, pending
  changes, outgoing faxes, DHCP/TFTP/FTP, local AI, TLS certificate, the nightly
  backup and what the installer set up. Every check has its own interval. The
  chosen users (and other addresses) get an e-mail when something stops working
  and again when it works again; short hiccups stay quiet, and the alarm repeats
  while a problem lasts. See [Health checks](docs/health.md).
- **Daily summary for managers:** every morning yesterday's calls (incoming,
  outgoing, internal, answered, missed, talk time), faxes, yesterday's alarms and
  the state of every check, by e-mail.
- **`sudo aipbx-selftest`** runs every check at once; the installer runs it at the
  end of every installation and update and prints the result.

## 1.16.1

- **Android app 1.0.62 — short status notification:** the permanent notification
  now reads just "AiPBX · 1000 ✅" — the extension with a mark that shows at a
  glance whether calls can come in (✅ connected, 🟡 connecting, 🔴 not connected),
  and the icon is green, amber or red to match. Notifications during a call are
  unchanged.

## 1.16.0

- **FTP:** the user `NECSDT700` of NEC DT700/DT800 phones is always there while FTP
  is on and needs no password; switching FTP on no longer asks for one. Extra FTP
  users with a password can be added and removed (read only, like everybody).
- **Extra DHCP options** (*PBX → Network services*, DHCP mode): `code,value` lines
  next to the ones AiPBX hands out; the same code replaces AiPBX's value, e.g.
  `66,ftp://…` for phones that fetch from FTP.
- **Website widget:** when the website itself forbids the microphone
  (`Permissions-Policy: microphone=()`, often set by WordPress security plugins),
  the widget now says so and what the site owner has to change, instead of
  asking the visitor to allow the microphone.
- **Fixes found by a fresh install test:** TFTP's firewall rule no longer needs the
  `nf_conntrack_tftp` kernel module (missing in containers and some kernels, where
  switching TFTP on failed); a firewall change that failed half way no longer
  blocks the next attempt; voicemail marked as heard in the app created the
  mailbox's `Old` folder without write access for Asterisk.

## 1.15.0

- **Voicemail in the Android app (1.0.61)** ([#46](https://github.com/mahirgul/AiPBX/issues/46)):
  a voicemail button next to the sign-out button shows the number of new
  messages and opens the mailbox: who called, when and how long, listen and
  delete. A message listened to counts as heard, like on the phone, so the
  voicemail lamp of desk phones goes off as well. New app endpoint
  `api/mobile/voicemail.php` (own mailbox only); Asterisk now notices messages
  listened to or deleted in the portal or the app within 15 s.
- **Android keypad:** the number can be edited like in a phone app: tap to place
  the cursor, type or delete in the middle, long press to paste (spaces, dashes
  and brackets of a pasted number are dropped).
- **Android chat:** a picture chosen to send shows a small preview before it is
  sent, and the cancel button is a plain ✕ instead of the red hang-up icon.

## 1.14.1

- **Portal installed as an app (PWA):** proper icons. Android gets "maskable"
  icons on white that fit its round and rounded-square shapes (before, the logo
  was squeezed into a white circle), iPhone a white 180 px icon (transparent
  parts used to turn black), and the other icons a little margin. Installing from
  the sign-in page now gets the app name and icons too. An app installed earlier
  picks up the new icon after its next update by the browser, or after removing
  and installing it again.

## 1.14.0

- **Read-only FTP server for desk phones** (*PBX → Network services*): for phones
  that fetch firmware and files over FTP, such as NEC DT700/DT800. It serves the
  same files as TFTP to anonymous logins and to one FTP user (default
  `NECSDT700`); nothing can be uploaded or changed. The firewall opens port 21
  and the passive ports 40000–40049 for the allowed phone networks only. See
  [Network services](docs/network-services.md).
- **Android app 1.0.60 — contacts:** tapping a contact no longer calls at once; it
  asks whether to call or chat. The buttons on the right call or open the chat
  directly (phone contacts offer calling only). The back button closes an open
  chat and goes back to the keypad again before leaving the app (on Android 16
  it used to close the app at once).

## 1.13.0

- **NEC desk phones:** *PBX → Phones* provisions NEC DT700/DT800 phones with SIP
  firmware (DT710, DT730, DT750, DT820, DT830): `dt-<mac>.cfg` with the account,
  server, transport, voicemail number, codecs, time zone, language and admin
  password. Keys are not written yet. See [Desk phones](docs/phones.md).

## 1.12.3

- **Translations:** the 221 texts added since 1.9 (local AI models, AI
  applications and voice requests, cloud services, wide logo, the "AI request"
  e-mail) are now translated into all 21 other portal languages, which showed
  them in English until now.

## 1.12.2

- **Invitation e-mail:** the "Sign in to the mobile app" box now comes first,
  right below the greeting, before the password-setup button (all languages).
  E-mail templates you changed under *E-Mail → Templates* keep your order.

## 1.12.1

- **Android app 1.0.59 — pictures in chat:** pictures sent in a chat on the main
  screen did not load or open in the Android app (the web portal showed them):
  the app asked for them without its session, and the server refused. Long press
  on your own message there now offers to delete it, as in the separate chat
  window.

## 1.12.0

- **Whisper speech recognition** in *AI → Models*: Whisper small for Turkish, German
  and English, run with int8 weights on the CPU (CTranslate2, no PyTorch). On our
  Turkish telephone test it makes less than half of Vosk's word errors (7.6 % against
  17 %) and recognised 22 of 24 hotel requests against 17; it answers about 1.5 s
  after the caller stops speaking. It needs about 650 MB of memory; keep Vosk on
  small servers or with many calls at once. The AI runtime is updated
  automatically (adds about 140 MB).
- **Voice requests with a cloud speech-to-text provider:** besides local models and
  the language engine, a voice-requests application can use a provider from *AI →
  Cloud services* directly (OpenAI, Groq, Deepgram, Azure, ElevenLabs, Google AI),
  with its own language. When the provider cannot be reached the call goes to the
  application's destination as "not understood".

## 1.11.1

- **Wide logos:** *Brand settings* has a new **Logo shape** option. "Wide"
  shows a wordmark logo across the whole head of the side menu and on the
  sign-in page, without the brand name next to it; "Square" (the default)
  keeps the small icon with the name.
- **Website widget microphone messages:** instead of one message for every
  problem, the widget now says whether the browser blocked the microphone,
  no microphone was found, it is in use by another program, or the page is
  not served over https.
- **Android app 1.0.58:** the "the app was put to sleep" warning no longer
  appears when the app is already exempt from battery optimisation or the
  phone was restarted in between, and at most once a week.

## 1.11.0

- **Voice requests** (new type of *AI → Applications*): the caller hears a
  greeting, says what they want, and the application recognises the request
  with a speech-to-text model of this server, answers by voice, e-mails the
  staff (with what was said and the caller's recording) and passes the call
  on — or, when it is not sure, asks again and then puts the caller through
  to a person. Example: a hotel guest calls reception from the room phone
  and says "Odaya iki havlu daha gönderir misiniz?" — the request "Towels"
  is recognised, the guest hears "Your towels are on the way", housekeeping
  gets an e-mail with the room number. Requests are set up with keywords
  (word beginnings), example sentences, a reply, an e-mail address and a
  destination. Every call is listed under **Recognised requests** with the
  text and the recording, and can be ticked as done. New e-mail template
  "AI request". See [AI applications](docs/ai-apps.md).

## 1.10.1

- **Turkish amounts read naturally:** "1.234,50 lira", "1.234,50 TL" and
  "₺1.234,50" are spoken as "bin iki yüz otuz dört lira elli kuruş" (before:
  "virgül beş sıfır" or "türk lirası"), "0,99 TL" as "doksan dokuz kuruş";
  euro, dollar and pound amounts likewise ("avro … sent"). Phone numbers are
  read with a short pause between their groups. Lookup addresses of AI
  applications can now return amounts as written ("1.234,50 TL").

## 1.10.0

- **AI applications** (*AI → Applications*, admin only): AI features that
  take calls, each with its own **internal number** and a destination
  afterwards; they can also be chosen as a destination in IVRs, inbound routes
  and time conditions ("AI application"). The first type, **announcement with
  values**, reads a text to the caller in real time with a voice model of this
  server: the caller's name and number from the call, and any value from your
  own system through a lookup address (e.g. "Dear {caller_name}, your balance
  is {amount}"), with a fallback text when the lookup fails. When the AI
  service is not running or busy, calls go straight on. See
  [AI applications](docs/ai-apps.md).
- The local AI service takes live calls: Asterisk connects the call's audio
  to it (AudioSocket on 127.0.0.1:8791); a sentence is ready in about half a
  second on a 2-core server.

## 1.9.5

- **AI → Cloud services** (admin only): the accounts of cloud AI providers in
  one place — Google AI Studio (Gemini, Gemma), OpenAI, Azure, Google Cloud,
  Amazon Polly, ElevenLabs, Deepgram, Groq and OpenRouter — with what each can
  do, a link to get a key, a connection test and this month's use. For each
  job and language (speech and speech to text in Turkish, German, English)
  you choose a local model or a cloud provider; the AI features that follow
  use this choice. A notice reminds that cloud engines send audio and text to
  the provider (KVKK/GDPR). See [Cloud AI services](docs/cloud-ai.md).
- **Cloud speech to text:** OpenAI, Groq (Whisper), Deepgram, Azure,
  ElevenLabs and Gemini appear next to the local models in *AI → Local models
  → Try: speech to text*, to compare them on the same recording.
- **Numbers read correctly in German and English voices:** amounts
  ("1.234,50 €", "$1,234.50"), times ("14:30 Uhr", "2:30 pm"), dates
  ("am 3. Oktober", "12/31/2026"), percentages, phone numbers (read digit by
  digit in their groups) and common abbreviations. The update adds the
  `num2words` package to the AI runtime.
- Cloud TTS: keys of the cloud providers are now changed on *Cloud services*
  only (admin role); the Cloud TTS page shows their status.

## 1.9.4

- **Speech to text on the server (Vosk):** small Turkish, German and English
  models (36–45 MB, Apache-2.0) on *AI → Local models*. A new *Try* panel
  records from the microphone (up to 15 s) or takes a WAV file and shows the
  text. On a 2-core server Turkish is recognised about 5× faster than real
  time; short requests come out well, about 1 word in 4 is wrong on noisy
  telephone audio.
- **Add a voice:** every voice of the public Piper voice list (about 180
  voices, 58 languages) can be found by language and quality and added. Its
  licence is read from the model card; non-commercial and fine-tuned voices
  are marked. Files are pinned and checked like the recommended ones.
- **English voices (Kokoro):** Heart and Michael (US), Emma and George (UK),
  Apache-2.0, very natural. They share one 311 MB model (each further voice
  adds 0.5 MB); about 1.7× faster than real time on a 2-core server, so they
  suit announcements made in advance rather than live calls.

## 1.9.3

- **AI → Local models, several models:** models can now be **run** and
  **stopped** one by one (a stopped model keeps its files and stays stopped
  after a restart). The page shows the memory each running model uses, the
  disk used by models (limit 5 GB), our own speed and memory measurement for
  recommended models, and a warning on models that are not cleared for
  commercial use.
- **Try panel:** speak your own text with any running voice model and keep
  the last results side by side to compare voices.
- **German and English voices (Piper):** German *MLS* (CC BY 4.0, many
  speakers), *Thorsten* and *Kerstin* (marked: fine-tuned from voices with a
  research or non-commercial licence), English *Cori* (public domain, slower
  high-quality voice). About 13× faster than real time on a 2-core server,
  120–150 MB of memory each. The update installs `espeak-ng` on servers that
  have the AI runtime.
- **Cloud TTS:** the "Local models" provider offers every running voice
  model, so announcements can be made in Turkish, German or English.
- The speed test now reads a sentence in the model's own language.
- Known limit: Piper voices read numbers as written (e.g. "1.234,50 Euro"
  comes out as "… Komma fünf null"); write amounts and dates out in words
  for now.

## 1.9.2

- **Local AI models are much lighter:** the runtime now uses ONNX Runtime
  instead of PyTorch — about 160 MB instead of 1.1 GB to download, about
  150–250 MB of memory instead of up to 1 GB, installed in about a minute.
  EMA Lightning speaks about 9–11× faster than real time on a 2-core server
  (3–4× before), with the same voice. The model files come from AiPBX's own
  release `models-ema-lightning-1` and are checked by SHA-256 before use.
  Servers that installed the runtime with 1.9.0–1.9.1 are moved over by the
  update: the runtime is rebuilt and the model downloaded again by itself.
- **AI → Local models:** the page now follows the runtime installation by
  itself; before, it could keep showing "Not installed" until reloaded.

## 1.9.1

- **Fix:** the update to 1.9.0 failed its page check and rolled back on
  servers (PHP 8.5): a deprecation notice of PHP 8.5 (`curl_close()`) was
  counted as an error once the Cloud TTS page asked the local AI service for
  its state. Update straight to 1.9.1; it contains everything from 1.9.0.

## 1.9.0

- **Local AI models** (*AI → Local models*, admin only): AI models that run
  on the PBX itself, so text and audio never leave the server, with no API
  key and no cost. The page installs the local AI runtime once (Python with
  PyTorch for the CPU, about 1 GB, in the background), downloads models after
  their licence is accepted, shows the service's CPU and memory use, and has
  a speed test that tells whether a model is fast enough on this server. The
  service (`aipbx-ai`) listens only on the server itself, runs at a lower CPU
  priority than calls and with a memory limit; it stays off until the runtime
  is installed. See [Local AI models](docs/local-ai.md).
- **EMA Lightning (Turkish text-to-speech):** the first local model
  (Apache-2.0). Once downloaded it appears on *AI → Cloud TTS* as
  "EMA Lightning (local, Turkish)": listening, MP3 download and saving as an
  announcement work as with the cloud providers. On a 2-core server it speaks
  about 3–4× faster than real time.
- **Fix:** announcements could not be uploaded on *Sounds & Announcements*
  (and AI-made ones not saved): the portal had no write access to the custom
  sounds folder. The update fixes the folder's permissions.

## 1.8.2

- **Translations:** the texts added up to 1.8.1 (website widget, network
  services, file storage, Apple push, IVR call flow, brand settings, audit
  log names) are translated into all 21 other portal languages. These are
  machine translations; corrections from native speakers are welcome.

## 1.8.1

- **Admin menu:** *System Update*, the last item of the Admin group, was cut
  off since *File storage* was added (an open group was limited in height).
- **Network services:** DHCP and TFTP are now two separate switches, so
  either one can run alone (DHCP without TFTP, TFTP without DHCP) or both.
  Existing settings are kept.
- **Audit log:** changes to the chat settings and the MS Teams settings, and
  showing a desk phone's admin password, are now recorded. All entries show
  a readable action name (sign-in, reboot, e-mail sent …) instead of an
  internal code.
- **Roles:** the File storage module has its name in the permission list.

## 1.8.0

- **Chat files in S3-compatible storage** (*Admin → File storage*, admin
  only): chat attachments, thumbnails and group pictures can be kept in an
  S3 bucket (AWS S3, MinIO, Wasabi, Backblaze B2, Cloudflare R2 …) instead of
  the local disk, which stays the default. The page tests the connection and
  can move the files already on the disk into the bucket. Downloads still go
  through the portal with the same checks, so the bucket can stay private;
  files keep opening after switching in either direction. Recordings,
  voicemail and faxes stay on the local disk. See
  [File storage](docs/file-storage.md).
- **IVR call flow:** a new button on each IVR shows the whole path of a
  caller as a tree: every key, no input and invalid key, with sub-menus,
  time conditions and announcements opened up in place. Loops and missing
  targets are marked.
- **Brand settings:** an optional dark theme logo, the choice of logo for
  e-mails, and an option to leave the brand name out of the e-mail header
  when the logo already contains it.
- **Voicemail e-mails:** the notification is delivered even when the mail
  script stops early (database unreachable, PHP error), and every
  notification leaves a line in `journalctl -t aipbx-voicemail`.
- **Translations:** ready for Hosted Weblate (see
  [Translating](docs/translating.md)); CI now checks every portal language
  for unknown keys, placeholders and HTML tags.
- **Android app 1.0.57:** after the session is rejected, the sign-in screen
  says why ("session expired") instead of a call history error.

## 1.7.5

- **Website call widget** (*Integrations → Web widgets*, admin only): a
  "Call us" button for any website. Visitors call the company from the
  browser over WebRTC, without a phone or an app, or leave a number for a
  call-back. Each widget works like a trunk with its own number as the DID,
  so routing, queues, time conditions and reports apply; the destination is
  fixed by the administrator. Calls need a one-time token, and allowed
  websites and rate limits are set per widget. The site needs one line of
  code; a WordPress plugin is in `integrations/wordpress`. See
  [Website call widget](docs/web-widgets.md).

## 1.7.4

- **DHCP and TFTP for desk phones** (*PBX → Network services*, roadmap 10):
  *TFTP only* (the page lists the options 66/160/150/42 to set on your own
  DHCP server) or *DHCP for a phone network* on one interface or VLAN, with
  the provisioning URL handed to the phones (option 66/160). Off by default;
  a check for other DHCP servers runs before DHCP is switched on. Leases are
  listed, unknown phones among them appear as waiting phones on *PBX →
  Phones*. TFTP answers only the provisioning *allowed networks* and never
  serves the per-phone configuration. See
  [Network services](docs/network-services.md). The update installs
  `dnsmasq-base` (the service stays off until it is switched on).

## 1.7.3

- **iOS push notifications (APNs):** iPhones now get incoming calls and chat
  messages while the app is closed, like Android with FCM. A call arrives as
  a VoIP push and rings on the CallKit screen; chat messages can be answered
  from the lock screen. Set it up on *Admin → Push* (new APNs section: the
  Apple `.p8` key, Key ID, Team ID, bundle ID, sandbox or production). Android
  (FCM) and iOS (APNs) can be on at the same time. The iOS app has to be
  signed with a push-capable profile; see `ios/README.md`.

## 1.7.2

- **Android app 1.0.56: empty call history and settings after an update**
  (#17). When the server no longer accepts the saved sign-in (expired after a
  long time, password reset, or a server change), the app looked signed in
  but every list stayed empty until it was reinstalled. It now signs out and
  opens the sign-in screen.

## 1.7.1

- **Phone provisioning: Snom, Cisco SPA and Poly** (#27) next to Yealink,
  Grandstream and Fanvil: Snom D3xx/D7xx, Cisco SPA303/50x/525G2 and Poly VVX /
  Edge E, with their key layouts and expansion modules, re-provision and
  (Snom, Cisco) reboot. See [Phones](docs/phones.md); not yet tried on real
  devices of these brands.
- **iOS app** (#26): remove calls from the call history (swipe with Undo,
  clear all), reply to a chat message from its notification, and delete your
  own chat messages, as on Android. iOS shows chat notifications only while
  the app's chat connection is alive (no push for iOS yet).
- **Translations:** the texts added in 1.6.6–1.7.0 (phones, key layouts,
  chat message deletion …) are in all 23 portal languages (#25).

## 1.7.0

- **Phone provisioning** (*PBX → Phones*, #19): add desk phones by MAC
  address or CSV, and they fetch their configuration over HTTPS from a
  per-phone URL: SIP account, server, transport and SRTP, codecs, time and
  language, voicemail key and pickup code, and the **key layout**. The key
  layout of each user (*Extensions → keys*) is drawn like the phone and its
  expansion modules (BLF, speed dial, park, DND …) and can be copied to other
  users. Unknown phones that ask for a configuration appear as *waiting
  phones* and are assigned with one click. *Re-provision* and *reboot* reach
  the phone at once. Yealink, Grandstream and Fanvil for now; every download
  is logged; optional allowed networks and rate limit. See
  [Phones](docs/phones.md). Grandstream and Fanvil settings still need a check
  on real devices: reports are welcome.
- **Delete chat messages** (#15, #21): your own messages, in the web chat and
  the Android app (long press). Everyone sees "This message was deleted", and
  an attached file is removed from the server. An administrator can limit
  deleting to the first N minutes (*Chat → Chat settings*). Android app 1.0.55.
- **Dialplan:** no more "The use of '_.' … is strongly discouraged" warnings
  on every reload (#20).
- **Buttons that did nothing:** in the portal, a form whose action is its
  button (AI TTS *Clear provider*, the new phone and chat buttons) lost that
  button on the way to the server.

## 1.6.6

- **More busy lamps for desk phones:** a BLF key with the value `DND1001`,
  `CF1001` or `QUEUE1001` shows whether 1001 has do-not-disturb on, forwards
  all calls, or is logged in to a queue (and not on a break). On the user's
  own phone the key switches do-not-disturb, cancels the forwarding, or logs
  in to / out of the queues. See [Busy lamps](docs/blf.md) for setting the
  keys on Yealink, Grandstream and Fanvil phones.

## 1.6.5

- **Feature codes missing on new installations:** do-not-disturb (`*78`),
  call forwarding (`*72`/`*73`), group and directed pickup (`*20`/`*21`) and
  call listening (`*90`) were not created on a fresh install (the seed data
  collided with rows the migrations had already written). They are added on
  the next update; existing codes and their settings are not changed.
- **Desk phones: pick up a ringing colleague with the BLF key.** A busy-lamp
  key dials the pickup code and the extension in one go (`*211001`); this
  now picks up the call instead of asking for the number.
- **Desk phones: voicemail lamp.** Phones of users with a voicemail box get
  the message-waiting indication (lamp and count).
- Busy lamps (BLF) were tested end to end: a phone watching an extension
  gets ringing, talking and free states.

## 1.6.4

- **Translations:** the texts added in 1.6.x (e-mail templates and their
  editor, voicemail e-mail settings, "Forgot your password?", show/hide
  password, the sign-in help contact) are in all 23 portal languages (#18).
  The translations are machine-made; corrections are welcome.
- E-mail templates: a link to a `{value}` in a template could be saved as
  `%7Bvalue%7D` with some PHP/libxml versions and was then not filled in.

## 1.6.3

- **Help contact on the sign-in page** (#13): *Brand settings* has an
  optional contact name, e-mail and phone. When set, the sign-in and
  "Forgot your password?" pages show "Need help?" with them.

## 1.6.2

- **Google sign-in returned to the sign-in page** (#9). The session cookie was
  `SameSite=Strict`, so the browser did not send it when Google sent the user
  back: the portal could not check the request and signed nobody in. It is
  `Lax` now (changes still need POST and a CSRF token).
- **"Forgot your password?"** on the sign-in page (#13): a user enters their
  username or e-mail address and gets the *Password reset* e-mail (valid 30
  minutes). The answer is the same whether the account exists or not; at most
  5 requests an hour per network and one e-mail per account every 10 minutes.
  The link always uses the installation's own address. Requests show in the
  audit log (*Sign-in attempts*).
- E-mail templates: in the plain-text version a button's link stands on its
  own line.

## 1.6.1

- **Show the password while typing** (#11): the password fields of the
  sign-in, password reset and password change pages have an eye button.

## 1.6.0

- **E-mail templates** (*Admin → E-Mail → Templates*): the texts of the
  invitation, sign-in link, password reset, voicemail, fax received / delivered
  / not delivered and test e-mails can be edited, per language, with a live
  preview, value buttons (`{name}`, `{caller}` …) and a test send. Every
  e-mail now uses one frame with the logo, name and colour from Brand
  settings. See [E-mail](docs/mail.md#templates).
- **E-mails in the recipient's language.** Portal users get them in their own
  language; fax unit addresses and other recipients without an account in the
  language set on the Templates page (default: the first administrator's).
  The fax e-mails used to be Turkish only, the invitation was in the language
  of the administrator who sent it.
- **Voicemail e-mails** are HTML with the logo, show the caller, time and
  length, and keep the recording as an attachment. If building them ever
  fails, Asterisk's own message is delivered instead.
- **Android app 1.0.54:**
  - *Full-screen incoming calls* (#7): Android 14 and later can switch off
    full-screen notifications for an app, and then a call on a locked phone
    only shows a small notification. The app explains this once and links to
    the setting; *Settings* shows whether it is on.
  - *Remove calls from the call history* (#10): swipe a call away (with
    Undo) or clear the whole history. Only your own list changes; the call
    records on the server, reports and recordings stay.
  - *Reply from a chat notification* (#8) without opening the app.

## 1.5.3

- **Google sign-in settings could not be saved** ("Unknown column
  'updated_at'"), so Google Workspace sign-in could not be set up (#9).
- **Voicemail could not be switched off for an extension** (#2): after
  unticking *Voicemail box enabled* and saving, the box was ticked again.
  The same happened to *Attach recording*.
- **Voicemail settings moved to My Phone → Voicemail**, next to the
  messages, with their own Save button. Saving call forwarding or DND (in the
  portal or the mobile app) used to reset them: the recording attachment
  was switched off and the forward-to-voicemail options were cleared.
- **E-mail notification switch for voicemail**, per user and on the
  extension form. It is on for existing users, so nothing changes until
  someone turns it off.
- **Mobile API: remove calls from your own call history** (one call or all).
  The call records themselves are not deleted: reports, call journeys and
  recordings stay. Used by the next Android app version (#10).

## 1.5.2

- **Updating from the portal failed** (*Admin → System Update*) with "The
  HOME or COMPOSER_HOME environment variable must be set" and rolled back,
  while `sudo aipbx-update` over SSH worked (#3). The update runs as a
  background service that had no HOME; it is set now. Older installations
  can update from the portal again, because the fix is in the new
  version's installer.
- **Android app 1.0.53:** the battery-optimisation request no longer opens
  every time the app starts (#6). It is shown once; later it is under
  *Settings → Battery optimisation*.

## 1.5.1

- **Croatian (Hrvatski) in the Android and iOS apps** (#5). Android app
  1.0.52.
- **Sign-in page:** the language dropdown sits at the bottom of the sign-in
  card on computers too, as on phones, instead of in the page corner.
- App translations are checked automatically on every change (missing
  texts, placeholders, apostrophes).

## 1.5.0

**20 new languages** (#3, #4): German, Russian, French, Spanish,
Azerbaijani, Serbian (Latin), Italian, Portuguese, Dutch, Polish, Ukrainian,
Romanian, Greek, Czech, Hungarian, Bulgarian, Swedish, Danish, Norwegian
Bokmål and Finnish.
- **Web portal:** 23 languages in total, picked from the language dropdown.
- **Android and iOS apps:** the same 20 languages (Croatian follows).
- These are machine translations: corrections from native speakers are very
  welcome, see [docs/translating.md](docs/translating.md).

**Android app 1.0.51**
- A language chosen in Android's own per-app language settings is now picked
  up everywhere: the language button and notifications used to stay in the
  previous language.

**Fixes**
- Phone sign-in page: the language dropdown was 16 px off to the left.

## 1.4.0

**Languages**
- New interface language: **Croatian (Hrvatski)**, fully translated.
- The language is now chosen from a **dropdown**: on the sign-in pages and
  in the user menu (bottom right). New languages appear there by themselves.
- A partly translated language works: missing texts are shown in English.
- Translations are open to everyone: `scripts/i18n.py` exports the missing
  texts, checks a translation (placeholders, HTML) and writes the language
  file. Guide: [docs/translating.md](docs/translating.md).

**Sign-in page**
- Animated background: a moving network and falling SIP / Asterisk log
  lines, in calmer colours in the light theme. It is turned off for users
  who ask their system for reduced motion.

**Dark mode fixes**
- The **Active / Passive** buttons, the incoming-call **Answer** button and
  some other green buttons were almost invisible: the green button style was
  missing.
- The **Incoming / Outgoing / Missed** filters on *My Phone* were white with
  white text.
- **Extensions**: the Active/Passive button was hidden behind the pinned
  Actions column when the table was wider than the screen; it now sits next
  to Edit / Delete.

## 1.3.9

- Sign-in page: the security question and its answer box are on one row.
- Phones: the **English / Türkçe** buttons sit at the bottom of the top
  card, as big as the sign-in button; turned sideways, both cards have the
  same height.

## 1.3.8

- Phone sign-in page: the **English / Türkçe** switch and **Sign in with a
  Passkey** are in the top card, with the Google Play button; the second
  card holds only the sign-in form.

## 1.3.7

**Sign-in page on phones**
- The page scrolls; before, a phone showed only part of it and could not
  scroll, and the logo was cut off at the top.
- Two cards: logo + a single **Get it on Google Play** button, and the
  sign-in form. Side by side when the phone is turned sideways.
- The language switch no longer covers the card on small screens.

**Portal**
- Outline buttons (e.g. *Sign in with a Passkey*, and buttons on about ten
  other pages) were never styled and looked like plain text; they now have
  a coloured border.

## 1.3.6

- The mobile app download buttons (sign-in page on phones, **My Phone →
  Settings**) open the app on **Google Play**. They pointed to `/app.apk`,
  a file that does not exist on new installations.
- Fix for the unit tests of 1.3.4 (the prompt-language change tried to
  edit the real `/etc/asterisk` during tests); no change on servers.

## 1.3.5

**Install / update**
- An update no longer fails and rolls back when Asterisk does not come up
  on the first restart but starts a moment later by itself; the installer
  waits up to 20 seconds for it.

## 1.3.4

Fixes found while going through user feedback (#1).

**Portal**
- Users without admin rights had no bottom bar on **My Phone** (their start
  page), so no logout, language or theme buttons: a pop-up on that page was
  missing a closing tag and swallowed the bar.
- **My Phone** shows the role name ("User") instead of the internal key
  ("user").
- The sign-in pages have an **English / Türkçe** switch in the top-right
  corner, and the sign-in button has a label.
- **Password change required:** the user can now set the new password right
  on that page. Before, the only option was a link by e-mail, which locked
  out users without an e-mail address or when mail was not working. An
  invitation now asks for a new password only if the e-mail was really sent.
- **Appearance → Branding:** uploading a logo failed with "could not be
  saved" on normal installations: the upload folder was missing. Installs
  and updates create it.

**Phone prompts**
- **PBX Settings → System Default Language** never took effect: the portal
  could not write `asterisk.conf`, failed silently and still asked for an
  Asterisk restart. It is written correctly now. (Restart Asterisk after
  changing it.)

## 1.3.3

**E-mail**
- Sending through a mail server that needs a login (SMTP authentication)
  never worked: the username and password were not saved for postfix,
  although the page said "saved". The mail server then refused every
  message ("Relay access denied"). They are saved now; if that fails, the
  page shows an error.
- Port 465 ("SSL") works: postfix now uses TLS from the first byte there.
- **Send Test E-Mail** waits up to 20 seconds for the real result and shows
  the mail server's answer, e.g. `535 Authentication credentials invalid`
  or `Sender address rejected`. Before, it said "queued" even when the
  message was rejected a second later.

**Music on hold**
- Callers on hold or waiting in a queue heard silence: the "default" music
  class pointed to an empty folder, so Asterisk dropped it. Installs and
  updates now link Ubuntu's stock music into it, as long as the folder has
  no music of its own.

## 1.3.2

**Install / update**
- Updates no longer run apt when every system package is already
  installed (system package updates are left to the OS). On servers that
  cannot reach the Ubuntu mirrors — e.g. behind a proxy that stalls port 80
  — an update used to hang for many minutes in `apt-get update` and could
  then fail on a package download and roll back. Only packages a new release
  adds are installed, with short network timeouts.

## 1.3.1

**Android app 1.0.50**
- The QR code scanner no longer turns the screen sideways on phones; it
  follows the device orientation like the rest of the app.

**Server**
- Static analysis fixes (no change in behaviour).

## 1.3.0

English is now the default language of the whole system; Turkish stays one
click away.

**Web portal**
- The portal, its login page and every message are in English by default.
  Switch with the TR / EN button in the user menu (bottom left) — each
  user's choice is remembered. Everything is translated: pages, buttons, tables,
  pop-ups, notifications, e-mails (password reset, invitations) and the
  softphone, chat and call-center panels.
- Built-in roles (Admin, Viewer, Agent, Queue Manager, ...) are shown
  in the selected language. Roles you create keep the name you gave them.
- Existing installations keep their data and language: users who already
  use Turkish stay in Turkish.

**Phone prompts (new installs)**
- New installations speak English prompts. Turkish prompts can be added any
  time on Sounds → Asterisk Sound Packs (or at install time with
  `AIPBX_TR_SOUNDS=yes`). An update no longer switches an installation's
  prompt language.

**Mobile apps**
- The server answers the apps in the app's language: error messages and
  contact role names are English or Turkish to match the app. The Android
  diagnostic report is in English. Android app 1.0.49.

**Fixes**
- System Update, Sound Packs and the mail settings page showed an error
  after the action had actually succeeded ("Call to undefined function
  writeAuditLog").
- Read-only admins were re-checked: they can view everything but cannot
  change anything.

## 1.2.0

Mobile apps in English by default, with Turkish selectable.

**Android and iOS apps**
- The apps open in English by default — also on a Turkish phone — until
  Turkish is picked. Pick the language on the server / login screen
  (🌐 English / Türkçe) or in Settings; it is kept after signing out.
- Android: switching applies at once; Android 13+ also lists English and
  Turkish in the phone's per-app language settings. Every text of the app
  (about 380) is translated, including notifications and error messages.
- iOS: switching rebuilds the screens at once, no restart. Permission texts
  (microphone, camera, photos) follow the phone's language, as iOS requires.
- Error messages that come from the server are shown as the server sends
  them.

## 1.1.0

Installation fixes and Asterisk sound packs. Supported system: Ubuntu 26.04 LTS.

**Install / update**
- The installer now stops at the very start, before installing anything, on
  systems older than Ubuntu 26.04 (the PHP dependencies need PHP 8.4+, the
  portal targets Asterisk 22). On Ubuntu 24.04 it used to fail half-way.
- Composer errors are shown and stop the install, instead of a later
  "vendor/bin/phinx" error.
- Turkish prompts no longer stop the install silently after
  "cp: warning: behavior of -n is non-portable" (#1): they are downloaded
  from the sounds-tr-1.0.0 release and verified; if that fails the install
  warns and goes on. `AIPBX_TR_SOUNDS=no` skips them.
- The chat service is built even when the install directory belongs to
  another user, and a build error is shown instead of a false "started".
- Release tags deleted on GitHub are pruned on update, so an old tag is never
  offered as an "update".

**Sounds**
- New Sounds → Asterisk Sound Packs tab: install or remove Asterisk's official
  core / extra prompts (en, en_AU, en_GB, en_NZ, es, fr, it, ja, ru, sv), the
  opsound hold music and AiPBX's Turkish prompts, per language and format
  (wav, ulaw, alaw, gsm, g722, sln16). Packages are checksum-verified and
  removing deletes only the files the pack installed.

**Chat**
- Leaving a direct chat can no longer hand its admin rights to the other
  person (who could then add a third user and expose the history).
- Deleted groups are closed: former members can no longer read, post or
  download attachments.
- Previews, titles and push texts are cut by characters, not bytes (no broken
  Turkish characters).

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
