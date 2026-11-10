#!/usr/bin/env bash
# ============================================================================
# STEP 13: SECURITY (FIREWALLD & FAIL2BAN)
# ============================================================================
# Time sync: CDRs, TLS, chat tokens and TOTP (30 s window) all need a correct
# clock. Ubuntu's default chrony sources are NTS-only; where TCP 4460 (NTS-KE)
# is blocked the clock never synchronised and drifted by minutes. Add plain
# NTP as a fallback and let chrony select it (NTS stays authenticated when
# reachable).
if [[ -d /etc/chrony ]]; then
    mkdir -p /etc/chrony/sources.d /etc/chrony/conf.d
    cat > /etc/chrony/sources.d/aipbx-fallback.sources << 'NTPSRC'
# AI PBX: plain NTP fallback for networks that block NTS-KE (TCP 4460)
pool pool.ntp.org iburst maxsources 4
NTPSRC
    cat > /etc/chrony/conf.d/aipbx.conf << 'NTPCONF'
# AI PBX: with NTS unreachable, "mix"/"prefer" never select plain NTP sources
authselectmode ignore
NTPCONF
    # aipbx-ntp-server.conf (allow lines for the phones) is written by
    # aipbx-priv netsvc ntp-on when Network services -> NTP server is on.
    systemctl restart chrony 2>/dev/null || true
fi

step "13. Configuring Firewall (firewalld) & Fail2ban"

# 13a. Firewalld configuration
# The portal's Firewall page manages firewalld. Ubuntu also ships ufw; with
# both enabled one closes what the other opens. We preserve UFW until firewalld
# is running and verified with active SSH access to prevent lockouts or fail-open windows.
WAS_UFW_ACTIVE=0
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    WAS_UFW_ACTIVE=1
fi

command -v firewall-cmd >/dev/null 2>&1 || error "firewalld is not installed; refusing to leave system without a firewall"

systemctl enable firewalld 2>/dev/null || error "Failed to enable firewalld service"
systemctl start firewalld 2>/dev/null || {
    if [[ "$WAS_UFW_ACTIVE" -eq 1 ]]; then
        warn "firewalld failed to start; preserving active ufw"
        ufw enable >/dev/null 2>&1 || true
    fi
    error "Could not start firewalld service; preserving existing firewall state"
}

# Verify firewalld daemon is responsive
if ! firewall-cmd --state >/dev/null 2>&1; then
    if [[ "$WAS_UFW_ACTIVE" -eq 1 ]]; then
        warn "firewalld is not responsive; restoring active ufw"
        ufw enable >/dev/null 2>&1 || true
    fi
    error "firewalld is running but not responding to firewall-cmd; preserving existing firewall state"
fi

# Ports are opened on the first install only; on upgrade the admin's own
# choices (portal Firewall page) must not be reverted.
if ! is_upgrade; then
    # SSH is opened and verified first to guarantee remote administration
    firewall-cmd --permanent --add-service=ssh || error "Failed to add ssh service to firewalld"
    firewall-cmd --permanent --add-service=http || error "Failed to add http service to firewalld"
    firewall-cmd --permanent --add-service=https || error "Failed to add https service to firewalld"
    firewall-cmd --permanent --add-port=5060/udp || error "Failed to add 5060/udp to firewalld"
    firewall-cmd --permanent --add-port=5060/tcp || error "Failed to add 5060/tcp to firewalld"
    firewall-cmd --permanent --add-port=5061/tcp || error "Failed to add 5061/tcp to firewalld"
    firewall-cmd --permanent --add-port=10000-20000/udp || error "Failed to add 10000-20000/udp to firewalld"
    firewall-cmd --permanent --add-port=4100-4999/udp || error "Failed to add 4100-4999/udp (T.38) to firewalld"
    firewall-cmd --permanent --add-port=3478/tcp || error "Failed to add 3478/tcp to firewalld"
    firewall-cmd --permanent --add-port=3478/udp || error "Failed to add 3478/udp to firewalld"
    firewall-cmd --permanent --add-port=5349/tcp || error "Failed to add 5349/tcp to firewalld"
    firewall-cmd --permanent --add-port=5349/udp || error "Failed to add 5349/udp to firewalld"
    firewall-cmd --permanent --add-port=49152-65535/udp || error "Failed to add 49152-65535/udp to firewalld"
    firewall-cmd --reload || error "Failed to reload firewalld with initial rules"

    # Verify that SSH access is open in the running firewall
    if ! firewall-cmd --query-service=ssh >/dev/null 2>&1; then
        error "SSH service is not active in running firewalld rules; refusing to proceed"
    fi
fi   # install mode

# Now that firewalld is active and verified, disable legacy UFW
if [[ "$WAS_UFW_ACTIVE" -eq 1 ]]; then
    warn "firewalld is active and verified — disabling ufw (portal: Firewall page manages firewalld)"
    ufw disable >/dev/null 2>&1 || true
    systemctl disable --now ufw 2>/dev/null || true
fi

# T.38 fax travels on the UDPTL ports (udptl.conf, 4100-4999), which the first
# install never opened: a carrier accepted T.38 and every packet was dropped
# ("Disconnected after permitted retries", live system 2026-10-22). Opened on
# upgrade too, but only where the RTP range is open as installed, so an admin
# who closed media ports on purpose is not overruled.
# A card with its own rules (portal: Firewall) has a zone aipbx-cardN, and an update changes it
# like the default zone. Every port an update opens or closes goes through this list.
FW_ZONES=""
if command -v firewall-cmd >/dev/null 2>&1; then
    FW_ZONES="$(firewall-cmd --get-default-zone 2>/dev/null) $(firewall-cmd --permanent --get-zones 2>/dev/null | tr ' ' '\n' | { grep -E '^aipbx-card[0-9]+$' || true; } | tr '\n' ' ')"
fi
FW_CHANGED=0
for z in $FW_ZONES; do
    if firewall-cmd --permanent --zone="$z" --query-port=10000-20000/udp >/dev/null 2>&1 \
       && ! firewall-cmd --permanent --zone="$z" --query-port=4100-4999/udp >/dev/null 2>&1; then
        firewall-cmd --permanent --zone="$z" --add-port=4100-4999/udp >/dev/null 2>&1 && FW_CHANGED=1
    fi
    # Asterisk's direct WSS (8089) now listens on loopback only; clients use 443 /ws.
    # Close the port that earlier versions opened.
    if firewall-cmd --permanent --zone="$z" --query-port=8089/tcp >/dev/null 2>&1; then
        firewall-cmd --permanent --zone="$z" --remove-port=8089/tcp >/dev/null 2>&1 && FW_CHANGED=1
    fi
done
[ "$FW_CHANGED" = 0 ] || firewall-cmd --reload >/dev/null 2>&1 || warn "Failed to reload firewalld after zone adjustments"

# 13b. Asterisk security logging
sed -i "s/^;security\.log => security/security.log => security/" /etc/asterisk/logger.conf 2>/dev/null || true
asterisk -rx "logger reload" 2>/dev/null || true

# 13c. Fail2ban configuration
cat > /etc/fail2ban/jail.d/asterisk.local << 'JAIL'
[asterisk]
enabled  = true
port     = 5060,5061
protocol = all
filter   = asterisk
logpath  = /var/log/asterisk/messages.log
           /var/log/asterisk/security.log
maxretry = 5
bantime  = 3600
findtime = 600
JAIL

# Brute-force protection for the web portal / mobile login. Needs the real
# client address, which Apache gets from nginx via the PROXY protocol.
cat > /etc/fail2ban/filter.d/aipbx-web.conf << 'F2BFILTER'
[Definition]
failregex = ^<HOST> - \[.*\] FAILED_LOGIN user=
ignoreregex =
F2BFILTER

cat > /etc/fail2ban/jail.d/aipbx-web.local << 'JAIL'
[aipbx-web]
enabled  = true
port     = http,https
filter   = aipbx-web
logpath  = /var/log/aipbx/web_login_failures.log
maxretry = 10
bantime  = 3600
findtime = 600
JAIL

# jail.d stays root-owned: fail2ban runs as root and a jail file can define
# the commands it executes, so a web-writable jail.d would be a root shell.
# The panel stages its overrides in /var/lib/aipbx and `aipbx-priv f2b
# install-override` validates and installs them.
chown -R root:root /etc/fail2ban/jail.d
chmod 755 /etc/fail2ban/jail.d
# The panel's overrides must be read last: fail2ban sorts jail.d by name, so
# "99-..." sorted before asterisk.local / aipbx-web.local and was overridden.
if [[ -f /etc/fail2ban/jail.d/99-ai-pbx.local ]]; then
    mv -n /etc/fail2ban/jail.d/99-ai-pbx.local /etc/fail2ban/jail.d/zz-ai-pbx.local
    rm -f /etc/fail2ban/jail.d/99-ai-pbx.local
fi
touch /etc/fail2ban/jail.d/zz-ai-pbx.local
chmod 644 /etc/fail2ban/jail.d/zz-ai-pbx.local

systemctl enable fail2ban 2>/dev/null || true
systemctl restart fail2ban 2>/dev/null || true
ok "firewall and fail2ban configured"

# The installed version, for the bridge check of later releases (see UPGRADE_FROM).
install -d -m 0755 /var/lib/aipbx
tr -d '[:space:]' < "$INSTALL_DIR/VERSION" > /var/lib/aipbx-system/installed-version
chmod 0644 /var/lib/aipbx-system/installed-version

if is_upgrade; then
    echo ""
    ok "Upgrade to $(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo '?') applied"
    exit 0
fi

# ============================================================================
