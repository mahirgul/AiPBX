#!/usr/bin/env bash
#
# Writes every credential the compose stack reads, in the scopes it reads them
# in (docker/README.md). Run once, before the first `docker compose up`.
#
# Why three files and not one: compose hands a service exactly the variables in
# its env_file, so the split is what stops a secret reaching a container that has
# no use for it. The database container gets the root password because it has to
# accept it; the one-shot init gets the root password *and* the accounts it
# creates; the four long-running tiers get neither, because they read the same
# values from AIPBX_ENV_FILE, which init writes at 0640 root:aipbx.
#
#   .env                    settings — names, ports, domain, timezone. No secrets;
#                           compose interpolates ${AIPBX_*} from it.
#   docker/secrets/db.env   the MariaDB root password, for `db` only.
#   docker/secrets/init.env the root password, the three users, AMI and the first
#                           admin, for `init` only.
#
# Because of that split no credential is written into compose.yaml, so a
# checkout of this repository contains none — only the placeholders in
# .env.example. The files are 0600, the directory 0700, and .gitignore keeps
# them out of git.
#
#   ./docker/gen-secrets.sh              # writes them if they do not exist
#   ./docker/gen-secrets.sh --force      # replaces them (and orphans the existing
#                                       # database volume: read the warning below)
#
# --force does NOT rotate the passwords inside an existing deployment: the
# MariaDB users already have the old ones and the environment file on the
# aipbx-conf volume keeps them too. To rotate, change the value in
# docker/secrets/*.env *and* ALTER USER in MariaDB. See docker/README.md,
# "Secrets and rotation".
set -euo pipefail

cd "$(dirname "$0")/.."
SECRETS=docker/secrets

if [[ -s .env && -s "$SECRETS/db.env" && -s "$SECRETS/init.env" && "${1:-}" != "--force" ]]; then
    echo "secrets already exist — leaving them alone (use --force to replace them)" >&2
    exit 0
fi

hex() { openssl rand -hex "${1:-16}"; }

umask 077
mkdir -p "$SECRETS"
chmod 0700 "$SECRETS"

# One root password: the database container is told about it, and init uses the
# same value to create the database. Two different values would lock init out.
DB_ROOT_PASSWORD="$(hex 24)"

cat > "$SECRETS/db.env" <<ENV
# AiPBX docker deployment — generated $(date -u +"%Y-%m-%dT%H:%M:%SZ")
# Read by the \`db\` service only. Never commit this file.
MARIADB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
ENV

cat > "$SECRETS/init.env" <<ENV
# AiPBX docker deployment — generated $(date -u +"%Y-%m-%dT%H:%M:%SZ")
# Read by the \`init\` service only: it creates these accounts in MariaDB and
# writes them to /etc/aipbx.env for the tiers that read that file.
# Never commit this file.

# --- MariaDB (root creates the database and the three users below) ---
AIPBX_DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}

# --- The three least-privileged users ---
# Runtime portal: data, never structure.
DB_PASS=$(hex 24)
# Phinx migrations: DDL, never used by the running portal.
MIGRATOR_DB_PASS=$(hex 24)
# Asterisk's ODBC user: CDR and queue_log.
ODBC_DB_PASS=$(hex 24)

# --- Asterisk AMI -----------------------------------------------------------
AMI_PASS=$(hex 24)

# --- First admin ------------------------------------------------------------
# Empty: aipbx-init generates one and writes /var/lib/aipbx/credentials.txt.
# Set both to choose them yourself.
ADMIN_PASSWORD=
ADMIN_SIP_PASSWORD=
ENV

cat > .env <<ENV
# AiPBX docker deployment — generated $(date -u +"%Y-%m-%dT%H:%M:%SZ")
# Settings only. The credentials are in docker/secrets/*.env (see above);
# keeping them out of this file is what keeps them out of compose.yaml.
# Never commit this file.

# --- Database (the MariaDB container creates these users on first start) ---
AIPBX_DB_NAME=asterisk
AIPBX_DB_USER=aipbx
AIPBX_DB_MIGRATOR_USER=aipbx_migrator
AIPBX_DB_ODBC_USER=asterisk_odbc

# --- Asterisk AMI -----------------------------------------------------------
AIPBX_AMI_USER=aipbx
AIPBX_AMI_PORT=5038

# --- Deployment identity ----------------------------------------------------
# A .local name is the LAN default the installer uses; a public FQDN needs the
# 443 edge, which this stack does not carry yet (docker/README.md).
AIPBX_DOMAIN=aipbx.local
AIPBX_SITE_NAME=AI PBX Portal
AIPBX_TIMEZONE=Europe/Istanbul

# --- Ports ------------------------------------------------------------------
# Host networking: these are the ports the containers bind on this machine,
# not a mapping. Change them if something else holds them.
AIPBX_HTTP_PORT=80
AIPBX_DB_PORT=3306
# The RTP window Asterisk opens. On host networking it is not proxied, so it can
# be as wide as the number of concurrent calls needs.
AIPBX_RTP_RANGE=10000-10199
ENV

chmod 0600 .env "$SECRETS/db.env" "$SECRETS/init.env"

echo "wrote .env, $SECRETS/db.env and $SECRETS/init.env (0600, directory 0700)"
echo "next: docker compose up -d --build"
