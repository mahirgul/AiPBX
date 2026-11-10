# Security regression tests

The regression checks cover five boundaries:

- Authentication: mobile token tampering, account deactivation, token epochs, and cached chat token expiry.
- Credential storage: authenticated ciphertext tampering, key rotation, persistent key permissions, damaged keys, and unavailable key directories. Encryption must fail rather than use an ephemeral key.
- Telephony: disabling queues removes generated configuration; disabling all configured outbound routes must not enable the first-install fallback. Real SIP tests also check outbound group isolation.
- Chat storage: interrupted uploads remove partial files, retries succeed, missing files have consistent semantics, and signatures cannot be reused for another upload.
- Installation: fresh installation and upgrade checks, bounded installer processes, and rollback of a deliberately broken release. Rollback checks code cleanliness, database contents, services, and preservation of an existing settings key.

## Local checks

Use the isolated `asterisk_test` database configured by `web/tests/.env.test`. Never point test credentials at production.

```sh
cd web
php vendor/bin/phpunit --display-skipped
php -d memory_limit=2G vendor/bin/phpstan analyse
cd ../chat
go vet ./...
go test -race ./...
```

PHP coverage needs Xdebug coverage mode and an explicit source filter. `web/src` coverage excludes `web/api`, templates and JavaScript; it is not whole-project coverage. Use `--cache-directory` and coverage output paths outside the checkout when measuring without generated project files.

## Disposable installed systems

The following commands mutate test database/configuration state. Run them only inside a disposable installation, never on a live server:

```sh
AIPBX_E2E=1 php scripts/e2e/chat_security.php
bash scripts/e2e/call_flows.sh
bash scripts/e2e/rollback.sh
AIPBX_E2E=1 php scripts/e2e/proxy_https.php
AIPBX_E2E=1 python3 scripts/e2e/turn_security.py
AIPBX_E2E=1 python3 scripts/e2e/log_security.py
```

`chat_security.php` creates and removes its own users and conversation. It tests successful authorized access as well as forbidden reads/writes, anonymous media access and malicious attachments. It does not use existing users.

`proxy_https.php` creates a temporary administrator, saves settings through the
HTTPS portal and checks forged protocol/client headers, IPv4/IPv6 trusted peers,
CIDRs, WebSocket handshakes and HTTP exceptions. It deliberately makes an unloaded
Apache configuration invalid to verify failed apply/DB rollback, then removes it.
Proxy settings and the active policy are restored in cleanup. The installer CI
runs this check; `python3 scripts/test_proxy_https.py` covers privileged input
validation, unsafe paths and reload failure recovery without a live server.

`turn_security.py` verifies coturn's config ownership, denies real file opens by
unrelated service users, checks secret-bearing temporary/backup files, and confirms
that an address refresh preserves the key and permissions without restarting
coturn. It then performs an authenticated TLS TURN allocation and packet relay to
a temporary local UDP peer. Only short-lived REST credentials reach the client
process arguments; the shared key is never printed. Install CI runs this test.
The upgrade CI step first creates the old 0644 config/backup state on the
disposable runner; the later TURN check must reject any unrepaired file.

`log_security.py` temporarily recreates the legacy web-owned log directory and
plants four symlinks as `www-data`, pointing to a root-only canary. It invokes
the real backup, restore refusal, local update-check failure and sound-pack refusal
paths. The canary must stay unchanged, root logs must be portal-readable but not
writable, and new symlinks must be denied. It also forces logrotate with a root
stderr descriptor held open and verifies that the portal can still append login
failures and Fail2ban consumes a new failure after rotation. Run only in a
disposable installation: it produces a labelled backup and diagnostic task states.
Full previous logs are kept in its private test archive under `/var/log`.

`python3 scripts/test_log_paths.py` covers symlink/hardlink targets, retained
untrusted descriptors, safe active root descriptors, FIFOs, bounded migration,
directory symlinks and legacy POSIX ACLs on the actual filesystem.

The LXD runner `scripts/e2e/run.sh` restores snapshots and tests **committed** source. Use a dedicated container with `pristine` and `installed-200` snapshots through `AIPBX_E2E_CONTAINER`. Do not assume uncommitted workspace changes were tested by this runner.

When testing a separate source copy inside a disposable system, set
`AIPBX_INSTALL_DIR` consistently for the installer, `checks.sh` and `call_flows.sh`.
The call-flow script also accepts its older `INSTALL_DIR` variable. Check the
selected source directory so that a successful call test cannot hide a run against
an older installed copy.

### Portal environment access during installation

Run both fresh and upgrade installation checks after changing service identities,
environment permissions or plugin transitions. An upgraded machine can already
have `www-data` in the required group and hide an ordering bug on a fresh install.
The installer must prepare access to `/etc/ai-pbx.env` before running any portal
CLI as `www-data`. Keep the file `root:asterisk` with mode `0640`; do not fix an
access failure by making it world-readable or running plugin transitions as root.

`scripts/e2e/checks.sh` opens the environment through PHP as `www-data`, refuses
access as `nobody`, and repeats the bundled-plugin transition as `www-data` against
the installed database. It uses an actual file open rather than an access-only
probe, which can behave differently under container syscall restrictions.

## Skips and external dependencies

- Python fake audio/model tests need NumPy, now installed by Web & Chat CI alongside num2words. Real E5 and Whisper tests still require model directories and their runtimes.
- Five legacy PHP chat tests require explicit live-service opt-in. Keep them disabled on developer machines; the disposable-system chat check covers their HTTP security scenarios in Install CI.
- The PHP rate-limit test needs write access to its rate-limit directory. A skipped test is not a successful rate-limit verification.
- The call-forward regression seeds its feature code transactionally, so it does not depend on pre-existing feature-code settings.

## Protected root state and staging

See [root-state-paths.md](root-state-paths.md). Run
`python3 scripts/test_system_state.py` locally, and on a disposable installed
test PBX run `sudo env AIPBX_E2E=1 python3 scripts/e2e/state_security.py`.
Verify both fresh installation and upgrade; legacy retention/catalog must
survive migration, while web-writable progress tokens must not be imported.
