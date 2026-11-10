#!/usr/bin/env bash
# ============================================================================
# STEP 14: SAVE CREDENTIALS TO FILE
# ============================================================================
CREDS_FILE="/root/aipbx-credentials.txt"
install -m 0600 -o root -g root /dev/null "$CREDS_FILE"

cat > "$CREDS_FILE" << CREDS
================================================================
  AI PBX — Installation Credentials
  Generated: $(date -u +"%Y-%m-%d %H:%M:%S UTC")
  Server: $(hostname) / ${SERVER_IP}
  ⚠  KEEP THIS FILE SECURE — delete it after noting credentials
================================================================

  WEB PORTAL & EXTENSION
  ──────────────────────
  URL           : https://${PORTAL_DOMAIN}
  Admin User    : admin
  Admin Password: ${ADMIN_PASS}
  Admin Exten   : 1000
  Admin SIP Pass: ${ADMIN_SIP_PASS}

  DATABASE (MariaDB)
  ──────────────────
  Database Name : asterisk
  App User      : ${DB_USER}
  App Password  : ${DB_PASS}
  DDL User      : ${MIGRATOR_USER}
  DDL Password  : ${MIGRATOR_PASS}

  ASTERISK AMI
  ────────────
  AMI User      : ${AMI_USER}
  AMI Password  : ${AMI_PASS}

  WebRTC TURN
  ───────────
  TURN Secret   : ${TURN_SECRET}

  TLS CERTIFICATE
  ───────────────
  Type          : $(if [[ "$USE_LETSENCRYPT" == "true" ]]; then echo "Let's Encrypt (auto-renews)"; else echo "Self-Signed (10 years)"; fi)
  Domain        : ${PORTAL_DOMAIN}
  Cert File     : ${CERT_FILE}
  Key File      : ${KEY_FILE}

  CONFIGURATION FILES
  ───────────────────
  Environment   : /etc/ai-pbx.env
  Install dir   : ${INSTALL_DIR}
  Web dir       : /var/www/html → ${INSTALL_DIR}/web
  Asterisk conf : /etc/asterisk/

  All passwords can be changed from: Admin Panel → Settings → System
================================================================
CREDS

chmod 600 "$CREDS_FILE"

# ============================================================================
# DONE — PRINT SUMMARY
# ============================================================================
TOOK=$((SECONDS - START_SECONDS))
echo ""
box "${I_OK} AiPBX is ready  ${SEP}  $((TOOK / 60)) min $((TOOK % 60)) s" \
    "Portal        ${CYAN}https://${PORTAL_DOMAIN}${NC}" \
    "Username      ${YELLOW}admin${NC}" \
    "Password      ${YELLOW}${ADMIN_PASS}${NC}" \
    "Extension     ${YELLOW}1000${NC} (web, mobile, SIP)" \
    "SIP password  ${YELLOW}${ADMIN_SIP_PASS}${NC}"
echo ""
box "Database (MariaDB)" \
    "App user      ${YELLOW}${DB_USER}${NC}" \
    "App password  ${YELLOW}${DB_PASS}${NC}" \
    "DDL user      ${YELLOW}${MIGRATOR_USER}${NC}" \
    "DDL password  ${YELLOW}${MIGRATOR_PASS}${NC}"
echo ""
box "Asterisk AMI" \
    "User          ${YELLOW}${AMI_USER}${NC}" \
    "Password      ${YELLOW}${AMI_PASS}${NC}"
echo ""

echo -e " ${BOLD}TLS certificate${NC}"
if [[ "${USE_LETSENCRYPT:-false}" == "true" ]]; then
    ok "Let's Encrypt certificate for ${CYAN}${PORTAL_DOMAIN}${NC}"
else
    warn "Self-signed certificate for ${PORTAL_DOMAIN}"
    info "Your browser will show a warning: click Advanced → Proceed to continue."
    info "WebRTC TURNS (calls behind strict firewalls) needs a trusted certificate."
    info "Get one later in the portal: ${CYAN}Security → Certificates${NC}"
fi
echo ""

echo -e " ${BOLD}Services${NC}"
svc() { # svc unit "Label" "detail" [optional]
    if systemctl is-active --quiet "$1"; then ok "$(printf '%-9s' "$2") ${GREEN}running${NC}${3:+ ($3)}"
    elif [[ -n "${4:-}" ]]; then warn "$(printf '%-9s' "$2") not running$([[ "$4" != - ]] && echo " ($4)")"
    else echo -e "   ${RED}${I_ERR}${NC} $(printf '%-9s' "$2") ${RED}stopped${NC}"; fi
}
svc mariadb MariaDB
svc asterisk Asterisk
svc nginx nginx "edge 443"
svc apache2 Apache2 "80 / 8443 backend"
svc coturn coturn "" -
svc aipbx-chat Chat "" "optional"
echo ""

# A crontab nobody reads is worse than none: the nightly backup, phone discovery
# and retention would stop without a word. Starting the daemon is this script's
# job, not the package's — on a container the enablement lives on the /etc
# volume, which an image built without cron never seeded, so `apt-get install`
# alone leaves it installed and never run. Last, once the scripts the jobs call
# are in place, so cron's first minute is not a page of "not found".
systemctl enable --now cron >/dev/null 2>&1 || warn "cron did not start — scheduled backups, phone discovery and retention will not run"
echo ""

# Self test (Admin -> Health checks): what this install set up, checked from the outside.
echo -e " ${BOLD}Self test${NC} ${DIM}(sudo aipbx-selftest)${NC}"
if /usr/local/sbin/aipbx-selftest 2>&1 | sed 's/^/   /'; then
    ok "No check failed"
else
    warn "A check failed: see above (the line marked FAIL)"
fi
echo ""

# Advisory only, so it may not decide whether this install succeeded: `sshd -T`
# exits 127 on a host with no SSH server at all (a container, a minimal cloud
# image) and non-zero when there is no host key yet to read the config with.
# Under `set -e` that status ended the script here — everything installed, exit
# 1 — and `aipbx-update` rolled a healthy box back over it.
ssh_cfg="$(sshd -T 2>/dev/null || true)"
if grep -qi '^permitrootlogin yes' <<<"$ssh_cfg" && grep -qi '^passwordauthentication yes' <<<"$ssh_cfg"; then
    warn "SSH allows root login with a password. Consider key-only logins and"
    warn "limiting port 22 to trusted networks (see docs/security.md)."
    echo ""
fi
info "All credentials saved to ${CYAN}${CREDS_FILE}${NC}"
warn "Note these credentials now! Delete the file after saving them securely."
info "All passwords can be changed later from: ${CYAN}Admin Panel → Settings → System${NC}"
echo ""
