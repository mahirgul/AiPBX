# AI PBX Portal

Official Website: [aipbx.bid](https://aipbx.bid) • Documentation: [mahirgul.github.io/AiPBX](https://mahirgul.github.io/AiPBX/)

A self-hosted PBX / fax / call-center management portal built on **native Asterisk** (PJSIP + AMI + ODBC), **MariaDB**, and plain **PHP** (Modern MVC) — no proprietary GUI (FreePBX, Issabel, etc.) required. Built as a high-performance, modular **AI PBX** infrastructure.

## Features

- **PBX management**: SIP/WebRTC extensions (dual-endpoint architecture), trunks (advanced PJSIP configuration & outbound caller name toggle), inbound/outbound routes, time conditions, IVR menus, feature codes (star codes), call queues (localized announcements), hangup actions.
- **Microsoft Teams integration**: Direct Routing (SBC / SIP TLS 5061), user & extension mapping, incoming webhooks & adaptive cards, dynamic M365 PowerShell script generator.
- **Call center**: live agent screen (WebRTC softphone in the browser), supervisor queue monitoring, wallboard, pause reports, queue logs.
- **Fax**: inbound/outbound fax over native Asterisk trunks (T.38 / spandsp), direct PDF upload or browser WYSIWYG rich-text editor, per-department routing and email notification, sent/received archives, and failed fax retry.
- **Admin & Security**: role-based access control (RBAC) with a per-module permission matrix, user management, branding/appearance customization, advanced PBX & SIP User Agent settings, integrated **Firewall (firewalld)** and **Fail2ban** management.
- **Reliability & Rollback**: automatic backup of working Asterisk configurations before every reload, with instant rollback and audit logging if syntax validation or reload fails.
- **Multi-language UI**: Turkish and English out of the box (`lang/tr.php`, `lang/en.php` with 1,600+ keys), independent from Asterisk's voice-prompt language setting.
- **CDR & recordings**: call detail records with waveform playback of recordings, scoped by role (agents see their own calls, supervisors/admins see full scope).

## Architecture

A lightweight, framework-free MVC layered over Asterisk's "database is the source of truth, generate config, reload" pattern:

```
index.php                 Front controller / router (clean URLs, whitelist mapping)
src/controllers/          35 controllers — auth check, POST handling, orchestration
src/repositories/         28 repositories — read queries (extends BaseRepository)
src/services/             24 services — write/business logic (validation, RBAC, sync triggers)
src/sync/                 12 sync generators — DB -> Asterisk .conf files (PJSIP, dialplan, ...)
src/ui_helpers.php        Reusable HTML component renderers (modals, forms, badges)
templates/views/          34 pure-PHP view templates
lang/                     Translation tables (tr.php / en.php) + t() helper in config.php
db/migrations/            Phinx — versioned, reversible schema changes
api/                      JSON endpoints consumed by browser JS (call control, WebRTC creds, ...)
bin/                      CLI maintenance scripts (e.g. `php bin/lint_lang.php`)
```

No heavy templating engine, no ORM — `BaseRepository`, `BaseController`, and `View` are a few hundred lines total. Controllers never talk to Asterisk directly; they call into `src/services/*.php`, which handles validation and triggers the relevant `src/sync/*.php` generator with automatic rollback on error.

Call routing/dialplan is deliberately **not** on Asterisk Realtime — it stays as generated `.conf` files, which is the safer, better-supported pattern for anything beyond flat PJSIP endpoint data.

## Requirements

- Asterisk 20+ (or Asterisk 22) with `res_pjsip`, `app_queue`, `res_fax` + `res_fax_spandsp` (open-source T.38, no commercial fax module needed)
- PHP 8.1+ with `pdo_mysql`
- MariaDB 10.3+ (or MySQL 8+)
- Apache with `mod_rewrite` (plus Nginx for stream ALPN port 443 multiplexing when using coturn)
- Composer (only used for Phinx, the migration tool — the app itself has zero runtime dependencies)

## Setup

1. **Clone and install the migration tool:**
   ```bash
   composer install --no-dev  # or without --no-dev if you'll also run tests/tooling later
   ```
2. **Create two dedicated MySQL/MariaDB users** — one for the running app (DML only), one for migrations (DDL). This separation is intentional: the running application should never be able to alter its own schema.
   ```sql
   CREATE DATABASE asterisk;
   CREATE USER 'aipbx_portal'@'localhost' IDENTIFIED BY '...';
   GRANT SELECT, INSERT, UPDATE, DELETE ON asterisk.* TO 'aipbx_portal'@'localhost';
   CREATE USER 'aipbx_migrator'@'localhost' IDENTIFIED BY '...';
   GRANT ALL PRIVILEGES ON asterisk.* TO 'aipbx_migrator'@'localhost';
   ```
3. **Copy the environment template and fill in real values:**
   ```bash
   cp .env.example /etc/ai-pbx.env
   chown root:asterisk /etc/ai-pbx.env
   chmod 640 /etc/ai-pbx.env
   $EDITOR /etc/ai-pbx.env
   ```
4. **Run migrations** (creates the full schema from scratch):
   ```bash
   vendor/bin/phinx migrate
   ```
5. **Point Apache at the project root**, with `/src`, `/db`, `/vendor` denied from direct web access (see `etc/httpd/routing.conf.example` — adapt to your paths) and everything else routed through `index.php`.
6. **Wire up Asterisk**: point `pjsip.conf` at `#include "pbx/pjsip_*.conf"` (portal-generated) and `extensions.conf` at `#include "pbx/extensions_*.conf"`. Log in as an admin and save any PBX settings page once to trigger the first config generation.
7. Log in with the seed admin account created by the baseline migration and change its password immediately.

## Security notes

- Secrets (DB credentials, AMI password, TURN secret) live only in `/etc/ai-pbx.env`, outside the web root and outside git. Nothing in this repository requires a real secret to be readable.
- `db/`, `vendor/`, and `src/` are denied from direct HTTP access — only `index.php` (the front controller) and `api/*.php` are reachable.
- The RBAC layer has a hardcoded circuit-breaker: the `roles` and `system_users` modules can never be revoked from the admin role, regardless of what the permission matrix says, to prevent administrative lockouts.
- Sudo permissions for Firewall and Fail2ban are restricted via sudoers rules to specific binary invocations with strict input regex validation.

## License

MIT — see [LICENSE](LICENSE).
