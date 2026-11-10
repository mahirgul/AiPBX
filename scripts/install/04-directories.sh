#!/usr/bin/env bash
# ============================================================================
# STEP 4: DIRECTORY STRUCTURE
# ============================================================================
step "4. Creating Directory Structure"

if [[ "$(realpath "$SCRIPT_DIR")" != "$(realpath "$INSTALL_DIR")" ]]; then
    mkdir -p "$INSTALL_DIR"
    rsync -a \
        --exclude='/.git' \
        --exclude='/.claude' \
        --exclude='/rapor' \
        --exclude='*.jks' \
        --exclude='keystore.properties' \
        "$SCRIPT_DIR"/ "$INSTALL_DIR"/
    rm -rf "$INSTALL_DIR/android" "$INSTALL_DIR/ios" "$INSTALL_DIR/asterisk-config" "$INSTALL_DIR/rapor" "$INSTALL_DIR/.claude" 2>/dev/null || true
    find "$INSTALL_DIR" \( -name '*.jks' -o -name 'keystore.properties' \) -delete 2>/dev/null || true
fi

rm -rf /var/www/html
ln -sf "$INSTALL_DIR/web" /var/www/html

# Symlink helper binaries and dialplan scripts to /usr/local/bin.
# web/bin must stay traversable (755, root-owned): Asterisk runs these scripts
# as the asterisk user through the symlinks (feature codes, web widget calls,
# fax processing).
# A root-only 750 bin/ silently broke *60/*72 because System(... &) logs nothing.
chmod 755 "$INSTALL_DIR/web/bin"
for script in feature_code_action.php widget_claim.php ai_request.php push_dispatcher.php process_incoming_fax.sh process_outgoing_fax_result.sh fax_cleanup.sh fax_pending_sweep.sh sync_queue_logs.php recordings_to_mp3.php aipbx_health.php voicemail_notify.php fax_to_bucket.php voicemail_to_bucket.php provision_tftp.php privacy_purge.php task_dispatcher.php; do
    if [[ -f "$INSTALL_DIR/web/bin/$script" ]]; then
        ln -sf "$INSTALL_DIR/web/bin/$script" "/usr/local/bin/$script"
        chmod 755 "$INSTALL_DIR/web/bin/$script"
    fi
done

# AI PBX automated maintenance and queue sync crons
cat > /etc/cron.d/aipbx << 'CRON'
# AI PBX Automated Maintenance & Synchronization Crons
# Asterisk Queue Log to MariaDB DB Synchronization
* * * * * root /usr/local/bin/sync_queue_logs.php >/dev/null 2>&1

# Daily fax retention & spool cleanup (fax_retention_days)
15 3 * * * root /usr/local/bin/fax_cleanup.sh >/dev/null 2>&1

# Outgoing fax pending sweep (detect unanswered / stale spool files)
* * * * * root /usr/local/bin/fax_pending_sweep.sh >/dev/null 2>&1

# Call recordings: finished WAVs → mono 16 kbps MP3 (~8x smaller), into the S3 bucket when chosen
*/5 * * * * root /usr/local/bin/recordings_to_mp3.php >/dev/null 2>&1

# Faxes into the S3 bucket an hour after they finished (Admin -> File storage)
*/5 * * * * root /usr/local/bin/fax_to_bucket.php >/dev/null 2>&1

# Heard voicemail older than N days archived to the S3 bucket (Admin -> File storage)
17 * * * * root /usr/local/bin/voicemail_to_bucket.php >/dev/null 2>&1

# Phone files into the TFTP/FTP folder when switched on (PBX -> Phones -> settings)
*/5 * * * * www-data /usr/local/bin/provision_tftp.php >/dev/null 2>&1

# Daily check for a new AiPBX release (shown on the portal's System Update page)
37 4 * * * root /usr/local/sbin/aipbx-update --check >/dev/null 2>&1

# Daily backup of the database and configuration (/var/backups/aipbx-daily, 14 days)
30 2 * * * root /usr/local/sbin/aipbx-backup >/dev/null 2>&1

# Health checks: the checks that are due, alarm e-mails, daily summary (Admin -> Health checks)
* * * * * root /usr/local/bin/aipbx_health.php >/dev/null 2>&1

# Nightly KVKK / GDPR data retention auto-purge (Security -> GDPR & Data Privacy)
45 3 * * * root /usr/local/bin/privacy_purge.php >/dev/null 2>&1

# Scheduled tasks (System -> Scheduled tasks; sandboxed, as aipbx-task), SIP debug
# switch-off and the night restart window (PBX settings)
* * * * * root /usr/local/bin/task_dispatcher.php >/dev/null 2>&1

# Desk phones found on the network (PBX -> Phones -> Discovered on the network):
# the engine looks, then the portal provisions what its settings allow. Both halves
# are in one script so the second can never run without the first.
*/5 * * * * www-data /usr/local/sbin/aipbx-phone-discovery >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/aipbx

# Scheduled tasks run as this user only (never root, never www-data), in a
# systemd sandbox where nothing but their own folder is writable.
id -u aipbx-task >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent \
    --shell /usr/sbin/nologin --user-group aipbx-task
# Root's own folder, not under /var/lib/aipbx: www-data owns that one and could swap
# what the root dispatcher works on. Task data of older versions moves along.
install -d -o root -g root -m 0755 /var/lib/aipbx-tasks
if [[ -d /var/lib/aipbx/tasks && ! -L /var/lib/aipbx/tasks ]]; then
    find /var/lib/aipbx/tasks -mindepth 1 -maxdepth 1 -type d -regex '.*/[0-9]+' -exec mv -n -t /var/lib/aipbx-tasks {} +
    find /var/lib/aipbx-tasks -mindepth 1 -type l -name '.run_*' -delete
    rm -rf --one-file-system /var/lib/aipbx/tasks
fi
chown -R -h aipbx-task:aipbx-task /var/lib/aipbx-tasks/* 2>/dev/null || true

mkdir -p /var/www/faxes
mkdir -p /var/spool/asterisk/fax/outgoing
mkdir -p /var/spool/asterisk/monitor
mkdir -p /var/lib/asterisk/sounds/custom
mkdir -p /var/lib/asterisk/sounds/tr
mkdir -p /var/lib/asterisk/moh
mkdir -p /var/lib/asterisk/moh/custom   # seed.sql's "custom" MOH class
# The "default" MOH class plays /var/lib/asterisk/moh, but Ubuntu installs its
# tracks (asterisk-moh-opsound-*) in /usr/share/asterisk/moh. An empty folder
# makes Asterisk drop the class ("No music on hold classes configured") and
# callers on hold or in a queue hear silence. Link the stock tracks in — only
# while the folder has no audio of its own, so an admin's music is never touched.
if ! find /var/lib/asterisk/moh -maxdepth 1 \( -type f -o -type l \) | grep -q .; then
    for track in /usr/share/asterisk/moh/*; do
        [[ -f "$track" ]] && ln -sf "$track" /var/lib/asterisk/moh/
    done
fi
mkdir -p /var/lib/aipbx/chat_files /var/lib/aipbx/tts
# Key that encrypts cloud credentials stored in the database (AI → Cloud TTS).
# Created here so it belongs to the web server: one made by root would lock it out.
[[ -L /var/lib/aipbx/settings.key ]] && rm -f /var/lib/aipbx/settings.key
if [[ ! -s /var/lib/aipbx/settings.key ]]; then
    tmp_settings_key="$(mktemp /var/lib/aipbx/.settings.key.XXXXXX)"
    head -c 32 /dev/urandom > "$tmp_settings_key"
    chmod 600 "$tmp_settings_key"
    mv -f "$tmp_settings_key" /var/lib/aipbx/settings.key
fi
chmod 600 /var/lib/aipbx/settings.key
mkdir -p /etc/asterisk/pbx
mkdir -p /etc/asterisk/keys
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-system-state" /usr/local/sbin/aipbx-system-state
/usr/local/sbin/aipbx-system-state prepare || error "Could not prepare protected system state"

# Fixed root log paths must be secured before any privileged helper writes.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-log-init" /usr/local/sbin/aipbx-log-init
/usr/local/sbin/aipbx-log-init install || error "Could not prepare safe log paths"

chown -R www-data:www-data /var/lib/aipbx
chmod 1755 /var/lib/aipbx
# Configuration sync locks, shared by the portal (www-data), feature codes
# (asterisk) and root (asterisk_sync.php): group asterisk, no one else.
install -d -o www-data -g asterisk -m 2770 /var/lib/aipbx/sync-locks
chgrp asterisk /var/lib/aipbx/sync-locks/*.lock 2>/dev/null || true
chmod 0660 /var/lib/aipbx/sync-locks/*.lock 2>/dev/null || true
# Logo / favicon uploads (Appearance → Branding). The code checkout belongs to
# root, so without this folder the portal could not save an uploaded logo.
mkdir -p "$INSTALL_DIR/web/assets/images/brand"
chown www-data:www-data "$INSTALL_DIR/web/assets/images/brand"
# TWO processes write to the fax directories: Asterisk (user asterisk; incoming
# faxes, send results) and the portal (www-data, member of group asterisk;
# outgoing fax archive and the .call file). Previously /var/www/faxes belonged to
# www-data, /var/spool/asterisk/fax to root and the outgoing spool was
# asterisk:root 750: incoming TIFFs could never be written and outgoing .call
# files could not be dropped. setgid (2775): subdirectories inherit group asterisk.
mkdir -p /var/www/faxes/recvd /var/www/faxes/sent /var/spool/asterisk/outgoing
chown -R asterisk:asterisk /var/www/faxes
chmod 2775 /var/www/faxes /var/www/faxes/recvd /var/www/faxes/sent
# Year directories (sent/2026, recvd/2026) were made with the creator's umask
# (0755). After the chown above the portal could not write into them, and every
# fax upload failed with "Permission denied" (live system, 2026-10-22).
find /var/www/faxes -type d -exec chmod 2775 {} +
# /var/spool/asterisk: the Debian asterisk package resets EVERY directory here
# to "asterisk: 750" on each upgrade (postinst) but leaves directories with a
# dpkg-statoverride alone. The permissions the portal needs — reading call
# recordings (monitor) and voicemail, writing .call and outgoing fax files —
# are pinned here. (Previously /var/spool/asterisk was asterisk:root 750:
# recording playback and fax sending never worked for www-data.)
for spec in "0750 /var/spool/asterisk" "2750 /var/spool/asterisk/monitor" \
            "2770 /var/spool/asterisk/outgoing" "2775 /var/spool/asterisk/fax" \
            "2775 /var/spool/asterisk/fax/outgoing"; do
    mode="${spec%% *}"; dir="${spec#* }"
    dpkg-statoverride --list "$dir" >/dev/null 2>&1 && dpkg-statoverride --remove "$dir" >/dev/null 2>&1
    dpkg-statoverride --update --add asterisk asterisk "$mode" "$dir"
done
# Ubuntu 26.04 confines Ghostscript with an AppArmor profile that reaches only
# HOME, /mnt, /media and /tmp: every outgoing fax failed at the PDF -> TIFF step
# ("Could not open the file"), and so did the TIFF -> PDF fallback for received
# faxes. The local include extends the packaged profile instead of replacing it.
if [ -f /etc/apparmor.d/gs ]; then
    mkdir -p /etc/apparmor.d/local
    cat > /etc/apparmor.d/local/gs <<'EOF'
# AiPBX: Ghostscript converts faxes here (PDF -> TIFF to send, TIFF -> PDF for
# received faxes). Written by install.sh; rewritten on every upgrade.
file rw /var/www/faxes/**,
file rw /var/spool/asterisk/fax/**,
EOF
    command -v apparmor_parser >/dev/null 2>&1 && { apparmor_parser -r /etc/apparmor.d/gs 2>/dev/null || true; }
fi
chgrp -R asterisk /var/spool/asterisk/voicemail /var/spool/asterisk/monitor 2>/dev/null || true
chmod -R g+rX /var/spool/asterisk/voicemail /var/spool/asterisk/monitor 2>/dev/null || true
# Root owns the directory; only this individual file is writable by the portal.
# Its ownership is restored on rotation too (aipbx-web Fail2ban jail).
cat > /etc/logrotate.d/aipbx << 'ROT'
/var/log/aipbx/backup.log /var/log/aipbx/restore.log /var/log/aipbx/update.log /var/log/aipbx/sound-packs.log {
    daily
    maxsize 10M
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    # Long-running scripts may hold stderr open. Keep that inode and its mode.
    copytruncate
}
/var/log/aipbx/web_login_failures.log {
    daily
    maxsize 10M
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
}
ROT
# The fax scripts run as the asterisk user (System()) and write these files;
# they cannot create files in /var/log themselves, so nothing was ever logged.
# The logrotate "create" line keeps logrotate from recreating them as root.
for f in fax_incoming fax_outgoing; do
    touch "/var/log/$f.log"
    chown asterisk:adm "/var/log/$f.log"
    chmod 640 "/var/log/$f.log"
done
cat > /etc/logrotate.d/aipbx-fax << 'ROT'
/var/log/fax_incoming.log /var/log/fax_outgoing.log {
    su root syslog
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 asterisk adm
}
ROT

cat > /etc/logrotate.d/asterisk << 'ROT'
/var/log/asterisk/*.log /var/log/asterisk/*_log {
    daily
    maxsize 50M
    missingok
    rotate 14
    compress
    delaycompress
    sharedscripts
    postrotate
        /usr/sbin/invoke-rc.d asterisk logger-reload > /dev/null 2>&1 || asterisk -rx "logger reload" > /dev/null 2>&1 || true
    endscript
}
ROT
chmod 755 "$(dirname "$INSTALL_DIR")" "$INSTALL_DIR"

ok "Directory structure created"
