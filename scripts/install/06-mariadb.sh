#!/usr/bin/env bash
# ============================================================================
# STEP 6: MARIADB DATABASE
# ============================================================================
step "6. Configuring MariaDB"

# Tune MariaDB defaults for AiPBX:
# - max_connections: 151 default is tight with Asterisk res_odbc (80) + Apache/chat/AI/workers
# - max_allowed_packet: 16M default can fail on large restores/transcripts
# - tmp_table_size / max_heap_table_size: 16M -> 32M for CDR/queue report aggregations
# - utf8mb4_unicode_ci: prevents collation mismatch on MariaDB 11.x (Ubuntu 26.04)
if [[ -d /etc/mysql/mariadb.conf.d ]]; then
    cat > /etc/mysql/mariadb.conf.d/99-aipbx.cnf << 'EOF'
[mysqld]
max_connections = 500
max_allowed_packet = 64M
tmp_table_size = 32M
max_heap_table_size = 32M
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
EOF
fi

systemctl start mariadb
systemctl enable mariadb
mysql -e "SET GLOBAL max_connections = 500, GLOBAL max_allowed_packet = 67108864, GLOBAL tmp_table_size = 33554432, GLOBAL max_heap_table_size = 33554432;" 2>/dev/null || true

# Create database
mysql -e "CREATE DATABASE IF NOT EXISTS asterisk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Validate database usernames
[[ "$DB_USER" =~ ^[a-zA-Z0-9_]{1,32}$ ]] || error "Invalid database user name: $DB_USER"
[[ "$MIGRATOR_USER" =~ ^[a-zA-Z0-9_]{1,32}$ ]] || error "Invalid database migrator user name: $MIGRATOR_USER"

for reserved in root admin mysql mariadb.sys debian-sys-maint asterisk_odbc asterisk; do
    [[ "${DB_USER,,}" != "$reserved" ]] || error "Runtime database user cannot be reserved user '$DB_USER'"
    [[ "${MIGRATOR_USER,,}" != "$reserved" ]] || error "Migrator database user cannot be reserved user '$MIGRATOR_USER'"
done

# App users: created when missing, password (re)applied — safe to re-run
mysql <<EOF
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '$(sql_q "${DB_PASS}")';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '$(sql_q "${DB_PASS}")';
GRANT SELECT, INSERT, UPDATE, DELETE ON asterisk.* TO '${DB_USER}'@'localhost';
CREATE USER IF NOT EXISTS '${MIGRATOR_USER}'@'localhost' IDENTIFIED BY '$(sql_q "${MIGRATOR_PASS}")';
ALTER USER '${MIGRATOR_USER}'@'localhost' IDENTIFIED BY '$(sql_q "${MIGRATOR_PASS}")';
GRANT ALL PRIVILEGES ON asterisk.* TO '${MIGRATOR_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

# Load schema and seed (fresh install only)
# Schema, seed data and the admin user are applied after Composer is installed
# (Phinx migrations are the single source of truth for the schema; see
# "Database schema (Phinx migrations)" below).
ok "MariaDB configured"
