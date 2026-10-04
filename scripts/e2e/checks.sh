#!/bin/bash
# AiPBX end-to-end checks, run as root inside a freshly installed or upgraded
# test machine (see scripts/e2e/run.sh). They exercise the installed system the
# way a user does — through the portal over HTTPS, Asterisk and the helpers —
# so problems that only appear on a real Ubuntu install (Apache sandbox, sudo,
# file permissions, sound paths, firewall) show up here.
#
# Exit code: 0 = all checks passed.

set -uo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

PASS=0
FAIL=0
pass() { echo "PASS  $1"; PASS=$((PASS + 1)); }
fail() { echo "FAIL  $1${2:+ — $2}"; FAIL=$((FAIL + 1)); }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then pass "$name"; else fail "$name"; fi; }

INSTALL_DIR="${AIPBX_INSTALL_DIR:-/opt/aipbx}"
DOMAIN="$(sed -n 's/^PORTAL_DOMAIN=//p' /etc/ai-pbx.env | tail -n 1)"
URL="https://$DOMAIN"
CURL=(curl -sk --max-time 30 --resolve "$DOMAIN:443:127.0.0.1")
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

# The installer restarts Asterisk at the end; wait until it has fully booted.
asterisk -rx "core waitfullybooted" >/dev/null 2>&1 || true

echo "== AiPBX e2e checks — $(git -C "$INSTALL_DIR" log --oneline -1 2>/dev/null) on $DOMAIN"

# --- Services -----------------------------------------------------------------
for s in asterisk mariadb nginx apache2 coturn aipbx-chat fail2ban firewalld; do
    check "service $s is running" systemctl is-active --quiet "$s"
done

# --- Portal login -------------------------------------------------------------
# A known admin password (the generated one is only in the credentials file).
E2E_PASS='E2e-Check-2026!'
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$E2E_PASS")"
mysql asterisk -e "UPDATE sys_users SET password_hash='${HASH}', must_reset_password=0 WHERE username='admin'"
mysql asterisk -e "UPDATE sys_users SET two_factor_enabled=0 WHERE username='admin'" 2>/dev/null || true
mysql asterisk -e "DELETE FROM sys_login_logs" 2>/dev/null || true

login_page="$("${CURL[@]}" -c "$JAR" -b "$JAR" "$URL/login")"
csrf="$(grep -o 'name="csrf_token" value="[^"]*"' <<<"$login_page" | head -n 1 | sed 's/.*value="//; s/"$//')"
captcha="$(grep -oE '[0-9]+ \+ [0-9]+ = \?' <<<"$login_page" | head -n 1)"
answer=$(( $(awk '{print $1}' <<<"$captcha") + $(awk '{print $3}' <<<"$captcha") ))
"${CURL[@]}" -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "csrf_token=$csrf" \
    --data-urlencode "username=admin" --data-urlencode "password=$E2E_PASS" \
    --data-urlencode "captcha_answer=$answer" "$URL/login"
dash="$("${CURL[@]}" -c "$JAR" -b "$JAR" -w '\n%{http_code} %{url_effective}' "$URL/dashboard")"
if tail -n 1 <<<"$dash" | grep -q '^200 .*/dashboard$' && grep -q 'dashLive' <<<"$dash"; then
    pass "portal login as admin, dashboard renders"
else
    fail "portal login as admin, dashboard renders" "$(tail -n 1 <<<"$dash")"
fi
APP_CSRF="$(grep -o 'window.CSRF_TOKEN = "[^"]*"' <<<"$dash" | head -n 1 | sed 's/.*= "//; s/"$//')"

live="$("${CURL[@]}" -b "$JAR" "$URL/api/dashboard_live.php")"
check "dashboard live API answers" grep -q '"success":true' <<<"$live"

# --- Save through the portal and Apply ----------------------------------------
# Writes /etc/asterisk/pbx from inside Apache's systemd sandbox.
"${CURL[@]}" -b "$JAR" -o /dev/null --data-urlencode "csrf_token=$APP_CSRF" \
    -d save_trunk=1 -d trunk_name=e2etrunk -d title=E2E -d ip_address=192.0.2.55 -d port=5060 \
    -d transport=udp -d codecs=alaw,ulaw -d qualify_frequency=60 -d connection_mode=ip -d is_active=1 \
    "$URL/trunks"
check "trunk saved from the portal" mysql -Nse "SELECT 1 FROM asterisk.pbx_trunks WHERE trunk_name='e2etrunk'"
apply="$("${CURL[@]}" -b "$JAR" -H 'Content-Type: application/json' -d "{\"csrf_token\":\"$APP_CSRF\"}" \
    "$URL/api/pending_sync.php?action=apply")"
if grep -q '"success":true' <<<"$apply"; then pass "Apply succeeds"; else fail "Apply succeeds" "${apply:0:200}"; fi
check "Apply wrote the trunk config" grep -q '^\[e2etrunk\]' /etc/asterisk/pbx/pjsip_trunks.conf
check "Asterisk loaded the new trunk" bash -c 'asterisk -rx "pjsip show endpoint e2etrunk" | grep -q "Endpoint:  *e2etrunk"'
check "nothing left pending after Apply" test "$(mysql -Nse 'SELECT COUNT(*) FROM asterisk.sys_pending_sync')" = 0

# --- Root helper through Apache (sudo inside the sandbox) ---------------------
f2b="$("${CURL[@]}" -b "$JAR" "$URL/fail2ban")"
check "portal reaches root helper (fail2ban page lists jails)" grep -q 'aipbx-web' <<<"$f2b"

# --- Certificates (aipbx-cert through systemd-run, outside Apache's sandbox) ---
served_fp() {   # fingerprint of the certificate Apache serves through the 443 multiplexer
    # ALPN http/1.1 goes to Apache; without ALPN the multiplexer would hand it to coturn.
    echo | openssl s_client -connect 127.0.0.1:443 -servername "$DOMAIN" -alpn http/1.1 2>/dev/null \
        | openssl x509 -noout -fingerprint -sha256 2>/dev/null
}
file_fp() { openssl x509 -noout -fingerprint -sha256 -in "$1" 2>/dev/null; }
copies_match() {
    local a; a="$(file_fp /etc/ssl/aipbx/active.crt)"
    [ -n "$a" ] && [ "$a" = "$(file_fp /etc/coturn/aipbx.crt)" ] \
        && [ "$a" = "$(file_fp /etc/asterisk/keys/fullchain.pem)" ] && [ "$a" = "$(served_fp)" ]
}
check "fresh install: certificate mode recorded" grep -qE '^(selfsigned|letsencrypt)$' /etc/ssl/aipbx/mode
check "Apache, coturn and Asterisk use the active certificate" copies_match
certs_page="$("${CURL[@]}" -b "$JAR" "$URL/certificates")"
check "certificates page renders" grep -q 'fa-certificate' <<<"$certs_page"

PKI="$(mktemp -d)"
openssl req -x509 -nodes -newkey rsa:2048 -days 30 -subj "/CN=E2E Test CA" \
    -addext "basicConstraints=critical,CA:TRUE" -keyout "$PKI/ca.key" -out "$PKI/ca.crt" 2>/dev/null
openssl req -nodes -newkey rsa:2048 -subj "/CN=$DOMAIN" -keyout "$PKI/leaf.key" -out "$PKI/leaf.csr" 2>/dev/null
openssl x509 -req -in "$PKI/leaf.csr" -CA "$PKI/ca.crt" -CAkey "$PKI/ca.key" -days 30 \
    -extfile <(printf 'subjectAltName=DNS:%s\nbasicConstraints=CA:FALSE\n' "$DOMAIN") -out "$PKI/leaf.crt" 2>/dev/null
"${CURL[@]}" -b "$JAR" -o /dev/null -F "csrf_token=$APP_CSRF" -F action=upload \
    -F "cert=@$PKI/leaf.crt" -F "chain=@$PKI/ca.crt" -F "key=@$PKI/leaf.key" "$URL/certificates"
sleep 2
check "uploaded certificate becomes active" grep -qx custom /etc/ssl/aipbx/mode
check "uploaded certificate is served and copied to coturn and Asterisk" \
    bash -c "[ \"\$(openssl x509 -noout -fingerprint -sha256 -in $PKI/leaf.crt)\" = \"\$(openssl x509 -noout -fingerprint -sha256 -in /etc/ssl/aipbx/active.crt)\" ]"
check "after upload all services use the same certificate" copies_match
check "upload stage is cleaned up" bash -c '! ls /var/lib/aipbx/cert-stage/*.pem 2>/dev/null | grep -q .'

# A key that does not belong to the certificate must be refused by the portal.
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$PKI/other.key" 2>/dev/null
"${CURL[@]}" -b "$JAR" -o /dev/null -F "csrf_token=$APP_CSRF" -F action=upload \
    -F "cert=@$PKI/leaf.crt" -F "key=@$PKI/other.key" "$URL/certificates"
check "mismatched key is refused, active certificate unchanged" \
    bash -c "[ \"\$(openssl x509 -noout -fingerprint -sha256 -in $PKI/leaf.crt)\" = \"\$(openssl x509 -noout -fingerprint -sha256 -in /etc/ssl/aipbx/active.crt)\" ]"

"${CURL[@]}" -b "$JAR" -o /dev/null --data-urlencode "csrf_token=$APP_CSRF" -d action=selfsigned "$URL/certificates"
sleep 2
check "switch back to self-signed" grep -qx selfsigned /etc/ssl/aipbx/mode
check "after switching back all services use the same certificate" copies_match
rm -rf "$PKI"

# --- Sounds -------------------------------------------------------------------
E2E_DP=/etc/asterisk/pbx/extensions_zz_e2e.conf
cat > "$E2E_DP" <<'EOF'
[aipbx-e2e]
exten => 1,1,Answer()
 same => n,Playback(custom/welcome)
 same => n,Log(NOTICE,E2E custom=${PLAYBACKSTATUS})
 same => n,Set(CHANNEL(language)=tr)
 same => n,Playback(vm-intro)
 same => n,Log(NOTICE,E2E tr=${PLAYBACKSTATUS})
 same => n,Hangup()
EOF
asterisk -rx "dialplan reload" >/dev/null
# One retry: a single call right after a restart can end early without a reason.
for attempt in 1 2; do
    mark="$(wc -l < /var/log/asterisk/messages.log)"
    asterisk -rx "channel originate Local/1@aipbx-e2e application Wait 30" >/dev/null
    for _ in $(seq 1 30); do tail -n +"$mark" /var/log/asterisk/messages.log | grep -q 'E2E tr=' && break; sleep 1; done
    tail -n +"$mark" /var/log/asterisk/messages.log | grep -q 'E2E tr=SUCCESS' && break
done
check "Asterisk plays an uploaded (custom) sound" bash -c "grep 'E2E custom=' /var/log/asterisk/messages.log | tail -n 1 | grep -q SUCCESS"
check "Asterisk plays a Turkish prompt" bash -c "grep 'E2E tr=' /var/log/asterisk/messages.log | tail -n 1 | grep -q SUCCESS"
rm -f "$E2E_DP"; asterisk -rx "dialplan reload" >/dev/null

# --- Turkish prompts and Cloud TTS ---------------------------------------------
# Every prompt listed in README-tts.txt is installed once, in the shipped WAV
# (an older .gsm of the same name would win on format cost).
tr_bad=""
while IFS='|' read -r name _; do
    n=$(compgen -G "/var/lib/asterisk/sounds/tr/$name.*" | wc -l)
    [[ "$n" -eq 1 && -f "/var/lib/asterisk/sounds/tr/$name.wav" ]] || tr_bad+=" $name($n)"
done < <(grep -E '^[A-Za-z0-9_/-]+\|' "$INSTALL_DIR/sounds/tr/README-tts.txt")
if [[ -z "$tr_bad" ]]; then pass "Turkish prompts installed (one WAV each)"; else fail "Turkish prompts installed (one WAV each)" "${tr_bad:0:200}"; fi
check "clients are given TURNS on 443" grep -q "^TURNS_PORT=443$" /etc/ai-pbx.env
check "Asterisk finds a Turkish prompt" bash -c 'asterisk -rx "core show file formats" >/dev/null && test -r /usr/share/asterisk/sounds/tr/vm-intro.wav'
tts_page="$("${CURL[@]}" -c "$JAR" -b "$JAR" -w '\n%{http_code}' "$URL/ai-tts?tab=providers")"
check "Cloud TTS page renders" bash -c '[[ "$(tail -n 1 <<<"$1")" == 200 ]] && grep -q "save_provider" <<<"$1"' _ "$tts_page"

# --- Security and maintenance --------------------------------------------------
check "Asterisk WSS 8089 listens on loopback only" bash -c '! ss -ltn | grep -E "(0\.0\.0\.0|\*|\[::\]):8089 "'
check "port 8089 closed in the firewall" bash -c '! firewall-cmd --query-port=8089/tcp'
check "portal fail2ban overrides read last (zz-ai-pbx.local)" test -f /etc/fail2ban/jail.d/zz-ai-pbx.local
check "daily backup runs" /usr/local/sbin/aipbx-backup
check "recording converter runs" /usr/local/bin/recordings_to_mp3.php
check "database migrations applied" bash -c "cd $INSTALL_DIR/web && out=\$(php vendor/bin/phinx status -e production 2>&1) && ! grep -q ' down ' <<<\"\$out\""

# --- Clean up -------------------------------------------------------------------
mysql asterisk -e "DELETE FROM pbx_trunks WHERE trunk_name='e2etrunk'"

echo "== $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
