#!/usr/bin/env bash
# ============================================================================
# STEP 11: LOCAL AI SERVICE (aipbx-ai)
# ============================================================================
step "11. Local AI Service"

# Install the standard-library gateway for every installation. The local runtime (text to speech
# and two call detectors) stays opt-in through aipbx-ai-setup; cloud-only installs need no venv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-ai-run" /usr/local/sbin/aipbx-ai-run
AI_BASE=/opt/aipbx-ai
AI_VENV="$AI_BASE/venv"
if ! id -u aipbx-ai >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/aipbx-ai --no-create-home \
        --shell /usr/sbin/nologin --user-group aipbx-ai
fi
install -d -o aipbx-ai -g aipbx-ai -m 0755 /var/lib/aipbx-ai
# Speech to text and language models are cloud only: remove what older releases downloaded (speech and
# sentence-meaning models, voices added from other engines, the old Hugging Face and index caches). Idempotent.
rm -rf /var/lib/aipbx-ai/models/whisper-* /var/lib/aipbx-ai/models/vosk-* /var/lib/aipbx-ai/models/e5-* \
    /var/lib/aipbx-ai/hf /var/lib/aipbx-ai/cache
for AI_MANIFEST in /var/lib/aipbx-ai/models/*/manifest.json; do
    if [[ -f "$AI_MANIFEST" ]] && ! grep -q '"engine": *"piper"' "$AI_MANIFEST"; then
        rm -rf "$(dirname "$AI_MANIFEST")"
    fi
done
unset AI_MANIFEST
install -d -o root -g root -m 0755 "$AI_BASE"
# Code: replaced on every install/upgrade (root-owned; the service only reads it).
rm -rf "$AI_BASE/app.new"
mkdir -p "$AI_BASE/app.new"
cp -a "$INSTALL_DIR/ai/requirements.txt" "$INSTALL_DIR/ai/aipbx_ai" "$AI_BASE/app.new/"
find "$AI_BASE/app.new" -name __pycache__ -prune -exec rm -rf {} +
chown -R root:root "$AI_BASE/app.new"; chmod -R u=rwX,go=rX "$AI_BASE/app.new"
rm -rf "$AI_BASE/app"; mv "$AI_BASE/app.new" "$AI_BASE/app"

# An empty venv (a few MB, no packages) so the local runtime never has to touch the system
# Python (Ubuntu refuses pip there). The ML packages follow only when the admin installs the
# runtime; a venv without .aipbx-requirements still counts as "absent".
if [[ ! -x "$AI_VENV/bin/python" ]]; then
    if ! python3 -c 'import ensurepip, venv' >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$APT_LOCK_WAIT" install -y -qq python3-venv >/dev/null 2>&1 \
            || warn "python3-venv could not be installed: the local AI runtime will try again when installed"
    fi
    python3 -m venv "$AI_VENV" >/dev/null 2>&1 \
        || { rm -rf "$AI_VENV"; warn "The Python venv for local AI could not be created"; }
fi

# Shared secret of the portal and the service; created once, never rotated.
# The service reads it through LoadCredential (as root), so aipbx-ai needs no
# access to the file itself.
install -d -o root -g root -m 0755 /etc/aipbx
if [[ ! -s /etc/aipbx/ai.token ]]; then
    (umask 077; openssl rand -hex 32 > /etc/aipbx/ai.token)
fi
chown root:www-data /etc/aipbx/ai.token
chmod 640 /etc/aipbx/ai.token

if [[ ! -s /etc/aipbx/ari.token ]]; then
    (umask 077; openssl rand -hex 24 > /etc/aipbx/ari.token)
fi
chown root:asterisk /etc/aipbx/ari.token 2>/dev/null || chown root:root /etc/aipbx/ari.token
chmod 640 /etc/aipbx/ari.token

if [[ -f /etc/asterisk/ari.conf ]] && ! grep -q '^\[aipbx_ai\]' /etc/asterisk/ari.conf; then
    ARI_PW=$(cat /etc/aipbx/ari.token)
    sed -i 's/^enabled = no/enabled = yes/' /etc/asterisk/ari.conf
    cat >> /etc/asterisk/ari.conf << ARIEOF

[aipbx_ai]
type = user
read_only = no
password = ${ARI_PW}
password_format = plain
ARIEOF
fi
# An upgrade restarts Asterisk in step 9, before this step has written the ARI user: Asterisk then
# has no "aipbx_ai" user and every AI call ends with "Stasis app 'aipbx-ai' doesn't exist".
# Load the file now (a reload keeps calls up).
if systemctl is-active --quiet asterisk && ! asterisk -rx 'ari show users' 2>/dev/null | grep -qw aipbx_ai; then
    asterisk -rx 'module reload res_ari.so' >/dev/null 2>&1 || warn "Asterisk did not reload the ARI user: run  asterisk -rx 'module reload res_ari.so'"
fi

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
LoadCredential=ari_token:/etc/aipbx/ari.token
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

# 09-asterisk.sh sent the AI applications to the service while it still ran the old code, which keeps only the
# fields it knows: send them again to the new code, or this release's settings would wait for the next save.
if systemctl is-active --quiet aipbx-ai.service; then
    for _ in $(seq 1 30); do
        [[ "$(curl -s -m 2 -o /dev/null -w '%{http_code}' http://127.0.0.1:8790/v1/health 2>/dev/null)" != 000 ]] && break
        sleep 0.5
    done
    php -r "require '/var/www/html/src/asterisk_sync.php'; exit(syncAiApps() === false ? 1 : 0);" >/dev/null 2>&1 \
        && ok "AI applications sent to the AI service" \
        || warn "AI applications could not be sent to the AI service — open the portal and apply the pending changes"
fi

# ============================================================================
