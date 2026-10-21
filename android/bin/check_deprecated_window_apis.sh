#!/bin/bash
# Fails when the BUILT app (our code AND every library in it) calls a window API
# that Android 15 deprecated. Google Play scans the bundle the same way and shows
# "Your app uses deprecated APIs or parameters for edge-to-edge" — a source-code
# check (edge_to_edge_audit.sh) cannot see calls made inside libraries, e.g.
# androidx enableEdgeToEdge() or Material dialogs.
#
#   bin/check_deprecated_window_apis.sh app/build/outputs/apk/release/app-release.apk [mapping.txt]
#
# The mapping file (R8) turns obfuscated class names back into real ones.
set -euo pipefail

APK="${1:?usage: $0 <apk> [mapping.txt]}"
MAPPING="${2:-}"
SDK="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-/opt/android-sdk}}"
DEXDUMP="$(ls -d "$SDK"/build-tools/*/ 2>/dev/null | sort -V | tail -1)dexdump"
[[ -x "$DEXDUMP" ]] || { echo "dexdump not found under $SDK/build-tools" >&2; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -q -o "$APK" 'classes*.dex' -d "$WORK"

# Window/WindowCompat methods deprecated in Android 15 (API 35).
DEPRECATED='Landroid/view/Window;[.](setStatusBarColor|getStatusBarColor|setNavigationBarColor|getNavigationBarColor|setNavigationBarDividerColor|getNavigationBarDividerColor|setStatusBarContrastEnforced|setNavigationBarContrastEnforced|setDecorFitsSystemWindows)|Landroidx/core/view/WindowCompat;[.](setDecorFitsSystemWindows|enableEdgeToEdge)'

hits="$(for dex in "$WORK"/classes*.dex; do "$DEXDUMP" -d "$dex" 2>/dev/null; done | awk -v re="$DEPRECATED" '
    /^  Class descriptor/ { cls = $4; gsub(/\x27/, "", cls); sub(/^L/, "", cls); sub(/;$/, "", cls); gsub(/\//, ".", cls) }
    /^      name +:/      { m = $3; gsub(/\x27/, "", m) }
    $0 ~ "invoke-" && match($0, re) { print cls "." m " -> " substr($0, RSTART, RLENGTH) }
    # Writing the cutout mode: SHORT_EDGES / DEFAULT are deprecated in Android 15.
    $0 ~ "iput" && match($0, /WindowManager\$LayoutParams;[.]layoutInDisplayCutoutMode/) { print cls "." m " -> sets " substr($0, RSTART, RLENGTH) }
' | sort -u)"

if [[ -z "$hits" ]]; then
    echo "OK: no Android 15-deprecated window API is called in $(basename "$APK")."
    exit 0
fi

if [[ -n "$MAPPING" && -f "$MAPPING" ]]; then
    # "real.Class -> a.b:" lines of the R8 mapping: obfuscated -> real class name.
    hits="$(awk 'NR==FNR { if ($0 !~ /^ / && $2 == "->") { o=$3; sub(/:$/, "", o); map[o]=$1 }; next }
                 { split($1, p, "."); c=$1; sub(/\.[^.]+$/, "", c); r=(c in map) ? map[c] : c; print r " (" $1 ") " $2 " " $3 }' "$MAPPING" - <<<"$hits")"
fi

echo "ERROR: the built app calls window APIs deprecated in Android 15 — Google Play will flag them:" >&2
echo "$hits" | sed 's/^/  /' >&2
echo "Remove the call, or the library/feature that brings it in (see android/app/src/main/java/com/mhrgl/aipbx/ui/BaseActivity.kt)." >&2
exit 1
