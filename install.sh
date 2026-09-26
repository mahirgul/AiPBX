#!/usr/bin/env bash
# ============================================================================
# AI PBX — Ubuntu LTS Fresh Install Script
# Supported: Ubuntu 26.04 LTS
#
# Usage:
#   git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
#   cd /opt/aipbx
#   sudo bash install.sh
#
# What this script does:
#   - Generates strong random passwords for all services (no hardcoded defaults)
#   - Asks for FQDN — uses Let's Encrypt if provided, self-signed if not
#     (blank => local name "aipbx.local", published on the LAN via mDNS/avahi)
#   - Installs: Asterisk, MariaDB, Apache2+PHP, coturn, Go+Chat, fail2ban
#   - Creates a single admin user with a secure random password
#   - Displays and saves all credentials at the end
# ============================================================================
set -euo pipefail

# --- Color codes ---
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()  { echo -e "${BLUE}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[ OK ]${NC}  $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error() { echo -e "${RED}[ERR ]${NC}  $*"; exit 1; }
step()  { echo -e "\n${CYAN}${BOLD}══ $* ══${NC}"; }

# --- Root check ---
[[ $EUID -ne 0 ]] && error "Run this script as root: sudo bash install.sh"

# --- Script and install directories ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "")"
INSTALL_DIR="${AIPBX_INSTALL_DIR:-/opt/aipbx}"

# Helper for interactive prompts when running via piped curl (reading from /dev/tty)
prompt_read() {
    local prompt="$1"
    local varname="$2"
    local default_val="${3:-}"
    local val=""

    if [[ -t 0 ]]; then
        read -r -p "$prompt" val || val=""
    elif [[ -e /dev/tty ]]; then
        read -r -p "$prompt" val < /dev/tty || val=""
    else
        val=""
    fi
    val="${val:-$default_val}"
    printf -v "$varname" '%s' "$val"
}

# If running via curl pipe or outside a cloned repository:
if [[ ! -f "$SCRIPT_DIR/web/config.php" ]]; then
    step "Bootstrapping Repository"
    info "Running via remote curl installer. Cloning AiPBX to $INSTALL_DIR..."
    
    if ! command -v git >/dev/null 2>&1; then
        info "Installing git..."
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq && apt-get install -y -qq git
    fi
    
    if [[ -d "$INSTALL_DIR/.git" ]]; then
        info "Existing repository found in $INSTALL_DIR, updating..."
        git -C "$INSTALL_DIR" fetch origin main
        git -C "$INSTALL_DIR" reset --hard origin/main
    else
        mkdir -p "$(dirname "$INSTALL_DIR")"
        git clone --depth 1 https://github.com/mahirgul/AiPBX.git "$INSTALL_DIR"
    fi
    SCRIPT_DIR="$INSTALL_DIR"
fi

# ============================================================================
# STEP 0: WELCOME BANNER
# ============================================================================
clear 2>/dev/null || true
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════════════╗"
echo "  ║              AI PBX — Fresh Installation                    ║"
echo "  ║         Open Source Enterprise Phone System                  ║"
echo "  ╚══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  Source directory : ${YELLOW}$SCRIPT_DIR${NC}"
echo -e "  Install directory: ${YELLOW}$INSTALL_DIR${NC}"
echo ""

# ============================================================================
# STEP 1: FQDN / DOMAIN CONFIGURATION
# ============================================================================
step "1. Domain / FQDN Configuration"

SERVER_IP=$(hostname -I | awk '{print $1}')
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
    # Yerel kurulum: Let's Encrypt .local için sertifika veremez; self-signed
    # sertifika (SAN: alan adı + sunucu IP) üretilir, ad LAN'a mDNS ile duyurulur.
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

echo ""

# ============================================================================
# STEP 2: GENERATE ALL RANDOM CREDENTIALS
# ============================================================================
step "2. Generating Secure Random Credentials"

# Generate strong random password (reads limited bytes from /dev/urandom to avoid SIGPIPE under pipefail)
gen_pass() {
    local len="${1:-20}"
    head -c 1024 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9!@#%^&*' | head -c "$len"
}

# Generate hex secret
gen_hex() {
    local len="${1:-32}"
    openssl rand -hex "$len"
}

# All passwords generated randomly — no static defaults
ADMIN_PASS=$(gen_pass 16)
ADMIN_SIP_PASS=$(gen_hex 12)
DB_USER="aipbx_portal"
DB_PASS=$(gen_pass 20)
MIGRATOR_USER="aipbx_migrator"
MIGRATOR_PASS=$(gen_pass 20)
AMI_USER="aipbx-manager"
AMI_PASS=$(gen_hex 16)
TURN_SECRET=$(gen_hex 32)

ok "All credentials generated"
echo ""

# ============================================================================
# STEP 3: INSTALL SYSTEM PACKAGES
# ============================================================================
step "3. Installing System Packages"

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq

apt-get install -y \
  asterisk \
  asterisk-core-sounds-en \
  asterisk-core-sounds-en-wav \
  asterisk-modules \
  mariadb-server \
  mariadb-client \
  nginx \
  libnginx-mod-stream \
  apache2 \
  libapache2-mod-php \
  php \
  php-mysql \
  php-mbstring \
  php-xml \
  php-curl \
  php-gd \
  php-intl \
  php-bcmath \
  php-zip \
  php-odbc \
  unixodbc \
  odbc-mariadb \
  composer \
  coturn \
  golang-go \
  build-essential \
  libc6-dev \
  git \
  fail2ban \
  firewalld \
  ghostscript \
  libtiff-tools \
  postfix \
  libsasl2-modules \
  mailutils \
  certbot \
  python3-certbot-apache \
  openssl \
  avahi-daemon \
  avahi-utils \
  2>&1 | tail -5

ok "System packages installed"

# Remove default Nginx site immediately so it does not conflict with Apache on port 80
rm -f /etc/nginx/sites-enabled/default
systemctl reload nginx 2>/dev/null || true

# ============================================================================
# STEP 4: DIRECTORY STRUCTURE
# ============================================================================
step "4. Creating Directory Structure"

if [[ "$SCRIPT_DIR" != "$INSTALL_DIR" ]]; then
    mkdir -p "$INSTALL_DIR"
    cp -a "$SCRIPT_DIR"/* "$INSTALL_DIR"/
    cp -a "$SCRIPT_DIR"/.gitignore "$INSTALL_DIR"/ 2>/dev/null || true
fi

rm -rf /var/www/html
ln -sf "$INSTALL_DIR/web" /var/www/html

# Symlink helper binaries and dialplan scripts to /usr/local/bin
for script in feature_code_action.php process_incoming_fax.sh process_outgoing_fax_result.sh fax_cleanup.sh fax_pending_sweep.sh sync_queue_logs.php; do
    if [[ -f "$INSTALL_DIR/web/bin/$script" ]]; then
        ln -sf "$INSTALL_DIR/web/bin/$script" "/usr/local/bin/$script"
        chmod 755 "$INSTALL_DIR/web/bin/$script"
    fi
done

# AI PBX automated maintenance and queue sync crons
cat > /etc/cron.d/aipbx << 'CRON'
# AI PBX Automated Maintenance & Synchronization Crons
# Asterisk Queue Log to MariaDB DB Synchronization
* * * * * root /usr/local/bin/sync_queue_logs.php >/dev/null 2>&1

# Daily fax retention & spool cleanup (fax_retention_days)
15 3 * * * root /usr/local/bin/fax_cleanup.sh >/dev/null 2>&1

# Outgoing fax pending sweep (detect unanswered / stale spool files)
* * * * * root /usr/local/bin/fax_pending_sweep.sh >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/aipbx

mkdir -p /var/www/faxes
mkdir -p /var/spool/asterisk/fax/outgoing
mkdir -p /var/spool/asterisk/monitor
mkdir -p /var/lib/asterisk/sounds/custom
mkdir -p /var/lib/asterisk/sounds/tr
mkdir -p /var/lib/asterisk/moh
mkdir -p /var/lib/aipbx/chat_files
mkdir -p /etc/asterisk/pbx
mkdir -p /etc/asterisk/keys
mkdir -p /var/log/httpd

chown -R www-data:www-data /var/www/faxes /var/lib/aipbx
chmod 755 "$(dirname "$INSTALL_DIR")" "$INSTALL_DIR"

ok "Directory structure created"

# ============================================================================
# STEP 5: ENVIRONMENT FILE
# ============================================================================
step "5. Creating Environment Configuration"

cat > /etc/ai-pbx.env << ENVFILE
# AI PBX - Environment Configuration
# Generated: $(date -u +"%Y-%m-%d %H:%M:%S UTC")
# KEEP THIS FILE SECURE — it contains all service secrets.
# You can update values here or via the Admin Panel (Settings → System).

# --- Database runtime user (DML: SELECT/INSERT/UPDATE/DELETE) ---
DB_HOST=localhost
DB_NAME=asterisk
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# --- Database DDL user (Phinx migrations: CREATE/ALTER/DROP) ---
MIGRATOR_DB_USER=${MIGRATOR_USER}
MIGRATOR_DB_PASS=${MIGRATOR_PASS}

# --- Site identity ---
SITE_NAME=AI PBX Portal
PORTAL_DOMAIN=${PORTAL_DOMAIN}
TIMEZONE=Europe/Istanbul

# --- Asterisk AMI (Manager Interface) ---
AMI_HOST=127.0.0.1
AMI_PORT=5038
AMI_USER=${AMI_USER}
AMI_PASS=${AMI_PASS}

# --- File/directory paths ---
ASTERISK_PBX_DIR=/etc/asterisk/pbx
ASTERISK_CALL_SPOOL=/var/spool/asterisk/outgoing
FAX_STORAGE_PATH=/var/www/faxes
FAX_OUTGOING_SPOOL=/var/spool/asterisk/fax/outgoing
SOUNDS_CUSTOM_DIR=/var/lib/asterisk/sounds/custom
MOH_BASE_DIR=/var/lib/asterisk/moh
MONITOR_STORAGE_PATH=/var/spool/asterisk/monitor
PJSIP_DTLS_CERT=/etc/asterisk/keys/asterisk.pem
GS_BINARY=/usr/bin/gs
SYNC_QUEUE_LOGS_SCRIPT=/usr/local/bin/sync_queue_logs.php

# --- Mail (password reset / fax notification emails) ---
MAIL_FROM_ADDRESS=no-reply@${PORTAL_DOMAIN}
MAIL_FROM_NAME=AI PBX Portal

# --- WebRTC TURN (coturn) ---
TURN_HOST=${PORTAL_DOMAIN}
TURN_SECRET=${TURN_SECRET}
TURNS_PORT=5349
ENVFILE

chown root:www-data /etc/ai-pbx.env
chmod 640 /etc/ai-pbx.env

ok "Environment file created: /etc/ai-pbx.env"

# ============================================================================
# STEP 6: MARIADB DATABASE
# ============================================================================
step "6. Configuring MariaDB"

systemctl start mariadb
systemctl enable mariadb

# Create database
mysql -e "CREATE DATABASE IF NOT EXISTS asterisk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Create app users (drop first to allow re-run)
mysql -e "
DROP USER IF EXISTS '${DB_USER}'@'localhost';
DROP USER IF EXISTS '${MIGRATOR_USER}'@'localhost';
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON asterisk.* TO '${DB_USER}'@'localhost';
CREATE USER '${MIGRATOR_USER}'@'localhost' IDENTIFIED BY '${MIGRATOR_PASS}';
GRANT ALL PRIVILEGES ON asterisk.* TO '${MIGRATOR_USER}'@'localhost';
FLUSH PRIVILEGES;
"

# Load schema and seed (fresh install only)
TABLE_COUNT=$(mysql -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='asterisk';" 2>/dev/null || echo "0")

if [[ "${TABLE_COUNT:-0}" -eq 0 ]]; then
    info "Loading database schema..."
    mysql asterisk < "$INSTALL_DIR/db/schema.sql"
    mysql asterisk < "$INSTALL_DIR/db/seed.sql"

    # Create admin user with generated random password (no hardcoded admin123!)
    ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")
    mysql asterisk -e "
    INSERT IGNORE INTO sys_users (username, password_hash, full_name, extension, sip_password, role, is_active, can_listen_recordings, can_view_all_cdrs, can_view_queue_monitor)
    VALUES ('admin', '${ADMIN_HASH}', 'Administrator', '1000', '${ADMIN_SIP_PASS}', 'admin', 1, 1, 1, 1);
    "
    ok "Database schema loaded and admin user created"
else
    warn "Database already has ${TABLE_COUNT} tables — schema not reloaded."
    ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")
    ADMIN_EXISTS=$(mysql -sN asterisk -e "SELECT COUNT(*) FROM sys_users WHERE username='admin';" 2>/dev/null || echo "0")
    if [[ "${ADMIN_EXISTS:-0}" -eq 0 ]]; then
        mysql asterisk -e "
        INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, role, is_active, can_listen_recordings, can_view_all_cdrs, can_view_queue_monitor)
        VALUES ('admin', '${ADMIN_HASH}', 'Administrator', '1000', '${ADMIN_SIP_PASS}', 'admin', 1, 1, 1, 1);
        "
        ok "Admin user created in existing database"
    else
        mysql asterisk -e "UPDATE sys_users SET password_hash='${ADMIN_HASH}' WHERE username='admin';" 2>/dev/null || true
        ok "Admin password updated in existing database"
    fi
fi

ok "MariaDB configured"

# ============================================================================
# STEP 7: TLS CERTIFICATE
# ============================================================================
step "7. TLS Certificate Setup"

if [[ "$USE_LETSENCRYPT" == "true" ]]; then
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

if [[ "$USE_SELFSIGNED" == "true" ]]; then
    info "Generating self-signed TLS certificate..."
    mkdir -p /etc/ssl/aipbx

    # Tarayıcılar CN'e bakmaz, sadece subjectAltName'e bakar — SAN olmadan
    # sertifika "kabul et" dense bile WSS/TURNS bağlantılarında reddedilir.
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

# Yerel .local adını LAN'a mDNS ile duyur (hostname'i değiştirmeden alias olarak).
if [[ "${USE_MDNS:-false}" == "true" ]]; then
    grep -qE "[[:space:]]${PORTAL_DOMAIN}([[:space:]]|$)" /etc/hosts || echo "127.0.0.1 ${PORTAL_DOMAIN}" >> /etc/hosts
    cat > /etc/systemd/system/aipbx-mdns.service << MDNS
[Unit]
Description=AiPBX mDNS alias (${PORTAL_DOMAIN} -> ${SERVER_IP})
After=avahi-daemon.service network-online.target
Requires=avahi-daemon.service

[Service]
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

# ============================================================================
# STEP 8: NGINX (EDGE 443 MULTIPLEXER) & APACHE2 (BACKEND)
# ============================================================================
step "8. Configuring Nginx (Edge 443) & Apache2 (80/8443 Backend)"

# Ensure default Nginx HTTP site is removed so Apache binds to port 80 exclusively
rm -f /etc/nginx/sites-enabled/default
systemctl reload nginx 2>/dev/null || true

a2enmod rewrite proxy proxy_wstunnel proxy_http ssl headers php* 2>/dev/null || true
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Apache ports: Listen 80 for HTTP/ACME, 127.0.0.1:8443 for HTTPS behind Nginx
cat > /etc/apache2/ports.conf << 'PORTS'
# Apache listens on 80 for HTTP and 127.0.0.1:8443 for HTTPS (behind nginx)
Listen 80
Listen 127.0.0.1:8443
PORTS

cat > /etc/apache2/sites-available/aipbx.conf << VHOST
<VirtualHost *:80>
    ServerName ${PORTAL_DOMAIN}
    DocumentRoot /var/www/html

    # Let's Encrypt HTTP-01 challenge pass-through, redirect all other traffic to HTTPS
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [END,NE,R=permanent]

    ErrorLog \${APACHE_LOG_DIR}/aipbx_error.log
    CustomLog \${APACHE_LOG_DIR}/aipbx_access.log combined
</VirtualHost>

<VirtualHost 127.0.0.1:8443>
    ServerName ${PORTAL_DOMAIN}
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile    ${CERT_FILE}
    SSLCertificateKeyFile ${KEY_FILE}
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
    CustomLog \${APACHE_LOG_DIR}/aipbx_ssl_access.log combined
</VirtualHost>
VHOST

cat > /etc/apache2/conf-available/aipbx-routing.conf << 'ROUTING'
<Directory "/var/www/html">
    DirectoryIndex index.php
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted

    RewriteEngine On
    RewriteRule ^(api|assets|modules)(/|$) - [L]
    RewriteRule ^chat/(ws|api|media)(/|$) - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</Directory>

<FilesMatch "\.aab$">
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
ROUTING

a2ensite aipbx.conf 2>/dev/null
a2dissite 000-default.conf 2>/dev/null || true
a2enconf aipbx-routing 2>/dev/null

# Composer dependencies
cd "$INSTALL_DIR/web" && composer install --no-dev --no-interaction --quiet 2>/dev/null || true

# Nginx Stream Multiplexer on Port 443 (ALPN routing: HTTPS/WSS -> Apache 8443, TURNS -> coturn 5349)
if ! grep -q "map \$ssl_preread_alpn_protocols" /etc/nginx/nginx.conf 2>/dev/null; then
    sed -i '/^http {/i \
stream {\
    log_format stream_debug '\''$remote_addr [$time_local] alpn="$ssl_preread_alpn_protocols" backend=$turn_backend status=$status bytes_s=$bytes_sent bytes_r=$bytes_received'\'';\
    access_log /var/log/nginx/stream.log stream_debug;\
\
    map $ssl_preread_alpn_protocols $turn_backend {\
        default     127.0.0.1:8443;\
        ""          127.0.0.1:5349;\
    }\
\
    server {\
        listen 443;\
        listen [::]:443;\
        ssl_preread on;\
        proxy_pass $turn_backend;\
        proxy_timeout 3600s;\
        proxy_connect_timeout 5s;\
    }\
}\
' /etc/nginx/nginx.conf
fi

# Ubuntu 24.04/26.04+ systemd proc isolation drop-in (allow Apache/PHP to inspect /proc/meminfo and pgrep asterisk)
mkdir -p /etc/systemd/system/apache2.service.d
cat > /etc/systemd/system/apache2.service.d/override.conf << 'APACHEOVERRIDE'
[Service]
ProcSubset=all
ProtectProc=default
APACHEOVERRIDE
systemctl daemon-reload

# Allow web server (www-data) to access Asterisk control socket
usermod -aG asterisk www-data

# Sudoers permissions for AI PBX management (service restarts and postfix/asterisk controls)
cat > /etc/sudoers.d/aipbx << 'SUDOOVERRIDE'
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart asterisk, /usr/bin/systemctl reload asterisk, /usr/bin/systemctl restart apache2, /usr/bin/systemctl reload apache2, /usr/bin/systemctl restart mariadb, /usr/bin/systemctl restart postfix, /usr/bin/systemctl reload postfix, /usr/bin/systemctl restart fail2ban, /usr/bin/systemctl reload fail2ban, /usr/bin/systemctl restart firewalld, /usr/bin/systemctl reload firewalld, /usr/sbin/asterisk, /usr/sbin/postconf, /usr/sbin/postmap, /usr/sbin/postfix, /usr/bin/fail2ban-client, /usr/bin/firewall-cmd
asterisk ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart asterisk, /usr/bin/systemctl reload asterisk, /usr/bin/systemctl restart apache2, /usr/bin/systemctl reload apache2, /usr/bin/systemctl restart mariadb, /usr/bin/systemctl restart postfix, /usr/bin/systemctl reload postfix, /usr/bin/systemctl restart fail2ban, /usr/bin/systemctl reload fail2ban, /usr/bin/systemctl restart firewalld, /usr/bin/systemctl reload firewalld, /usr/sbin/asterisk, /usr/bin/fail2ban-client, /usr/bin/firewall-cmd
SUDOOVERRIDE
chmod 440 /etc/sudoers.d/aipbx

# Postfix mail service initialization
touch /etc/postfix/sasl_passwd
chown root:www-data /etc/postfix/sasl_passwd
chmod 660 /etc/postfix/sasl_passwd
systemctl enable postfix
systemctl restart postfix

systemctl restart apache2
systemctl enable apache2

nginx -t 2>/dev/null && {
    systemctl restart nginx
    systemctl enable nginx
}

ok "Nginx (Edge 443 ALPN Multiplexer) & Apache2 (80/8443) configured"

# ============================================================================
# STEP 9: ASTERISK CONFIGURATION
# ============================================================================
step "9. Configuring Asterisk PBX"

mkdir -p /etc/asterisk/pbx
cp "$INSTALL_DIR/asterisk-config/pbx/"*.conf /etc/asterisk/pbx/ 2>/dev/null || true

for f in extensions.conf pjsip.conf queues.conf musiconhold.conf http.conf rtp.conf modules.conf; do
    if [[ -f "$INSTALL_DIR/asterisk-config/$f" ]]; then
        cp "$INSTALL_DIR/asterisk-config/$f" /etc/asterisk/"$f"
    fi
done

cat > /etc/asterisk/manager.conf << MANAGER
[general]
enabled = yes
bindaddr = 127.0.0.1
port = 5038

[${AMI_USER}]
secret = ${AMI_PASS}
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = originate,call,agent
write = originate,call,agent
writetimeout = 5000
MANAGER

# Install Asterisk Sound Prompts (Turkish & WebRTC/IVR custom sounds)
info "Installing Asterisk sound prompts (Turkish & WebRTC/IVR sounds)..."
mkdir -p /var/lib/asterisk/sounds/custom /var/lib/asterisk/sounds/tr

if [[ -d "$INSTALL_DIR/sounds/custom" ]]; then
    cp -a "$INSTALL_DIR/sounds/custom/"* /var/lib/asterisk/sounds/custom/
fi

if [[ -d "$INSTALL_DIR/sounds/tr" ]]; then
    cp -a "$INSTALL_DIR/sounds/tr/"* /var/lib/asterisk/sounds/tr/
fi

# Set default language to Turkish in asterisk.conf
if [[ -f /etc/asterisk/asterisk.conf ]]; then
    if grep -q "defaultlanguage" /etc/asterisk/asterisk.conf; then
        sed -i 's/^;*defaultlanguage\s*=.*/defaultlanguage = tr/' /etc/asterisk/asterisk.conf
    else
        echo "defaultlanguage = tr" >> /etc/asterisk/asterisk.conf
    fi
    if ! grep -q "^\[files\]" /etc/asterisk/asterisk.conf; then
        cat >> /etc/asterisk/asterisk.conf << 'EOF'

[files]
astctlpermissions = 0660
astctlgroup = asterisk
EOF
    fi
fi

chown -R asterisk:asterisk /var/lib/asterisk/sounds/
chmod -R 755 /var/lib/asterisk/sounds/
chown -R asterisk:asterisk /etc/asterisk/
chmod -R 775 /etc/asterisk/pbx
chmod 664 /etc/asterisk/pbx/*.conf 2>/dev/null || true

systemctl restart asterisk
systemctl enable asterisk

# Sync initial endpoints, dialplans, and transports from DB to Asterisk
info "Syncing database endpoints and dialplan to Asterisk..."
php /var/www/html/src/asterisk_sync.php 2>/dev/null || true

ok "Asterisk configured"

# ============================================================================
# STEP 10: CHAT SERVICE (Go)
# ============================================================================
step "10. Building Chat Service"

if [[ -f "$INSTALL_DIR/chat/main.go" ]]; then
    info "Building chat service..."
    cd "$INSTALL_DIR/chat"
    CGO_ENABLED=0 go build -o aipbx-chat . 2>/dev/null || warn "Chat service build failed (Go dependencies may be missing)"

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
ExecStart=${INSTALL_DIR}/chat/aipbx-chat
Restart=always
RestartSec=3
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        systemctl enable aipbx-chat 2>/dev/null || true
        systemctl start aipbx-chat 2>/dev/null || true
        ok "Chat service built and started"
    fi
fi

# ============================================================================
# STEP 11: COTURN (WebRTC TURN)
# ============================================================================
step "11. Configuring coturn"

cat > /etc/turnserver.conf << TURNCONF
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
TURNCONF

# coturn "turnserver" kullanıcısıyla çalışır; root'a ait 600 anahtarı okuyamaz.
mkdir -p /etc/coturn
cp -L "$CERT_FILE" /etc/coturn/aipbx.crt
cp -L "$KEY_FILE" /etc/coturn/aipbx.key
chown turnserver:turnserver /etc/coturn/aipbx.crt /etc/coturn/aipbx.key 2>/dev/null || true
chmod 640 /etc/coturn/aipbx.key

mkdir -p /var/log/turnserver
chown turnserver:turnserver /var/log/turnserver 2>/dev/null || true
systemctl restart coturn 2>/dev/null || true
systemctl enable coturn 2>/dev/null || true

ok "coturn configured"

# ============================================================================
# STEP 12: SECURITY (FIREWALLD & FAIL2BAN)
# ============================================================================
step "12. Configuring Firewall (firewalld) & Fail2ban"

# 12a. Firewalld configuration
systemctl enable firewalld 2>/dev/null || true
systemctl start firewalld 2>/dev/null || true

firewall-cmd --permanent --add-service=http 2>/dev/null || true
firewall-cmd --permanent --add-service=https 2>/dev/null || true
firewall-cmd --permanent --add-service=ssh 2>/dev/null || true
firewall-cmd --permanent --add-port=5060/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=5060/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=5061/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=8089/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=8443/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=10000-20000/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=3478/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=3478/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=5349/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=5349/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=49152-65535/udp 2>/dev/null || true
firewall-cmd --reload 2>/dev/null || true

# 12b. Asterisk security logging
sed -i "s/^;security\.log => security/security.log => security/" /etc/asterisk/logger.conf 2>/dev/null || true
asterisk -rx "logger reload" 2>/dev/null || true

# 12c. Fail2ban configuration
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

chgrp -R www-data /etc/fail2ban/jail.d 2>/dev/null || true
chmod 775 /etc/fail2ban/jail.d 2>/dev/null || true
touch /etc/fail2ban/jail.d/99-ai-pbx.local
chown root:www-data /etc/fail2ban/jail.d/99-ai-pbx.local 2>/dev/null || true
chmod 664 /etc/fail2ban/jail.d/99-ai-pbx.local 2>/dev/null || true

systemctl enable fail2ban 2>/dev/null || true
systemctl restart fail2ban 2>/dev/null || true
ok "firewall and fail2ban configured"

# ============================================================================
# STEP 13: SAVE CREDENTIALS TO FILE
# ============================================================================
CREDS_FILE="/root/aipbx-credentials.txt"

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
echo ""
echo -e "${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════════════╗"
echo "  ║           ✅  AI PBX Installation Complete!                  ║"
echo "  ╚══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "  ${BOLD}WEB PORTAL & DEFAULT EXTENSION${NC}"
echo -e "  ┌─────────────────────────────────────────────────────────┐"
echo -e "  │  URL          : ${CYAN}https://${PORTAL_DOMAIN}${NC}"
echo -e "  │  Username     : ${YELLOW}admin${NC}"
echo -e "  │  Password     : ${YELLOW}${ADMIN_PASS}${NC}"
echo -e "  │  Extension    : ${YELLOW}1000${NC} (Web/Mobile/SIP)"
echo -e "  │  SIP Password : ${YELLOW}${ADMIN_SIP_PASS}${NC}"
echo -e "  └─────────────────────────────────────────────────────────┘"
echo ""

echo -e "  ${BOLD}DATABASE (MariaDB)${NC}"
echo -e "  ┌─────────────────────────────────────────────────────────┐"
echo -e "  │  App User    : ${YELLOW}${DB_USER}${NC}"
echo -e "  │  App Password: ${YELLOW}${DB_PASS}${NC}"
echo -e "  │  DDL User    : ${YELLOW}${MIGRATOR_USER}${NC}"
echo -e "  │  DDL Password: ${YELLOW}${MIGRATOR_PASS}${NC}"
echo -e "  └─────────────────────────────────────────────────────────┘"
echo ""

echo -e "  ${BOLD}ASTERISK AMI${NC}"
echo -e "  ┌─────────────────────────────────────────────────────────┐"
echo -e "  │  User    : ${YELLOW}${AMI_USER}${NC}"
echo -e "  │  Password: ${YELLOW}${AMI_PASS}${NC}"
echo -e "  └─────────────────────────────────────────────────────────┘"
echo ""

echo -e "  ${BOLD}TLS CERTIFICATE${NC}"
if [[ "${USE_LETSENCRYPT:-false}" == "true" ]]; then
    echo -e "  ✅ Let's Encrypt certificate for ${CYAN}${PORTAL_DOMAIN}${NC}"
else
    echo -e "  ⚠️  Self-signed certificate for ${CYAN}${PORTAL_DOMAIN}${NC}"
    echo -e "     Your browser will show a warning — click Advanced → Proceed to continue."
    echo -e "     To upgrade to a real certificate later:"
    echo -e "     ${CYAN}certbot --apache -d ${PORTAL_DOMAIN}${NC}"
fi
echo ""

echo -e "  ${BOLD}SERVICE STATUS${NC}"
systemctl is-active --quiet mariadb    && echo -e "  ✅ MariaDB  : ${GREEN}running${NC}"  || echo -e "  ❌ MariaDB  : ${RED}stopped${NC}"
systemctl is-active --quiet asterisk   && echo -e "  ✅ Asterisk : ${GREEN}running${NC}"  || echo -e "  ❌ Asterisk : ${RED}stopped${NC}"
systemctl is-active --quiet nginx      && echo -e "  ✅ Nginx    : ${GREEN}running${NC} (Edge 443 ALPN Multiplexer)" || echo -e "  ❌ Nginx    : ${RED}stopped${NC}"
systemctl is-active --quiet apache2    && echo -e "  ✅ Apache2  : ${GREEN}running${NC} (80 / 8443 Backend)"  || echo -e "  ❌ Apache2  : ${RED}stopped${NC}"
systemctl is-active --quiet coturn     && echo -e "  ✅ coturn   : ${GREEN}running${NC}"  || echo -e "  ⚠️  coturn   : ${YELLOW}not running${NC}"
systemctl is-active --quiet aipbx-chat && echo -e "  ✅ Chat     : ${GREEN}running${NC}"  || echo -e "  ⚠️  Chat     : ${YELLOW}not running (optional)${NC}"
echo ""

echo -e "  ${BOLD}📄 All credentials saved to:${NC} ${CYAN}${CREDS_FILE}${NC}"
echo -e "  ${YELLOW}⚠️  Note these credentials now! Delete the file after saving them securely.${NC}"
echo ""
echo -e "  All passwords can be changed later from: ${CYAN}Admin Panel → Settings → System${NC}"
echo ""
