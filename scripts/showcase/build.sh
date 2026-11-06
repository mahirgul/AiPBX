#!/bin/bash
# Whole showcase: reseed the container, export en/tr/de, add assets and the demo script.
set -e
cd "$(dirname "$0")"
lxc file push seed.php aipbx-showcase/root/seed.php
# Back to the fresh install's database (kept from the first run), then the made-up company.
lxc exec aipbx-showcase -- bash -c '[ -f /root/before-seed.sql ] || mysqldump --single-transaction asterisk > /root/before-seed.sql
  mysql -e "DROP DATABASE asterisk; CREATE DATABASE asterisk" && mysql asterisk < /root/before-seed.sql && php /root/seed.php; H=$(php -r "echo password_hash(\"Showcase-2026!\", PASSWORD_DEFAULT);"); mysql asterisk -e "UPDATE sys_users SET password_hash=\"$H\", must_reset_password=0 WHERE username=\"admin\"; DELETE FROM sys_notices; DELETE FROM sys_pending_sync"'
# No health alarms in the bell while the pages are read.
lxc exec aipbx-showcase -- sed -i 's#^\(\* \* \* \* \* root /usr/local/bin/aipbx_health.php\)#\# \1#' /etc/cron.d/aipbx
lxc exec aipbx-showcase -- mysql -e "DELETE FROM asterisk.sys_notices"
rm -rf out
IP=$(lxc list aipbx-showcase -c4 --format csv | cut -d' ' -f1)
node export.mjs "https://$IP" 'Showcase-2026!' out en,tr,de 2>&1 | grep -v -i warning
lxc exec aipbx-showcase -- tar czf /root/assets.tgz -C /opt/aipbx/web assets
lxc file pull aipbx-showcase/root/assets.tgz assets.tgz
python3 assets.py assets.tgz out en tr de
rm -f assets.tgz
python3 fixup.py out en tr de
for l in en tr de; do cp showcase.js out/$l/; rm -f out/$l/segments.json; done
cp index.html out/
du -sh out
