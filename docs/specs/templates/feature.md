# Spec [XXX]: [Feature Title]

- **Status:** Draft / In Progress / Implemented
- **Author:** [Name]
- **Target Release:** v1.XX.X
- **Date:** YYYY-MM-DD

---

## 1. Problem & Goal (What & Why)

### 1.1 Problem Statement
<!-- What problem does this solve? Why is it needed? Who asked for it? -->

### 1.2 Proposed Solution
<!-- High-level summary of the solution without overwhelming technical details. -->

---

## 2. User Stories & Acceptance Criteria (DoD)

### User Stories
- As an **Administrator**, I want to [...] so that [...].
- As a **User**, I want to [...] so that [...].

### Acceptance Criteria (Definition of Done)
- [ ] AC-1: [Specific verifiable condition]
- [ ] AC-2: [Specific verifiable condition]
- [ ] AC-3: [Security / RBAC check]
- [ ] AC-4: [22-Language i18n parity check]
- [ ] AC-5: [Automated test passes]

---

## 3. Technical Design (How)

### 3.1 Database Schema
<!-- New tables or column additions, default values, indices. -->
```sql
-- Schema draft if applicable
```

### 3.2 Asterisk & Dialplan Architecture
<!-- Contexts, include chains, reload / rollback requirements. -->

### 3.3 Backend Services & Repositories
- **Repository:** `[Name]Repository.php` (CRUD & queries)
- **Service:** `[Name]Service.php` (Business logic, validation, pending sync)
- **Controller:** `[Name]Controller.php` (Routes & AJAX)

### 3.4 Security & RBAC Circuit Breakers
- Module key: `[module_key]`
- Hardcoded admin circuit breaker in `web/auth.php`: Required? (Yes / No)
- CSRF & input sanitization notes.

---

## 4. Implementation Tasks & Checklist

- [ ] **Phase 1: Database Migration**
  - [ ] Create Phinx migration `YYYYMMDDHHMMSS_create_...php`
  - [ ] Run migration on `asterisk` and `asterisk_test`
- [ ] **Phase 2: Backend & Sync Engine**
  - [ ] Implement Repository and Service classes
  - [ ] Implement pure config generator (`Sync...php`)
  - [ ] Register pending sync domain in `asterisk_sync.php`
- [ ] **Phase 3: Controller & User Interface**
  - [ ] Create Controller and register route in `routes.php`
  - [ ] Build responsive view with CSRF and notices
  - [ ] Add menu item in `sidebar_menu.php`
- [ ] **Phase 4: i18n & RBAC**
  - [ ] Add strings across all 22 languages (`web/lang/*.php`)
  - [ ] Configure `RoleRepository.php` permissions and `auth.php` circuit breaker
- [ ] **Phase 5: Quality Gate & Verification**
  - [ ] Write unit tests in `tests/unit/`
  - [ ] Run `lint_lang.php`, `smoke.php`, `phpunit`, `phpstan`
  - [ ] Update documentation and release notes
