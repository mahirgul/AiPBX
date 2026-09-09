#!/bin/bash
# AI PBX — izole test veritabanı kurulumu.
#
# Kullanım: bash bin/setup-test-db.sh
#
# Şema Phinx migration'larından üretilir; ÜRETİM VERİSİ KOPYALANMAZ.
# Testler yalnızca bu veritabanında çalışır (tests/bootstrap.php emniyet kilidi).
set -e

DB="asterisk_test"
USER="aipbx_test"
PASS="$(openssl rand -hex 16)"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

echo "Test veritabanı kuruluyor: $DB"

mysql -e "DROP DATABASE IF EXISTS \`$DB\`;"
mysql -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$USER'@'localhost' IDENTIFIED BY '$PASS';"
mysql -e "ALTER USER '$USER'@'localhost' IDENTIFIED BY '$PASS';"
mysql -e "GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

mkdir -p "$REPO/tests"
cat > "$REPO/tests/.env.test" <<EOF
DB_NAME=$DB
DB_USER=$USER
DB_PASS=$PASS
EOF
chmod 600 "$REPO/tests/.env.test"

echo "Şema Phinx ile üretiliyor..."
cd "$REPO"
DB_NAME="$DB" DB_USER="$USER" DB_PASS="$PASS" php vendor/bin/phinx migrate -e testing

mysql -e "INSERT IGNORE INTO \`$DB\`.sys_roles (role_key, role_name, description, is_system) VALUES
('admin', 'Yönetici', 'Tam yetkili', 1),
('read_only_admin', 'İzleyici', 'İzleyici', 1),
('cc_agent', 'Temsilci', 'Temsilci', 1),
('fax_user', 'Faks', 'Faks', 1),
('cc_manager', 'Kuyruk Yönetici', 'Kuyruk Yönetici', 1),
('user', 'Kullanıcı', 'Kullanıcı', 1);"

echo ""
echo "Hazır: $DB"
echo "Kimlik bilgileri: tests/.env.test (git'e girmez)"
