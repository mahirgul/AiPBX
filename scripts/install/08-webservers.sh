#!/usr/bin/env bash
# ============================================================================
# STEP 8: NGINX (EDGE 443 MULTIPLEXER) & APACHE2 (BACKEND)
# ============================================================================
step "8. Configuring Nginx (Edge 443) & Apache2 (80/8443 Backend)"

# Ensure default Nginx HTTP site is removed so Apache binds to port 80 exclusively
rm -f /etc/nginx/sites-enabled/default
systemctl reload nginx 2>/dev/null || true

a2enmod rewrite proxy proxy_wstunnel proxy_http ssl headers remoteip php* 2>/dev/null || true
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Ubuntu's apache2.service has MemoryDenyWriteExecute=yes: PHP's PCRE JIT cannot
# get executable memory, falls back to the interpreter and logged "Allocation of
# JIT memory failed" for every request. Switch the JIT off for Apache's PHP.
# PHP's own upload limits (2 MB a file, 8 MB a request) refused a few minutes of
# music on hold or a plugin package, and the page showed nothing (issue #164).
for php_conf_d in /etc/php/*/apache2/conf.d; do
    if [[ -d "$php_conf_d" ]]; then
        printf '; AiPBX (install.sh): apache2.service denies executable memory\npcre.jit=0\n; sound, MOH and plugin uploads\nupload_max_filesize=64M\npost_max_size=72M\n' > "$php_conf_d/99-aipbx.ini"
    fi
done

# Apache ports: Listen 80 for HTTP/ACME, 127.0.0.1:8443 for HTTPS behind Nginx
cat > /etc/apache2/ports.conf << 'PORTS'
# Apache listens on 80 for HTTP and 127.0.0.1:8443 for HTTPS (behind nginx)
Listen 80
Listen 127.0.0.1:8443
PORTS

# Keep Apache's redirect policy in sync with the portal's trusted proxy settings.
# This directory is root-owned: the web user supplies IPs, never Apache directives.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-proxy-https" /usr/local/sbin/aipbx-proxy-https
php -r '
    require $argv[1] . "/web/config.php";
    // Composer and migrations have not run yet on a fresh machine. Read only
    // these two keys through PDO, without loading portal services/autoloaders.
    $db = getDB();
    $exists = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $exists->execute(["sys_settings"]);
    $settings = [];
    if ($exists->fetchColumn()) {
        $query = $db->prepare("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN (?, ?)");
        $query->execute(["proxy_enabled", "proxy_trusted_proxies"]);
        $settings = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    echo json_encode(["enabled" => ($settings["proxy_enabled"] ?? "0") === "1",
        "trusted_proxies" => $settings["proxy_trusted_proxies"] ?? "127.0.0.1, ::1"], JSON_THROW_ON_ERROR);
' "$INSTALL_DIR" | /usr/local/sbin/aipbx-proxy-https install \
    || error "Could not prepare Apache trusted proxy settings"

cat > /etc/apache2/sites-available/aipbx.conf << VHOST
<VirtualHost *:80>
    ServerName ${PORTAL_DOMAIN}
    DocumentRoot /var/www/html

    # Let's Encrypt HTTP-01 challenge pass-through, redirect all other traffic to HTTPS.
    # Desk phone files (/provision/) are served over plain HTTP too: many phones
    # cannot do HTTPS to an IP address or do not follow redirects, and phone
    # networks often have no DNS. The files are still limited to the allowed
    # networks and the per-phone token (PhoneProvisionService).
    # The path is checked after Apache has resolved it (/provision/../login is
    # /login); internal redirects to index.php (REDIRECT_STATUS set) are skipped,
    # otherwise /provision/ would be redirected on its second pass.
    RewriteEngine On
    # Only an enabled, trusted TCP peer may assert HTTPS on this HTTP listener.
    Include /etc/apache2/aipbx/proxy-https.conf
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteCond %{ENV:REDIRECT_STATUS} ^\$
    RewriteCond %{REQUEST_URI} !^/provision/
    # Plugin endpoints and the IVR data step lookup stay on plain HTTP only for
    # the PBX itself (Asterisk CURL() to http://127.0.0.1/api/...; CURL() does
    # not follow a redirect); everyone else is sent to HTTPS like the rest of
    # the portal (sign-in callbacks, widgets).
    RewriteCond %{REQUEST_URI} !^/api/(plugins/|ivr_data_lookup\.php\$|meeting_join_check\.php\$|chat_call_check\.php\$) [OR]
    RewriteCond %{CONN_REMOTE_ADDR} !^(127\.0\.0\.1|::1)\$
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [END,NE,R=permanent]

    # Asterisk WebRTC & Go Chat Reverse Proxy (for edge-terminated reverse proxies forwarding to port 80)
    ProxyPass /ws ws://127.0.0.1:8088/ws retry=0 timeout=3600
    ProxyPassReverse /ws ws://127.0.0.1:8088/ws
    ProxyPass /chat/ws ws://127.0.0.1:8086/ws retry=0 timeout=3600 keepalive=On
    ProxyPassReverse /chat/ws ws://127.0.0.1:8086/ws
    ProxyPass /chat/api/ http://127.0.0.1:8086/api/
    ProxyPassReverse /chat/api/ http://127.0.0.1:8086/api/
    ProxyPass /chat/media/ http://127.0.0.1:8086/media/
    ProxyPassReverse /chat/media/ http://127.0.0.1:8086/media/

    ErrorLog \${APACHE_LOG_DIR}/aipbx_error.log
    CustomLog \${APACHE_LOG_DIR}/aipbx_access.log combined
</VirtualHost>

<VirtualHost 127.0.0.1:8443>
    ServerName ${PORTAL_DOMAIN}
    DocumentRoot /var/www/html

    # nginx forwards the TLS stream without decrypting it, so it passes the
    # client address in a PROXY protocol header. Without this every request
    # appeared to come from 127.0.0.1 (shared login throttling, useless
    # fail2ban and audit logs).
    RemoteIPProxyProtocol On

    SSLEngine on
    SSLCertificateFile    /etc/ssl/aipbx/active.crt
    SSLCertificateKeyFile /etc/ssl/aipbx/active.key
    SSLProtocol TLSv1.2 TLSv1.3
    SSLHonorCipherOrder on
    Header always set Strict-Transport-Security "max-age=31536000"

    # Asterisk WebRTC WebSocket Reverse Proxy
    ProxyPass /ws ws://127.0.0.1:8088/ws retry=0 timeout=3600
    ProxyPassReverse /ws ws://127.0.0.1:8088/ws

    # Go Chat WebSocket & HTTP API Reverse Proxy
    ProxyPass /chat/ws ws://127.0.0.1:8086/ws retry=0 timeout=3600 keepalive=On
    ProxyPassReverse /chat/ws ws://127.0.0.1:8086/ws
    ProxyPass /chat/api/ http://127.0.0.1:8086/api/
    ProxyPassReverse /chat/api/ http://127.0.0.1:8086/api/
    ProxyPass /chat/media/ http://127.0.0.1:8086/media/
    ProxyPassReverse /chat/media/ http://127.0.0.1:8086/media/

    ErrorLog \${APACHE_LOG_DIR}/aipbx_ssl_error.log
    # Chat WebSocket and media URLs carry the session token in the query
    # string: log those requests without it.
    LogFormat "%a %l %u %t \\"%r\\" %>s %O \\"%{Referer}i\\" \\"%{User-Agent}i\\"" aipbx_combined
    LogFormat "%a %l %u %t \\"%m %U %H\\" %>s %O \\"%{Referer}i\\" \\"%{User-Agent}i\\"" aipbx_noquery
    SetEnvIf Request_URI "^/chat/(ws|media/)" aipbx_no_query
    CustomLog \${APACHE_LOG_DIR}/aipbx_ssl_access.log aipbx_combined env=!aipbx_no_query
    CustomLog \${APACHE_LOG_DIR}/aipbx_ssl_access.log aipbx_noquery env=aipbx_no_query
</VirtualHost>
VHOST

cat > /etc/apache2/conf-available/aipbx-routing.conf << 'ROUTING'
<Directory "/var/www/html">
    DirectoryIndex index.php
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/api/(v1|plugins)(/|$)
    RewriteRule ^(api|assets|modules)(/|$) - [L]
    RewriteRule ^chat/(ws|api|media)(/|$) - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</Directory>

<FilesMatch "\.aab$">
    Require all denied
</FilesMatch>

<FilesMatch "(composer\.(json|lock)|phpunit\.xml|phinx\.php|phpstan(\.dist)?\.neon|\.env(\..+)?)$">
    Require all denied
</FilesMatch>

<Directory "/var/www/html/src">
    Require all denied
</Directory>
<Directory "/var/www/html/vendor">
    Require all denied
</Directory>
<Directory "/var/www/html/db">
    Require all denied
</Directory>
<Directory "/var/www/html/bin">
    Require all denied
</Directory>
<Directory "/var/www/html/tests">
    Require all denied
</Directory>
# Server-side only: templates ran as PHP on their own, the docs told outsiders the stack.
<DirectoryMatch "^/var/www/html/(templates|config|lang|docs|etc)/">
    Require all denied
</DirectoryMatch>
<FilesMatch "\.md$">
    Require all denied
</FilesMatch>
ROUTING

# Security headers for every response (clickjacking, MIME sniffing, version
# disclosure). Referrer-Policy uses setifempty so pages that send their own
# stricter policy (e.g. /mobile-login: no-referrer) are not overridden.
# Ubuntu's security.conf is loaded after ours and would override these two.
sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf 2>/dev/null || true
cat > /etc/apache2/conf-available/aipbx-security.conf << 'SECHDR'
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Content-Security-Policy "frame-ancestors 'self'; base-uri 'self'; object-src 'none'"
    Header setifempty Referrer-Policy "strict-origin-when-cross-origin"
    # Brand files uploaded by the admin (may be SVG): the regex clean-up can be
    # bypassed, so no script may ever run in an SVG opened directly.
    <LocationMatch "^/assets/images/brand/">
        Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; sandbox"
    </LocationMatch>
</IfModule>
SECHDR
a2enconf aipbx-security 2>/dev/null

a2ensite aipbx.conf 2>/dev/null
a2dissite 000-default.conf 2>/dev/null || true
a2enconf aipbx-routing 2>/dev/null
apache2ctl configtest || error "Apache configuration is invalid"

# Composer dependencies. Errors were hidden (--quiet 2>/dev/null || true) and
# the install then stopped at the migrations with "vendor/bin/phinx" missing.
info "Installing PHP dependencies (Composer)..."
cd "$INSTALL_DIR/web"   # the migrations below run from here (vendor/bin/phinx)
# Streamed and double-bounded exactly like the package phase above, and for the
# same reason: `--no-progress` prints a line per package and NOTHING at all
# while a dist file is coming down, so a slow packagist or GitHub fetch looked
# like a hang and a working install was killed as one. Every download Composer
# makes lands in its cache, so that directory is handed over as the progress
# signal — bytes arriving there count as work, and only a fetch that neither
# prints nor delivers for COMPOSER_SILENCE seconds is called a stall. Output
# goes straight to stdout now (the CI tees it), so a cancelled job still has the
# lines; a file-and-tail pipe was what hid the apt phase on 2026-10-26.
COMPOSER_CACHE="${COMPOSER_CACHE_DIR:-$COMPOSER_HOME/cache}"   # where every dist lands (COMPOSER_CACHE_DIR wins if set)
mkdir -p "$COMPOSER_CACHE" 2>/dev/null || true   # so the first byte of progress is measurable
composer_status=0
# The two Composer variables are scoped to this call, as they always were: the
# rest of the install has no business inheriting COMPOSER_PROCESS_TIMEOUT.
(
    umask 022
    export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=300
    stream_bounded "composer install" "$COMPOSER_TIMEOUT" "$COMPOSER_SILENCE" \
        --progress="$COMPOSER_CACHE" -- \
        composer install --no-dev --no-interaction --no-progress
) || composer_status=$?
case $composer_status in
    0) ;;
    124) error "Composer install hit the ${COMPOSER_TIMEOUT}s budget: packagist or GitHub stopped answering (see output above)" ;;
    125) error "Composer install printed nothing and downloaded nothing for ${COMPOSER_SILENCE}s: packagist or GitHub is wedged (see output above)" ;;
    *) error "Composer install failed (see output above)" ;;
esac
[[ -x "$INSTALL_DIR/web/vendor/bin/phinx" ]] || error "Composer did not install the PHP dependencies (see output above)"

# Explicitly enforce safe 0755/0644 permissions on vendor files so inherited
# restrictive caller umasks (e.g. umask 077) cannot break web/cli autoloading.
find "$INSTALL_DIR/web/vendor" -type d -exec chmod 0755 {} + 2>/dev/null || true
find "$INSTALL_DIR/web/vendor" -type f -exec chmod 0644 {} + 2>/dev/null || true
chmod 0755 "$INSTALL_DIR/web/vendor/bin/"* 2>/dev/null || true

# Database schema (Phinx migrations), seed data, admin user, ODBC user.
# Migrations are the single source of truth: the old db/schema.sql had fallen
# behind (no conference / boss-secretary / permission-group tables, 48 missing
# columns) and migrations never ran on fresh installs. Re-running install.sh
# upgrades an existing database the same way.
info "Applying database migrations..."
( cd "$INSTALL_DIR/web" && php vendor/bin/phinx migrate -e production ) \
    || error "Database migrations failed (see output above)"
# seed.sql uses INSERT IGNORE: safe on a migrated or already-seeded database.
mysql asterisk < "$INSTALL_DIR/db/seed.sql" || error "Loading seed data failed"
# Stop campaign/event work until the moved plugins and their dialplan are ready.
systemctl stop aipbx-webhook-worker.service 2>/dev/null || true
# Move formerly bundled plugins before discovering hooks; no network required.
install -d -o www-data -g www-data -m 0750 /var/lib/aipbx/plugins
runuser -u www-data -- php "$INSTALL_DIR/web/bin/migrate-bundled-plugins.php" || error "Bundled plugin transition failed"

# Ensure plg_* tables exist for all enabled plugins (schema.sql) on install/upgrade
php -r "require '$INSTALL_DIR/web/config.php'; if (is_file('$INSTALL_DIR/web/src/core/PluginManager.php')) { require_once '$INSTALL_DIR/web/src/core/PluginManager.php'; PluginManager::syncAllEnabledPluginSchemas(); }" || warn "One or more plugin schema syncs reported an issue"

if ! is_upgrade; then
ADMIN_HASH=$(printf '%s' "$ADMIN_PASS" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);')
ADMIN_EXISTS=$(mysql -sN asterisk -e "SELECT COUNT(*) FROM sys_users WHERE username='admin';" 2>/dev/null || echo "0")
if [[ "${ADMIN_EXISTS:-0}" -eq 0 ]]; then
    mysql asterisk <<EOF
    INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, role, is_active, can_listen_recordings, can_view_all_cdrs, can_view_queue_monitor)
    VALUES ('admin', '${ADMIN_HASH}', 'Administrator', '1000', '${ADMIN_SIP_PASS}', 'admin', 1, 1, 1, 1);
EOF
    ok "Admin user created"
else
    mysql asterisk <<EOF 2>/dev/null || true
    UPDATE sys_users SET password_hash='${ADMIN_HASH}' WHERE username='admin';
EOF
    ok "Admin password updated in existing database"
fi
fi   # install mode — the admin password is never touched on upgrade

# Asterisk writes CDRs (cdr_adaptive_odbc → asteriskcdr) and queue_log
# (extconfig → asteriskqueue) through ODBC; call reports, call history and
# call-center reports read those tables. This user did not exist before, so
# nothing was ever recorded.
mysql <<EOF
CREATE USER IF NOT EXISTS 'asterisk_odbc'@'localhost' IDENTIFIED BY '$(sql_q "${ODBC_PASS}")';
ALTER USER 'asterisk_odbc'@'localhost' IDENTIFIED BY '$(sql_q "${ODBC_PASS}")';
GRANT SELECT, INSERT ON asterisk.asteriskcdr TO 'asterisk_odbc'@'localhost';
GRANT SELECT, INSERT ON asterisk.asteriskqueue TO 'asterisk_odbc'@'localhost';
FLUSH PRIVILEGES;
EOF
ok "Database schema migrated, seed data and ODBC user in place"

# Nginx Stream Multiplexer on Port 443 (ALPN routing: HTTPS/WSS -> Apache 8443, TURNS -> coturn 5349)
# nginx never decrypts TLS here, so the client address reaches Apache only
# through a PROXY protocol header. coturn cannot parse that header, so TURNS
# goes through a local relay (127.0.0.1:15349) that strips it. Desk phones on
# networks that let TLS out only on 443 (Provisioning → Regions, SIP on 443) go
# to Asterisk's TLS transport (5061): by their region's own server name
# (aipbx-sip443/names.conf), or without a name by their addresses
# (aipbx-sip443/addresses.conf) when they send no ALPN, as TURN does. aipbx-priv
# sip443 writes both, from the portal: their folder is writable in Apache's
# sandbox (ReadWritePaths below). The relay is a socket, so it takes no port a
# transport could want.
mkdir -p /etc/nginx/aipbx-sip443
# 1.71.0 kept the lists beside nginx.conf, where the portal could not write them.
[ -f /etc/nginx/aipbx-sip443-names.conf ] && mv -f /etc/nginx/aipbx-sip443-names.conf /etc/nginx/aipbx-sip443/names.conf
[ -f /etc/nginx/aipbx-sip443.conf ] && mv -f /etc/nginx/aipbx-sip443.conf /etc/nginx/aipbx-sip443/addresses.conf
rm -f /etc/nginx/aipbx-sip443-names.conf.prev /etc/nginx/aipbx-sip443.conf.prev
for f in names.conf addresses.conf; do
    [ -f "/etc/nginx/aipbx-sip443/$f" ] || echo "# Written by aipbx-priv sip443 (Provisioning → Regions)" > "/etc/nginx/aipbx-sip443/$f"
done
# The lists again from the regions, so an update never leaves them behind the database.
if sip443_lines="$(php -r "require '$INSTALL_DIR/web/config.php'; require_once '$INSTALL_DIR/web/src/services/PhoneRegionService.php'; echo implode(PHP_EOL, PhoneRegionService::sip443Lines());" 2>/dev/null)"; then
    printf '# Written by aipbx-priv sip443 (Provisioning → Regions)\n' | tee /etc/nginx/aipbx-sip443/names.conf > /etc/nginx/aipbx-sip443/addresses.conf
    while read -r kind value; do
        [[ "$value" =~ ^[A-Za-z0-9.:/-]{1,253}$ ]] || continue
        case "$kind" in
            name) echo "$value 1;" >> /etc/nginx/aipbx-sip443/names.conf ;;
            addr) echo "$value 1;" >> /etc/nginx/aipbx-sip443/addresses.conf ;;
        esac
    done <<< "$sip443_lines"
fi
cat > /etc/nginx/aipbx-stream.conf << 'STREAM'
# AI PBX edge multiplexer on 443 (managed by install.sh)
stream {
    log_format aipbx_stream '$remote_addr [$time_local] alpn="$ssl_preread_alpn_protocols" backend=$aipbx_backend status=$status bytes_s=$bytes_sent bytes_r=$bytes_received';
    access_log /var/log/nginx/stream.log aipbx_stream;

    limit_conn_zone $binary_remote_addr zone=aipbx_stream_conn:10m;

    map $ssl_preread_server_name $aipbx_sip_name {
        default 0;
        include /etc/nginx/aipbx-sip443/names.conf;
    }

    geo $aipbx_sip_addr {
        default 0;
        include /etc/nginx/aipbx-sip443/addresses.conf;
    }

    # Key: region name? : region address? : ALPN
    map "$aipbx_sip_name:$aipbx_sip_addr:$ssl_preread_alpn_protocols" $aipbx_backend {
        default     127.0.0.1:8443;
        # Browsers send no ALPN for TURNS; RFC 7443 clients send stun.turn.
        "0:0:"      127.0.0.1:15349;
        ~stun\.turn 127.0.0.1:15349;
        # A browser or an HTTPS download on a region's name stays with the portal.
        ~^1:.*(h2|http/1\.1) 127.0.0.1:8443;
        # A phone region's own name, or no ALPN from its addresses: SIP over TLS.
        ~^1:        unix:/run/aipbx-sip443.sock;
        "0:1:"      unix:/run/aipbx-sip443.sock;
    }

    server {
        listen 443;
        listen [::]:443;
        ssl_preread on;
        preread_timeout 5s;
        limit_conn aipbx_stream_conn 128;
        proxy_pass $aipbx_backend;
        proxy_protocol on;
        proxy_timeout 3600s;
        proxy_connect_timeout 5s;
    }

    server {
        listen 127.0.0.1:15349 proxy_protocol;
        proxy_pass 127.0.0.1:5349;
        proxy_timeout 3600s;
        proxy_connect_timeout 5s;
    }

    server {
        listen unix:/run/aipbx-sip443.sock proxy_protocol;
        proxy_pass 127.0.0.1:5061;
        proxy_timeout 3600s;
        proxy_connect_timeout 5s;
    }
}
STREAM
# Older installs had the stream block inline in nginx.conf: replace it.
if grep -q 'map \$ssl_preread_alpn_protocols \$turn_backend' /etc/nginx/nginx.conf 2>/dev/null; then
    sed -i '/^stream {$/,/^}$/d' /etc/nginx/nginx.conf
fi
if ! grep -q 'include /etc/nginx/aipbx-stream.conf;' /etc/nginx/nginx.conf 2>/dev/null; then
    sed -i '/^http {/i include /etc/nginx/aipbx-stream.conf;\n' /etc/nginx/nginx.conf
fi

# Ubuntu 24.04/26.04+ systemd proc isolation drop-in (allow Apache/PHP to inspect /proc/meminfo and pgrep asterisk)
mkdir -p /etc/systemd/system/apache2.service.d
# Ubuntu's apache2 unit makes /etc/sudoers{,.d} inaccessible, which breaks
# every portal call to aipbx-priv. Reset the list and restore the rest of it.
cat > /etc/systemd/system/apache2.service.d/override.conf << 'APACHEOVERRIDE'
[Service]
ProcSubset=all
ProtectProc=default
InaccessiblePaths=
InaccessiblePaths=/boot /root -/etc/ssh -/etc/apt -/etc/.git -/etc/.svn -/etc/ai-pbx-migrator.env
# ProtectSystem=full makes /etc read-only for Apache and for aipbx-priv run
# through sudo inside it: "Apply" writes generated dialplan/endpoint files under /etc/asterisk/pbx and settings in /etc/asterisk;
# phone regions write nginx's lists for SIP on 443.
ReadWritePaths=/etc/asterisk /etc/postfix /etc/fail2ban/jail.d -/etc/chrony/conf.d -/etc/nginx/aipbx-sip443 /etc/apache2/aipbx
APACHEOVERRIDE
systemctl daemon-reload

# Privileged helper: the portal's ONLY root entry point. Every subcommand maps
# to one fixed operation with validated arguments (see conf/sbin/aipbx-priv).
# Copied (not symlinked) so it stays root-owned and outside anything www-data can write.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-priv" /usr/local/sbin/aipbx-priv
# Update command (sudo aipbx-update); the portal calls it through aipbx-priv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-update" /usr/local/sbin/aipbx-update
# Daily database/configuration backup (cron below).
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-backup" /usr/local/sbin/aipbx-backup
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-restore" /usr/local/sbin/aipbx-restore
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-readdress" /usr/local/sbin/aipbx-readdress
# Asterisk sound packs (Sounds page); the portal calls it through aipbx-priv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-sounds" /usr/local/sbin/aipbx-sounds

# Runtime of the local AI service (AI → Local models, step 11); the portal
# calls it through aipbx-priv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-ai-setup" /usr/local/sbin/aipbx-ai-setup

# Self test: every health check now (Admin -> Health checks), run at the end of install.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-selftest" /usr/local/sbin/aipbx-selftest

# DHCP check for PBX → Network services; the portal calls it through aipbx-priv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-dhcp-probe" /usr/local/sbin/aipbx-dhcp-probe
# VPN server and client connections; the portal calls it through aipbx-priv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-vpn" /usr/local/sbin/aipbx-vpn
# The server's own address (Network settings); a change on trial goes back at a reboot.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-netcfg" /usr/local/sbin/aipbx-netcfg
cat > /etc/systemd/system/aipbx-netcfg-boot.service << 'NETCFGUNIT'
[Unit]
Description=AiPBX: an address change not confirmed before a reboot goes back
Before=network-online.target
After=systemd-networkd.service NetworkManager.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/aipbx-netcfg boot-check

[Install]
WantedBy=multi-user.target
NETCFGUNIT
systemctl daemon-reload
systemctl enable aipbx-netcfg-boot.service >/dev/null 2>&1 || true
# --- phone discovery engine (PBX -> Phones -> Discovered on the network):
# looks at the DHCP lease file, the ARP table and dnsmasq's log (opt-in sweep)
# and records what it found; the portal decides what happens to it. Installed on
# fresh installs AND by --upgrade.
if [ -d "$INSTALL_DIR/engine" ]; then
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$APT_LOCK_WAIT" install -y -qq python3-pymysql
    fi
    install -d -o www-data -g www-data -m 0750 /var/lib/aipbx/engine
    # dnsmasq's DHCP log is read with journalctl and it is the source that finds a
    # phone whose lease has expired. Without this group www-data gets "No journal
    # files were opened due to insufficient permissions" (exit 1) and the engine's
    # journal reader returns nothing on every run, with no other sign of trouble.
    usermod -a -G systemd-journal www-data 2>/dev/null || true
    install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-phone-discovery" /usr/local/sbin/aipbx-phone-discovery
    chmod 755 "$INSTALL_DIR/engine"
    # The CLI the wrapper and an operator both call, next to the other bin scripts.
    for script in aipbx_phone_discovery.php aipbx_cisco_firmware.php; do
        if [ -f "$INSTALL_DIR/web/bin/$script" ]; then
            chmod 755 "$INSTALL_DIR/web/bin/$script"
            ln -sf "$INSTALL_DIR/web/bin/$script" "/usr/local/bin/$script"
        fi
    done
fi

# --- signaling capture (opt-in: conf/asterisk/hep.conf decides whether
# Asterisk sends HEP; the local daemon stores events for the portal's
# Signaling page). Installed here on fresh installs AND by --upgrade, so
# aipbx-update upgrades land the same components.
if [ -d "$INSTALL_DIR/signaling" ]; then
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$APT_LOCK_WAIT" install -y -qq python3-pymysql
    fi
    if [ -d /etc/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
        install -o root -g root -m 0644 "$INSTALL_DIR/conf/systemd/aipbx-signaling.service" /etc/systemd/system/aipbx-signaling.service
        systemctl daemon-reload >/dev/null 2>&1 || true
        systemctl enable --now aipbx-signaling.service >/dev/null 2>&1 || true
        # A running daemon picks up a changed unit only here; no call depends on it.
        systemctl try-restart aipbx-signaling.service >/dev/null 2>&1 || true
    fi
fi

# PBX → Network services (DHCP/TFTP for desk phones): dnsmasq in its own unit,
# off until the page writes a configuration. aipbx-priv writes it into this
# root-owned folder (a dnsmasq file can run commands as root, so the portal
# must not be able to write it); TFTP serves only the uploads in
# /var/lib/aipbx/tftp, never the per-phone configuration.
install -d -o root -g root -m 0755 /var/lib/aipbx-netsvc
install -d -o www-data -g www-data -m 0755 /var/lib/aipbx/tftp
install -d -o www-data -g www-data -m 0750 /var/lib/aipbx/plugins
install -d -o www-data -g www-data -m 0750 /var/lib/aipbx/api-ratelimit
# Backup page: an upload is collected here piece by piece, then aipbx-restore checks it.
install -d -o www-data -g www-data -m 0700 /var/lib/aipbx/backup-upload

cat > /etc/systemd/system/aipbx-webhook-worker.service << 'WEBHOOKUNIT'
[Unit]
Description=AiPBX Outbound Webhook & Plugin Event Worker
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php /var/www/html/bin/webhook_worker.php --daemon
Restart=always
RestartSec=3
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
ProtectKernelTunables=yes
ProtectControlGroups=yes
RestrictRealtime=yes
InaccessiblePaths=-/etc/ai-pbx-migrator.env
UMask=0027

[Install]
WantedBy=multi-user.target
WEBHOOKUNIT
systemctl daemon-reload
systemctl enable aipbx-webhook-worker.service >/dev/null 2>&1 || true
# Network -> VPN Server and VPN Client: one unit per server or connection. All of it is
# under /var/lib/aipbx-vpn, root only. An OpenVPN or WireGuard of the server's own
# (openvpn-client@..., wg-quick@...) is a different unit and is not touched.
cat > /etc/systemd/system/aipbx-vpn@.service << 'VPNUNIT'
[Unit]
Description=AiPBX VPN %i (Network -> VPN)
After=network-online.target firewalld.service
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
ExecStart=/usr/local/sbin/aipbx-vpn run %i
Restart=always
RestartSec=15
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
VPNUNIT
systemctl daemon-reload
# What the state says should run, runs; nothing restarts unless its configuration
# changed, so an update does not drop a call on the VPN.
/usr/local/sbin/aipbx-vpn reapply >/dev/null 2>&1 || warn "VPN: could not apply the saved configuration (Network -> VPN Server)"

cat > /etc/systemd/system/aipbx-dnsmasq.service << 'NETSVC'
[Unit]
Description=AiPBX DHCP/TFTP for desk phones (PBX -> Network services)
After=network-online.target
Wants=network-online.target
ConditionPathExists=/var/lib/aipbx-netsvc/dnsmasq.conf

[Service]
# Binds its ports as root and then drops to its own user by itself.
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
PrivateTmp=yes
ExecStart=/usr/sbin/dnsmasq --keep-in-foreground --conf-file=/var/lib/aipbx-netsvc/dnsmasq.conf
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
NETSVC
systemctl daemon-reload
systemctl enable aipbx-dnsmasq.service >/dev/null 2>&1 || true
systemctl try-restart aipbx-dnsmasq.service 2>/dev/null || true
# A separate TFTP server (tftpd-hpa and the like) takes the port: dnsmasq then
# restarts for ever and phones get that server's files. Not ours to remove.
# "|| true": grep finds nothing when our own dnsmasq holds the port, and this
# script runs with pipefail, so without it every update stops here.
if [ -f /var/lib/aipbx-netsvc/dnsmasq.conf ] && grep -q '^enable-tftp' /var/lib/aipbx-netsvc/dnsmasq.conf; then
    tftp_pid="$(systemctl show -p MainPID --value aipbx-dnsmasq.service 2>/dev/null)"
    tftp_other="$(ss -Hlunp 'sport = :69' 2>/dev/null | grep -v "pid=${tftp_pid:-0}," | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | head -1 || true)"
    if [ -n "$tftp_other" ]; then
        warn "UDP port 69 is held by $tftp_other: PBX -> Network services TFTP cannot start, and phones get that program's files. Stop it to use the portal's TFTP."
    fi
fi

# Read-only FTP of the same folder for phones that fetch files over FTP (NEC
# DT700 and others): off until the page turns it on; aipbx-priv writes its
# configuration and the password file of the extra FTP users (not system
# accounts: PAM checks them against that file).
systemctl disable --now vsftpd.service >/dev/null 2>&1 || true
systemctl mask vsftpd.service >/dev/null 2>&1 || true
cat > /etc/pam.d/aipbx-ftp << 'FTPPAM'
# AiPBX read-only FTP (PBX -> Network services). Every FTP user, NECSDT700 (the
# user of NEC DT700/DT800 phones) included, is checked against the password file
# written by aipbx-priv. No system account can log in.
auth    required   pam_pwdfile.so pwdfile=/var/lib/aipbx-netsvc/ftp.passwd
account required   pam_permit.so
FTPPAM
cat > /etc/systemd/system/aipbx-vsftpd.service << 'FTPUNIT'
[Unit]
Description=AiPBX read-only FTP for desk phones (PBX -> Network services)
After=network-online.target
Wants=network-online.target
ConditionPathExists=/var/lib/aipbx-netsvc/vsftpd.conf

[Service]
# vsftpd has to start as root (port 21, chroot of each login).
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
PrivateTmp=yes
ExecStart=/usr/sbin/vsftpd /var/lib/aipbx-netsvc/vsftpd.conf
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
FTPUNIT
systemctl daemon-reload
# Up to 1.30.1 NECSDT700 and anonymous logins needed no password. Write the FTP
# configuration again from the portal's settings, so a running FTP server asks
# for passwords right after the upgrade (and NECSDT700 is in the password file).
if [[ -f /var/lib/aipbx-netsvc/vsftpd.conf ]]; then
    ( cd "$INSTALL_DIR/web" && php bin/netsvc_ftp_reapply.php ) \
        || warn "FTP settings could not be applied again: save PBX → Network services once"
fi
systemctl try-restart aipbx-vsftpd.service 2>/dev/null || true

# Phone logs (PBX -> Phone logs): desk phones send syslog (UDP 514) to
# aipbx-syslogd, switched on under Network services (aipbx-priv netsvc
# syslog-on writes its networks). Own system user in group www-data, so the
# portal reads the log; only the right to bind port 514.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-syslogd" /usr/local/sbin/aipbx-syslogd
id -u aipbx-syslog >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent \
    --shell /usr/sbin/nologin --gid www-data aipbx-syslog
cat > /etc/systemd/system/aipbx-syslog.service << 'SYSLOGUNIT'
[Unit]
Description=AiPBX phone log receiver, syslog UDP 514 (PBX -> Phone logs)
After=network-online.target
Wants=network-online.target
ConditionPathExists=/var/lib/aipbx-netsvc/syslog.conf

[Service]
ExecStart=/usr/local/sbin/aipbx-syslogd
User=aipbx-syslog
Group=www-data
LogsDirectory=aipbx-phones
LogsDirectoryMode=0750
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
SYSLOGUNIT
systemctl daemon-reload
systemctl try-restart aipbx-syslog.service 2>/dev/null || true

# Sudoers: www-data may run the helper and nothing else as root. Granting
# asterisk/postconf/fail2ban-client/firewall-cmd directly would let any code
# execution bug in the portal escalate straight to root.
cat > /etc/sudoers.d/aipbx << 'SUDOOVERRIDE'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/aipbx-priv
SUDOOVERRIDE
chmod 440 /etc/sudoers.d/aipbx
visudo -cf /etc/sudoers.d/aipbx >/dev/null || error "Invalid sudoers file generated: /etc/sudoers.d/aipbx"

# Postfix mail service initialization
[[ ! -L /etc/postfix/sasl_passwd ]] || error "Unsafe Postfix credential path"
touch /etc/postfix/sasl_passwd
chmod 0600 /etc/postfix/sasl_passwd
chown root:root /etc/postfix/sasl_passwd
systemctl enable postfix
systemctl restart postfix

systemctl restart apache2
systemctl enable apache2

if nginx -t; then
    systemctl restart nginx
    systemctl enable nginx
else
    error "Nginx configuration test failed"
fi

ok "Nginx (Edge 443 ALPN Multiplexer) & Apache2 (80/8443) configured"

# ============================================================================
