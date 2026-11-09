#!/bin/bash
# Does aipbx-update really come back when a release is bad? (run as root inside the e2e
# container, on an installed system).
#
# Publishes a fake release v99.0.0 to a local bare repository: it adds a migration that
# creates a table and then fails its migration. aipbx-update must attempt it, fail the
# verification, and leave the old code and the old database: no probe table, and a row
# written just before the update still there.
#
# Replaces the container's origin for the run and puts it back.
set -uo pipefail
INSTALL_DIR="${INSTALL_DIR:-/opt/aipbx}"
PASS=0; FAIL=0
pass() { echo "PASS  $1"; PASS=$((PASS + 1)); }
fail() { echo "FAIL  $1${2:+ — $2}"; FAIL=$((FAIL + 1)); }
db() { mysql -N asterisk -e "$1"; }
WORK="$(mktemp -d)"
git_in() { git -C "$INSTALL_DIR" "$@"; }

BEFORE_COMMIT="$(git_in rev-parse HEAD)"
BEFORE_VERSION="$(tr -d '[:space:]' < "$INSTALL_DIR/VERSION")"
ORIGIN="$(git_in remote get-url origin)"
KEY_BEFORE="$(sha256sum /var/lib/aipbx/settings.key 2>/dev/null || true)"
cleanup() {
    git_in remote set-url origin "$ORIGIN"
    git_in tag -d v99.0.0 >/dev/null 2>&1
    db "DELETE FROM sys_settings WHERE setting_key = 'e2e_rollback_marker'"
    rm -rf "$WORK"
}
trap cleanup EXIT

git clone -q --bare "$INSTALL_DIR" "$WORK/origin.git"
git clone -q "$WORK/origin.git" "$WORK/bad"
(
    cd "$WORK/bad" || exit 1
    git checkout -q -B main "$BEFORE_COMMIT"
    echo 99.0.0 > VERSION
    cat > web/db/migrations/29990101000000_e2e_rollback_probe.php <<'PHP'
<?php
use Phinx\Migration\AbstractMigration;
final class E2eRollbackProbe extends AbstractMigration
{
    public function up(): void {
        $this->execute('CREATE TABLE e2e_rollback_probe (id INT PRIMARY KEY)');
        throw new RuntimeException('E2E deliberate migration failure after DDL');
    }
    public function down(): void { $this->execute('DROP TABLE e2e_rollback_probe'); }
}
PHP
    git add -A
    git -c user.name=e2e -c user.email=e2e@localhost commit -q -m 'bad release for the rollback test'
    git -c user.name=e2e -c user.email=e2e@localhost tag -a v99.0.0 -m 'bad release'
    git push -q origin HEAD:refs/heads/e2e-bad v99.0.0
) || { echo "FAIL  could not build the fake release"; exit 1; }

git_in remote set-url origin "$WORK/origin.git"
db "INSERT INTO sys_settings (setting_key, setting_value) VALUES ('e2e_rollback_marker', 'kept') ON DUPLICATE KEY UPDATE setting_value = 'kept'"

aipbx-update --to v99.0.0 --yes --allow-calls > "$WORK/update.log" 2>&1
code=$?
if [[ $code -ne 0 ]]; then pass "the bad release is refused (exit $code)"; else fail "the bad release is refused" "aipbx-update exited 0"; fi

now_commit="$(git_in rev-parse HEAD)"
if [[ "$now_commit" == "$BEFORE_COMMIT" ]]; then pass "the code is back"; else fail "the code is back" "HEAD is $now_commit"; fi
now_version="$(tr -d '[:space:]' < "$INSTALL_DIR/VERSION")"
if [[ "$now_version" == "$BEFORE_VERSION" ]]; then pass "VERSION is $BEFORE_VERSION"; else fail "VERSION is $BEFORE_VERSION" "it is $now_version"; fi
if [[ "$(db "SHOW TABLES LIKE 'e2e_rollback_probe'")" == "" ]]; then pass "the new release's table is gone"; else fail "the new release's table is gone"; fi
if [[ "$(db "SELECT setting_value FROM sys_settings WHERE setting_key = 'e2e_rollback_marker'")" == "kept" ]]; then pass "the database has its data"; else fail "the database has its data"; fi
if [[ -z "$(git_in status --porcelain --untracked-files=no)" ]]; then pass "rollback leaves managed code clean"; else fail "rollback leaves managed code clean"; fi
if [[ -n "$KEY_BEFORE" ]]; then
    if [[ "$(sha256sum /var/lib/aipbx/settings.key 2>/dev/null || true)" == "$KEY_BEFORE" ]]; then
        pass "rollback preserves the settings encryption key"
    else
        fail "rollback preserves the settings encryption key"
    fi
fi
for svc in mariadb apache2 nginx asterisk aipbx-webhook-worker; do
    if systemctl is-active --quiet "$svc"; then pass "$svc runs"; else fail "$svc runs"; fi
done
http="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 https://127.0.0.1/login)"
if [[ "$http" == 200 ]]; then pass "the portal answers"; else fail "the portal answers" "HTTP $http"; fi
if grep -q 'rolled_back' /var/lib/aipbx/update-status.json 2>/dev/null; then pass "the status says rolled back"; else fail "the status says rolled back" "$(cat /var/lib/aipbx/update-status.json 2>/dev/null | head -c 200)"; fi

echo "== $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
