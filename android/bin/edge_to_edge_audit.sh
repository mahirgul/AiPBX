#!/bin/bash
# Check that keeps Android 15+ edge-to-edge violations and deprecations from coming back.
# Google Play Console rejects or warns on deprecated APIs (setDecorFitsSystemWindows,
# bar colors, SystemBarStyle custom parameters) or missing edge-to-edge on API 35+.
# Run: android/bin/edge_to_edge_audit.sh (exit 1 when any violation is found).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAILED=0

check_pattern() {
    local pattern="$1" description="$2" found
    found=$(grep -rnE "$pattern" "$ROOT/app/src/main/java" 2>/dev/null | grep -vE ':[0-9]+:[[:space:]]*(\*|//|/\*)' || true)
    if [ -n "$found" ]; then
        echo "VIOLATION: $description"
        echo "$found" | sed 's/^/    /'
        FAILED=1
    fi
}

check_res() {
    local pattern="$1" description="$2" found
    found=$(grep -rnE "$pattern" "$ROOT/app/src/main/res" 2>/dev/null || true)
    if [ -n "$found" ]; then
        echo "VIOLATION: $description"
        echo "$found" | sed 's/^/    /'
        FAILED=1
    fi
}

echo "=== Running Android 15 Edge-to-Edge Architectural Audit ==="

# Check forbidden deprecated APIs
check_pattern 'setDecorFitsSystemWindows' "Deprecated Window.setDecorFitsSystemWindows / WindowCompat call"
check_pattern 'setStatusBarColor|setNavigationBarColor' "Deprecated Window bar color call"
check_pattern 'SystemBarStyle\.(dark|light)\(' "Deprecated/custom SystemBarStyle factory call"
check_pattern 'FLAG_LAYOUT_NO_LIMITS' "Legacy FLAG_LAYOUT_NO_LIMITS window flag"
check_pattern 'enableEdgeToEdge|EdgeToEdge\.enable' "androidx enableEdgeToEdge (its Android 8-14 code uses deprecated bar APIs; see BaseActivity)"
check_pattern 'setStatusBarContrastEnforced|setNavigationBarContrastEnforced|LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES' "API deprecated in Android 15"

# Check themes
check_res 'windowOptOutEdgeToEdgeEnforcement' "Opt-out from edge-to-edge in XML theme"
check_res 'android:(statusBarColor|navigationBarColor)' "System bar colors defined in XML theme"

# Check that every UI activity extends BaseActivity
MANIFEST="$ROOT/app/src/main/AndroidManifest.xml"
ACTIVITIES=$(grep -oE 'android:name="\.ui\.[A-Za-z0-9_]+"' "$MANIFEST" | sed 's/android:name="\.ui\.//;s/"//')
for act in $ACTIVITIES; do
    ACT_FILE="$ROOT/app/src/main/java/com/mhrgl/aipbx/ui/${act}.kt"
    if [ ! -f "$ACT_FILE" ]; then
        echo "VIOLATION: Activity declared in manifest not found: $ACT_FILE"
        FAILED=1
    else
        if ! grep -qE "class ${act}\s*:\s*BaseActivity" "$ACT_FILE"; then
            echo "VIOLATION: Activity ${act} does not extend BaseActivity"
            FAILED=1
        fi
    fi
done

if [ "$FAILED" -eq 0 ]; then
    echo "CLEAN - 100% Android 15+ edge-to-edge compliant across all activities and themes."
else
    echo "FAILED - Edge-to-edge architectural violations detected!"
fi

exit "$FAILED"
