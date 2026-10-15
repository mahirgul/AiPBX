#!/bin/bash
# Check that keeps TLS bypasses from coming back.
# Google Play rejected the app on 2026-09-05 for "Unsafe Implementation of
# WebView SSL Error Handler"; there were two more bypasses of the same kind.
# Run: android/bin/tls_audit.sh (exit 1 when one is found).
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAILED=0

check() {
    local pattern="$1" description="$2" found
    found=$(grep -rnE "$pattern" "$ROOT/app/src/main/java" "$ROOT/app/src/main/AndroidManifest.xml" 2>/dev/null)
    if [ -n "$found" ]; then
        echo "VIOLATION: $description"
        echo "$found" | sed 's/^/    /'
        FAILED=1
    fi
}

check 'handler\?*\.proceed\(\)'                   "proceed() inside onReceivedSslError (rejected by Play)"
check 'checkServerTrusted[^{]*\{ *\}'             "empty checkServerTrusted (trust-all TrustManager)"
check 'hostnameVerifier *\{ *_, *_ *-> *true'     "hostnameVerifier that accepts every host"
check 'usesCleartextTraffic="true"'               "cleartext traffic allowed in the manifest"
check 'allowUniversalAccessFromFileURLs *= *true' "WebView allowUniversalAccessFromFileURLs"

[ "$FAILED" -eq 0 ] && echo "CLEAN - no known TLS bypass."
exit "$FAILED"
