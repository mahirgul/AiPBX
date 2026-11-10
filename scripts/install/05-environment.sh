#!/usr/bin/env bash
# ============================================================================
# STEP 5: ENVIRONMENT FILE
# ============================================================================
step "5. Creating Environment Configuration"

if is_upgrade; then
    # The environment file belongs to the installation (admins edit it):
    # only keys added by newer releases are appended.
    env_ensure ODBC_DB_PASS "$ODBC_PASS"
    # Own key for the mobile/chat sign-in tokens (they were signed with
    # TURN_SECRET). Added once: every app signs in again after this upgrade.
    if [[ -z "$(env_get CHAT_JWT_SECRET)" ]]; then
        sed -i '/^CHAT_JWT_SECRET=$/d' /etc/ai-pbx.env
        echo "CHAT_JWT_SECRET=$(gen_hex 32)" >> /etc/ai-pbx.env
        ok "CHAT_JWT_SECRET added (mobile apps and chat sign in again)"
    fi
    # Older installs sent clients coturn's own port; TURNS goes through the
    # 443 multiplexer now, which also passes networks that only allow 443.
    if [[ "$(env_get TURNS_PORT)" == 5349 ]]; then
        sed -i 's/^TURNS_PORT=5349$/TURNS_PORT=443/' /etc/ai-pbx.env
        ok "TURNS_PORT 5349 → 443 (TURNS through the 443 multiplexer)"
    fi

    # P1.3: Separate DDL migrator credentials from runtime env file
    if [[ ! -f /etc/ai-pbx-migrator.env ]]; then
        cat > /etc/ai-pbx-migrator.env << MIGRATORENV
# AI PBX - Database Migrator / DDL Configuration
# Restricted to root — runtime web and asterisk processes must NEVER read this file.
MIGRATOR_DB_USER=${MIGRATOR_USER}
MIGRATOR_DB_PASS=${MIGRATOR_PASS}
MIGRATORENV
        chmod 0600 /etc/ai-pbx-migrator.env
        chown root:root /etc/ai-pbx-migrator.env
        ok "Migrator credentials moved to /etc/ai-pbx-migrator.env (0600 root:root)"
    else
        chmod 0600 /etc/ai-pbx-migrator.env 2>/dev/null || true
        chown root:root /etc/ai-pbx-migrator.env 2>/dev/null || true
    fi
    if grep -q '^MIGRATOR_DB_' /etc/ai-pbx.env; then
        sed -i '/^MIGRATOR_DB_USER=/d; /^MIGRATOR_DB_PASS=/d' /etc/ai-pbx.env
        ok "Scrubbed DDL credentials from runtime /etc/ai-pbx.env"
    fi

    ok "Environment file kept (missing keys added)"
else
# The portal starts on the server's own zone, so both agree; PBX Settings changes both.
SERVER_TIMEZONE="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
[[ "$SERVER_TIMEZONE" =~ ^[A-Za-z0-9_+/-]+$ ]] || SERVER_TIMEZONE="$(cat /etc/timezone 2>/dev/null || true)"
[[ "$SERVER_TIMEZONE" =~ ^[A-Za-z0-9_+/-]+$ ]] || SERVER_TIMEZONE=UTC

# P1.3: Migrator credentials live in dedicated root-only file
cat > /etc/ai-pbx-migrator.env << MIGRATORENV
# AI PBX - Database Migrator / DDL Configuration
# Restricted to root — runtime web and asterisk processes must NEVER read this file.
MIGRATOR_DB_USER=${MIGRATOR_USER}
MIGRATOR_DB_PASS=${MIGRATOR_PASS}
MIGRATORENV
chmod 0600 /etc/ai-pbx-migrator.env
chown root:root /etc/ai-pbx-migrator.env

cat > /etc/ai-pbx.env << ENVFILE
# AI PBX - Environment Configuration
# Generated: $(date -u +"%Y-%m-%d %H:%M:%S UTC")
# KEEP THIS FILE SECURE — it contains all service secrets.
# You can update values here or via the Admin Panel (Settings → System).

# --- Database runtime user (DML: SELECT/INSERT/UPDATE/DELETE) ---
DB_HOST=localhost
DB_NAME=asterisk
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# --- Site identity ---
SITE_NAME=AI PBX Portal
PORTAL_DOMAIN=${PORTAL_DOMAIN}
TIMEZONE=${SERVER_TIMEZONE}

# --- Asterisk AMI (Manager Interface) ---
AMI_HOST=127.0.0.1
AMI_PORT=5038
AMI_USER=${AMI_USER}
AMI_PASS=${AMI_PASS}

# --- File/directory paths ---
ASTERISK_PBX_DIR=/etc/asterisk/pbx
ASTERISK_CALL_SPOOL=/var/spool/asterisk/outgoing
FAX_STORAGE_PATH=/var/www/faxes
FAX_OUTGOING_SPOOL=/var/spool/asterisk/fax/outgoing
SOUNDS_CUSTOM_DIR=/var/lib/asterisk/sounds/custom
MOH_BASE_DIR=/var/lib/asterisk/moh
MONITOR_STORAGE_PATH=/var/spool/asterisk/monitor
PJSIP_DTLS_CERT=/etc/asterisk/keys/asterisk.pem
GS_BINARY=/usr/bin/gs
SYNC_QUEUE_LOGS_SCRIPT=/usr/local/bin/sync_queue_logs.php

# --- Mail (password reset / fax notification emails) ---
MAIL_FROM_ADDRESS=no-reply@${PORTAL_DOMAIN}
MAIL_FROM_NAME=AI PBX Portal

# --- WebRTC TURN (coturn) ---
TURN_HOST=${PORTAL_DOMAIN}
TURN_SECRET=${TURN_SECRET}
# Signs the mobile/chat sign-in tokens (own key, not shared with TURN).
CHAT_JWT_SECRET=$(gen_hex 32)
# Port given to clients for turns:. coturn listens on 5349; nginx on 443 hands
# TURNS (no ALPN / "stun.turn") to it, so calls work where only 443 is open.
TURNS_PORT=443

# --- Asterisk -> MariaDB (CDR / queue_log through ODBC) ---
ODBC_DB_PASS=${ODBC_PASS}
ENVFILE
fi   # install mode

# Secrets are read by the portal (www-data) AND by the scripts Asterisk runs
# as the asterisk user (feature codes, push wake-up, fax). With root:www-data
# the asterisk-side scripts could not reach the database at all.
# Group "asterisk": Asterisk is started with -G asterisk, which drops every
# supplementary group, so only its primary group works for System() children;
# Prepare group access before any portal CLI runs, including the bundled-plugin
# transition. runuser starts a new process with the updated supplementary groups;
# Apache will pick them up when it is restarted later in the installation.
chown root:asterisk /etc/ai-pbx.env
chmod 640 /etc/ai-pbx.env
usermod -aG asterisk www-data
runuser -u www-data -- php -r '$f = @fopen("/etc/ai-pbx.env", "rb"); if ($f === false) { exit(1); } fclose($f);' \
    || error "The portal user cannot read /etc/ai-pbx.env; refusing to continue"

ok "Environment file created: /etc/ai-pbx.env"
