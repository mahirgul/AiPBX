#!/bin/bash
# Real call-flow checks (run as root inside the e2e container, after checks.sh).
#
# Unit tests check the generated dialplan; this checks what Asterisk really
# dials. A tiny SIP "carrier" on 127.0.0.1:5099 records the Request-URI of every
# INVITE and answers 486 Busy. A test trunk + catch-all route point at it, and
# calls are started from the per-device contexts (from-internal-<device>-1) the
# way phones, the mobile app and the web phone enter the dialplan.
#
#   bash call_flows.sh            (INSTALL_DIR=/opt/aipbx by default)
#
# Leaves the system as it found it (rules, settings, routes, trunk).
set -uo pipefail
INSTALL_DIR="${INSTALL_DIR:-/opt/aipbx}"
PASS=0; FAIL=0
pass() { echo "PASS  $1"; PASS=$((PASS + 1)); }
fail() { echo "FAIL  $1${2:+ — $2}"; FAIL=$((FAIL + 1)); }
db() { mysql -N asterisk -e "$1"; }
WORK="$(mktemp -d)"; SINK_LOG="$WORK/invites.log"; touch "$SINK_LOG"

# --- SIP sink --------------------------------------------------------------------
cat > "$WORK/sink.py" <<'PY'
import socket, sys
log = open(sys.argv[1], "a", buffering=1)
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.bind(("127.0.0.1", 5099))
while True:
    data, addr = s.recvfrom(65535)
    msg = data.decode("utf-8", "replace")
    lines = msg.split("\r\n")
    method = lines[0].split(" ")[0]
    if method not in ("INVITE", "OPTIONS"):
        continue  # ACK, BYE ...: no answer needed
    if method == "INVITE":
        uri = lines[0].split(" ")[1]
        log.write(uri.split(":", 1)[1].split("@")[0] + "\n")
    keep = [l for l in lines[1:] if l.split(":", 1)[0].lower() in ("via", "from", "to", "call-id", "cseq")]
    keep = [l + ";tag=e2esink" if l.lower().startswith("to:") and "tag=" not in l else l for l in keep]
    status = "486 Busy Here" if method == "INVITE" else "200 OK"
    s.sendto(("SIP/2.0 " + status + "\r\n" + "\r\n".join(keep) + "\r\nContent-Length: 0\r\n\r\n").encode(), addr)
PY
# --- remember state ------------------------------------------------------------
db "SELECT setting_value FROM sys_settings WHERE setting_key='dialplan_rules_enabled'" > "$WORK/enabled.txt"
mysqldump --no-create-info --skip-triggers asterisk pbx_dialplan_rules > "$WORK/rules.sql" 2>/dev/null
db "SELECT id FROM pbx_outbound_routes WHERE is_active = 1" > "$WORK/active_routes.txt"
SINK_PID=""

cleanup() {
    [[ -n "$SINK_PID" ]] && kill "$SINK_PID" 2>/dev/null
    db "DELETE FROM pbx_outbound_routes WHERE route_name LIKE 'E2E call flows%'"
    db "DELETE FROM pbx_trunks WHERE trunk_name = 'e2esink'"
    while read -r id; do [[ -n "$id" ]] && db "UPDATE pbx_outbound_routes SET is_active = 1 WHERE id = $id"; done < "$WORK/active_routes.txt"
    db "DELETE FROM pbx_dialplan_rules"; mysql asterisk < "$WORK/rules.sql"
    if [[ -s "$WORK/enabled.txt" ]]; then
        db "UPDATE sys_settings SET setting_value = '$(cat "$WORK/enabled.txt")' WHERE setting_key = 'dialplan_rules_enabled'"
    else
        db "DELETE FROM sys_settings WHERE setting_key = 'dialplan_rules_enabled'"
    fi
    php "$INSTALL_DIR/web/src/asterisk_sync.php" >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

python3 "$WORK/sink.py" "$SINK_LOG" & SINK_PID=$!
for _ in $(seq 1 20); do ss -lun | grep -q '127.0.0.1:5099 ' && break; sleep 0.2; done
ss -lun | grep -q '127.0.0.1:5099 ' || { echo "FAIL  SIP sink could not listen on 127.0.0.1:5099"; exit 1; }

db "UPDATE pbx_outbound_routes SET is_active = 0"   # only the test route may match
db "INSERT INTO pbx_trunks (trunk_name, title, ip_address, port, transport, connection_mode, codecs, is_active)
    VALUES ('e2esink', 'E2E sink', '127.0.0.1', 5099, 'udp', 'ip', 'alaw,ulaw', 1)"
db "INSERT INTO pbx_outbound_routes (route_name, match_pattern, prepend, append, strip_front, strip_back, trunks_json, is_internal, route_group, is_active, sort_order)
    VALUES ('E2E call flows', '_X.', '', '', 0, 0, '[{\"trunk_name\":\"e2esink\"}]', 0, 1, 1, 1)"

load_rules() {   # $1 = country code, '' = no rules
    php -r '
        require getenv("INSTALL_DIR") . "/web/config.php";
        require_once getenv("INSTALL_DIR") . "/web/src/services/DialplanRuleService.php";
        $db = getDB(); $db->beginTransaction(); $db->exec("DELETE FROM pbx_dialplan_rules");
        $st = $db->prepare("INSERT INTO pbx_dialplan_rules (client_type, rule_name, match_pattern, strip_front, prepend, strip_back, append, sort_order, is_active, description) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach (DialplanRuleService::getDefaultPresets($argv[1]) as $d) { $st->execute($d); }
        $db->commit();
    ' -- "$1"
}
set_enabled() { db "INSERT INTO sys_settings (setting_key, setting_value) VALUES ('dialplan_rules_enabled', '$1') ON DUPLICATE KEY UPDATE setting_value = '$1'"; }
apply() { php "$INSTALL_DIR/web/src/asterisk_sync.php" >/dev/null 2>&1; sleep 1; }

# dial <device> <number>: start a call in that device's context, print what reached the carrier
dial() {
    local before; before=$(wc -l < "$SINK_LOG")
    asterisk -rx "channel originate Local/$2@from-internal-$1-1 application Wait 5" >/dev/null 2>&1
    for _ in $(seq 1 30); do
        [[ $(wc -l < "$SINK_LOG") -gt $before ]] && break
        sleep 0.2
    done
    if [[ $(wc -l < "$SINK_LOG") -gt $before ]]; then sed -n "$((before + 1))p" "$SINK_LOG"; else echo "(nothing)"; fi
    sleep 0.3   # let a late retransmission land before the next case
}
expect() {   # expect <device> <dialled> <expected at the carrier> <label>
    local got; got="$(dial "$1" "$2")"
    if [[ "$got" == "$3" ]]; then pass "$4: $1 $2 -> $3"; else fail "$4: $1 $2 -> $3" "carrier got $got"; fi
}

export INSTALL_DIR
asterisk -rx "core show version" >/dev/null 2>&1 || { echo "Asterisk is not running"; exit 1; }

# 1. No rules (fresh installation): numbers go out as dialled
load_rules ''; set_enabled 1; apply
trunk_ok=0
for _ in $(seq 1 20); do   # the PJSIP reload finishes after the sync returns
    asterisk -rx 'pjsip show endpoint e2esink' 2>/dev/null | grep -q 'Endpoint:  *e2esink ' && { trunk_ok=1; break; }
    sleep 0.5
done
[[ $trunk_ok -eq 1 ]] && pass "test trunk loaded" || fail "test trunk loaded"
expect mobile 05321234567 05321234567 "no rules"
expect desktop 0049301234567 0049301234567 "no rules"

# 2. Turkey presets
load_rules 90; apply
expect mobile +905321234567 05321234567 "TR presets"
expect mobile 5321234567 05321234567 "TR presets"
expect mobile +12125551234 0012125551234 "TR presets"
expect web +905321234567 05321234567 "TR presets"
expect web 5321234567 05321234567 "TR presets"
expect desktop +905321234567 05321234567 "TR presets"
expect desktop 0049301234567 0049301234567 "TR presets: international from a desk phone is not touched"
expect desktop 00049301234567 0049301234567 "TR presets: outside-line 0 before international"
expect desktop 02121234567 02121234567 "TR presets: national stays"
expect mobile 05321234567 05321234567 "TR presets: national stays"

# 3. Germany presets (another country code length)
load_rules 49; apply
expect mobile +49301234567 0301234567 "DE presets"
expect mobile +905321234567 00905321234567 "DE presets: foreign E.164 -> 00"
expect mobile 5321234567 5321234567 "DE presets: no Turkey-only rule"

# 4. Switched off: rules kept, not used
load_rules 90; set_enabled 0; apply
expect mobile 5321234567 5321234567 "switched off"
expect desktop 00049301234567 00049301234567 "switched off"

# 5. Other dialling plans: US (national 1, international 011), Italy (no national prefix)
set_enabled 1; load_rules 1; apply
expect mobile +12125551234 12125551234 "US presets"
expect mobile +442071234567 011442071234567 "US presets: international 011"
load_rules 39; apply
expect mobile +390612345678 0612345678 "IT presets: no national prefix"

# 6. Route-level transformation: a more specific route strips 1 digit and prepends 00
db "INSERT INTO pbx_outbound_routes (route_name, match_pattern, prepend, append, strip_front, strip_back, trunks_json, is_internal, route_group, is_active, sort_order)
    VALUES ('E2E call flows 7', '_7X.', '00', '', 1, 0, '[{\"trunk_name\":\"e2esink\"}]', 0, 1, 1, 2)"
load_rules ''; apply
expect mobile 74930123456 004930123456 "route strip 1 + prepend 00 (most specific route wins)"

# 7. + numbers without normalization reach a + route as dialled
db "INSERT INTO pbx_outbound_routes (route_name, match_pattern, prepend, append, strip_front, strip_back, trunks_json, is_internal, route_group, is_active, sort_order)
    VALUES ('E2E call flows plus', '_+X.', '', '', 0, 0, '[{\"trunk_name\":\"e2esink\"}]', 0, 1, 1, 3)"
load_rules 90; set_enabled 0; apply
expect mobile +905321234567 +905321234567 "switched off: + route gets E.164"
set_enabled 1

# 8. Internal numbers never reach the carrier
apply
ext="$(db "SELECT extension FROM sys_users WHERE is_active = 1 AND extension <> '' AND extension_type = 'sip' ORDER BY id LIMIT 1")"
if [[ -n "$ext" ]]; then
    got="$(dial mobile "$ext")"
    [[ "$got" == "(nothing)" ]] && pass "internal extension $ext stays internal" || fail "internal extension $ext stays internal" "carrier got $got"
fi

echo "== $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
