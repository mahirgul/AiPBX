#!/usr/bin/env bash
# ============================================================================
# STEP 1: FQDN / DOMAIN CONFIGURATION
# ============================================================================
step "1. Domain / FQDN Configuration"

# The address the server reaches the world from. The first of `hostname -I` can be a
# tunnel, which is gone when the tunnel is.
SERVER_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' | head -1)
[ -n "$SERVER_IP" ] || SERVER_IP=$(hostname -I | awk '{print $1}')
if is_upgrade; then
    # Domain and certificate come from the existing installation.
    PORTAL_DOMAIN="$(env_get PORTAL_DOMAIN)"
    [[ -n "$PORTAL_DOMAIN" ]] || error "PORTAL_DOMAIN missing in /etc/ai-pbx.env"
    USE_MDNS=false
    [[ "${PORTAL_DOMAIN,,}" == *.local ]] && USE_MDNS=true
    if [[ -f "/etc/letsencrypt/live/$PORTAL_DOMAIN/fullchain.pem" ]]; then
        USE_LETSENCRYPT=true; USE_SELFSIGNED=false
        CERT_FILE="/etc/letsencrypt/live/$PORTAL_DOMAIN/fullchain.pem"
        KEY_FILE="/etc/letsencrypt/live/$PORTAL_DOMAIN/privkey.pem"
    else
        USE_LETSENCRYPT=false; USE_SELFSIGNED=true
        CERT_FILE="/etc/ssl/aipbx/aipbx.crt"
        KEY_FILE="/etc/ssl/aipbx/aipbx.key"
    fi
    ok "Upgrading ${PORTAL_DOMAIN} (certificate: ${CERT_FILE})"
else
# No questions: a name from AIPBX_FQDN or aipbx.local, always with a self-signed certificate.
# The names and a Let's Encrypt certificate are set later on System -> Certificates.
PORTAL_DOMAIN="${AIPBX_FQDN:-aipbx.local}"
PORTAL_DOMAIN="${PORTAL_DOMAIN,,}"
[[ "$PORTAL_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]] \
    || error "AIPBX_FQDN is not a valid domain name: ${AIPBX_FQDN}"
USE_SELFSIGNED=true
USE_LETSENCRYPT=false
USE_MDNS=false
# A .local name is announced on the LAN via mDNS, no DNS record needed.
[[ "$PORTAL_DOMAIN" == *.local ]] && USE_MDNS=true
info "Portal address: https://${PORTAL_DOMAIN} (server IP ${SERVER_IP}), self-signed certificate."
info "Set the domain names and get a Let's Encrypt certificate later on System -> Certificates."
fi   # install mode

echo ""
