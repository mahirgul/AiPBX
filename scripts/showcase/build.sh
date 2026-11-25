#!/bin/bash
# Whole showcase: reseed the container, export en/tr/de, add assets and the demo script.
set -euo pipefail
cd "$(dirname "$0")"
# A new admin password for each build, used only to sign the exporter in.
PASS="${SHOWCASE_PASS:-$(openssl rand -hex 12)}"
CONTAINER="${SHOWCASE_CONTAINER:-aipbx-showcase}"
BASELINE="${SHOWCASE_BASELINE:-/root/before-seed.sql}"
lxc file push seed.php "$CONTAINER/root/seed.php"
# Back to the fresh install's database (kept from the first run), then the made-up company.
lxc exec "$CONTAINER" --env BASELINE="$BASELINE" --env PASS="$PASS" -- bash -ec '
  umask 077
  [ -s "$BASELINE" ] || mysqldump --single-transaction asterisk > "$BASELINE"
  systemctl stop cron aipbx-signaling aipbx-webhook-worker aipbx-call-events >/dev/null 2>&1 || true
  mysql -e "DROP DATABASE asterisk; CREATE DATABASE asterisk"
  mysql asterisk < "$BASELINE"
  php /root/seed.php
  H=$(php -r "echo password_hash(getenv(\"PASS\"), PASSWORD_DEFAULT);")
  mysql asterisk -e "UPDATE sys_users SET password_hash=\"$H\", must_reset_password=0, two_factor_enabled=0 WHERE username=\"admin\"; DELETE FROM sys_notices; DELETE FROM sys_pending_sync"
  mysql asterisk -e "INSERT INTO sys_settings (setting_key, setting_value) VALUES (\"license_accepted_at\", NOW()), (\"license_accepted_by\", \"Demo Admin\") ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
'
rm -rf out
IP=$(lxc list "$CONTAINER" -c4 --format csv | cut -d' ' -f1)
SHOWCASE_PASS="$PASS" node export.mjs "https://$IP" - out en,tr,de
lxc exec "$CONTAINER" -- tar czf - -C /opt/aipbx/web assets > assets.tgz
python3 assets.py assets.tgz out en tr de
rm -f assets.tgz
python3 fixup.py out en tr de
for l in en tr de; do cp showcase.js out/$l/; rm -f out/$l/segments.json; done
cp index.html out/
du -sh out
