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
echo ""
echo -e "  Your server's IP address: ${YELLOW}$SERVER_IP${NC}"
echo ""
echo -e "  Enter your fully qualified domain name (FQDN) for HTTPS."
echo -e "  Examples: ${CYAN}pbx.company.com${NC}, ${CYAN}voice.example.org${NC}"
echo -e "  Leave blank for a local-only install: ${CYAN}aipbx.local${NC} + self-signed certificate"
echo -e "  (the .local name is announced on the LAN via mDNS, no DNS record needed)."
echo ""
if [[ -n "${AIPBX_FQDN:-}" ]]; then
    PORTAL_DOMAIN_INPUT="$AIPBX_FQDN"
    echo -e "  FQDN provided via environment: ${GREEN}$PORTAL_DOMAIN_INPUT${NC}"
else
    prompt_read "  FQDN (or press Enter to skip): " PORTAL_DOMAIN_INPUT ""
fi

if [[ -z "$PORTAL_DOMAIN_INPUT" || "${PORTAL_DOMAIN_INPUT,,}" == *.local ]]; then
    # Local install: Let's Encrypt cannot issue for .local; a self-signed
    # certificate (SAN: name + server IP) is generated and the name is announced on the LAN via mDNS.
    PORTAL_DOMAIN="${PORTAL_DOMAIN_INPUT:-aipbx.local}"
    PORTAL_DOMAIN="${PORTAL_DOMAIN,,}"
    USE_SELFSIGNED=true
    USE_LETSENCRYPT=false
    USE_MDNS=true
    warn "Local install: ${PORTAL_DOMAIN} with a self-signed TLS certificate."
    warn "Browsers will show a security warning — this is normal for self-signed certs."
else
    PORTAL_DOMAIN="$PORTAL_DOMAIN_INPUT"
    USE_SELFSIGNED=false
    USE_MDNS=false
    echo ""
    echo -e "  Domain set to: ${GREEN}$PORTAL_DOMAIN${NC}"
    echo ""
    echo -e "  Do you want a free Let's Encrypt TLS certificate? (recommended)"
    echo -e "  Note: DNS must point ${CYAN}$PORTAL_DOMAIN${NC} → ${CYAN}$SERVER_IP${NC} already."
    prompt_read "  Use Let's Encrypt? [y/N]: " LE_CHOICE "n"
    if [[ "${LE_CHOICE,,}" == "y" || "${LE_CHOICE,,}" == "yes" ]]; then
        USE_LETSENCRYPT=true
        echo ""
        prompt_read "  Email for Let's Encrypt notifications: " LE_EMAIL ""
    else
        USE_LETSENCRYPT=false
        USE_SELFSIGNED=true
        info "A self-signed certificate will be used for $PORTAL_DOMAIN"
    fi
fi
fi   # install mode

echo ""
