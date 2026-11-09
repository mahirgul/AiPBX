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
```

`chat_security.php` creates and removes its own users and conversation. It tests successful authorized access as well as forbidden reads/writes, anonymous media access and malicious attachments. It does not use existing users.

The LXD runner `scripts/e2e/run.sh` restores snapshots and tests **committed** source. Use a dedicated container with `pristine` and `installed-200` snapshots through `AIPBX_E2E_CONTAINER`. Do not assume uncommitted workspace changes were tested by this runner.

## Skips and external dependencies

- Python fake audio/model tests need NumPy, now installed by Web & Chat CI alongside num2words. Real E5 and Whisper tests still require model directories and their runtimes.
- Five legacy PHP chat tests require explicit live-service opt-in. Keep them disabled on developer machines; the disposable-system chat check covers their HTTP security scenarios in Install CI.
- The PHP rate-limit test needs write access to its rate-limit directory. A skipped test is not a successful rate-limit verification.
- The call-forward regression seeds its feature code transactionally, so it does not depend on pre-existing feature-code settings.
