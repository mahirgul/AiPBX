# Testing

## Smoke test

One command to check that "nothing blows up". It runs on every commit.

    php bin/smoke.php                    # everything (~18 s)
    php bin/smoke.php --only=routes      # page rendering only (~12 s)
    php bin/smoke.php --only=rbac        # permissions only (~2 s)
    php bin/smoke.php --only=conventions # architecture rules only (<1 s)
    php bin/smoke.php --only=lint        # syntax/language/JS only (~4 s)
    php bin/smoke.php --route=/queues    # one route (debugging)

Exit code: `0` clean, `1` at least one error.

### What it checks

| Group | Check |
|---|---|
| **routes** | Every route renders in TR and EN: no fatal error, no PHP warning, the output is not suspiciously short, the HTML ends with `</html>` |
| **rbac** | Admin-only pages are blocked for a low-privilege role (with a "canary": admin can see the same page) |
| **conventions** | 5 architecture rules: deferred reload, FileHelper, branding, permission check, API guard |
| **lint** | `php -l` (196 files), tr/en key symmetry, JS bracket balance |

The route list is read from `src/routes.php`: **a new page is covered automatically**, no list to
update by hand.

### How it works

Every route is rendered in its own PHP subprocess (`bin/_smoke_render.php`). A separate process is
required: redirecting controllers call `header()+exit`, and `exit` would kill a runner working in a
single process; fatal errors are isolated this way too.

PHP warnings are **not searched for in the output**; they are caught programmatically with
`set_error_handler` in the subprocess. The application runs with `display_errors=Off` on purpose, so
warnings never reach the HTML.

### Safety

The smoke test only **reads and renders**. It triggers no POST, no service write, no Asterisk
configuration generation or reload. The only known side effect: `requireLogin()` →
`_touchLastSeen()` updates the admin's `last_seen_at`.

It runs as `root`: the `bin/` directory is `drwxr-x--- root:root` for hardening.

## Unit tests

    php vendor/bin/phpunit
    php vendor/bin/phpunit --filter SanitizationTest

| Test | What it locks in |
|---|---|
| `SanitizationTest` | `toCleanAscii` / `sanitizeDestType`: defence against configuration injection |
| `ValidationTest` | firewall/fail2ban IP-CIDR and protected port range validation |
| `SyncGeneratorTest` | Output of the PJSIP trunk generator, resistance to injection, idempotence |
| `PendingSyncTest` | Deferred reload contract (marking does not touch the configuration) |
| `RbacTest` | Privilege escalation breaker: a non-admin is refused even if the database allows it |

Each test file was written to stop a bug that **really happened** from coming back; the date of the
incident is in its header.

### Isolation

Tests run only on the `asterisk_test` database and in `/tmp/aipbx-test-conf`.
`tests/bootstrap.php` sets three locks:

1. If `DB_NAME` is not `asterisk_test`, the tests **do not start at all**
2. `ASTERISK_PBX_DIR` points to a temporary directory: nothing is written to the live `/etc/asterisk`
3. `AIPBX_NO_ASTERISK=1`: no CLI command (reload included) goes to a live Asterisk

The third lock is required: generators such as `syncAllTrunks()` trigger `pjsip reload` from
`writeConfWithRollback()`. `SyncGeneratorTest` checks in its first two tests that these locks are
set.

## Setup (new clone / server)

    composer install
    bash bin/setup-test-db.sh     # creates the asterisk_test database
    bash bin/install-hooks.sh     # installs the pre-commit hook

The hook runs the smoke test and (when the test database exists) the unit tests before a commit and
stops the commit when they fail. If really needed it can be skipped with `git commit --no-verify`,
but do not make it a habit.

## Out of scope (on purpose)

Browser/SPA behaviour, the WebRTC audio path and real call/fax flows are not tested automatically.
They cannot be automated in a meaningful way, so they stay manual tests (`pjsua` test clients, real
calls and faxes).
