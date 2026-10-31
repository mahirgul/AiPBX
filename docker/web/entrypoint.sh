#!/usr/bin/env bash
#
# Portal container start-up (docker/README.md).
#
# Three things before Apache: put the environment file where the application
# looks for it, render the vhost for the configured port (host networking means
# there is no port mapping to do it for us), and make sure the document root is
# the copy of the code the image carries. Then Apache runs in the foreground.
set -euo pipefail

ENV_FILE="${AIPBX_ENV_FILE:-/etc/aipbx-env.d/ai-pbx.env}"
SRC="${AIPBX_SRC:-/opt/aipbx}"
HTTP_PORT="${HTTP_PORT:-80}"
DOMAIN="${PORTAL_DOMAIN:-aipbx.local}"

log() { printf 'aipbx-web: %s\n' "$*"; }

ln -sfn "$ENV_FILE" /etc/ai-pbx.env

# The document root is a symlink so the code stays where install.sh puts it and
# the vhost keeps the path the routing rules above are written against. The
# apache2 package ships /var/www/html with its "It works!" page, so an existing
# *directory* has to be replaced — only a symlink to the code is left alone.
if [[ -L /var/www/html && "$(readlink -f /var/www/html)" == "$(readlink -f "$SRC/web")" ]]; then
    :
else
    rm -rf /var/www/html
    ln -sfn "$SRC/web" /var/www/html
    log "linked /var/www/html → $SRC/web"
fi

# Written straight into sites-enabled: the build disables 000-default, and a
# file in sites-available that nobody enabled serves the package's default page
# instead of the portal (which is exactly what happened the first time).
sed -e "s/__AIPBX_HTTP_PORT__/${HTTP_PORT}/g" \
    -e "s/__AIPBX_DOMAIN__/${DOMAIN}/g" \
    /etc/apache2/aipbx-vhost.conf.template > /etc/apache2/sites-enabled/000-aipbx.conf
printf 'Listen %s\n' "$HTTP_PORT" > /etc/apache2/ports.conf

# Uploads, recordings and faxes the portal writes: owned by www-data, group
# aipbx, so the same files are readable by the Asterisk container.
mkdir -p /var/www/faxes "$AIPBX_STATE_DIR" /var/lib/aipbx/sync-locks /var/log/aipbx
chown -R www-data:aipbx /var/www/faxes "$AIPBX_STATE_DIR" /var/lib/aipbx/sync-locks /var/log/aipbx 2>/dev/null || true
chmod 2775 /var/www/faxes "$AIPBX_STATE_DIR" /var/lib/aipbx/sync-locks 2>/dev/null || true

if [[ ! -r "$ENV_FILE" ]]; then
    log "WARNING: $ENV_FILE is not readable yet; the portal will serve errors until init has run"
fi

log "Apache on port $HTTP_PORT (portal ${DOMAIN})"
exec "$@"
