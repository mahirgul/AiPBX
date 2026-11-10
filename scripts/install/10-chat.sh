#!/usr/bin/env bash
# ============================================================================
# STEP 10: CHAT SERVICE (Go)
# ============================================================================
step "10. Building Chat Service"

if [[ -f "$INSTALL_DIR/chat/main.go" ]]; then
    info "Building chat service..."
    cd "$INSTALL_DIR/chat"
    # -buildvcs=false: when the install dir belongs to another user (dev
    # installs such as /home/pbx), git refuses the repo as "dubious ownership"
    # and VCS stamping would fail the whole build.
    if build_out="$(CGO_ENABLED=0 go build -buildvcs=false -o aipbx-chat . 2>&1)"; then
        chat_built=true
    else
        chat_built=false
        warn "Chat service build failed:"
        # Go prints the root cause first; later lines are follow-on errors.
        printf '%s\n' "$build_out" | head -20
    fi

    if [[ -f "$INSTALL_DIR/chat/aipbx-chat" ]]; then
        cat > /etc/systemd/system/aipbx-chat.service << EOF
[Unit]
Description=AI-PBX Chat & Messaging Service
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
WorkingDirectory=${INSTALL_DIR}/chat
EnvironmentFile=/etc/ai-pbx.env
Environment="GODEBUG=multipathtcp=0"
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectControlGroups=yes
InaccessiblePaths=-/etc/ai-pbx-migrator.env
UMask=0027
ExecStart=${INSTALL_DIR}/chat/aipbx-chat
Restart=always
RestartSec=3
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        systemctl enable aipbx-chat 2>/dev/null || true
        # restart (not start): on upgrade the freshly built binary must take over
        systemctl restart aipbx-chat 2>/dev/null || true
        sleep 2   # Type=simple is "active" at once; give a crash time to show
        if ! systemctl is-active --quiet aipbx-chat; then
            warn "Chat service is not running — check: journalctl -u aipbx-chat"
        elif [[ "$chat_built" == true ]]; then
            ok "Chat service built and started"
        else
            warn "Chat service build failed; the previous binary is running"
        fi
    fi
fi

# ============================================================================
