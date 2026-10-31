# Spec 001: Custom Asterisk Dialplan & Context Injection

- **Status:** Implemented
- **Author:** Mahir Gül
- **Target Release:** v1.50.35
- **Date:** 2026-10-31

---

## 1. Problem & Goal (What & Why)

### 1.1 Problem Statement
AiPBX administrators frequently need to configure custom star codes, paging groups, supervisor whisper/spy codes, CRM webhooks, or direct Asterisk application routines (*80, *55, *700, DISA) without opening an SSH terminal or editing `/etc/asterisk/extensions_*.conf` files manually. Manual file modifications are prone to syntax errors and get overwritten or desynchronized during system updates.

### 1.2 Proposed Solution
Provide a dedicated Web Management UI under **PBX → Custom Dialplan** (`/custom-dialplan`) that allows administrators to define custom Asterisk contexts safely, validate syntax before saving, preview the compiled `.conf` file, and automatically link internal contexts to the PBX internal dialplan (`[from-internal-pbx-ortak]`).

---

## 2. User Stories & Acceptance Criteria (DoD)

### User Stories
- As an **Administrator**, I want to define custom Asterisk contexts and dialplans from the web interface so I can inject custom star codes without SSH access.
- As an **Administrator**, I want to use pre-built recipe templates (Intercom, Spy, Paging, CRM Webhook) so I do not have to write Asterisk dialplans from scratch.
- As an **Administrator**, I want live syntax validation so that unbalanced brackets or syntax errors are caught before Asterisk reloads.

### Acceptance Criteria (Definition of Done)
- [x] **AC-1:** Dedicated database table `pbx_custom_dialplans` with fields for context name, content, internal inclusion, order, and active state.
- [x] **AC-2:** Safe dialplan compilation to `/etc/asterisk/pbx/extensions_custom.conf` with automatic atomic rollback on failure.
- [x] **AC-3:** Internal aggregator context `[custom-dialplan-pbx]` included in `[from-internal-pbx-ortak]` for active entries with `include_in_internal = 1`.
- [x] **AC-4:** Syntax validator catching unbalanced `()`, `[]`, and `${}` before save.
- [x] **AC-5:** Reserved Asterisk context names (`from-internal`, `from-trunk`, `default`, etc.) protected against overwriting.
- [x] **AC-6:** Strict administrative circuit breaker in `web/auth.php` restricting access strictly to Admin (`role_id === 1`).
- [x] **AC-7:** 100% 22-language translation parity (5,574 / 5,574 keys).
- [x] **AC-8:** Automated PHPUnit tests covering CRUD, syntax validation, config generation, and pending sync.

---

## 3. Technical Design (How)

### 3.1 Architecture Diagram
```
Dahili Arama (PJSIP/1001)
         │
         ▼
[from-internal-pbx-ortak]
         │
         ├── include => custom-dialplan-pbx ───► [custom-dialplan-pbx]
         │                                              ├── include => app-intercom (*80EXT)
         │                                              ├── include => app-supervisor-spy (*55EXT)
         │                                              └── include => app-group-paging (*700)
         ├── include => local-extensions
         └── include => outbound-routes
```

### 3.2 Database Schema
Table: `pbx_custom_dialplans`
- `id`: INT AUTO_INCREMENT PRIMARY KEY
- `title`: VARCHAR(150) NOT NULL
- `context_name`: VARCHAR(80) NOT NULL UNIQUE
- `dialplan_content`: TEXT NOT NULL
- `include_in_internal`: TINYINT(1) DEFAULT 1
- `description`: VARCHAR(255) NULL
- `sort_order`: INT DEFAULT 10
- `is_active`: TINYINT(1) DEFAULT 1
- `created_at` / `updated_at`: DATETIME

### 3.3 Core Components
- **Repository:** [`CustomDialplanRepository.php`](../../web/src/repositories/CustomDialplanRepository.php)
- **Service:** [`CustomDialplanService.php`](../../web/src/services/CustomDialplanService.php) (Syntax validator, reserved context guard, recipe templates)
- **Generator:** [`SyncCustomDialplan.php`](../../web/src/sync/SyncCustomDialplan.php) (Generates `[custom-dialplan-pbx]` aggregator and custom context blocks)
- **Controller:** [`CustomDialplanController.php`](../../web/src/controllers/CustomDialplanController.php)
- **View:** [`templates/views/custom_dialplan/index.php`](../../web/templates/views/custom_dialplan/index.php)

### 3.4 Security Circuit Breaker
- Added `'custom_dialplan'` to the hardcoded circuit breaker in [`web/auth.php`](../../web/auth.php).
- Marked with `'admin_only' => true` in [`RoleRepository.php`](../../web/src/repositories/RoleRepository.php).

---

## 4. Implementation Tasks & Verification

- [x] Create Phinx migration `20261031200000_create_pbx_custom_dialplans.php`
- [x] Implement Repository and Service classes with static linter
- [x] Implement `SyncCustomDialplan` and integrate into `syncEverything()`
- [x] Build web controller, routes, and modal view with syntax checking
- [x] Seed 6 production-grade recipe templates (Intercom, Spy, Paging, CRM Webhook, VIP, DISA)
- [x] Translate into all 22 languages
- [x] Write unit tests in [`CustomDialplanTest.php`](../../web/tests/unit/CustomDialplanTest.php) (7 tests, 83 assertions passing)
- [x] Publish system documentation in [`docs/custom-dialplan.md`](../custom-dialplan.md) and release v1.50.35
