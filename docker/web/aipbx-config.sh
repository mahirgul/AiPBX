#!/usr/bin/env bash
#
# Generates the Asterisk configuration the portal owns (docker/README.md).
#
# This runs as its own one-shot service, after Asterisk is healthy, and that
# ordering is the point: the portal's Sync* classes write a file and *reload
# Asterisk* to check it, rolling back if the reload fails. Run before a daemon
# exists, every reload fails and the first file rolls back — which is exactly
# what happened when this was the last step of aipbx-init.
#
# Afterwards this is the portal's job: every Apply button calls the same
# functions. This exists so a fresh deployment has a dialplan, transports and
# endpoint templates from the first second, instead of an empty Asterisk that
# fills in only when an administrator happens to press something.
set -euo pipefail

SRC="${AIPBX_SRC:-/opt/aipbx}"
ENV_FILE="${AIPBX_ENV_FILE:-/etc/aipbx-env.d/ai-pbx.env}"

log() { printf 'aipbx-config: %s\n' "$*"; }

ln -sfn "$ENV_FILE" /etc/ai-pbx.env

# Belt and braces: compose already waits for the healthcheck, but a restart of
# this one-shot service on its own should still wait rather than fail.
for _ in $(seq 1 30); do
    if asterisk -rx 'core show uptime' >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
if ! asterisk -rx 'core show uptime' >/dev/null 2>&1; then
    log "Asterisk is not answering its CLI — the portal will generate the configuration on its first Apply"
    exit 0
fi

log "generating the Asterisk configuration from the database"
if ( cd "$SRC/web" && php src/asterisk_sync.php 2>&1 | tail -8 | sed 's/^/    /' ); then
    log "configuration generated and reloaded"
else
    log "WARNING: asterisk_sync reported an issue (docker compose logs asterisk for the reload it refused)"
    exit 0
fi

# One volume, two writers: the files just written are root's, and the Asterisk
# user has to read them (docker/asterisk/entrypoint.sh does the same).
chown -R root:aipbx /etc/asterisk 2>/dev/null || true
chmod 2775 /etc/asterisk/pbx 2>/dev/null || true
find /etc/asterisk/pbx -maxdepth 1 -type f -exec chmod 0664 {} + 2>/dev/null || true

log "done"
