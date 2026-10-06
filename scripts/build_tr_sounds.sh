#!/bin/bash
# Builds the Turkish Asterisk core sounds package from the 16 kHz masters.
#
#   scripts/build_tr_sounds.sh MASTER_DIR VERSION
#
# MASTER_DIR holds <prompt>.wav masters (16 kHz 16-bit mono) made by
# web/bin/generate_tr_sounds.php. The non-speech tones are taken from the
# installed English set (asterisk-core-sounds-en-wav) and silence/1..10 are
# generated. Every prompt is written in the formats Asterisk reads natively:
#   wav (8 kHz), ulaw, alaw, gsm   narrowband
#   g722, sln16                    wideband (HD)
# Output at the repo root, one package per format like Asterisk's own
# asterisk-core-sounds-<lang>-<format>-<version> packages:
#   asterisk-core-sounds-tr-<format>-VERSION.tar.xz   (flat; extract into sounds/tr)
#   asterisk-core-sounds-tr-VERSION.SHA256SUMS
# Packages of older versions are removed. Only the SHA256SUMS file is
# committed; the .tar.xz files are git-ignored and published as assets of the
# GitHub release sounds-tr-VERSION, where install.sh downloads them from
# (see docs/sounds.md).

set -euo pipefail

MASTER="${1:?usage: build_tr_sounds.sh MASTER_DIR VERSION}"
VERSION="${2:?usage: build_tr_sounds.sh MASTER_DIR VERSION}"
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
EN_SOUNDS="${EN_SOUNDS:-/usr/share/asterisk/sounds/en}"
TONES="ascending-2tone beep beeperr confbridge-join confbridge-leave descending-2tone tt-monkeys"
FORMATS="wav ulaw alaw gsm g722 sln16"
SUMS="asterisk-core-sounds-tr-$VERSION.SHA256SUMS"

for tool in sox ffmpeg xz; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 1; }
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
SRC="$WORK/src"
PKG="$WORK/pkg"
mkdir -p "$SRC" "$PKG"

# Masters: speech from MASTER_DIR, tones from the English set, generated silence.
cp -a "$MASTER/." "$SRC/"
for t in $TONES; do
    [[ -f "$EN_SOUNDS/$t.wav" ]] || { echo "missing tone $EN_SOUNDS/$t.wav" >&2; exit 1; }
    sox "$EN_SOUNDS/$t.wav" -r 16000 -c 1 -b 16 "$SRC/$t.wav"
done
mkdir -p "$SRC/silence"
for n in 1 2 3 4 5 6 7 8 9 10; do
    sox -n -r 16000 -c 1 -b 16 "$SRC/silence/$n.wav" trim 0 "$n"
done

# Every prompt in the text list must have a master.
missing=0
while IFS='|' read -r name _; do
    [[ -f "$SRC/$name.wav" ]] || { echo "missing master: $name" >&2; missing=1; }
done < <(grep -E '^[A-Za-z0-9_/-]+\|' "$REPO/sounds/core-sounds-tr.txt")
[[ $missing -eq 0 ]] || exit 1

convert_one() {
    local rel="$1" src="$2" pkg="$3" base
    base="$pkg/${rel%.wav}"
    mkdir -p "$(dirname "$base")"
    sox "$src/$rel" -r 8000 -c 1 -b 16 "$base.wav"
    sox "$src/$rel" -r 8000 -c 1 -t ul "$base.ulaw"
    sox "$src/$rel" -r 8000 -c 1 -t al "$base.alaw"
    sox "$src/$rel" -r 8000 -c 1 -t gsm "$base.gsm"
    sox "$src/$rel" -r 16000 -c 1 -b 16 -e signed-integer -t raw "$base.sln16"
    ffmpeg -loglevel error -y -i "$src/$rel" -ar 16000 -ac 1 -c:a g722 -f g722 "$base.g722"
}
export -f convert_one
(cd "$SRC" && find . -type f -name '*.wav' -printf '%P\n') \
    | xargs -P "$(nproc)" -I{} bash -c 'convert_one "$1" "$2" "$3"' _ {} "$SRC" "$PKG"

cp "$REPO/sounds/core-sounds-tr.txt" "$PKG/core-sounds-tr.txt"
cat > "$PKG/LICENSE-asterisk-core-tr-$VERSION" <<EOF
Turkish Asterisk core sounds — AiPBX (asterisk-core-sounds-tr $VERSION)

The Turkish speech prompts were generated with Google Cloud Text-to-Speech
(voice tr-TR-Wavenet-C) for the AiPBX project and are released under the
Creative Commons Attribution-ShareAlike 4.0 International license
(CC BY-SA 4.0): https://creativecommons.org/licenses/by-sa/4.0/

The non-speech tones (ascending-2tone, beep, beeperr, confbridge-join,
confbridge-leave, descending-2tone, tt-monkeys) come from the Asterisk
core sounds (asterisk-core-sounds-en), Copyright Digium, Inc., licensed
under CC BY-SA 3.0.

Source and prompt texts: https://github.com/mahirgul/AiPBX
EOF
cat > "$PKG/CHANGES-asterisk-core-tr-$VERSION" <<EOF
asterisk-core-sounds-tr $VERSION
  - Every prompt of asterisk-core-sounds-en plus voicemail and AiPBX prompts
    in Turkish, one voice (Google Wavenet tr-TR-Wavenet-C).
  - Formats: wav (8 kHz), ulaw, alaw, gsm, g722 and sln16 (16 kHz).
EOF

rm -f "$REPO"/asterisk-core-sounds-tr-*.tar.* "$REPO"/asterisk-core-sounds-tr-*.SHA256SUMS
DOCS="core-sounds-tr.txt LICENSE-asterisk-core-tr-$VERSION CHANGES-asterisk-core-tr-$VERSION"
for fmt in $FORMATS; do
    out="asterisk-core-sounds-tr-$fmt-$VERSION.tar.xz"
    (cd "$PKG" && { find . -type f -name "*.$fmt" -printf '%P\n' | sort; tr ' ' '\n' <<<"$DOCS"; } \
        | tar --owner=0 --group=0 -cf - -T - | xz -9 -T0 > "$REPO/$out")
    echo "$out: $(du -h "$REPO/$out" | cut -f1)"
done
(cd "$REPO" && sha256sum asterisk-core-sounds-tr-*-"$VERSION".tar.xz > "$SUMS")
echo "$(find "$PKG" -type f -name '*.wav' | wc -l) prompts × ${FORMATS// /, }"
