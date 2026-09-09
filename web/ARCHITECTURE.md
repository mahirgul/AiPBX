# Architecture — AI PBX Portal

This document describes how the AI PBX system is put together: the request lifecycle, the layers, the Asterisk integration, and the conventions a new contributor should know before touching anything.

## The core idea

**The database is the single source of truth for everything.** Asterisk itself never has a config value that didn't originate from a database row. The application's job is: read/write `sys_*`/`pbx_*` tables through a normal web UI, then regenerate the relevant Asterisk `.conf` files and trigger a reload. Nothing is edited by hand on the Asterisk side in normal operation.

```
Admin fills in a form
        │
        ▼
Controller validates + calls a Service
        │
        ▼
Service writes to MariaDB, then calls a Sync generator
        │
        ▼
Sync generator (src/sync/*.php) backs up .bak, queries DB,
and writes modular files under /etc/asterisk/pbx/*.conf
        │
        ▼
Asterisk is reloaded (`dialplan reload`, `pjsip reload`, `queue reload`, ...)
   ├── SUCCESS ──▶ Updates audit log, cleans up .bak
   └── FAILURE ──▶ Automatic Rollback: restores .bak immediately & logs error
```

This pattern (informally "DB → generate config → reload") is what lets a plain PHP app manage a full PBX without touching Asterisk Realtime for the risky parts (see below).

## Request lifecycle (MVC)

There is no framework. `index.php` is a whitelist front controller: a fixed `$ROUTES` array maps clean URLs (`/dashboard`, `/trunks`, `/cc-agent`, `/firewall`, `/fail2ban`, ...) to a `[Controller::class, 'action']` pair. Nothing outside that array is reachable — unknown paths get a 404, and `src/`, `vendor/`, `db/` are denied at the web server level regardless.

```
Browser request
   │
   ▼
index.php (router)               — resolves clean URL, enforces whitelist, sets PHP_SELF
   │
   ▼
src/controllers/*Controller.php  — auth check, POST handling, orchestration only
   │            │
   │            ▼
   │       src/services/*Service.php   — validation, business rules, triggers sync
   │            │
   │            ▼
   │       src/sync/*.php               — regenerates Asterisk .conf files with auto-rollback
   │
   ▼
src/repositories/*Repository.php — read queries (extends BaseRepository)
   │
   ▼
templates/views/<page>/index.php — pure-PHP view, no logic beyond looping/formatting
```

`BaseController`, `BaseRepository`, and `View` (`src/core/`) are a few hundred lines total — no ORM, no template compiler. Controllers never talk to Asterisk or even to raw SQL directly; they call a Service (writes) or a Repository (reads).

Static-class, no-namespace conventions are used throughout (`TrunkService::saveTrunk(...)`, `ExtensionRepository::getAll(...)`) to keep the codebase cohesive, direct, and free of unnecessary boilerplate.

## The PHP layers, precisely

| Layer | Lives in | Responsibility | Never does |
|---|---|---|---|
| **Controller** | `src/controllers/` | `requireRole()`, read `$_POST`, call one Service method, pass data to a View | Contain SQL or business rules |
| **Service** | `src/services/` | Validate input, enforce RBAC edge cases, write to DB, call the matching `src/sync/*` generator | Render HTML |
| **Repository** | `src/repositories/` | Read-only queries, `extends BaseRepository` | Mutate data |
| **View** | `templates/views/` | Loop over data, escape output, call `t()` for every string | Query the database |
| **UI Helpers** | `src/ui_helpers.php` | Reusable UI component renderers (modals, forms, status badges) | Execute business logic |

## Asterisk integration & Sync layer

Asterisk configurations are separated into distinct, modular generators under `src/sync/`:

- **PJSIP & Trunks**:
  - `SyncExtensions.php`: Generates dual-endpoint PJSIP configurations (`<ext>-sip` and `<ext>-webrtc`) from templates (`[endpoint-sip](!)` / `[endpoint-wss](!)`).
  - `SyncTrunks.php`: Generates dynamic trunk endpoints, AORs, auth objects, and registrations from `pbx_trunks`.
  - `SyncTransports.php`: Generates transport definitions (UDP, TCP, TLS, WS, WSS).
- **Dialplan & Routing**:
  - `SyncGeneralDialplan.php`: Core context definitions, includes, internal dial routing, and feature context bridges.
  - `SyncInboundDialplan.php`: Inbound DID routing logic (`pbx_dids`).
  - `SyncOutboundDialplan.php`: Outbound route patterns and trunk failover sequences (`pbx_outbound_routes`).
  - `SyncIVRs.php`: Interactive Voice Response menus, timeout handling, and direct dialing logic (`pbx_ivrs`).
  - `SyncTimeConditions.php`: Time and date condition checks and branching (`pbx_time_conditions`).
  - `SyncFeatureCodes.php`: Star-code feature handlers (`pbx_feature_codes`).
  - `DialplanBuilders.php`: Shared dialplan line generators and destination dispatchers.
- **Queues & MOH**:
  - `SyncQueues.php`: Queue strategies, announcements, and timeout rules (`pbx_queues`).
  - `SyncMOH.php`: Music On Hold class mappings (`pbx_moh`).

All user-supplied strings that end up in generated config go through a shared sanitization helper (`toCleanAscii()` / `sanitizeDestType()` in `config.php`) to block config-injection via `\r\n` or special characters.

### Automatic Rollback on Reload Failure

Whenever configuration files are regenerated:
1. Existing files under `/etc/asterisk/pbx/*.conf` are backed up to `.bak`.
2. New configuration files are written and verified.
3. Asterisk reload commands (`asterisk -rx "dialplan reload"`, `asterisk -rx "pjsip reload"`, etc.) are executed.
4. If a reload fails (non-zero exit code or error output), the generator instantly restores the `.bak` files, issues a reload to revert Asterisk to the working state, and records an audit log entry containing the exact CLI error output.

## Data layer & migrations

Schema changes go through **Phinx** (`db/migrations/`), the PHP equivalent of Alembic — never a manual `ALTER TABLE`. Two MySQL users exist on purpose: the running app (`aipbx_portal`, DML only — `SELECT/INSERT/UPDATE/DELETE`) and the migration runner (`aipbx_migrator`, full DDL). The web-facing app can never alter its own schema, even if fully compromised.

```bash
vendor/bin/phinx migrate     # apply pending migrations
vendor/bin/phinx rollback    # undo the last one
```

## Security model

- **RBAC**: A per-module, per-action (`view`/`access`/`edit`/`delete`) permission matrix stored in `sys_role_permissions`, editable from `/roles`. Two modules (`roles`, `system_users`) have a hardcoded circuit-breaker in `auth.php::hasModulePermission()` that no permission-matrix row can override — this exists so a misconfigured role can never lock every admin out.
- **CSRF**: Every state-changing form carries a token verified server-side; every `POST` handler in every Service checks it before touching the database.
- **Secrets**: Never in source. Everything (`DB_PASS`, `AMI_PASS`, `TURN_SECRET`, ...) is read from `/etc/ai-pbx.env` at runtime — outside the web root, outside git.
- **Firewall & Fail2ban Management**: Admin panel integrates live `firewalld` and `fail2ban` controls via `FirewallService` and `Fail2banService`. Calls are dispatched via a locked-down sudoers configuration with strict regex whitelisting on IP addresses, ports, and service names.
- **Ownership boundary**: Application PHP files are `root:root`, mode 644/755 — the app cannot write to its own source. Only upload/output directories (fax storage, custom sounds) are writable by the runtime user.

## Internationalization

Two flat PHP arrays, `lang/tr.php` and `lang/en.php` (`return ['key' => 'value', ...]`), loaded by `t($key, $default = null)` in `config.php`. No JSON, no gettext, no library — a PHP array is already a fast, native-parsed map. A missing key falls back to the key itself (never a blank string), so a missing translation is loud, not silent. Language is stored per-user (`sys_users.language_preference`) and in-session; switching is a single `/set-language?lang=..&redirect=..` route with open-redirect protection.

This is a completely separate axis from Asterisk's voice-prompt language (`pbx_dids.language`, `pbx_ivrs.language`, `pbx_queues.language`, system default in `asterisk_settings`) — one controls web UI text, the other controls what a caller *hears*.

## Directory reference

```
index.php                 Front controller / router
config.php                Bootstrap: env loading, DB connection, t(), constants
auth.php                  Session, RBAC, CSRF primitives
header.php / footer.php   Shared page chrome (included by every Controller)
templates/                sidebar.php, topbar.php + templates/views/<page>/index.php
src/core/                 BaseController, BaseRepository, View — the core foundation
src/controllers/          35 Controllers (one per page/route)
src/repositories/         28 Repositories (read queries)
src/services/             24 Services (write/business logic)
src/sync/                 12 Sync generators (DB -> Asterisk .conf generators)
src/ui_helpers.php        Reusable HTML UI components
src/helpers.php           PBXHelper facade
lang/                     tr.php, en.php (1,323 translation keys each)
db/migrations/            Phinx migrations (versioned schema history)
api/                      JSON/file endpoints called by browser JS
assets/                   CSS/JS, all vendored locally (no CDN dependencies)
```

## Current scale (informational, will drift)

35 MVC controllers / 28 repositories / 24 services / 34 views / 12 sync generators, 1,323 translation keys × 2 languages, 32 DB tables, 191 commits.
