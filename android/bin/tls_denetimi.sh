#!/bin/bash
# Check that keeps TLS bypasses from coming back.
# Google Play rejected the app on 2026-09-05 for "Unsafe Implementation of
# WebView SSL Error Handler"; there were two more bypasses of the same kind.
set -u
KOK="$(cd "$(dirname "$0")/.." && pwd)"
HATA=0

kontrol() {
    local desen="$1" aciklama="$2" bulgu
    bulgu=$(grep -rnE "$desen" "$KOK/app/src/main/java" "$KOK/app/src/main/AndroidManifest.xml" 2>/dev/null)
    if [ -n "$bulgu" ]; then
        echo "IHLAL: $aciklama"
        echo "$bulgu" | sed 's/^/    /'
        HATA=1
    fi
}

kontrol 'handler\?*\.proceed\(\)'                   "onReceivedSslError icinde proceed() (Play reddi)"
kontrol 'checkServerTrusted[^{]*\{ *\}'             "bos checkServerTrusted (trust-all TrustManager)"
kontrol 'hostnameVerifier *\{ *_, *_ *-> *true'     "her hostu kabul eden hostnameVerifier"
kontrol 'usesCleartextTraffic="true"'               "manifestte cleartext trafik acik"
kontrol 'allowUniversalAccessFromFileURLs *= *true' "WebView allowUniversalAccessFromFileURLs"

[ "$HATA" -eq 0 ] && echo "TEMIZ - bilinen TLS baypasi yok."
exit "$HATA"
