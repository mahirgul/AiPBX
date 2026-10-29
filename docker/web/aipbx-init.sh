#!/usr/bin/env bash
#
# The one-shot bootstrap of a container deployment (docker/README.md).
#
# install.sh owns a host: it installs packages, writes systemd units and
# configures Apache, nginx, coturn, Postfix and firewalld. Almost none of that
# applies to a split deployment, where each tier is its own image and the
# services that used to be units are containers.
#
# What does still have to happen exactly once is the part install.sh calls
# "configuration": generate the secrets, write /etc/ai-pbx.env, create the
# schema and the admin user, and let the portal generate the Asterisk
# configuration it owns. That is this script. It is idempotent — every step
# either fills a gap or does nothing — so a failed run can simply be repeated.
set -euo pipefail

SRC="${AIPBX_SRC:-/opt/aipbx}"
CONF_DIR="${AIPBX_ASTERISK_CONF_DIR:-/etc/asterisk}"
ENV_FILE="${AIPBX_ENV_FILE:-/etc/aipbx-env.d/ai-pbx.env}"
ENV_DIR="$(dirname "$ENV_FILE")"
STATE="${AIPBX_STATE_DIR:-/var/lib/aipbx}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-asterisk}"
DB_USER="${DB_USER:?}"
DB_PASS="${DB_PASS:?}"
MIG_USER="${MIGRATOR_DB_USER:?}"
MIG_PASS="${MIGRATOR_DB_PASS:?}"
ODBC_USER="${ODBC_DB_USER:?}"
ODBC_PASS="${ODBC_DB_PASS:?}"
AMI_USER="${AMI_USER:?}"
AMI_PASS="${AMI_PASS:?}"
AMI_PORT="${AMI_PORT:-5038}"
RTP_RANGE="${RTP_RANGE:-10000-10199}"
DOMAIN="${PORTAL_DOMAIN:-aipbx.local}"
SITE_NAME="${SITE_NAME:-AI PBX Portal}"
TZ_NAME="${TIMEZONE:-Europe/Istanbul}"

log() { printf 'aipbx-init: %s\n' "$*"; }

gen_hex() { php -r "echo bin2hex(random_bytes($1));"; }

sql_app() { MYSQL_PWD="$MIG_PASS" mariadb --protocol=tcp -h"$DB_HOST" -P"$DB_PORT" -u"$MIG_USER" "$DB_NAME" "$@"; }

# --- 1. the database is up --------------------------------------------------
# depends_on: service_started only, because this loop is the real wait: the
# MariaDB image is not accepting TCP connections until it has initialised its
# data directory, and on a slow disk that is longer than any healthcheck window
# worth hard-coding.
log "waiting for MariaDB at ${DB_HOST}:${DB_PORT}"
for _ in $(seq 1 60); do
    if MYSQL_PWD="${AIPBX_DB_ROOT_PASSWORD:-}" mariadb --protocol=tcp -h"$DB_HOST" -P"$DB_PORT" \
            -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        break
    fi
    sleep 3
done
if ! MYSQL_PWD="${AIPBX_DB_ROOT_PASSWORD:-}" mariadb --protocol=tcp -h"$DB_HOST" -P"$DB_PORT" \
        -uroot -e 'SELECT 1' >/dev/null 2>&1; then
    log "FATAL: root cannot reach MariaDB at ${DB_HOST}:${DB_PORT} (docker compose logs db; is AIPBX_DB_ROOT_PASSWORD the one the volume was created with?)"
    exit 1
fi
log "database is reachable"

# --- 1b. the database and its three users -----------------------------------
# The same privilege split install.sh makes on a bare host, created here rather
# than by the image's initdb hook: the hook runs only while the data directory
# is empty and is invisible when it fails, whereas this is idempotent and
# reports what it did. The accounts are '@%' because a client that reaches the
# published loopback port arrives from the docker bridge gateway, not from
# 127.0.0.1 — MariaDB would not match an '@localhost' grant. The port is
# published on loopback only, and every password is generated per deployment.
log "ensuring the database and its users exist"
MYSQL_PWD="${AIPBX_DB_ROOT_PASSWORD:-}" mariadb --protocol=tcp -h"$DB_HOST" -P"$DB_PORT" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The portal: data, never structure.
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';

-- Migrations: DDL, and never used by the running portal.
CREATE USER IF NOT EXISTS '${MIG_USER}'@'%' IDENTIFIED BY '${MIG_PASS}';
ALTER USER '${MIG_USER}'@'%' IDENTIFIED BY '${MIG_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${MIG_USER}'@'%';

-- Asterisk's ODBC user: CDR (asteriskcdr) and queue_log (asteriskqueue).
CREATE USER IF NOT EXISTS '${ODBC_USER}'@'%' IDENTIFIED BY '${ODBC_PASS}';
ALTER USER '${ODBC_USER}'@'%' IDENTIFIED BY '${ODBC_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${ODBC_USER}'@'%';

FLUSH PRIVILEGES;
SQL
log "database ${DB_NAME} and its three users are ready"

# --- 2. the environment file ------------------------------------------------
# Everything install.sh generates per deployment lives here: the passwords it
# made up, the secrets the portal signs things with, the paths it reads. It is
# written once and never rewritten — a deployment's secrets must not change
# because a container was recreated.
mkdir -p "$ENV_DIR" "$STATE" "$CONF_DIR/pbx" "$CONF_DIR/keys" \
         /var/www/faxes /var/lib/aipbx/sync-locks /var/log/aipbx
# The directory has to be reachable by the group as well as the file: it is a
# 0640 root:aipbx file inside it, and a root:root directory hides it no matter
# how the file is owned. That is not hypothetical — left root:root, the portal
# (www-data, group aipbx) cannot traverse it and answers every request with
# "Database connection failed", because the /etc/ai-pbx.env symlink leads here.
chown root:aipbx "$ENV_DIR"
chmod 0750 "$ENV_DIR"

if [[ -s "$ENV_FILE" ]]; then
    log "$ENV_FILE already exists — keeping the deployment's secrets"
else
    log "writing $ENV_FILE"
    cat > "$ENV_FILE" <<ENVFILE
# AI PBX - Environment Configuration
# Generated: $(date -u +"%Y-%m-%d %H:%M:%S UTC") by aipbx-init (docker compose)
# KEEP THIS FILE SECURE — it contains all service secrets.

# --- Database runtime user (DML: SELECT/INSERT/UPDATE/DELETE) ---
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# --- Database DDL user (Phinx migrations: CREATE/ALTER/DROP) ---
MIGRATOR_DB_USER=${MIG_USER}
MIGRATOR_DB_PASS=${MIG_PASS}

# --- Site identity ---
# Quoted: several of the project's own scripts source this file in a shell, and
# an unquoted "AI PBX Portal" is three words and an error to bash.
SITE_NAME="${SITE_NAME}"
PORTAL_DOMAIN=${DOMAIN}
TIMEZONE=${TZ_NAME}

# --- Asterisk AMI (Manager Interface) ---
AMI_HOST=127.0.0.1
AMI_PORT=${AMI_PORT}
AMI_USER=${AMI_USER}
AMI_PASS=${AMI_PASS}

# --- File/directory paths ---
ASTERISK_PBX_DIR=${CONF_DIR}/pbx
ASTERISK_CONF_DIR=${CONF_DIR}
ASTERISK_CALL_SPOOL=/var/spool/asterisk/outgoing
FAX_STORAGE_PATH=/var/www/faxes
FAX_OUTGOING_SPOOL=/var/spool/asterisk/fax/outgoing
SOUNDS_CUSTOM_DIR=/var/lib/asterisk/sounds/custom
MOH_BASE_DIR=/var/lib/asterisk/moh
MONITOR_STORAGE_PATH=/var/spool/asterisk/monitor
PJSIP_DTLS_CERT=${CONF_DIR}/keys/asterisk.pem
GS_BINARY=/usr/bin/gs
SYNC_QUEUE_LOGS_SCRIPT=/usr/local/bin/sync_queue_logs.php

# --- Mail (password reset / fax notification emails) ---
MAIL_FROM_ADDRESS=no-reply@${DOMAIN}
MAIL_FROM_NAME="${SITE_NAME}"

# --- WebRTC TURN (coturn) ---
TURN_HOST=${DOMAIN}
TURN_SECRET=$(gen_hex 32)
# Signs the mobile/chat sign-in tokens (own key, not shared with TURN).
CHAT_JWT_SECRET=$(gen_hex 32)
TURNS_PORT=443

# --- Asterisk -> MariaDB (CDR / queue_log through ODBC) ---
ODBC_DB_USER=${ODBC_USER}
ODBC_DB_PASS=${ODBC_PASS}
ENVFILE

    # Read by the portal (www-data) and by the scripts Asterisk runs (the
    # asterisk user): group aipbx, which both images carry as gid 2000.
    chown root:aipbx "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
fi
ln -sfn "$ENV_FILE" /etc/ai-pbx.env

# --- 3. keys ----------------------------------------------------------------
# WebRTC needs DTLS (keys/asterisk.pem) and the WSS listener needs the pair
# http.conf names (fullchain.pem / privkey.pem). install.sh's aipbx-cert makes
# a self-signed one on a LAN deployment and Let's Encrypt replaces it on a
# public name; there is no certificate authority here, so this is the
# self-signed path, once, and the file is left alone afterwards.
if [[ ! -s "$CONF_DIR/keys/privkey.pem" ]]; then
    log "generating a self-signed certificate for ${DOMAIN}"
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -keyout "$CONF_DIR/keys/privkey.pem" \
        -out "$CONF_DIR/keys/fullchain.pem" \
        -subj "/CN=${DOMAIN}" \
        -addext "subjectAltName=DNS:${DOMAIN}" >/dev/null 2>&1
    cat "$CONF_DIR/keys/privkey.pem" "$CONF_DIR/keys/fullchain.pem" > "$CONF_DIR/keys/asterisk.pem"
    chmod 0600 "$CONF_DIR/keys/"*.pem
    log "certificate written to $CONF_DIR/keys"
fi

# --- 3b. the AI runtime's token --------------------------------------------
# The portal and aipbx_ai authenticate to each other with this file (the
# assistant, the "AI apps" dialplan hooks and the model downloads all use it).
# install.sh's aipbx-ai-setup generates it on a bare host.
mkdir -p /etc/aipbx
if [[ ! -s /etc/aipbx/ai.token ]]; then
    gen_hex 32 > /etc/aipbx/ai.token
    log "generated /etc/aipbx/ai.token"
fi
chown root:aipbx /etc/aipbx /etc/aipbx/ai.token
chmod 0750 /etc/aipbx
chmod 0640 /etc/aipbx/ai.token

# --- 4. AMI -----------------------------------------------------------------
# The portal drives Asterisk over AMI with these credentials; install.sh writes
# the same file. Only loopback is allowed in, which is where the portal runs.
if [[ ! -s "$CONF_DIR/manager.conf" ]]; then
    cat > "$CONF_DIR/manager.conf" <<MANAGER
[general]
enabled = yes
bindaddr = 127.0.0.1
port = ${AMI_PORT}

[${AMI_USER}]
secret = ${AMI_PASS}
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = originate,call,agent
write = originate,call,agent
writetimeout = 5000
MANAGER
    log "wrote manager.conf"
fi
chown root:aipbx "$CONF_DIR/manager.conf"
chmod 0640 "$CONF_DIR/manager.conf"

# --- 5. schema --------------------------------------------------------------
# Phinx owns the schema and reads its credentials from the same env file, so
# this is the project's own migration path, not a private copy of it.
log "running database migrations"
( cd "$SRC/web" && php vendor/bin/phinx migrate -e production --no-interaction 2>&1 | \
    sed 's/^/    /' )

log "loading seed data"
sql_app < "$SRC/db/seed.sql"

# Plugin schemas, the same call install.sh makes after migrating.
( cd "$SRC/web" && php -r "
require '$SRC/web/config.php';
if (is_file('$SRC/web/src/core/PluginManager.php')) {
    require_once '$SRC/web/src/core/PluginManager.php';
    PluginManager::syncAllEnabledPluginSchemas();
}" 2>&1 | sed 's/^/    /' ) || log "WARNING: a plugin schema sync reported an issue"

# --- 6. admin user ----------------------------------------------------------
# install.sh generates the password and prints it once; here it comes from
# .env when set, otherwise it is generated, printed, and kept on the state
# volume (the portal stores a hash, so this is the only copy).
ADMIN_USER_EXISTS="$(sql_app -sN -e "SELECT COUNT(*) FROM sys_users WHERE username='admin';" 2>/dev/null || echo 0)"
if [[ "${ADMIN_USER_EXISTS:-0}" -eq 0 ]]; then
    ADMIN_PASS="${ADMIN_PASSWORD:-}"
    if [[ -z "$ADMIN_PASS" ]]; then
        ADMIN_PASS="$(gen_hex 8)"
        printf 'admin username: admin\nadmin password: %s\n' "$ADMIN_PASS" > "$STATE/credentials.txt"
        chmod 0600 "$STATE/credentials.txt"
    fi
    ADMIN_HASH="$(printf '%s' "$ADMIN_PASS" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);')"
    ADMIN_SIP="${ADMIN_SIP_PASSWORD:-$(gen_hex 8)}"
    MYSQL_PWD="$MIG_PASS" mariadb --protocol=tcp -h"$DB_HOST" -P"$DB_PORT" -u"$MIG_USER" "$DB_NAME" <<SQL
INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, role, is_active, can_listen_recordings, can_view_all_cdrs, can_view_queue_monitor)
VALUES ('admin', '${ADMIN_HASH}', 'Administrator', '1000', '${ADMIN_SIP}', 'admin', 1, 1, 1, 1);
SQL
    log "admin user created (password: $([[ -f $STATE/credentials.txt ]] && echo "in $STATE/credentials.txt" || echo 'from AIPBX_ADMIN_PASSWORD'))"
else
    log "admin user already exists — left untouched"
fi

# --- 7. the RTP window ------------------------------------------------------
# http.conf and the dialplan read Asterisk's range from rtp.conf, which the
# portal regenerates from sys_settings. Setting both sides here means the range
# compose asks for is the range Asterisk actually opens.
RTP_START="${RTP_RANGE%-*}"
RTP_END="${RTP_RANGE#*-}"
if [[ "$RTP_START" =~ ^[0-9]+$ && "$RTP_END" =~ ^[0-9]+$ && "$RTP_END" -gt "$RTP_START" ]]; then
    sql_app -e "
        INSERT INTO sys_settings (setting_key, setting_value) VALUES
            ('rtp_start', '${RTP_START}'), ('rtp_end', '${RTP_END}')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);" 2>/dev/null \
        || log "WARNING: could not store the RTP range in sys_settings"
    log "RTP window ${RTP_START}-${RTP_END}"
fi

# --- 8. the Asterisk configuration the portal owns --------------------------
# extensions, pjsip endpoints/trunks/transports, queues, music on hold, rtp.conf
# are generated from the database by asterisk_sync.php — but that function
# reloads Asterisk after each file and rolls back when the reload fails, so it
# cannot run before the daemon exists. The `cfg` service in compose.yaml does
# it once Asterisk is healthy (docker/web/aipbx-config.sh). Asterisk starts with
# the seed files in the meantime, which is a valid PBX, just an empty one.
log "Asterisk configuration will be generated by the cfg service once Asterisk is up"

# One volume, two writers (see docker/asterisk/entrypoint.sh).
chown -R root:aipbx "$CONF_DIR" 2>/dev/null || true
chmod 2775 "$CONF_DIR/pbx" 2>/dev/null || true
find "$CONF_DIR/pbx" -maxdepth 1 -type f -exec chmod 0664 {} + 2>/dev/null || true

touch "$STATE/.installed"
log "done — Asterisk can start; the portal is on port ${HTTP_PORT:-80}"
