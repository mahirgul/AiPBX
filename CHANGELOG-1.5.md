# AiPBX — Changelog (1.5 Series)

← [Current Changelog (1.7 Series)](CHANGELOG.md) | [1.6 Series](CHANGELOG-1.6.md) | [1.0 - 1.34 Archive](CHANGELOG-archive.md)

---

## 1.50.37

- **Configuration Directory Structure Consolidation (`conf/asterisk/`):**
  - Consolidated all static Asterisk 22 configuration files and seed dialplans from the legacy `asterisk-config/` root directory into `conf/asterisk/`.
  - Updated `install.sh`, systemd service scripts, provisioning templates, and test harnesses with backward-compatible fallbacks.
  - Enhanced `scripts/asterisk-restart-needed.sh` to recognize pure Git `R100` directory rename events between `asterisk-config/` and `conf/asterisk/`, ensuring seamless updates without unnecessary Asterisk daemon restarts or in-flight call drops.
- **Repository Decoupling & Mobile Directory Cleanup:**
  - Removed deprecated `android/` and `ios/` root stubs from the core PBX repository in favor of their dedicated standalone repositories ([`AiPBX-Android`](https://github.com/mahirgul/AiPBX-Android) and [`AiPBX-ios`](https://github.com/mahirgul/AiPBX-ios)).
  - Cleaned up obsolete build, packaging, and CI scope rules in `.gitignore`, `.github/workflows/pr-scope.yml`, `scripts/release.sh`, and system documentation.
- **Documentation & Specifications:**
  - Published Specification 003 ([`docs/specs/003-reverse-proxy.md`](docs/specs/003-reverse-proxy.md)) covering reverse proxy architecture, network firewall port matrix, and Cloudflare DNS-01 validation.
  - Updated living specification index (`docs/specs/README.md`), roadmap (`docs/roadmap.md`), system map (`docs/system-map.md`), and main `README.md`.

## 1.50.36

- **Reverse Proxy Architecture & Management (`/reverse-proxy`) (#148):**
  - Dedicated management interface under **Network → Reverse Proxy** for configuring external FQDN, TLS termination modes (Edge TLS Termination, Re-encrypt TLS, No TLS), and trusted proxy CIDR ranges.
  - Spoof-proof client IP resolution (`ReverseProxyService::resolveClientIp` & global `getClientIP()`): traverses `X-Forwarded-For` right-to-left only when incoming packets originate from trusted CIDRs (`127.0.0.1`, `::1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), falling back to `X-Real-IP`. Direct untrusted connections never honor forwarded headers.
  - Live reverse proxy configuration generator producing production-ready snippets for **Nginx**, **Caddy**, **Traefik**, **HAProxy**, and **Apache 2.4** with WebRTC WebSocket (`/ws` and `/chat/ws`) pass-through.
  - Interactive Network Ports & Firewall Matrix detailing public VoIP bypass ports (SIP 5060/5061, RTP 10000-20000 UDP, STUN/TURNS 3478/5349) vs proxied HTTP/HTTPS web ports, with copyable shell setups for **UFW**, **firewalld**, and **iptables**.
  - Real-time HTTP header diagnostic inspector verifying incoming client IP, proxy trust status, forwarded proto, host, and port.
- **Let's Encrypt Cloudflare DNS-01 Validation & Wildcards (#148):**
  - Added Cloudflare DNS-01 challenge support in **System → Certificates** (`/certificates`) and `conf/sbin/aipbx-cert` via `certbot-dns-cloudflare`.
  - Enables SSL/TLS issuance for internal/private servers without public port 80 exposure.
  - One-click wildcard certificate support (`*.<domain>`).
  - Hardened staging mechanism with restricted permissions (`0600`) and private temp directories (`mktemp -d`), preserving saved tokens across test dry-runs and live issue requests.
- **Security Hardening & SSO Callback Improvements:**
  - Integrated `ReverseProxyService::isHttps()` and `isHttpsRequest()` across Web Chat session cookies, OIDC redirect URIs, and SAML metadata generators to automatically detect `X-Forwarded-Proto: https` behind Edge TLS terminators.
  - Unified `getClientIP()` across all authentication surfaces (2FA challenge, Forgot Password, Google OAuth, OIDC, SAML SSO, WebAuthn Passkeys, QR Login, and Mobile API), preventing reverse proxy IP lockouts during brute-force mitigation.
  - Hardened Apache port 80 virtual host in `install.sh` to prevent redirect loops when reverse proxy terminates TLS at edge, and enabled `/ws` and `/chat/*` reverse proxying on port 80.
- **Internationalization & Testing:**
  - 100% translation key parity across all 22 supported languages (5,632 / 5,632 strings, 0 problems).
  - Comprehensive unit test suite in `ReverseProxyTest.php` covering CIDR parsing (IPv4/IPv6), spoofing protection, HTTPS detection, and configuration generators.
  - 1,045 PHPUnit tests passing with 0 errors, 0 PHPStan issues, and clean smoke test verification.

## 1.50.35

- **Custom Asterisk Dialplan Contexts & Code Injection (`/custom-dialplan`):**
  - Dedicated management interface under **PBX → Custom Dialplan** for creating, editing, and organizing custom Asterisk dialplan contexts.
  - Live Asterisk dialplan syntax validator checking bracket balance, variable expansions, and directive formatting before persisting.
  - Automatic compilation into `/etc/asterisk/pbx/extensions_custom.conf` with seamless Asterisk reload and rollback protection (`writeConfWithRollback`).
  - Internal Aggregator Context (`[custom-dialplan-pbx]`): entries flagged with "Include in internal dialplan" are automatically included into `[from-internal-pbx-ortak]`, making custom codes dialable from all registered desk phones, softphones, and mobile apps.
  - Standalone contexts for custom inbound trunk filters, IVR subroutines, AGI scripts, and CRM notifications.
- **Built-in Telephony Recipe Templates & Feature Codes:**
  - One-click recipe insert library in the custom dialplan editor:
    - *1-to-1 Intercom / Auto-Answer (`*80<ext>`)* with SIP `Alert-Info` and `Call-Info` headers.
    - *Supervisor Direct Listen (`*55<ext>`) & Whisper Coaching (`*56<ext>`) via ChanSpy*.
    - *Broadcast Audio Paging Group (`*700`)* using Asterisk `Page()`.
    - *Non-Blocking CRM Webhook Trigger* sending asynchronous HTTP curl POST on call arrival.
    - *Inbound VIP Caller Priority Routing Hook* with Caller ID name tagging.
    - *PIN-Protected DISA (Direct Inward System Access)* for secure remote trunk calling.
- **Security & Access Control:**
  - Administrative circuit breaker enforcement: strictly locked to `admin` role in `RoleRepository` and `auth.php` (`hasModulePermission`).
  - Full audit logging for create, edit, toggle, and delete operations in `sys_audit_log`.
- **Internationalization & Testing:**
  - 100% translation key parity across all 22 supported languages (5,573 / 5,573 strings, 0 problems).
  - Comprehensive unit test coverage in `CustomDialplanTest.php` and full green verification on `smoke.php` and PHPStan.

## 1.50.34

- **Inbound Caller ID Blacklist & Spam Blocker (`/blacklist`) (#148):**
  - Dedicated management interface under **Telephony → Inbound Blacklist** with RBAC (`did_routes` module permissions).
  - Sub-microsecond caller ID lookups via Asterisk internal AstDB (`blacklist/<clean_number>` and `blacklist_action/<clean_number>`).
  - Supports both specific phone numbers (E.164 / local) and dialplan wildcard patterns (`_X.`, `_0850.`, `_0900.`).
  - Four customizable call blocking actions: Busy tone (`Hangup 17`), Congestion / Out-of-service (`Hangup 34`), Silent drop (`Hangup 16`), and Spoken announcement (`Playback ss-noservice`).
  - Automatic CDR tagging with `SPAM_BLOCKED` and `Blacklist: <action>`.
  - Batch CSV import with delimiter autodetection and last-caller fast lookup per extension.
- **RFC 6442 & RFC 6443 Emergency Call Geolocation Support:**
  - Added `geolocation_uri` and `geolocation_routing` fields to Outbound Routes.
  - Automatically appends SIP `Geolocation` and `Geolocation-Routing` headers to outbound emergency INVITE requests via `sub-callee-jb`.
  - Configurable system-wide default emergency location URI with per-route overriding.
- **Security Architecture & Threat Model Documentation:**
  - Published comprehensive [Security Architecture & Threat Model](docs/threat-model.md) covering SIP exposure boundaries, Port 443 ALPN stream multiplexing, WebRTC DTLS-SRTP media encryption, ephemeral TURN tokens, mobile push token minimization, and AI assistant Least Privilege execution sandboxing.
- **Disaster Recovery & Automated Upgrade Rollback Documentation:**
  - Published [Backups, Disaster Recovery & Upgrade Rollback](docs/backup-and-rollback.md) guide detailing daily reproducible backups (`aipbx-backup`), step-by-step restoration commands, and the automated atomic rollback safeguard in `aipbx-update`.
- **Expanded Multi-OS & Multi-Architecture Verification:**
  - Formally validated single-command turnkey installation across Ubuntu 26.04 LTS+, Debian 13 (Trixie)+, 64-bit Raspberry Pi OS, and 64-bit Armbian on both x86_64 and ARM64 (`aarch64`).
- **Fixes & Code Hardening:**
  - Standardized RBAC access control in `InboundBlacklistController` using `BaseController::requireModule('did_routes', 'access')`.
  - Improved CSV row parsing and type safety in blacklist imports.

## 1.50.32

- **64-Bit ARM (`aarch64` / `arm64`) Architecture Support (#147):**
  - Officially enabled full support for 64-bit ARM architectures in `install.sh`, allowing seamless single-command installation on Raspberry Pi 4, Raspberry Pi 5, AWS Graviton, and Ampere Altra servers.
  - Added strict 64-bit architecture validation during installation; immediately catches and stops unsupported 32-bit platforms (`armv7l`, `i686`) with a clear advisory to install a 64-bit OS.
  - Native ARM64 binary compilation for the Go chat microservice (`aipbx-chat`) and full compatibility with existing Asterisk 22, Apache, and MariaDB package ecosystems.
- **Expanded Operating System Support (Debian Family):**
  - Broadened `install.sh` system verification beyond Ubuntu 26.04 LTS to officially support Debian 13 (Trixie)+, 64-bit Raspberry Pi OS, and 64-bit Armbian.
  - Added automatic detection for pre-existing PHP >= 8.4 environments on Debian-family installations (e.g. Debian 12 / Bookworm with Surý PPA).
- **Cleanup:**
  - Removed unused partial `docker/` first-boot init script directory.

## 1.50.31

- **Security Hardening (CVE-2026-98432 & Go 1.24+ MPTCP Defense-in-Depth):**
  - **Linux Kernel MPTCP Disabling (`/etc/sysctl.d/99-aipbx.conf`):** Added `net.mptcp.enabled = 0` to kernel sysctl tuning in `install.sh` and `aipbx-update`. This completely shuts down MultiPath TCP at the kernel level across all processes and network listeners on the system, definitively neutralizing the remote UAF attack vector identified in CVE-2026-98432.
  - **Compile-Time Go Module Hardening (`chat/go.mod`):** Configured build directive `godebug multipathtcp=0`, embedding `multipathtcp=0` directly into the binary's `DefaultGODEBUG` so `aipbx-chat` rejects MPTCP listeners by default even in the absence of explicit environment flags.
  - **Chat Systemd Unit Hardening (`aipbx-chat.service`):** Injected `Environment="GODEBUG=multipathtcp=0"` into `/etc/systemd/system/aipbx-chat.service` for runtime service isolation.

## 1.50.30

- **SSO Role Mapping, Session Integrity & Plugin Detail Hardening:**
  - Standardized `default_role` options in `oidc-auth` and `saml-auth` manifests to match valid system roles in `sys_roles` (`user`, `admin`, `cc_manager`, `cc_agent`, `read_only_admin`), preventing database foreign key constraint violations during JIT auto-provisioning.
  - Added role validation fallback in `OidcAuthService::autoProvisionUser` ensuring unmatched or deprecated roles gracefully fallback to `'user'`.
  - Harmonized SAML web session keys in `SamlAuthService::loginUserSession` by setting `user_role` (alongside `role`), `ui_language`, login attempt audit logging, and routing authenticated users to their respective `roleHomePath`.
  - Hardened select field rendering in `web/templates/views/plugins/detail.php` to seamlessly support both key-value dictionaries and indexed `{value, label}` option formats.

## 1.50.29

- **Official SAML 2.0 Corporate Single Sign-On (SSO) Plugin (`saml-auth`) (#146):**
  - Added official built-in plugin `plugins/saml-auth/` providing complete SAML 2.0 Web Browser SSO Profile support (Service Provider - SP role) and auto-provisioning.
  - Compatible out of the box with major enterprise identity providers: Microsoft Entra ID (Azure AD Enterprise Applications SAML), Okta SAML 2.0, Keycloak, Authentik, Active Directory Federation Services (ADFS), Google Workspace, and PingFederate.
  - **Service Provider (SP) Metadata & Endpoints:** Automatic generation of SP EntityDescriptor XML at `/auth/saml/metadata` with HTTP-POST binding, NameID formats (`emailAddress`, `persistent`, `transient`), and dedicated Assertion Consumer Service (ACS) at `/auth/saml/acs`.
  - **Quick Setup IdP Metadata XML Importer:** Built-in admin parser that accepts federation metadata XML from Entra ID, Okta, Keycloak, or ADFS to auto-populate Entity ID, SingleSignOnService URL, and X.509 Certificate with one click.
  - **Cryptographic XML-DSig Verification:** Enforces digital signature and digest verification (SHA-256 and SHA-1) using IdP X.509 public certificates over Response or Assertion elements, with enveloped signature canonicalization (`C14N`).
  - **Enterprise Security & Replay Mitigation:** Enforces temporal validity (`NotBefore` and `NotOnOrAfter` with 120-second clock skew tolerance), AudienceRestriction, Destination matching, and cryptographically random RelayState state tokens against CSRF and replay attacks.
  - **Just-In-Time (JIT) Auto-Provisioning & Role Mapping:** Automatically provisions new users in `sys_users` on first sign-in with claim-based username extraction, collision resolution, email domain allowlisting, and customizable role attribute mapping.
  - **Login Portal & Mobile Deep-Linking:** Dynamic SAML SSO button on the login screen with customizable label and icon, plus mobile client authentication callback support (`aipbx://auth?success=1&code=...`).
  - **Unit Test Coverage:** 10 new unit tests with 41 assertions in `web/tests/unit/SamlAuthTest.php`, full role matrix integration, and zero-defect plugin validation.

## 1.50.28

- **Official OpenID Connect (OIDC) & Corporate SSO Plugin (`oidc-auth`) (#146):**
  - Added official built-in plugin `plugins/oidc-auth/` providing standard OpenID Connect (OIDC Core 1.0) Single Sign-On and auto-provisioning.
  - Compatible out of the box with any OpenID Provider (OP): Keycloak, Microsoft Entra ID (Azure AD), Authentik, Okta, Google Workspace, and PingFederate.
  - **Dynamic OpenID Discovery & Caching:** Discovers authorization, token, and userinfo endpoints automatically from `/.well-known/openid-configuration` with persistent KV caching via `sys_plugin_kv`.
  - **Interactive Admin Discovery Tester & Setup Guides:** Dedicated admin panel with a copyable redirect URI (`/auth/oidc/callback`), a real-time discovery validation tool, and setup guides for major identity providers.
  - **Zero-Trust Security & Token Exchange:** State and nonce verification against CSRF and replay attacks, secure token code exchange, and client secret encryption at rest via `SecretBox`.
  - **Just-In-Time (JIT) Auto-Provisioning:** Seamless user account creation on first sign-in with automatic username extraction from claims (`preferred_username` / `email`), unique collision handling, and secure randomized passwords.
  - **Claim-Based Role Mapping & Domain Restrictions:** Flexible claim paths for role resolution (e.g. `roles`, `groups`), configurable default role assignment (`admin`, `manager`, `user`), and email domain allowlist enforcement (`allowed_domains`).
  - **Login Portal Integration & Deep Linking:** Dynamic SSO button on the web login interface with customizable button label/icon, plus mobile client authentication callback support (`aipbx://auth?success=1&code=...`).
  - **Comprehensive Test Suite:** 9 new unit tests with 38 assertions (`web/tests/unit/OidcAuthTest.php`), full role matrix registration, and plugin schema verification.

## 1.50.27

- **Linux Kernel & Network Sysctl Optimization:**
  - Added dedicated `/etc/sysctl.d/99-aipbx.conf` kernel tuning in `install.sh` applied automatically on both fresh installations and upgrades (`aipbx-update`):
    - `net.core.rmem_max` & `wmem_max = 16777216` (16 MB buffer limits to absorb massive UDP burst traffic without dropped packets).
    - `net.core.netdev_max_backlog = 10000` (deep interface queue to withstand simultaneous VoIP invite bursts).
    - `net.core.somaxconn = 4096` (connection listen backlog for high-density WebRTC/WSS signaling).
    - `vm.swappiness = 10`, `vm.dirty_ratio = 10`, `vm.dirty_background_ratio = 5` (preserves RAM for media processing and eliminates disk write stalls).
- **Systemd Priority & Memory Protection:**
  - Configured Asterisk systemd unit drop-in in `/etc/systemd/system/asterisk.service.d/aipbx.conf` with:
    - `Nice=-10` (elevated CPU scheduling priority to ensure real-time audio packet dispatch under host load).
    - `OOMScoreAdjust=-500` (protects Asterisk from Linux Out-Of-Memory killer termination during high memory load or AI inference).
  - Bumped `ASTERISK_RESTART_EPOCH` from 1 to 2 in `install.sh` so `aipbx-update` restarts Asterisk and immediately activates the new systemd scheduling and OOM parameters.
- **VoIP & WebRTC Media Integrity:**
  - Blacklisted and dynamically unloaded kernel SIP ALG modules (`nf_conntrack_sip`, `nf_nat_sip`) in `/etc/modprobe.d/aipbx-blacklist-sip.conf` to prevent kernel-level mangling of SIP headers and WebRTC SDP payloads.
  - Added `rtpkeepalive = 5` in `asterisk-config/rtp.conf` and `web/src/sync/SyncRtpSettings.php` to prevent stateful NAT routers and firewalls from dropping inactive RTP audio streams.
- **Config & Security Hardening (Phase 1 Audit):**
  - Corrected Portuguese ISO code typo (`'pr'` -> `'pt'`) in `web/config.php` `LANGUAGE_LABELS`.
  - Added explicit error logging in `setSystemSetting` (`web/config.php`) when database writes fail, preventing silent suppression of critical configuration updates.
  - Added regression test `testLanguageLabelsIncludesPortuguesePt()` in `web/tests/unit/PbxFineTuningTest.php`.

## 1.50.26

- **Asterisk Core & High-Concurrency Performance Tuning:**
  - Resolved taskprocessor queue backlog warnings (`stasis/pool-control reached 500 tasks`) during mass reloads by increasing Stasis threadpool capacity in `asterisk-config/stasis.conf` (`max_size = 500`, `initial_size = 10`, `idle_timeout_sec = 60`).
  - Raised Asterisk process open files limit from the Linux default 1024 to 65,536 files via `asterisk-config/asterisk.conf` (`maxfiles = 65536`) and systemd service override `LimitNOFILE=65536` in `install.sh`, preventing `socket(): Too many open files` errors under high concurrent call volume.
  - Enabled asynchronous managed DNS lookups in `asterisk-config/dnsmgr.conf` (`enable = yes`, `refreshinterval = 300`) to prevent temporary DNS resolution stalls from blocking PJSIP distributor threads.
  - Scaled PJSIP threadpool capacity to 500 threads in `web/src/sync/SyncTransports.php` `[system]` section (`threadpool_max_size = 500`, `threadpool_initial_size = 10`), avoiding SIP registration timeouts during burst registrations.
  - Expanded Asterisk database connection pool in `asterisk-config/res_odbc.conf` (`max_connections => 30` for `[asterisk]`, `50` for `[general]`), eliminating CDR and queue log write contention on busy call centers.
- **Log Rotation & Disk Protection Policy:**
  - Integrated hardened Asterisk logrotate configuration in `install.sh` (`/etc/logrotate.d/asterisk`) with daily rotation, 50MB ceiling (`maxsize 50M`), 14-day history retention, and automatic gzip compression, preventing `security.log` and `messages.log` from exhausting disk space.

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
