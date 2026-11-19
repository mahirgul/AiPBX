#!/usr/bin/env bash
# ============================================================================
# STEP 11: LOCAL AI SERVICE (aipbx-ai)
# ============================================================================
step "11. Local AI Service"

# Install the standard-library gateway for every installation. Local inference
# dependencies remain opt-in through aipbx-ai-setup; cloud-only installs need no venv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-ollama-setup" /usr/local/sbin/aipbx-ollama-setup
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-ai-run" /usr/local/sbin/aipbx-ai-run
AI_BASE=/opt/aipbx-ai
AI_VENV="$AI_BASE/venv"
if ! id -u aipbx-ai >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/aipbx-ai --no-create-home \
        --shell /usr/sbin/nologin --user-group aipbx-ai
fi
install -d -o aipbx-ai -g aipbx-ai -m 0755 /var/lib/aipbx-ai
install -d -o root -g root -m 0755 "$AI_BASE"
# Code: replaced on every install/upgrade (root-owned; the service only reads it).
rm -rf "$AI_BASE/app.new"
mkdir -p "$AI_BASE/app.new"
cp -a "$INSTALL_DIR/ai/requirements.txt" "$INSTALL_DIR/ai/aipbx_ai" "$AI_BASE/app.new/"
find "$AI_BASE/app.new" -name __pycache__ -prune -exec rm -rf {} +
chown -R root:root "$AI_BASE/app.new"; chmod -R u=rwX,go=rX "$AI_BASE/app.new"
rm -rf "$AI_BASE/app"; mv "$AI_BASE/app.new" "$AI_BASE/app"

# Shared secret of the portal and the service; created once, never rotated.
# The service reads it through LoadCredential (as root), so aipbx-ai needs no
# access to the file itself.
install -d -o root -g root -m 0755 /etc/aipbx
if [[ ! -s /etc/aipbx/ai.token ]]; then
    (umask 077; openssl rand -hex 32 > /etc/aipbx/ai.token)
fi
chown root:www-data /etc/aipbx/ai.token
chmod 640 /etc/aipbx/ai.token

cat > /etc/systemd/system/aipbx-ai.service << 'AIUNIT'
[Unit]
Description=AiPBX local AI models (AI -> Local models)
After=network-online.target aipbx-ai-resolver.service
Wants=network-online.target aipbx-ai-resolver.service

[Service]
Type=simple
User=aipbx-ai
Group=aipbx-ai
WorkingDirectory=/opt/aipbx-ai/app
ExecStart=/usr/local/sbin/aipbx-ai-run
LoadCredential=token:/etc/aipbx/ai.token
Environment=HOME=/var/lib/aipbx-ai AIPBX_AI_DATA=/var/lib/aipbx-ai
Environment=PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
Restart=on-failure
RestartSec=5
# Calls come first: Asterisk keeps the CPU when both want it.
Nice=5
CPUWeight=50
MemoryHigh=2G
MemoryMax=4G
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=/var/lib/aipbx-ai
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
RestrictNamespaces=yes
LockPersonality=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
UMask=0022

[Install]
WantedBy=multi-user.target
AIUNIT
cat > /etc/systemd/system/aipbx-ai-resolver.service << 'RESOLVER'
[Unit]
Description=AiPBX scoped AI credential resolver
After=mariadb.service

[Service]
Type=simple
User=www-data
Group=aipbx-ai
SupplementaryGroups=www-data asterisk
WorkingDirectory=/opt/aipbx-ai/app
ExecStart=/usr/bin/python3 -m aipbx_ai.credential_broker
RuntimeDirectory=aipbx-ai-resolver
RuntimeDirectoryMode=0750
UMask=0007
Environment=PYTHONDONTWRITEBYTECODE=1
Restart=on-failure
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
RestrictNamespaces=yes
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
MemoryMax=128M

[Install]
WantedBy=multi-user.target
RESOLVER
systemctl daemon-reload
systemctl enable --now aipbx-ai-resolver.service >/dev/null 2>&1
systemctl restart aipbx-ai-resolver.service

# READY_MARK: written by aipbx-ai-setup once the venv is complete.
AI_READY_MARK="$AI_VENV/.aipbx-requirements"
if [[ -f "$AI_READY_MARK" ]] && ! command -v espeak-ng >/dev/null 2>&1; then
    # Piper voices (1.9.3) need espeak-ng; runtimes installed before get it here.
    DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$APT_LOCK_WAIT" install -y -qq espeak-ng >/dev/null 2>&1 \
        || warn "espeak-ng could not be installed: Piper voices will not load"
fi
if [[ -f "$AI_READY_MARK" ]]; then
    if [[ "$(cat "$AI_READY_MARK")" != "$(sha256sum "$AI_BASE/app/requirements.txt" | cut -d' ' -f1)" ]] \
       && ! systemctl is-active --quiet aipbx-ai-setup.service; then
        # This release needs other packages: refresh the venv in the background
        # (downloads; it restarts the service when done).
        systemctl reset-failed aipbx-ai-setup.service >/dev/null 2>&1 || true
        systemd-run --quiet --collect --unit=aipbx-ai-setup \
            --description="AiPBX AI runtime: install" /usr/local/sbin/aipbx-ai-setup install \
            && info "Local AI runtime is being updated in the background (/var/log/aipbx-ai-setup.log)"
    elif ! is_upgrade || systemctl is-enabled --quiet aipbx-ai.service; then
        # Upgrade: only a service the admin had running is restarted.
        systemctl enable aipbx-ai.service >/dev/null 2>&1 || true
        systemctl restart aipbx-ai.service 2>/dev/null || warn "Local AI service does not start — check: journalctl -u aipbx-ai"
    fi
    ok "Local AI service updated"
else
    systemctl enable aipbx-ai.service >/dev/null 2>&1
    systemctl restart aipbx-ai.service
    ok "AI gateway active (local ML runtime optional)"
fi

# ============================================================================
