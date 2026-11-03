#!/bin/bash
# Backup and restore on a real install (run as root inside the e2e container): a restore
# brings the data back and keeps a safety backup, and a bad upload is refused.
set -uo pipefail
PASS=0; FAIL=0
pass() { echo "PASS  $1"; PASS=$((PASS + 1)); }
fail() { echo "FAIL  $1${2:+ — $2}"; FAIL=$((FAIL + 1)); }
db() { mysql -N asterisk -e "$1"; }
WORK="$(mktemp -d)"; DEST=/var/backups/aipbx-daily
marker() { db "SELECT setting_value FROM sys_settings WHERE setting_key = 'e2e_backup_marker'"; }
cleanup() { db "DELETE FROM sys_settings WHERE setting_key = 'e2e_backup_marker'"; rm -rf "$WORK"; }
trap cleanup EXIT

db "INSERT INTO sys_settings (setting_key, setting_value) VALUES ('e2e_backup_marker', 'before') ON DUPLICATE KEY UPDATE setting_value = 'before'"
aipbx-backup --label test-run >/dev/null || fail "backup"
NAME="$(ls -1t "$DEST" | grep -m1 -- '-test-run$')"
if [[ -f "$DEST/$NAME/db.sql.gz" && -f "$DEST/$NAME/config.tgz" && -f "$DEST/$NAME/version" ]]; then pass "backup made: $NAME"; else fail "backup made"; fi
db "UPDATE sys_settings SET setting_value = 'after' WHERE setting_key = 'e2e_backup_marker'"

# Bad uploads
mkdir -p "$WORK/x/aipbx-20200101-0000"
cp "$DEST/$NAME/db.sql.gz" "$DEST/$NAME/config.tgz" "$WORK/x/aipbx-20200101-0000/"
echo evil > "$WORK/x/aipbx-20200101-0000/evil.sh"
tar czf "$WORK/extra.tgz" -C "$WORK/x" aipbx-20200101-0000
if aipbx-restore --import "$WORK/extra.tgz" >/dev/null 2>&1; then fail "an archive with another file is refused"; else pass "an archive with another file is refused"; fi
mkdir -p "$WORK/c/etc/cron.d"; echo evil > "$WORK/c/etc/cron.d/e2e-evil"
tar czf "$WORK/x/aipbx-20200101-0000/config.tgz" -C "$WORK/c" etc/cron.d/e2e-evil
rm -f "$WORK/x/aipbx-20200101-0000/evil.sh"
tar czf "$WORK/cron.tgz" -C "$WORK/x" aipbx-20200101-0000
if aipbx-restore --import "$WORK/cron.tgz" >/dev/null 2>&1; then fail "a file outside the backup folders is refused"; else pass "a file outside the backup folders is refused"; fi
if [[ -e /etc/cron.d/e2e-evil ]]; then fail "nothing was planted"; else pass "nothing was planted"; fi

# A good upload, restored
tar czf "$WORK/good.tgz" -C "$DEST" "$NAME"
UP="$(aipbx-restore --import "$WORK/good.tgz" 2>/dev/null | tail -n 1)"
if [[ "$UP" == *-upload* && -f "$DEST/$UP/uploaded" ]]; then pass "a good upload is accepted: $UP"; else fail "a good upload is accepted" "$UP"; fi
sleep 61   # the safety backup is named by the minute
aipbx-restore "$UP" >/dev/null 2>&1
code=$?
if [[ $code -eq 0 ]]; then pass "the restore finishes"; else fail "the restore finishes" "exit $code: $(cat /var/lib/aipbx/restore-status.json)"; fi
if [[ "$(marker)" == before ]]; then pass "the data is back"; else fail "the data is back" "$(marker)"; fi
SAFETY="$(ls -1t "$DEST" | grep -m1 -- '-before-restore$')"
if zcat "$DEST/$SAFETY/db.sql.gz" | grep -q "e2e_backup_marker','after'"; then pass "the safety backup holds the data from before"; else fail "the safety backup holds the data from before"; fi
for svc in mariadb apache2 nginx asterisk aipbx-webhook-worker; do
    if systemctl is-active --quiet "$svc"; then pass "$svc runs"; else fail "$svc runs"; fi
done
http="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 https://127.0.0.1/login)"
if [[ "$http" == 200 ]]; then pass "the portal answers"; else fail "the portal answers" "HTTP $http"; fi
rm -rf "${DEST:?}/$NAME" "${DEST:?}/$UP" "${DEST:?}/$SAFETY"

echo "== $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
