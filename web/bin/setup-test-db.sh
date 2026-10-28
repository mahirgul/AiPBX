#!/bin/bash
# AI PBX — isolated test database setup.
#
# Usage: bash bin/setup-test-db.sh
#
# The schema is built from the Phinx migrations; NO PRODUCTION DATA IS COPIED.
# Tests run only against this database (the tests/bootstrap.php safety lock).
set -e

DB="asterisk_test"
USER="aipbx_test"
PASS="$(openssl rand -hex 16)"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

echo "Setting up the test database: $DB"

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

echo "Building the schema with Phinx..."
cd "$REPO"
DB_NAME="$DB" DB_USER="$USER" DB_PASS="$PASS" php vendor/bin/phinx migrate -e testing

mysql -e "INSERT IGNORE INTO \`$DB\`.sys_roles (role_key, role_name, description, is_system) VALUES
('admin', 'Administrator', 'Full access', 1),
('read_only_admin', 'Viewer', 'Viewer', 1),
('cc_agent', 'Agent', 'Agent', 1),
('fax_user', 'Fax', 'Fax', 1),
('cc_manager', 'Queue manager', 'Queue manager', 1),
('user', 'User', 'User', 1);"

mysql -e "INSERT IGNORE INTO \`$DB\`.sys_users
(id, username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active)
VALUES (1, 'admin', '', 'Test Admin', 'admin@example.com', 'admin', '1000', 'sip', 'test-not-a-secret', 1);"

echo ""
echo "Ready: $DB"
echo "Credentials: tests/.env.test (not in git)"
