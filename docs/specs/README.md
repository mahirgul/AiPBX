# Specification-Driven Development (SDD) Guide & Constitution

AiPBX uses a lightweight **Specification-Driven Development (SDD)** workflow. Before writing code for non-trivial features, we define **what** we are building, **why**, and the **technical blueprint**. This eliminates guesswork, prevents security gaps, and keeps our 1,000+ unit test suite green.

---

## 🏛️ The AiPBX Constitution (Core Rules)

Every new feature, API endpoint, or configuration generator in AiPBX must respect these non-negotiable rules:

1. **Zero Dialplan Regressions:**
   - Asterisk core dialplans (`[from-internal-pbx-ortak]`, `[local-extensions]`, `[outbound-routes]`) must never break. If a custom context is empty or removed, default PBX calling flows must remain 100% operational.

2. **Strict Administrative Circuit Breakers:**
   - Any module that permits running commands, touching system configuration, modifying network interfaces, or injecting Asterisk dialplan code must be hardcoded in [`web/auth.php`](../../web/auth.php) to restrict access strictly to `role_id === 1` (Admin). Database-level permission overrides must not bypass this circuit breaker.

3. **Pure Generators & Atomic Rollback:**
   - Asterisk `.conf` generators must be deterministic, pure functions that take data structures and return string contents.
   - File writes must always use `writeConfWithRollback($path, $content)` to prevent partial or corrupted Asterisk reloads.

4. **Schema Migrations First:**
   - Never run manual `CREATE TABLE` or `ALTER TABLE` queries. All database schema adjustments must be encapsulated in Phinx migrations applied to both `asterisk` (production) and `asterisk_test` (unit test environment).

5. **100% 22-Language Parity:**
   - New user-facing text must be added to all 22 languages (`web/lang/*.php`). No PR or release is permitted with missing language keys (`php web/bin/lint_lang.php` or `python3 scripts/i18n.py check` must report 0 missing keys).

6. **Automated Quality Gate:**
   - Every service must have dedicated unit tests in `web/tests/unit/`.
   - Before any release, the full test gate must pass:
     ```bash
     php web/bin/lint_lang.php
     php web/bin/smoke.php
     vendor/bin/phpunit
     vendor/bin/phpstan analyse
     ```

---

## 📋 The 3-Step Workflow

For any new feature or substantial enhancement:

```
1. SPECIFY               2. PLAN & CHECKLIST          3. IMPLEMENT & VERIFY
   (What & Why)             (How & Technical Design)     (Code, Tests & Release)
┌────────────────┐       ┌──────────────────────┐     ┌───────────────────────┐
│ User Stories   │ ────► │ Schema / Asterisk    │ ──► │ Phinx Migration       │
│ Requirements   │       │ Services / RBAC      │     │ Code & Unit Tests     │
│ Acceptance (DoD)       │ Step-by-step tasks   │     │ QA Gate & Deploy      │
└────────────────┘       └──────────────────────┘     └───────────────────────┘
```

1. **Copy the template:** Copy [`templates/feature.md`](templates/feature.md) into `docs/specs/<number>-<feature-slug>.md`.
2. **Fill in the sections:** Define the goal, acceptance criteria, technical design, and task list.
3. **Execute:** Check off the tasks as code and unit tests are written.

---

## 📚 Living Specifications Index

| Spec # | Feature | Status | Release |
|---|---|---|---|
| [`001`](001-custom-dialplan.md) | Custom Asterisk Dialplan & Context Injection | Implemented | v1.50.35 |
| [`002`](002-ios-app-parity.md) | iOS Mobile App Feature Parity & In-App Media Viewer | Implemented | v1.50.35 |
