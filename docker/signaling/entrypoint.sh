#!/usr/bin/env bash
# The daemon reads /etc/ai-pbx.env (signaling/aipbx_signaling/db.py, ENV_FILE),
# which lives on the shared configuration volume. Nothing in the image can link
# it there at build time, so it is done here.
set -euo pipefail
ENV_FILE="${AIPBX_ENV_FILE:-/etc/aipbx-env.d/ai-pbx.env}"
ln -sfn "$ENV_FILE" /etc/ai-pbx.env
exec "$@"
