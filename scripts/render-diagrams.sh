#!/usr/bin/env bash
# Renders docs/diagrams/*.mmd (Mermaid) to docs/img/<name>.svg.
#
# The SVGs are committed: GitHub, the portal's Help page and aipbx.bid/docs
# show them as they are, with no JavaScript and no internet access. Edit the
# .mmd source, run this script, commit both. Needs Node (npx) and Chrome or
# Chromium; a developer tool, never run on a server.
#
#   scripts/render-diagrams.sh            all diagrams
#   scripts/render-diagrams.sh signaling  one diagram
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC="$ROOT/docs/diagrams"
OUT="$ROOT/docs/img"
MMDC_VERSION=11.4.2

CHROME=${PUPPETEER_EXECUTABLE_PATH:-}
if [ -z "$CHROME" ]; then
    for c in google-chrome chromium chromium-browser; do
        if command -v "$c" >/dev/null; then CHROME=$(command -v "$c"); break; fi
    done
fi
[ -n "$CHROME" ] || { echo "Chrome or Chromium is needed (or set PUPPETEER_EXECUTABLE_PATH)." >&2; exit 1; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
printf '{"executablePath":"%s","args":["--no-sandbox"]}\n' "$CHROME" > "$TMP/puppeteer.json"

names=("$@")
if [ ${#names[@]} -eq 0 ]; then
    for f in "$SRC"/*.mmd; do
        n=$(basename "$f" .mmd)
        [ "${n:0:1}" = "_" ] || names+=("$n")
    done
fi

for n in "${names[@]}"; do
    [ -f "$SRC/$n.mmd" ] || { echo "No such diagram: $n" >&2; exit 1; }
    # "%% @classes" pulls in the shared colours, so every diagram looks the same.
    sed -e "/^%% @classes$/{r $SRC/_classes.mmd" -e 'd}' "$SRC/$n.mmd" > "$TMP/$n.mmd"
    PUPPETEER_SKIP_DOWNLOAD=1 npx -y "@mermaid-js/mermaid-cli@$MMDC_VERSION" -q \
        -i "$TMP/$n.mmd" -o "$OUT/$n.svg" -c "$SRC/mermaid-config.json" \
        -p "$TMP/puppeteer.json" -b white -I "diagram-$n"
    echo "docs/img/$n.svg"
done
