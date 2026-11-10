#!/usr/bin/env bash
# ============================================================================
# STEP 2: GENERATE ALL RANDOM CREDENTIALS
# ============================================================================
step "2. Generating Secure Random Credentials"

# Generate strong random password (reads limited bytes from /dev/urandom to avoid SIGPIPE under pipefail)
gen_pass() {
    local len="${1:-20}"
    head -c 1024 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9!@#%^*' | head -c "$len"
}

# Generate hex secret
gen_hex() {
    local len="${1:-32}"
    openssl rand -hex "$len"
}

if is_upgrade; then
    # Existing secrets are reused: regenerating them would lock the portal,
    # Asterisk, chat and TURN out of each other.
    DB_USER="$(env_get DB_USER)"; DB_PASS="$(env_get DB_PASS)"
    MIGRATOR_USER="$(env_get MIGRATOR_DB_USER)"; MIGRATOR_PASS="$(env_get MIGRATOR_DB_PASS)"
    if [[ -z "$MIGRATOR_USER" || -z "$MIGRATOR_PASS" ]] && [[ -f /etc/ai-pbx-migrator.env ]]; then
        MIGRATOR_USER="$(sed -n 's/^MIGRATOR_DB_USER=//p' /etc/ai-pbx-migrator.env | tail -n 1 | sed "s/^[\"']//; s/[\"']\$//")"
        MIGRATOR_PASS="$(sed -n 's/^MIGRATOR_DB_PASS=//p' /etc/ai-pbx-migrator.env | tail -n 1 | sed "s/^[\"']//; s/[\"']\$//")"
    fi
    AMI_USER="$(env_get AMI_USER)"; AMI_PASS="$(env_get AMI_PASS)"
    TURN_SECRET="$(env_get TURN_SECRET)"
    ODBC_PASS="$(env_get ODBC_DB_PASS)"
    if [[ -z "$ODBC_PASS" ]]; then
        # Older installs kept it only in res_odbc.conf.
        ODBC_PASS="$(sed -n 's/^[[:space:]]*password[[:space:]]*=>*[[:space:]]*//p' /etc/asterisk/res_odbc.conf 2>/dev/null | head -1)"
    fi
    if [[ -z "$ODBC_PASS" || "$ODBC_PASS" == *'${'* ]]; then ODBC_PASS=$(gen_hex 16); fi
    for v in DB_USER DB_PASS MIGRATOR_USER MIGRATOR_PASS AMI_USER AMI_PASS TURN_SECRET; do
        [[ -n "${!v}" ]] || error "$v could not be read from environment configuration"
    done
    ok "Existing credentials loaded"
else
# All passwords generated randomly — no static defaults
ADMIN_PASS=$(gen_pass 16)
ADMIN_SIP_PASS=$(gen_hex 12)
DB_USER="aipbx_portal"
DB_PASS=$(gen_pass 20)
MIGRATOR_USER="aipbx_migrator"
MIGRATOR_PASS=$(gen_pass 20)
AMI_USER="aipbx-manager"
AMI_PASS=$(gen_hex 16)
ODBC_PASS=$(gen_hex 16)   # Asterisk → MariaDB (CDR + queue_log via ODBC)
TURN_SECRET=$(gen_hex 32)

ok "All credentials generated"
fi   # install mode
echo ""
