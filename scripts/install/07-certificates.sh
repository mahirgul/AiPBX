#!/usr/bin/env bash
# ============================================================================
# STEP 7: TLS CERTIFICATE
# ============================================================================
step "7. TLS Certificate Setup"

if is_upgrade; then
    # With /etc/ssl/aipbx/mode the active certificate is kept below (it may be
    # an uploaded one); older installs still point at CERT_FILE.
    [[ -f /etc/ssl/aipbx/mode || ( -f "$CERT_FILE" && -f "$KEY_FILE" ) ]] || error "Certificate not found: $CERT_FILE"
    info "Keeping the existing certificate"
elif [[ "$USE_LETSENCRYPT" == "true" ]]; then
    info "Requesting Let's Encrypt certificate for $PORTAL_DOMAIN..."
    systemctl start apache2 2>/dev/null || true
    if certbot certonly --apache -d "$PORTAL_DOMAIN" \
        --non-interactive --agree-tos \
        -m "${LE_EMAIL:-admin@${PORTAL_DOMAIN}}" 2>&1; then
        CERT_FILE="/etc/letsencrypt/live/$PORTAL_DOMAIN/fullchain.pem"
        KEY_FILE="/etc/letsencrypt/live/$PORTAL_DOMAIN/privkey.pem"
        USE_SELFSIGNED=false
        ok "Let's Encrypt certificate obtained"
    else
        warn "Let's Encrypt failed — falling back to self-signed certificate"
        USE_SELFSIGNED=true
    fi
fi

if ! is_upgrade && [[ "$USE_SELFSIGNED" == "true" ]]; then
    info "Generating self-signed TLS certificate..."
    mkdir -p /etc/ssl/aipbx

    # Browsers ignore the CN and only look at subjectAltName — without a SAN the
    # certificate is rejected for WSS/TURNS even after "accept the risk".
    CERT_SAN="DNS:${PORTAL_DOMAIN},IP:${SERVER_IP}"
    if [[ "$PORTAL_DOMAIN" =~ ^[0-9.]+$ ]]; then CERT_SAN="IP:${SERVER_IP}"; fi
    openssl req -x509 -nodes -days 3650 \
        -newkey rsa:2048 \
        -keyout /etc/ssl/aipbx/aipbx.key \
        -out /etc/ssl/aipbx/aipbx.crt \
        -subj "/C=TR/ST=Istanbul/L=Istanbul/O=AiPBX/OU=IT/CN=${PORTAL_DOMAIN}" \
        -addext "subjectAltName=${CERT_SAN}" \
        2>/dev/null

    chmod 600 /etc/ssl/aipbx/aipbx.key
    chmod 644 /etc/ssl/aipbx/aipbx.crt

    CERT_FILE="/etc/ssl/aipbx/aipbx.crt"
    KEY_FILE="/etc/ssl/aipbx/aipbx.key"
    ok "Self-signed certificate generated (valid 10 years)"
fi

# One active certificate (/etc/ssl/aipbx/active.*) serves Apache, coturn and
# Asterisk; aipbx-cert copies it to each. The Certificates page switches it
# later (Let's Encrypt, uploaded, self-signed), so an upgrade keeps whatever
# is active instead of going back to the installer's choice.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-cert" /usr/local/sbin/aipbx-cert
if is_upgrade && [[ -f /etc/ssl/aipbx/mode && -f /etc/ssl/aipbx/active.crt && -f /etc/ssl/aipbx/active.key ]]; then
    info "Keeping the active certificate ($(cat /etc/ssl/aipbx/mode))"
else
    CERT_MODE=selfsigned
    [[ "$CERT_FILE" == /etc/letsencrypt/* ]] && CERT_MODE=letsencrypt
    /usr/local/sbin/aipbx-cert deploy --no-reload "$CERT_MODE" "$CERT_FILE" "$KEY_FILE" \
        || error "Could not install the TLS certificate ($CERT_FILE)"
fi

# An install before 1.60.15 wrote this unit running as root.
if is_upgrade && [[ -f /etc/systemd/system/aipbx-mdns.service ]] && ! grep -q '^User=nobody' /etc/systemd/system/aipbx-mdns.service; then
    sed -i 's/^\[Service\]$/[Service]\nUser=nobody\nNoNewPrivileges=yes\nProtectSystem=full\nProtectHome=yes\nPrivateTmp=yes/' /etc/systemd/system/aipbx-mdns.service
    systemctl daemon-reload
    systemctl try-restart aipbx-mdns 2>/dev/null || true
fi

# Announce the local .local name on the LAN via mDNS (as an alias, hostname unchanged).
if [[ "${USE_MDNS:-false}" == "true" ]]; then
    grep -qE "[[:space:]]${PORTAL_DOMAIN}([[:space:]]|$)" /etc/hosts || echo "127.0.0.1 ${PORTAL_DOMAIN}" >> /etc/hosts
    cat > /etc/systemd/system/aipbx-mdns.service << MDNS
[Unit]
Description=AiPBX mDNS alias (${PORTAL_DOMAIN} -> ${SERVER_IP})
After=avahi-daemon.service network-online.target
Requires=avahi-daemon.service

[Service]
# Only asks avahi-daemon over D-Bus: no reason to be root.
User=nobody
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
PrivateTmp=yes
ExecStart=/usr/bin/avahi-publish -a -R ${PORTAL_DOMAIN} ${SERVER_IP}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
MDNS
    systemctl daemon-reload
    systemctl enable --now avahi-daemon aipbx-mdns 2>/dev/null || true
    ok "mDNS alias published: ${PORTAL_DOMAIN} -> ${SERVER_IP}"
fi

# Generate Asterisk DTLS certificate (WebRTC)
if [[ ! -f /etc/asterisk/keys/asterisk.pem ]]; then
    info "Generating Asterisk DTLS certificate..."
    openssl req -x509 -nodes -days 3650 \
        -newkey rsa:2048 \
        -keyout /etc/asterisk/keys/asterisk.key \
        -out /etc/asterisk/keys/asterisk.crt \
        -subj "/CN=asterisk" 2>/dev/null
    cat /etc/asterisk/keys/asterisk.crt /etc/asterisk/keys/asterisk.key \
        > /etc/asterisk/keys/asterisk.pem
    chmod 640 /etc/asterisk/keys/asterisk.pem /etc/asterisk/keys/asterisk.key
    chown asterisk:asterisk /etc/asterisk/keys/asterisk.pem \
        /etc/asterisk/keys/asterisk.key 2>/dev/null || true
    ok "Asterisk DTLS certificate generated"
fi
