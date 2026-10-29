#!/usr/bin/env bash
#
# Asterisk container start-up (docker/README.md).
#
# Two jobs, and nothing else:
#
#  1. Make the shared /etc/asterisk volume usable — seed the files the repo
#     ships (the same list install.sh copies), and render the two values that
#     are secrets rather than configuration: the ODBC password and odbc.ini.
#  2. Run Asterisk in the foreground, as the asterisk user, with the group the
#     portal also belongs to so `asterisk -rx` from a different container
#     reaches this process through the socket in the shared run directory.
#
# Nothing is generated from the database here. The portal's Sync* classes own
# every file under pbx/ and rewrite them on Apply; this script must never
# overwrite them, which is why it only seeds what is missing.
set -euo pipefail

CONF_DIR="${AIPBX_ASTERISK_CONF_DIR:-/etc/asterisk}"
SEED=/opt/aipbx-seed/asterisk
ENV_DIR="${AIPBX_ENV_FILE:-/etc/aipbx-env.d/ai-pbx.env}"
ENV_FILE="$ENV_DIR"
STATE="${AIPBX_STATE_DIR:-/var/lib/aipbx}"

log() { printf 'aipbx-asterisk: %s\n' "$*"; }

# The portal reads the same file at /etc/ai-pbx.env. Nothing in the image can
# hardlink it there (the file is created after the image), so the symlink is
# made at start-up in every container that needs the application's view.
ln -sfn "$ENV_FILE" /etc/ai-pbx.env

if [[ ! -r "$ENV_FILE" ]]; then
    log "waiting for $ENV_FILE (the init service writes it)"
    for _ in $(seq 1 60); do
        [[ -r "$ENV_FILE" ]] && break
        sleep 2
    done
fi
if [[ ! -r "$ENV_FILE" ]]; then
    log "FATAL: $ENV_FILE never appeared — the init service failed (docker compose logs init)"
    exit 1
fi

env_get() {   # read one value without sourcing the file (it holds values with spaces)
    grep -m1 "^$1=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true
}

mkdir -p "$CONF_DIR" "$CONF_DIR/pbx" "$CONF_DIR/keys" /var/run/asterisk \
         /var/lib/asterisk/sounds/custom /var/lib/asterisk/moh \
         /var/spool/asterisk/{monitor,outgoing,fax/outgoing,voicemail} \
         /var/www/faxes "$STATE"

# --- the seed ---------------------------------------------------------------
# Exactly the files install.sh copies, for the same reason: they are the base
# configuration the portal then edits through its own pages. `pbx/*.conf` is
# seeded file-by-file and never overwritten, because the portal generates it
# and a fresh copy would wipe the deployment's extensions, hints and members.
# These are the files the *repository* owns, and the package ships its own
# versions of most of them: Debian's default pjsip.conf and extensions.conf
# were what Asterisk loaded when this only copied missing files. They are
# installed once — a marker on the volume records that — and left alone
# afterwards, because rtp.conf, http.conf and the rest are then written by the
# portal, and a restart must not undo the deployment's settings.
if [[ ! -f "$CONF_DIR/.aipbx-seeded" ]]; then
    for f in extensions.conf pjsip.conf pjsip_notify.conf queues.conf musiconhold.conf \
             http.conf rtp.conf hep.conf modules.conf cdr.conf res_odbc.conf \
             cdr_adaptive_odbc.conf extconfig.conf; do
        if [[ -f "$SEED/$f" ]]; then
            cp "$SEED/$f" "$CONF_DIR/$f"
            log "seeded $f"
        fi
    done
fi
# Files the gallery does not ship are still worth filling in on a start where
# the marker exists but the volume was only partly populated (a half-finished
# first boot).
for f in extensions.conf pjsip.conf pjsip_notify.conf queues.conf musiconhold.conf \
         http.conf rtp.conf hep.conf modules.conf cdr.conf res_odbc.conf \
         cdr_adaptive_odbc.conf extconfig.conf; do
    if [[ -f "$SEED/$f" && ! -e "$CONF_DIR/$f" ]]; then
        cp "$SEED/$f" "$CONF_DIR/$f"
        log "seeded missing $f"
    fi
done
for f in "$SEED"/pbx/*.conf; do
    [[ -e "$f" ]] || continue
    target="$CONF_DIR/pbx/$(basename "$f")"
    if [[ ! -e "$target" ]]; then
        cp "$f" "$target"
    fi
done

# --- the secrets the seed leaves as placeholders ----------------------------
ODBC_USER="$(env_get ODBC_DB_USER)"
ODBC_PASS="$(env_get ODBC_DB_PASS)"
DB_NAME="$(env_get DB_NAME)"
DB_PORT="$(env_get DB_PORT)"
DB_HOST="$(env_get DB_HOST)"

if [[ -z "$ODBC_USER" || -z "$ODBC_PASS" ]]; then
    log "FATAL: ODBC_DB_USER/ODBC_DB_PASS missing from $ENV_FILE"
    exit 1
fi

# res_odbc.conf ships with ${ODBC_PASS} so no secret is in the repo (the same
# placeholder install.sh replaces). Re-render it on every start: the password
# can be rotated in .env and a restart should pick it up.
if [[ -f "$CONF_DIR/res_odbc.conf" ]]; then
    escaped="$(printf '%s\n' "$ODBC_PASS" | sed 's/[&/\]/\\&/g')"
    sed -i "s/\${ODBC_PASS}/${escaped}/" "$CONF_DIR/res_odbc.conf"
    chown root:aipbx "$CONF_DIR/res_odbc.conf" 2>/dev/null || true
    chmod 0640 "$CONF_DIR/res_odbc.conf"
fi

# ODBC data source "asterisk" (install.sh writes the same on a bare host, with
# the socket instead of the host because MariaDB was local there; here the
# database is its own container, published on loopback).
cat > /etc/odbc.ini <<ODBCINI
[asterisk]
Description = AI PBX MariaDB (Asterisk CDR / queue_log)
Driver      = MariaDB Unicode
Server      = ${DB_HOST:-127.0.0.1}
Port        = ${DB_PORT:-3306}
Database    = ${DB_NAME:-asterisk}
User        = ${ODBC_USER}
Password    = ${ODBC_PASS}
Charset     = utf8mb4
ODBCINI
chmod 0640 /etc/odbc.ini
chown root:aipbx /etc/odbc.ini 2>/dev/null || true

# --- ownership --------------------------------------------------------------
# One volume, two writers: the portal (www-data, group aipbx) writes pbx/ and
# rtp.conf, Asterisk reads them and writes the runtime directories. Group 2000
# is "aipbx" in every image, so the modes mean the same thing on both sides.
chown -R root:aipbx "$CONF_DIR"
chmod 0775 "$CONF_DIR"
chmod 2775 "$CONF_DIR/pbx" 2>/dev/null || true
find "$CONF_DIR/pbx" -maxdepth 1 -type f -exec chmod 0664 {} + 2>/dev/null || true
# Keys and the two files holding the AMI/ODBC secrets stay closed.
touch "$CONF_DIR/.aipbx-seeded"

for secret in manager.conf res_odbc.conf keys; do
    [[ -e "$CONF_DIR/$secret" ]] || continue
    chown -R root:aipbx "$CONF_DIR/$secret" 2>/dev/null || true
    chmod 0750 "$CONF_DIR/$secret" 2>/dev/null || true
done
[[ -f "$CONF_DIR/manager.conf" ]] && chmod 0640 "$CONF_DIR/manager.conf"

chown -R asterisk:aipbx /var/run/asterisk /var/lib/asterisk /var/spool/asterisk /var/www/faxes 2>/dev/null || true
chmod 0770 /var/run/asterisk
chmod 2775 /var/www/faxes

log "starting Asterisk (host networking; SIP 5060, RTP from rtp.conf)"
exec "$@" -f -U asterisk -G aipbx -C /etc/asterisk/asterisk.conf
