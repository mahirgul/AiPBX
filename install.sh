#!/usr/bin/env bash
# ============================================================================
# AI PBX — Ubuntu LTS Kurulum Betiği
# Desteklenen: Ubuntu 22.04 / 24.04 / 26.04 LTS
#
# Kullanım:
#   git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
#   cd /opt/aipbx
#   sudo bash install.sh
#
# Bu betik aşağıdakileri kurar ve yapılandırır:
#   - Asterisk PBX (Ubuntu reposu)
#   - MariaDB veritabanı + şema
#   - Apache2 + PHP + gerekli modüller
#   - coturn TURN sunucusu (WebRTC için)
#   - Go + Chat servisi (build & systemd)
#   - fail2ban, ghostscript, ODBC
# ============================================================================
set -euo pipefail

# --- Renk kodları ---
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

info()  { echo -e "${BLUE}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# --- Root kontrolü ---
[[ $EUID -ne 0 ]] && error "Bu betiği root olarak çalıştırın: sudo bash install.sh"

# --- Proje kök dizini ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="${AIPBX_INSTALL_DIR:-/opt/aipbx}"

info "AI PBX kurulumu başlıyor..."
info "Proje dizini: $SCRIPT_DIR"
info "Kurulum dizini: $INSTALL_DIR"

# ============================================================================
# 1. SİSTEM PAKETLERİ
# ============================================================================
info "Sistem paketleri kuruluyor (Ubuntu repoları)..."

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq

apt-get install -y \
  asterisk \
  asterisk-core-sounds-en \
  asterisk-core-sounds-en-wav \
  asterisk-modules \
  mariadb-server \
  mariadb-client \
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
  git \
  fail2ban \
  ghostscript \
  libtiff-tools \
  certbot \
  2>&1 | tail -5

ok "Sistem paketleri kuruldu"

# ============================================================================
# 2. DİZİN YAPISINI OLUŞTUR
# ============================================================================
info "Dizin yapısı oluşturuluyor..."

# Proje dosyalarını kurulum dizinine kopyala (eğer farklıysa)
if [[ "$SCRIPT_DIR" != "$INSTALL_DIR" ]]; then
    mkdir -p "$INSTALL_DIR"
    cp -a "$SCRIPT_DIR"/* "$INSTALL_DIR"/
    cp -a "$SCRIPT_DIR"/.gitignore "$INSTALL_DIR"/ 2>/dev/null || true
fi

# Web portal symlink
rm -rf /var/www/html
ln -sf "$INSTALL_DIR/web" /var/www/html

# Gerekli dizinler
mkdir -p /var/www/faxes
mkdir -p /var/spool/asterisk/fax/outgoing
mkdir -p /var/spool/asterisk/monitor
mkdir -p /var/lib/asterisk/sounds/custom
mkdir -p /var/lib/asterisk/moh
mkdir -p /var/lib/aipbx/chat_files
mkdir -p /etc/asterisk/pbx
mkdir -p /etc/asterisk/keys
mkdir -p /var/log/httpd

# Sahiplik
chown -R www-data:www-data /var/www/faxes /var/lib/aipbx
chmod 755 "$(dirname "$INSTALL_DIR")" "$INSTALL_DIR"

ok "Dizin yapısı oluşturuldu"

# ============================================================================
# 3. ENV DOSYASI
# ============================================================================
if [[ ! -f /etc/ai-pbx.env ]]; then
    info "Ortam dosyası oluşturuluyor..."

    # Rastgele şifreler üret
    DB_PASS_GEN=$(openssl rand -base64 24 | tr -d '/+=')
    MIGRATOR_PASS_GEN=$(openssl rand -base64 24 | tr -d '/+=')
    AMI_PASS_GEN=$(openssl rand -hex 16)
    TURN_SECRET_GEN=$(openssl rand -hex 32)

    cp "$INSTALL_DIR/web/.env.example" /etc/ai-pbx.env
    sed -i "s/^DB_PASS=.*/DB_PASS=$DB_PASS_GEN/" /etc/ai-pbx.env
    sed -i "s/^MIGRATOR_DB_PASS=.*/MIGRATOR_DB_PASS=$MIGRATOR_PASS_GEN/" /etc/ai-pbx.env
    sed -i "s/^AMI_PASS=.*/AMI_PASS=$AMI_PASS_GEN/" /etc/ai-pbx.env
    sed -i "s/^TURN_SECRET=.*/TURN_SECRET=$TURN_SECRET_GEN/" /etc/ai-pbx.env

    chown root:www-data /etc/ai-pbx.env
    chmod 640 /etc/ai-pbx.env

    ok "Ortam dosyası oluşturuldu: /etc/ai-pbx.env"
else
    warn "/etc/ai-pbx.env zaten mevcut, dokunulmadı."
    # Mevcut değerleri oku
    DB_PASS_GEN=$(grep '^DB_PASS=' /etc/ai-pbx.env | cut -d= -f2)
    MIGRATOR_PASS_GEN=$(grep '^MIGRATOR_DB_PASS=' /etc/ai-pbx.env | cut -d= -f2)
    AMI_PASS_GEN=$(grep '^AMI_PASS=' /etc/ai-pbx.env | cut -d= -f2)
fi

# ============================================================================
# 4. MARİADB VERİTABANI
# ============================================================================
info "MariaDB yapılandırılıyor..."

systemctl start mariadb
systemctl enable mariadb

# Veritabanı ve kullanıcılar
mysql -e "CREATE DATABASE IF NOT EXISTS asterisk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

mysql -e "
CREATE USER IF NOT EXISTS 'aipbx_portal'@'localhost' IDENTIFIED BY '$DB_PASS_GEN';
GRANT SELECT, INSERT, UPDATE, DELETE ON asterisk.* TO 'aipbx_portal'@'localhost';

CREATE USER IF NOT EXISTS 'aipbx_migrator'@'localhost' IDENTIFIED BY '$MIGRATOR_PASS_GEN';
GRANT ALL PRIVILEGES ON asterisk.* TO 'aipbx_migrator'@'localhost';

FLUSH PRIVILEGES;
"

# Şema ve temel veriler
TABLE_COUNT=$(mysql -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='asterisk';")
if [[ "$TABLE_COUNT" -eq 0 ]]; then
    info "Veritabanı şeması yükleniyor..."
    mysql asterisk < "$INSTALL_DIR/db/schema.sql"
    mysql asterisk < "$INSTALL_DIR/db/seed.sql"

    # Varsayılan admin kullanıcısı (şifre: admin123 — ilk girişte değiştirin!)
    ADMIN_HASH=$(php -r "echo password_hash('admin123', PASSWORD_BCRYPT);")
    mysql asterisk -e "
    INSERT IGNORE INTO sys_users (username, password_hash, display_name, role_id, is_active)
    VALUES ('admin', '$ADMIN_HASH', 'Yönetici', 1, 1);
    "
    ok "Veritabanı şeması ve varsayılan admin kullanıcısı oluşturuldu"
    warn "Varsayılan admin şifresi: admin123 — İLK GİRİŞTE DEĞİŞTİRİN!"
else
    warn "Veritabanında zaten $TABLE_COUNT tablo var, şema yüklenmedi."
fi

ok "MariaDB yapılandırıldı"

# ============================================================================
# 5. APACHE2 YAPILANDIRMASI
# ============================================================================
info "Apache2 yapılandırılıyor..."

# Gerekli modüller
a2enmod rewrite proxy proxy_wstunnel ssl headers php* 2>/dev/null || true
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# VirtualHost
cat > /etc/apache2/sites-available/aipbx.conf << 'VHOST'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html

    # Asterisk WebRTC WebSocket Reverse Proxy
    ProxyPass /ws ws://127.0.0.1:8088/ws retry=0 timeout=3600
    ProxyPassReverse /ws ws://127.0.0.1:8088/ws

    ErrorLog ${APACHE_LOG_DIR}/aipbx_error.log
    CustomLog ${APACHE_LOG_DIR}/aipbx_access.log combined
</VirtualHost>
VHOST

# Routing kuralları
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

# Composer bağımlılıkları
cd "$INSTALL_DIR/web" && composer install --no-dev --no-interaction --quiet 2>/dev/null || true

# nginx'i durdur (port 80 çakışması)
systemctl stop nginx 2>/dev/null || true
systemctl disable nginx 2>/dev/null || true

systemctl restart apache2
systemctl enable apache2

ok "Apache2 yapılandırıldı"

# ============================================================================
# 6. ASTERİSK YAPILANDIRMASI
# ============================================================================
info "Asterisk yapılandırılıyor..."

# Asterisk PBX config dizini
mkdir -p /etc/asterisk/pbx
cp "$INSTALL_DIR/asterisk-config/pbx/"*.conf /etc/asterisk/pbx/ 2>/dev/null || true

# Master config dosyalarını kopyala (varsa)
for f in extensions.conf pjsip.conf queues.conf musiconhold.conf http.conf rtp.conf modules.conf; do
    if [[ -f "$INSTALL_DIR/asterisk-config/$f" ]]; then
        cp "$INSTALL_DIR/asterisk-config/$f" /etc/asterisk/"$f"
    fi
done

# AMI (Manager) yapılandırması
cat > /etc/asterisk/manager.conf << MANAGER
[general]
enabled = yes
bindaddr = 127.0.0.1
port = 5038

[aipbx-manager]
secret = $AMI_PASS_GEN
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = originate,call,agent
write = originate,call,agent
writetimeout = 5000
MANAGER

# Sahiplik
chown -R asterisk:asterisk /etc/asterisk/

systemctl restart asterisk
systemctl enable asterisk

ok "Asterisk yapılandırıldı"

# ============================================================================
# 7. CHAT SERVİSİ (Go build)
# ============================================================================
if [[ -f "$INSTALL_DIR/chat/main.go" ]]; then
    info "Chat servisi derleniyor..."
    cd "$INSTALL_DIR/chat"
    go build -o aipbx-chat . 2>/dev/null || warn "Chat servisi derlenemedi (Go bağımlılıkları eksik olabilir)"

    if [[ -f "$INSTALL_DIR/chat/aipbx-chat" ]]; then
        cat > /etc/systemd/system/aipbx-chat.service << EOF
[Unit]
Description=AI-PBX Chat & Messaging Service
After=network.target mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR/chat
ExecStart=$INSTALL_DIR/chat/aipbx-chat
Restart=always
RestartSec=3
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        ok "Chat servisi derlendi ve systemd servisi oluşturuldu"
    fi
fi

# ============================================================================
# 8. COTURN (WebRTC TURN)
# ============================================================================
info "coturn yapılandırılıyor..."

TURN_SECRET=$(grep '^TURN_SECRET=' /etc/ai-pbx.env | cut -d= -f2)

if [[ -n "$TURN_SECRET" && "$TURN_SECRET" != "change-me" ]]; then
    cat > /etc/turnserver.conf << TURNCONF
listening-port=3478
tls-listening-port=5349
fingerprint
use-auth-secret
static-auth-secret=$TURN_SECRET
realm=localhost
server-name=localhost
no-cli
no-multicast-peers
stale-nonce=600
verbose
log-file=/var/log/turnserver/turnserver.log
simple-log
min-port=49152
max-port=65535
TURNCONF
    mkdir -p /var/log/turnserver
    systemctl restart coturn 2>/dev/null || true
    systemctl enable coturn 2>/dev/null || true
    ok "coturn yapılandırıldı"
else
    warn "TURN_SECRET boş — coturn devre dışı. WebRTC için /etc/ai-pbx.env'de ayarlayın."
fi

# ============================================================================
# 9. FAİL2BAN
# ============================================================================
info "fail2ban yapılandırılıyor..."
systemctl enable fail2ban 2>/dev/null || true
systemctl start fail2ban 2>/dev/null || true
ok "fail2ban yapılandırıldı"

# ============================================================================
# 10. SON DURUM
# ============================================================================
echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  AI PBX kurulumu tamamlandı!${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  Portal URL:     ${BLUE}http://$(hostname -I | awk '{print $1}')${NC}"
echo -e "  Admin Kullanıcı: ${YELLOW}admin${NC}"
echo -e "  Admin Şifre:     ${YELLOW}admin123${NC} (ilk girişte değiştirin!)"
echo ""
echo -e "  Ortam dosyası:   /etc/ai-pbx.env"
echo -e "  Web dizini:      $INSTALL_DIR/web → /var/www/html"
echo -e "  Asterisk conf:   /etc/asterisk/"
echo ""
echo -e "  ${BLUE}Servisler:${NC}"
systemctl is-active --quiet mariadb   && echo -e "    MariaDB:   ${GREEN}aktif${NC}" || echo -e "    MariaDB:   ${RED}kapalı${NC}"
systemctl is-active --quiet asterisk  && echo -e "    Asterisk:  ${GREEN}aktif${NC}" || echo -e "    Asterisk:  ${RED}kapalı${NC}"
systemctl is-active --quiet apache2   && echo -e "    Apache2:   ${GREEN}aktif${NC}" || echo -e "    Apache2:   ${RED}kapalı${NC}"
echo ""
echo -e "  ${YELLOW}Önemli: /etc/ai-pbx.env dosyasını kontrol edip SITE_NAME ve${NC}"
echo -e "  ${YELLOW}PORTAL_DOMAIN değerlerini kendi ortamınıza göre düzenleyin.${NC}"
echo ""
