#!/usr/bin/env bash
# ============================================================================
# STEP 12: COTURN (WebRTC TURN)
# ============================================================================
step "12. Configuring coturn"

# TURN may relay only to this server. Every AiPBX call goes through Asterisk,
# so the far end of a relay is always one of this server's addresses; private
# and link-local networks are denied otherwise. TURN credentials are handed to
# anonymous website visitors (call widget), and without this a relay could
# reach other hosts of the company network (review of 2026-10-16). Loopback
# is denied by coturn itself. Public addresses stay allowed: the server's own
# public address (NAT) can be a candidate too.
TURN_ALLOWED=""
for ip in $(hostname -I); do
    TURN_ALLOWED+="allowed-peer-ip=${ip}"$'\n'
done

# The shared auth key is readable only by root and the coturn service account.
# Repair old files even when their content is unchanged (no restart needed).
for turn_config in /etc/turnserver.conf /etc/turnserver.conf.new /etc/turnserver.conf.before-readdress; do
    if [[ -e "$turn_config" || -L "$turn_config" ]]; then
        [[ -f "$turn_config" && ! -L "$turn_config" ]] || error "Unsafe coturn configuration path: $turn_config"
        chmod 0600 "$turn_config"
        chown root:turnserver "$turn_config"
        chmod 0640 "$turn_config"
    fi
done
# mktemp creates mode 0600 before any secret is written; never use a predictable
# world-readable .new file. A failed install may leave this private file behind.
TURN_CONFIG_TMP="$(mktemp /etc/turnserver.conf.XXXXXXXX)"
cat > "$TURN_CONFIG_TMP" << TURNCONF
listening-port=3478
tls-listening-port=5349
fingerprint
use-auth-secret
static-auth-secret=${TURN_SECRET}
realm=${PORTAL_DOMAIN}
server-name=${PORTAL_DOMAIN}
cert=/etc/coturn/aipbx.crt
pkey=/etc/coturn/aipbx.key
no-cli
no-multicast-peers
stale-nonce=600
log-file=/var/log/turnserver/turnserver.log
simple-log
min-port=49152
max-port=65535
# Relay on the server's own address. TURNS arrives through the 443 nginx
# multiplexer (127.0.0.1), and coturn relays on the address the client came
# in on: a loopback relay that Asterisk's media never matched, so calls that
# needed TURN had no audio.
relay-ip=${SERVER_IP}
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.0.0.0-192.0.0.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=198.18.0.0-198.19.255.255
denied-peer-ip=fc00::-fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
denied-peer-ip=fe80::-febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff
${TURN_ALLOWED}
TURNCONF
chown root:turnserver "$TURN_CONFIG_TMP"
chmod 0640 "$TURN_CONFIG_TMP"

# coturn's certificate (/etc/coturn/aipbx.*) is the copy aipbx-cert wrote in step 7.

# Let's Encrypt renews every ~60-90 days, but coturn and Asterisk read copies
# and Apache keeps the old certificate in memory: the deploy hook hands each
# renewal to aipbx-cert. Written on every install, because Let's Encrypt can
# be switched on later from the Certificates page.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/aipbx.sh << 'LEHOOK'
#!/bin/bash
# AiPBX: deploy a renewed certificate while Let's Encrypt is the active source.
exec /usr/local/sbin/aipbx-cert renewal-hook
LEHOOK
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/aipbx.sh

mkdir -p /var/log/turnserver
chown turnserver:turnserver /var/log/turnserver 2>/dev/null || true
# Restart only when the configuration changed (or coturn is down): a restart
# drops every WebRTC/app call relayed through TURN, and an update that keeps
# Asterisk running must not drop them here instead.
# coturn serves the addresses it finds at its start: the list follows the cards then.
mkdir -p /etc/systemd/system/coturn.service.d
cat > /etc/systemd/system/coturn.service.d/aipbx-addresses.conf << 'TURNDROPIN'
[Service]
ExecStartPre=-+/usr/local/sbin/aipbx-netcfg coturn-addresses
TURNDROPIN
systemctl daemon-reload
if cmp -s "$TURN_CONFIG_TMP" /etc/turnserver.conf && systemctl is-active --quiet coturn; then
    rm -f "$TURN_CONFIG_TMP"
else
    mv -f "$TURN_CONFIG_TMP" /etc/turnserver.conf
    timeout 150 systemctl restart coturn 2>/dev/null || warn "coturn did not restart in time: systemctl status coturn"
fi
systemctl enable coturn 2>/dev/null || true

ok "coturn configured"

# ============================================================================
