#!/usr/bin/env bash
# ============================================================================
# STEP 9: ASTERISK CONFIGURATION
# ============================================================================
step "9. Configuring Asterisk PBX"

mkdir -p /etc/asterisk/pbx
# The pbx/ files are generated from the database (asterisk_sync.php below);
# the repo copies only seed a fresh install. Overwriting them on upgrade made
# Asterisk restart with no extensions, hints or queue members: phones dropped
# their registration and static queue members stayed "Invalid" (never rung)
# because their hints did not exist yet when the queues were loaded.
for f in "$INSTALL_DIR/conf/asterisk/pbx/"*.conf "$INSTALL_DIR/asterisk-config/pbx/"*.conf; do
    [[ -f "$f" ]] || continue
    [[ -e "/etc/asterisk/pbx/$(basename "$f")" ]] || cp "$f" /etc/asterisk/pbx/
done

for f in extensions.conf pjsip.conf pjsip_notify.conf queues.conf musiconhold.conf http.conf rtp.conf hep.conf modules.conf cdr.conf res_odbc.conf cdr_adaptive_odbc.conf extconfig.conf stasis.conf dnsmgr.conf cli_permissions.conf; do
    if [[ -f "$INSTALL_DIR/conf/asterisk/$f" ]]; then
        cp "$INSTALL_DIR/conf/asterisk/$f" /etc/asterisk/"$f"
    elif [[ -f "$INSTALL_DIR/asterisk-config/$f" ]]; then
        cp "$INSTALL_DIR/asterisk-config/$f" /etc/asterisk/"$f"
    fi
done

# users.conf is not used by AiPBX, but pbx_config reads it on every dialplan
# reload, and with the packaged sample each reload left one more subscription
# to the manager topic behind (Asterisk 22.5). They pile up until Asterisk
# restarts; every event then fans out to all of them and the stasis pool
# overflows ("stasis/pool-control ... reached 500 scheduled tasks"), when
# Asterisk drops SIP requests without an answer. A file with users of its own
# (made by hand) is kept.
if [[ -f /etc/asterisk/users.conf ]] && ! grep -qE '^\s*\[[^]]+\]' <(grep -vE '^\s*\[general\]' /etc/asterisk/users.conf); then
    mv /etc/asterisk/users.conf /etc/asterisk/users.conf.aipbx-unused
fi

# Voicemail: Ensure Asterisk app_voicemail doesn't emit "maxsilence should be less than minsecs"
if [[ -f /etc/asterisk/voicemail.conf ]]; then
    sed -i -E 's/^[; ]*minsecs\s*=.*/minsecs=3/' /etc/asterisk/voicemail.conf
    sed -i -E 's/^[; ]*maxsilence\s*=.*/maxsilence=2/' /etc/asterisk/voicemail.conf
fi
# res_odbc.conf ships with a ${ODBC_PASS} placeholder (no secret in the repo).
escaped_odbc_pass="$(printf '%s\n' "$ODBC_PASS" | sed 's/[&/\]/\\&/g')"
sed -i "s/\${ODBC_PASS}/${escaped_odbc_pass}/" /etc/asterisk/res_odbc.conf
chown asterisk:root /etc/asterisk/res_odbc.conf
chmod 600 /etc/asterisk/res_odbc.conf

# ODBC data source "asterisk" used by res_odbc.conf (MariaDB Connector/ODBC).
cat > /etc/odbc.ini << 'ODBCINI'
[asterisk]
Description = AI PBX MariaDB (Asterisk CDR / queue_log)
Driver      = MariaDB Unicode
Socket      = /run/mysqld/mysqld.sock
Server      = localhost
Database    = asterisk
Charset     = utf8mb4
ODBCINI

# seed.sql enables SIP-TLS (5061) and WSS (8089) with /etc/asterisk/keys/
# fullchain.pem and privkey.pem; aipbx-cert wrote them in step 7.

cat > /etc/asterisk/manager.conf << MANAGER
[general]
enabled = yes
bindaddr = 127.0.0.1
port = 5038

[${AMI_USER}]
secret = ${AMI_PASS}
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = originate,call,agent
write = originate,call,agent
writetimeout = 5000
MANAGER
chown asterisk:root /etc/asterisk/manager.conf
chmod 600 /etc/asterisk/manager.conf

# Administrator's lines from the Advanced configuration page live in pbx/user_<file>.conf. The line
# goes in here because this script replaces several of these files on every upgrade. It takes effect
# on the next reload, so no restart is needed; a file that is not there yet is simply skipped.
for f in rtp features queues voicemail musiconhold cdr logger manager udptl indications confbridge; do
    [[ -f "/etc/asterisk/$f.conf" ]] || continue
    grep -q "pbx/user_$f.conf" "/etc/asterisk/$f.conf" \
        || printf '\n#tryinclude "pbx/user_%s.conf"\n' "$f" >> "/etc/asterisk/$f.conf"
done

# Install Asterisk Sound Prompts (Turkish & WebRTC/IVR custom sounds)
info "Installing Asterisk sound prompts (Turkish & WebRTC/IVR sounds)..."
mkdir -p /var/lib/asterisk/sounds/custom /var/lib/asterisk/sounds/tr

if [[ -d "$INSTALL_DIR/sounds/custom" ]]; then
    # --update=none: sounds uploaded from the portal with the same name are kept
    # (upgrade). Same as -n, which newer coreutils warn about.
    cp -a --update=none "$INSTALL_DIR/sounds/custom/"* /var/lib/asterisk/sounds/custom/
fi


# Default prompt language (asterisk.conf) = the system_default_language
# setting (PBX Settings). seed.sql makes it "en" on a fresh install; an
# upgrade keeps whatever the installation uses (e.g. "tr").
SPOKEN_LANG="$(mysql -N -B asterisk -e "SELECT setting_value FROM sys_settings WHERE setting_key='system_default_language'" 2>/dev/null | tr -cd 'a-zA-Z_' || true)"
SPOKEN_LANG="${SPOKEN_LANG:-en}"
if [[ -f /etc/asterisk/asterisk.conf ]]; then
    if grep -q "defaultlanguage" /etc/asterisk/asterisk.conf; then
        sed -i "s/^;*defaultlanguage\s*=.*/defaultlanguage = ${SPOKEN_LANG}/" /etc/asterisk/asterisk.conf
    else
        echo "defaultlanguage = ${SPOKEN_LANG}" >> /etc/asterisk/asterisk.conf
    fi
    if grep -q "maxfiles" /etc/asterisk/asterisk.conf; then
        sed -i "s/^;*maxfiles\s*=.*/maxfiles = 65536/" /etc/asterisk/asterisk.conf
    elif grep -q "^\[options\]" /etc/asterisk/asterisk.conf; then
        sed -i "/^\[options\]/a maxfiles = 65536" /etc/asterisk/asterisk.conf
    fi
    if ! grep -q "^\[files\]" /etc/asterisk/asterisk.conf; then
        cat >> /etc/asterisk/asterisk.conf << 'EOF'

[files]
astctlpermissions = 0660
astctlgroup = asterisk
EOF
    fi
fi

chown -R asterisk:asterisk /var/lib/asterisk/sounds/
chmod -R 755 /var/lib/asterisk/sounds/
# The portal (www-data, in the asterisk group) writes uploaded and AI-made
# announcements into custom/: group-writable, and files it may overwrite too.
chmod 2775 /var/lib/asterisk/sounds/custom
find /var/lib/asterisk/sounds/custom -maxdepth 1 -type f -exec chmod 664 {} +

# Debian's Asterisk searches prompts in /usr/share/asterisk/sounds, where
# "custom" points at /usr/local/share/asterisk/sounds. The portal stores sounds
# in /var/lib/asterisk/sounds/{custom,tr}; without these links every IVR,
# announcement and Turkish prompt failed with "does not exist in any format".
link_sound_dir() {  # link_sound_dir TARGET LINK
    if [[ -d "$2" && ! -L "$2" ]]; then
        cp -an "$2"/. "$1"/ 2>/dev/null || true
        rm -rf "$2"
    fi
    mkdir -p "$(dirname "$2")"
    ln -sfn "$1" "$2"
}
link_sound_dir /var/lib/asterisk/sounds/custom /usr/local/share/asterisk/sounds
link_sound_dir /var/lib/asterisk/sounds/tr /usr/share/asterisk/sounds/tr

# Turkish prompts: AiPBX's own asterisk-core-sounds-tr packages (built by
# scripts/build_tr_sounds.sh, texts in sounds/core-sounds-tr.txt), one per
# format like Asterisk's own sound packages. They are not in git but assets of
# the sounds-tr-<version> GitHub release; aipbx-sounds (installed in step 8)
# downloads and verifies them, so they also show up — and can be removed or
# installed again — on Sounds → Asterisk Sound Packs. Packages built locally
# at the repo root are used instead of downloading. A failed download only
# warns: the install goes on.
# AIPBX_TR_SOUNDS=auto (default) installs them when the prompt language is
# Turkish or they are already installed (an upgrade keeps them current); a
# fresh install is English and leaves them out. yes = always, no = never.
TR_SOUNDS_MODE="${AIPBX_TR_SOUNDS:-auto}"
if [[ "$TR_SOUNDS_MODE" == auto ]]; then
    if [[ "$SPOKEN_LANG" == tr ]] || compgen -G "/var/lib/aipbx-system/sound-packs/core-tr-*.list" >/dev/null \
       || compgen -G "/var/lib/asterisk/sounds/tr/*.wav" >/dev/null; then
        TR_SOUNDS_MODE=yes
    else
        TR_SOUNDS_MODE=no
    fi
fi
if [[ "$TR_SOUNDS_MODE" == no ]]; then
    info "Turkish prompts not installed — add them any time on Sounds → Asterisk Sound Packs"
else
    TR_SOUNDS_VERSION="$(sed -n 's/^TR_VERSION=//p' /usr/local/sbin/aipbx-sounds)"
    if compgen -G "$INSTALL_DIR/asterisk-core-sounds-tr-*-${TR_SOUNDS_VERSION}.tar.xz" >/dev/null \
       && [[ -f "$INSTALL_DIR/asterisk-core-sounds-tr-${TR_SOUNDS_VERSION}.SHA256SUMS" ]]; then
        mkdir -p "/var/cache/aipbx/sounds-tr-${TR_SOUNDS_VERSION}"
        cp "$INSTALL_DIR"/asterisk-core-sounds-tr-*"${TR_SOUNDS_VERSION}"* "/var/cache/aipbx/sounds-tr-${TR_SOUNDS_VERSION}/"
    fi
    TR_SOUNDS_FAILED=()
    for fmt in wav ulaw alaw gsm g722 sln16; do
        # Already installed in this version (upgrade): nothing to download.
        python3 -c 'import json,sys; d=json.load(open("/var/lib/aipbx-system/sound-packs.json")); sys.exit(d.get("core-tr-"+sys.argv[1],{}).get("version")!=sys.argv[2])' \
            "$fmt" "$TR_SOUNDS_VERSION" 2>/dev/null && continue
        /usr/local/sbin/aipbx-sounds install core tr "$fmt" >/dev/null 2>&1 || TR_SOUNDS_FAILED+=("$fmt")
    done
    if [[ ${#TR_SOUNDS_FAILED[@]} -eq 0 ]]; then
        ok "Turkish prompts installed (6 formats, sounds-tr-${TR_SOUNDS_VERSION})"
    else
        warn "Turkish prompts not installed for: ${TR_SOUNDS_FAILED[*]} (see /var/log/aipbx/sound-packs.log) — install them later on Sounds → Asterisk Sound Packs"
    fi
fi
chown -R asterisk:asterisk /etc/asterisk/
chown root:asterisk /etc/asterisk && chmod 0755 /etc/asterisk
chown -R asterisk:asterisk /etc/asterisk/pbx
chmod 2775 /etc/asterisk/pbx
chmod 664 /etc/asterisk/pbx/*.conf /etc/asterisk/rtp.conf /etc/asterisk/udptl.conf /etc/asterisk/voicemail.conf 2>/dev/null || true
if [[ -f /etc/asterisk/cli_permissions.conf ]]; then
    chown root:asterisk /etc/asterisk/cli_permissions.conf
    chmod 0640 /etc/asterisk/cli_permissions.conf
fi
for secret_conf in /etc/asterisk/manager.conf /etc/asterisk/res_odbc.conf /etc/asterisk/keys/privkey.pem /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem; do
    if [[ -f "$secret_conf" ]]; then
        chown asterisk:root "$secret_conf"
        chmod 600 "$secret_conf"
    fi
done

# Voicemail/recording files created by Asterisk are group-writable (UMask 0002)
# so the portal (www-data, group asterisk) can delete voicemail; with 0022
# deletion silently failed.
# Asterisk starts after MariaDB: started together (boot, or needrestart after
# a library update) Asterisk came up first, cdr_adaptive_odbc found no
# connection and wrote no CDRs until the next Asterisk restart (11 hours on
# production, 2026-10-21).
mkdir -p /etc/systemd/system/asterisk.service.d
cat > /etc/systemd/system/asterisk.service.d/aipbx.conf << 'UNIT'
[Unit]
Wants=mariadb.service
After=mariadb.service

[Service]
UMask=0002
LimitNOFILE=65536
Nice=-10
OOMScoreAdjust=-500
UNIT
systemctl daemon-reload

# Kernel sysctl tuning for real-time VoIP, high UDP burst, and WebRTC
mkdir -p /etc/sysctl.d
cat > /etc/sysctl.d/99-aipbx.conf << 'SYSCTL'
# Managed by AiPBX: VoIP & Asterisk high-concurrency kernel network tuning
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.core.rmem_default = 1048576
net.core.wmem_default = 1048576
net.core.netdev_max_backlog = 10000
net.core.somaxconn = 4096
vm.swappiness = 10
vm.dirty_background_ratio = 5
vm.dirty_ratio = 10
# Security: disable Linux MPTCP (MultiPath TCP) at kernel level
# Reduces exposure to CVE-2026-98432 (the kernel update is the fix)
net.mptcp.enabled = 0
SYSCTL
sysctl --system >/dev/null 2>&1 || sysctl -p /etc/sysctl.d/99-aipbx.conf >/dev/null 2>&1 || true

# Disable Linux kernel SIP ALG (nf_conntrack_sip/nf_nat_sip) which corrupts VoIP/WebRTC SDP
mkdir -p /etc/modprobe.d
cat > /etc/modprobe.d/aipbx-blacklist-sip.conf << 'MOD'
# Managed by AiPBX: disable kernel SIP ALG modules that corrupt VoIP/WebRTC SDP
blacklist nf_conntrack_sip
blacklist nf_nat_sip
MOD
modprobe -r nf_nat_sip nf_conntrack_sip 2>/dev/null || true

# needrestart (unattended-upgrades) restarted Asterisk after library updates
# at 06:35 and dropped the calls in progress. Asterisk is left out; it picks
# up new libraries on the next update or reboot.
if [[ -d /etc/needrestart ]]; then
    mkdir -p /etc/needrestart/conf.d
    cat > /etc/needrestart/conf.d/aipbx.conf << 'NR'
# Managed by AiPBX install.sh: never restart Asterisk automatically (drops calls).
$nrconf{override_rc}{qr(^asterisk\.service$)} = 0;
NR
fi

# A restart can fail although Asterisk comes up a moment later through the
# unit's Restart=on-failure (seen once on an update: the new process got a
# SIGKILL at start and was running 2 s later). Wait for that before failing
# the whole install — a failed upgrade rolls back.
if is_upgrade && [[ "${AIPBX_ASTERISK_RESTART:-yes}" == no ]] && systemctl is-active --quiet asterisk; then
    # aipbx-update found nothing that needs a restart: reload instead, calls stay up.
    timeout 30 asterisk -rx "core reload" >/dev/null 2>&1 || warn "Asterisk did not answer the reload in time"
    ok "Asterisk reloaded (no restart needed, calls kept)"
elif ! timeout 150 systemctl restart asterisk; then
    warn "Asterisk did not start on the first try — waiting for the automatic restart"
    for _ in $(seq 1 20); do
        systemctl is-active --quiet asterisk && break
        sleep 1
    done
    systemctl is-active --quiet asterisk || error "Asterisk does not start — see: journalctl -u asterisk"
    ok "Asterisk is running"
fi
systemctl enable asterisk

# Sync initial endpoints, dialplans, and transports from DB to Asterisk
info "Syncing database endpoints and dialplan to Asterisk..."
# Not silent: phones point at generated contexts (from-internal-desktop/-mobile/-web);
# if this fails they cannot call until the configuration is applied from the portal.
php /var/www/html/src/asterisk_sync.php \
    || warn "Asterisk configuration sync reported an error — open the portal and apply the pending changes"

systemctl restart aipbx-webhook-worker.service 2>/dev/null || true

ok "Asterisk configured"

# ============================================================================
