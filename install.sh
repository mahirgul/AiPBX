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
# Upgrade mode (used by `aipbx-update`, can also be run by hand):
#   sudo bash install.sh --upgrade
#   Re-applies every system step of the checked-out version WITHOUT generating
#   new secrets: credentials, domain and certificate are read from the existing
#   installation; the admin password and firewall ports are left alone.
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
# The portal's System Update page runs this as a systemd unit, which has no
# HOME: Composer then stops with "The HOME or COMPOSER_HOME environment variable
# must be set" and the update rolls back (an update over SSH worked).
export HOME="${HOME:-/root}"
export COMPOSER_HOME="${COMPOSER_HOME:-$HOME/.config/composer}"

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

# --- Supported OS: Ubuntu 26.04 LTS or newer ---
# The PHP dependencies (Symfony 8 via Phinx, composer.lock) need PHP >= 8.4.1
# and the portal targets Asterisk 22; Ubuntu 24.04 ships PHP 8.3 / Asterisk 20,
# so an install there used to fail half-way with "vendor/bin/phinx" missing.
# Stop before touching anything; AIPBX_SKIP_OS_CHECK=1 tries anyway.
if [[ "${AIPBX_SKIP_OS_CHECK:-0}" != 1 ]]; then
    OS_ID="$(. /etc/os-release 2>/dev/null; echo "${ID:-unknown}")"
    OS_VER="$(. /etc/os-release 2>/dev/null; echo "${VERSION_ID:-0}")"
    if [[ "$OS_ID" != ubuntu ]] || [[ "$(printf '%s\n' 26.04 "$OS_VER" | sort -V | head -1)" != 26.04 ]]; then
        error "AiPBX needs Ubuntu 26.04 LTS or newer (PHP >= 8.4, Asterisk 22); this is ${OS_ID} ${OS_VER}. Set AIPBX_SKIP_OS_CHECK=1 to try anyway."
    fi
fi

# Raise this number in a change that needs Asterisk RESTARTED on update (a new
# Asterisk package or module, the service unit, anything read only at start).
# aipbx-update compares it between releases (scripts/asterisk-restart-needed.sh):
# when it and asterisk-config/ are unchanged, Asterisk is only reloaded and
# calls in progress stay up.
ASTERISK_RESTART_EPOCH=1

# --- Bounded waits: a stall must become an error, not a 45-minute silence ---
# On 2026-10-26 the install test sat inside `install.sh` until the job timeout
# with nothing to show for it. Two of its steps can wait far longer than the
# work they are waiting on, with nothing in the log to say so:
#   * `apt-get` on a held dpkg lock (the runner's own unattended-upgrades, or an
#     apt left behind by an earlier step): with no bound set, that wait has run
#     into the quarter-hour range in the wild, and nothing here needs it to;
#   * `composer install` has no network timeout at all, so a packagist or GitHub
#     connection that stops answering is simply waited on.
# `-o DPkg::Lock::Timeout` gives apt a bound (it then fails with its own "Could
# not get lock" message), and `timeout` bounds Composer, which has no such knob.
#
# Neither of those covers the third way this can stop: apt's own configure stage
# runs postinst scripts that can block for ever, with nothing printed and no
# timeout of apt's own. That is why the package phase below also runs under
# stream_bounded (see APT_* there): a wall-clock budget for the phase, and a
# bound on how long its output may stand still.
APT_LOCK_WAIT=120      # seconds apt-get may wait for the dpkg lock
COMPOSER_TIMEOUT=900   # seconds the whole `composer install` may take

# --- Streaming, time-bounded apt ---------------------------------------------
# This step used to end in `apt-get ... 2>&1 | tail -5`, and that pipe is why the
# 2026-10-26 stall was invisible: it holds every line until apt exits, so a phase
# that ran for 45 minutes wrote nothing to the log at all — and even the healthy
# run showed 126 s of silence while dpkg configured, with no way to tell that
# from a hang.
#
# So apt now runs under stream_bounded: every line reaches stdout as it arrives
# (the CI keeps it through `tee`, so a cancelled job still has the evidence), a
# copy stays in a file so the last lines can be shown after a kill, the phase
# has a wall-clock budget, and the output may not stand still for long either.
# A stall now ends in minutes, naming the phase and printing the last thing apt
# said, instead of costing the caller's whole timeout.
APT_UPDATE_TIMEOUT=300    # apt-get update: a mirror that cannot answer in 5 min is down
APT_INSTALL_TIMEOUT=1200  # apt-get install of every package; the healthy run took ~2.5 min
APT_SILENCE=300           # output standing still this long means a download or a postinst is stuck

# stream_bounded <label> <budget-seconds> <silence-seconds> -- <command...>
# Runs the command under both clocks, streaming its output. Returns the
# command's own status, or
#   124  the wall-clock budget ran out
#   125  the output stood still for the silence bound
stream_bounded() {
    local label="$1" budget="$2" quiet="$3"
    local log rc=0 shown=0 lines=0 start last_out reason="" pid i elapsed
    shift 3
    [[ "${1:-}" == "--" ]] && shift

    log="$(mktemp)" || { "$@"; return $?; }
    start=$SECONDS
    last_out=$start
    info "$label — up to ${budget}s, ${quiet}s without a line counts as stuck"

    # `timeout` rather than a clock inside the loop: it puts the command in its
    # own process group and signals the group, so a hung postinst goes with
    # apt-get instead of being orphaned halfway through a configure.
    timeout --signal=TERM --kill-after=60 "$budget" "$@" >>"$log" 2>&1 &
    pid=$!

    while kill -0 "$pid" 2>/dev/null; do
        sleep 2
        lines=$(wc -l <"$log" 2>/dev/null | tr -d ' ')
        [ -n "$lines" ] || lines=0
        if [ "$lines" -gt "$shown" ]; then
            sed -n "$((shown + 1)),${lines}p" "$log"
            shown=$lines
            last_out=$SECONDS
        fi
        if [ $((SECONDS - last_out)) -ge "$quiet" ]; then
            reason="went silent for $((SECONDS - last_out))s"
            break
        fi
    done

    if [ -n "$reason" ]; then
        # Signal the whole group (both GNU and uutils timeout make themselves
        # the group leader, so the command's group id is their pid) and only
        # fall back to the single pid: a postinst that ignores TERM must not be
        # left orphaned in the middle of a configure step.
        kill -s TERM -- "-$pid" 2>/dev/null || kill -s TERM "$pid" 2>/dev/null || true
        i=0
        while kill -0 "$pid" 2>/dev/null && [ "$i" -lt 30 ]; do
            sleep 1
            i=$((i + 1))
        done
        kill -s KILL -- "-$pid" 2>/dev/null || kill -s KILL "$pid" 2>/dev/null || true
    fi
    wait "$pid" 2>/dev/null || rc=$?
    # timeout has exited by now. If it ended on a clock and something in its
    # group outlived it (a postinst that ignored TERM), sweep the group: the
    # others are gone already, so this is normally a no-op.
    if [ "$rc" -ne 0 ]; then
        kill -s KILL -- "-$pid" 2>/dev/null || true
    fi

    # The loop stops looking the moment the process ends, so whatever was
    # printed between the last tick and the exit still has to be flushed: on a
    # failure those lines are the whole diagnosis.
    lines=$(wc -l <"$log" 2>/dev/null | tr -d ' ')
    [ -n "$lines" ] || lines=0
    if [ "$lines" -gt "$shown" ]; then
        sed -n "$((shown + 1)),${lines}p" "$log"
    fi
    rm -f "$log"

    elapsed=$((SECONDS - start))
    if [ -n "$reason" ]; then
        warn "$label $reason — killed after ${elapsed}s; the lines above are the last thing it printed"
        return 125
    fi
    # Ubuntu 26.04 ships uutils' timeout, which reports a child that had to be
    # SIGKILLed as 137 where GNU timeout says 124. The clock we know about is
    # our own, so "it died at or after the budget" is what identifies this, and
    # the caller still gets a name for it instead of "failed (status 137)".
    if [ "$rc" -ne 0 ] && [ "$elapsed" -ge "$budget" ]; then
        warn "$label hit the ${budget}s budget — killed; the lines above are where it stopped"
        return 124
    fi
    case $rc in
        0)   ok "$label finished in ${elapsed}s" ; return 0 ;;
        124) warn "$label hit the ${budget}s budget — killed; the lines above are where it stopped" ; return 124 ;;
        *)   warn "$label failed (status $rc)" ; return "$rc" ;;
    esac
}

# --- Mode: fresh install (default) or --upgrade ---
AIPBX_MODE=install
for arg in "$@"; do
    case "$arg" in
        --upgrade) AIPBX_MODE=upgrade ;;
        *) error "Unknown argument: $arg (supported: --upgrade)" ;;
    esac
done
if [[ "$AIPBX_MODE" == "upgrade" && ! -f /etc/ai-pbx.env ]]; then
    error "--upgrade needs an existing installation (/etc/ai-pbx.env not found)"
fi
is_upgrade() { [[ "$AIPBX_MODE" == "upgrade" ]]; }

# Reads KEY=value from the existing environment file (upgrade mode).
env_get() {
    grep -m1 "^$1=" /etc/ai-pbx.env 2>/dev/null | cut -d= -f2-
}
# Appends KEY=value only when the key is missing: new settings introduced by a
# release are added, values the admin edited are never overwritten.
env_ensure() {
    grep -q "^$1=" /etc/ai-pbx.env || echo "$1=$2" >> /etc/ai-pbx.env
}

# --- Script and install directories ---
# BASH_SOURCE is empty when the script comes through `curl ... | bash`; with
# `set -u` that printed "BASH_SOURCE[0]: unbound variable" as the first line.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-}")" 2>/dev/null && pwd || echo "")"
INSTALL_DIR="${AIPBX_INSTALL_DIR:-/opt/aipbx}"

# --- Bridge releases ---
# A release may contain UPGRADE_FROM (a version): it can then only be applied
# to installations of that version or newer — it carries no code for older ones.
# Older installations update to that bridge release first (aipbx-update does it
# by itself from 1.50.0 on). The installed version is recorded at the end of
# every successful install/upgrade in /var/lib/aipbx/installed-version.
if is_upgrade && [[ -f "$INSTALL_DIR/UPGRADE_FROM" ]]; then
    need_from="$(tr -d '[:space:]' < "$INSTALL_DIR/UPGRADE_FROM")"
    have="$(tr -d '[:space:]' < /var/lib/aipbx/installed-version 2>/dev/null || true)"
    if [[ -z "$have" || "$(printf '%s\n%s\n' "$have" "$need_from" | sort -V | head -1)" != "$need_from" ]]; then
        error "This release can only update AiPBX ${need_from} or newer (installed: ${have:-older than 1.50.0}). Update to v${need_from} first: sudo aipbx-update --to v${need_from}"
    fi
fi

# Helper for interactive prompts when running via piped curl (reading from /dev/tty)
prompt_read() {
    local prompt="$1"
    local varname="$2"
    local default_val="${3:-}"
    local val=""

    if [[ -t 0 ]]; then
        read -r -p "$prompt" val || val=""
    elif { : < /dev/tty; } 2>/dev/null; then
        # /dev/tty exists even without a controlling terminal (systemd, CI);
        # only use it when it can actually be opened.
        read -r -p "$prompt" val < /dev/tty || val=""
    else
        val=""
    fi
    val="${val:-$default_val}"
    printf -v "$varname" '%s' "$val"
}

# If running via curl pipe or outside a cloned repository:
if ! is_upgrade && [[ ! -f "$SCRIPT_DIR/web/config.php" ]]; then
    step "Bootstrapping Repository"
    info "Running via remote curl installer. Cloning AiPBX to $INSTALL_DIR..."
    
    if ! command -v git >/dev/null 2>&1; then
        info "Installing git..."
        export DEBIAN_FRONTEND=noninteractive
        apt-get -o DPkg::Lock::Timeout="$APT_LOCK_WAIT" update -qq \
            && apt-get -o DPkg::Lock::Timeout="$APT_LOCK_WAIT" install -y -qq git
    fi
    
    if [[ -d "$INSTALL_DIR/.git" ]]; then
        info "Existing repository found in $INSTALL_DIR, fetching..."
        git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" fetch --quiet --tags --force origin
    else
        mkdir -p "$(dirname "$INSTALL_DIR")"
        # Full clone (not --depth 1): aipbx-update later checks out release tags.
        git clone --quiet https://github.com/mahirgul/AiPBX.git "$INSTALL_DIR"
    fi
    # Install the latest published release by default so that the installed
    # version matches VERSION/CHANGELOG and aipbx-update works from a known
    # release. AIPBX_REF=main (or any tag/commit) installs something else.
    AIPBX_REF="${AIPBX_REF:-$(git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -1)}"
    AIPBX_REF="${AIPBX_REF:-main}"
    if [[ "$AIPBX_REF" == main ]]; then
        git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" checkout --quiet main && git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" reset --quiet --hard origin/main
    else
        git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" checkout --quiet --force --detach "$AIPBX_REF"
    fi
    info "Installing AiPBX ${AIPBX_REF}"
    # Re-run the installer from the checked-out release (it may differ from this copy).
    # Through `curl | bash` the rest of this script is still in the pipe: read it
    # first, or curl ends the install with "(23) Failure writing output".
    [[ -t 0 ]] || cat > /dev/null
    exec bash "$INSTALL_DIR/install.sh" "$@"
fi

# ============================================================================
# STEP 0: WELCOME BANNER
# ============================================================================
clear 2>/dev/null || true
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════════════╗"
if is_upgrade; then
echo "  ║                    AI PBX — Upgrade                          ║"
else
echo "  ║              AI PBX — Fresh Installation                    ║"
fi
echo "  ║         Open Source Enterprise Phone System                  ║"
echo "  ╚══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  Source directory : ${YELLOW}$SCRIPT_DIR${NC}"
echo -e "  Install directory: ${YELLOW}$INSTALL_DIR${NC}"
echo ""
echo -e "  ${YELLOW}${BOLD}NOTICE & DISCLAIMER (MIT License):${NC}"
echo -e "  AiPBX is open-source software provided ${BOLD}\"AS IS\"${NC} without warranty of any kind."
echo -e "  • ${BOLD}Emergency Calls (112/911):${NC} Not guaranteed; maintain an independent emergency line."
echo -e "  • ${BOLD}SIP Security & Billing:${NC} You are solely responsible for securing trunks and toll costs."
echo -e "  • ${BOLD}Privacy (GDPR/KVKK):${NC} Call recording and AI features require legal consent compliance."
echo -e "  Full license & terms: ${CYAN}$INSTALL_DIR/LICENSE${NC}"
echo ""

# ============================================================================
# STEP 1: FQDN / DOMAIN CONFIGURATION
# ============================================================================
step "1. Domain / FQDN Configuration"

SERVER_IP=$(hostname -I | awk '{print $1}')
if is_upgrade; then
    # Domain and certificate come from the existing installation.
    PORTAL_DOMAIN="$(env_get PORTAL_DOMAIN)"
    [[ -n "$PORTAL_DOMAIN" ]] || error "PORTAL_DOMAIN missing in /etc/ai-pbx.env"
    USE_MDNS=false
    [[ "${PORTAL_DOMAIN,,}" == *.local ]] && USE_MDNS=true
    if [[ -f "/etc/letsencrypt/live/$PORTAL_DOMAIN/fullchain.pem" ]]; then
        USE_LETSENCRYPT=true; USE_SELFSIGNED=false
        CERT_FILE="/etc/letsencrypt/live/$PORTAL_DOMAIN/fullchain.pem"
        KEY_FILE="/etc/letsencrypt/live/$PORTAL_DOMAIN/privkey.pem"
    else
        USE_LETSENCRYPT=false; USE_SELFSIGNED=true
        CERT_FILE="/etc/ssl/aipbx/aipbx.crt"
        KEY_FILE="/etc/ssl/aipbx/aipbx.key"
    fi
    ok "Upgrading ${PORTAL_DOMAIN} (certificate: ${CERT_FILE})"
else
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
    # Local install: Let's Encrypt cannot issue for .local; a self-signed
    # certificate (SAN: name + server IP) is generated and the name is announced on the LAN via mDNS.
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
fi   # install mode

echo ""

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
    AMI_USER="$(env_get AMI_USER)"; AMI_PASS="$(env_get AMI_PASS)"
    TURN_SECRET="$(env_get TURN_SECRET)"
    ODBC_PASS="$(env_get ODBC_DB_PASS)"
    if [[ -z "$ODBC_PASS" ]]; then
        # Older installs kept it only in res_odbc.conf.
        ODBC_PASS="$(sed -n 's/^[[:space:]]*password[[:space:]]*=>*[[:space:]]*//p' /etc/asterisk/res_odbc.conf 2>/dev/null | head -1)"
    fi
    if [[ -z "$ODBC_PASS" || "$ODBC_PASS" == *'${'* ]]; then ODBC_PASS=$(gen_hex 16); fi
    for v in DB_USER DB_PASS MIGRATOR_USER MIGRATOR_PASS AMI_USER AMI_PASS TURN_SECRET; do
        [[ -n "${!v}" ]] || error "$v could not be read from /etc/ai-pbx.env"
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

# ============================================================================
# STEP 3: INSTALL SYSTEM PACKAGES
# ============================================================================
step "3. Installing System Packages"

export DEBIAN_FRONTEND=noninteractive

PACKAGES=(
  asterisk
  asterisk-core-sounds-en
  asterisk-core-sounds-en-wav
  asterisk-modules
  asterisk-moh-opsound-gsm
  mariadb-server
  mariadb-client
  xz-utils
  nginx
  libnginx-mod-stream
  apache2
  libapache2-mod-php
  php
  php-mysql
  php-mbstring
  php-xml
  php-curl
  php-gd
  php-intl
  php-bcmath
  php-zip
  php-odbc
  unixodbc
  odbc-mariadb
  composer
  coturn
  golang-go
  build-essential
  libc6-dev
  git
  fail2ban
  firewalld
  ghostscript
  lame
  sox
  rsync
  libtiff-tools
  postfix
  libsasl2-modules
  mailutils
  certbot
  python3-certbot-apache
  openssl
  avahi-daemon
  avahi-utils
  dnsmasq-base
  vsftpd
  libpam-pwdfile
)

# An upgrade only installs packages a newer release added: system package
# updates are the OS's job, and on servers that cannot reach the Ubuntu
# mirrors (e.g. behind a stalling proxy) apt-get used to hang the update for
# many minutes. Short timeouts so an unreachable mirror fails fast, and a bound
# on the dpkg lock (see APT_LOCK_WAIT) so a lock held by something else fails
# with apt's own message instead of waiting out the caller's timeout.
APT_NET=(-o Acquire::http::Timeout=20 -o Acquire::https::Timeout=20 -o Acquire::Retries=1
         -o "DPkg::Lock::Timeout=$APT_LOCK_WAIT")
# vsftpd's own unit would start right after installing, with local system
# accounts allowed to log in: masked first, AiPBX runs its own (aipbx-vsftpd).
systemctl mask vsftpd.service >/dev/null 2>&1 || true
MISSING=()
for p in "${PACKAGES[@]}"; do
    dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q "install ok installed" || MISSING+=("$p")
done
if is_upgrade && [[ ${#MISSING[@]} -eq 0 ]]; then
    info "All system packages present — apt skipped"
else
    is_upgrade && info "Installing new packages: ${MISSING[*]}"
    # Streamed and double-bounded (see stream_bounded): a silent `tail -5` pipe
    # was what made this phase look like nothing at all for 45 minutes.
    apt_rc=0
    stream_bounded "apt-get update" "$APT_UPDATE_TIMEOUT" "$APT_SILENCE" -- \
        apt-get "${APT_NET[@]}" update -qq || apt_rc=$?
    case $apt_rc in
        0) ;;
        124|125) error "apt-get update stopped making progress (see above) — the mirror or the dpkg lock is wedged" ;;
        *) warn "apt-get update failed — using the existing package lists" ;;
    esac

    apt_rc=0
    if is_upgrade; then
        stream_bounded "apt-get install (${#MISSING[@]} new packages)" \
            "$APT_INSTALL_TIMEOUT" "$APT_SILENCE" -- \
            apt-get "${APT_NET[@]}" install -y "${MISSING[@]}" || apt_rc=$?
    else
        stream_bounded "apt-get install (${#PACKAGES[@]} packages)" \
            "$APT_INSTALL_TIMEOUT" "$APT_SILENCE" -- \
            apt-get "${APT_NET[@]}" install -y "${PACKAGES[@]}" || apt_rc=$?
    fi
    case $apt_rc in
        0) ;;
        124) error "apt-get install hit the ${APT_INSTALL_TIMEOUT}s budget — dpkg was still working (see above)" ;;
        125) error "apt-get install printed nothing for ${APT_SILENCE}s — a download or a postinst script is stuck (see above)" ;;
        *) error "apt-get install failed (status $apt_rc): the packages above are missing" ;;
    esac
fi

ok "System packages installed"

# Remove default Nginx site immediately so it does not conflict with Apache on port 80
rm -f /etc/nginx/sites-enabled/default
systemctl reload nginx 2>/dev/null || true

# ============================================================================
# STEP 4: DIRECTORY STRUCTURE
# ============================================================================
step "4. Creating Directory Structure"

if [[ "$(realpath "$SCRIPT_DIR")" != "$(realpath "$INSTALL_DIR")" ]]; then
    mkdir -p "$INSTALL_DIR"
    rsync -a \
        --exclude='/android' \
        --exclude='/ios' \
        --exclude='/.git' \
        --exclude='/.claude' \
        --exclude='/rapor' \
        --exclude='*.jks' \
        --exclude='keystore.properties' \
        "$SCRIPT_DIR"/ "$INSTALL_DIR"/
    rm -rf "$INSTALL_DIR/android" "$INSTALL_DIR/ios" "$INSTALL_DIR/rapor" "$INSTALL_DIR/.claude" 2>/dev/null || true
    find "$INSTALL_DIR" \( -name '*.jks' -o -name 'keystore.properties' \) -delete 2>/dev/null || true
fi

rm -rf /var/www/html
ln -sf "$INSTALL_DIR/web" /var/www/html

# Symlink helper binaries and dialplan scripts to /usr/local/bin.
# web/bin must stay traversable (755, root-owned): Asterisk runs these scripts
# as the asterisk user through the symlinks (feature codes, web widget calls,
# fax processing).
# A root-only 750 bin/ silently broke *60/*72 because System(... &) logs nothing.
chmod 755 "$INSTALL_DIR/web/bin"
for script in feature_code_action.php widget_claim.php ai_request.php push_dispatcher.php process_incoming_fax.sh process_outgoing_fax_result.sh fax_cleanup.sh fax_pending_sweep.sh sync_queue_logs.php recordings_to_mp3.php aipbx_health.php voicemail_notify.php fax_to_bucket.php voicemail_to_bucket.php provision_tftp.php privacy_purge.php task_dispatcher.php; do
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

# Call recordings: finished WAVs → mono 16 kbps MP3 (~8x smaller), into the S3 bucket when chosen
*/5 * * * * root /usr/local/bin/recordings_to_mp3.php >/dev/null 2>&1

# Faxes into the S3 bucket an hour after they finished (Admin -> File storage)
*/5 * * * * root /usr/local/bin/fax_to_bucket.php >/dev/null 2>&1

# Heard voicemail older than N days archived to the S3 bucket (Admin -> File storage)
17 * * * * root /usr/local/bin/voicemail_to_bucket.php >/dev/null 2>&1

# Phone files into the TFTP/FTP folder when switched on (PBX -> Phones -> settings)
*/5 * * * * www-data /usr/local/bin/provision_tftp.php >/dev/null 2>&1

# Daily check for a new AiPBX release (shown on the portal's System Update page)
37 4 * * * root /usr/local/sbin/aipbx-update --check >/dev/null 2>&1

# Daily backup of the database and configuration (/var/backups/aipbx-daily, 14 days)
30 2 * * * root /usr/local/sbin/aipbx-backup >/dev/null 2>&1

# Health checks: the checks that are due, alarm e-mails, daily summary (Admin -> Health checks)
* * * * * root /usr/local/bin/aipbx_health.php >/dev/null 2>&1

# Nightly KVKK / GDPR data retention auto-purge (Security -> GDPR & Data Privacy)
45 3 * * * root /usr/local/bin/privacy_purge.php >/dev/null 2>&1

# Scheduled tasks (System -> Scheduled tasks; sandboxed, as aipbx-task), SIP debug
# switch-off and the night restart window (PBX settings)
* * * * * root /usr/local/bin/task_dispatcher.php >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/aipbx

# Scheduled tasks run as this user only (never root, never www-data), in a
# systemd sandbox where nothing but their own folder is writable.
id -u aipbx-task >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent \
    --shell /usr/sbin/nologin --user-group aipbx-task
install -d -o root -g root -m 0755 /var/lib/aipbx/tasks

mkdir -p /var/www/faxes
mkdir -p /var/spool/asterisk/fax/outgoing
mkdir -p /var/spool/asterisk/monitor
mkdir -p /var/lib/asterisk/sounds/custom
mkdir -p /var/lib/asterisk/sounds/tr
mkdir -p /var/lib/asterisk/moh
mkdir -p /var/lib/asterisk/moh/custom   # seed.sql's "custom" MOH class
# The "default" MOH class plays /var/lib/asterisk/moh, but Ubuntu installs its
# tracks (asterisk-moh-opsound-*) in /usr/share/asterisk/moh. An empty folder
# makes Asterisk drop the class ("No music on hold classes configured") and
# callers on hold or in a queue hear silence. Link the stock tracks in — only
# while the folder has no audio of its own, so an admin's music is never touched.
if ! find /var/lib/asterisk/moh -maxdepth 1 \( -type f -o -type l \) | grep -q .; then
    for track in /usr/share/asterisk/moh/*; do
        [[ -f "$track" ]] && ln -sf "$track" /var/lib/asterisk/moh/
    done
fi
mkdir -p /var/lib/aipbx/chat_files /var/lib/aipbx/tts
# Key that encrypts cloud credentials stored in the database (AI → Cloud TTS).
# Created here so it belongs to the web server: one made by root would lock it out.
[[ -L /var/lib/aipbx/settings.key ]] && rm -f /var/lib/aipbx/settings.key
if [[ ! -s /var/lib/aipbx/settings.key ]]; then
    tmp_settings_key="$(mktemp /var/lib/aipbx/.settings.key.XXXXXX)"
    head -c 32 /dev/urandom > "$tmp_settings_key"
    chmod 600 "$tmp_settings_key"
    mv -f "$tmp_settings_key" /var/lib/aipbx/settings.key
fi
chmod 600 /var/lib/aipbx/settings.key
mkdir -p /etc/asterisk/pbx
mkdir -p /etc/asterisk/keys
mkdir -p /var/log/aipbx

chown -R www-data:www-data /var/lib/aipbx
chmod 1755 /var/lib/aipbx
# Configuration sync locks, shared by the portal (www-data), feature codes
# (asterisk) and root (asterisk_sync.php): group asterisk, no one else.
install -d -o www-data -g asterisk -m 2770 /var/lib/aipbx/sync-locks
chgrp asterisk /var/lib/aipbx/sync-locks/*.lock 2>/dev/null || true
chmod 0660 /var/lib/aipbx/sync-locks/*.lock 2>/dev/null || true
# Logo / favicon uploads (Appearance → Branding). The code checkout belongs to
# root, so without this folder the portal could not save an uploaded logo.
mkdir -p "$INSTALL_DIR/web/assets/images/brand"
chown www-data:www-data "$INSTALL_DIR/web/assets/images/brand"
# TWO processes write to the fax directories: Asterisk (user asterisk; incoming
# faxes, send results) and the portal (www-data, member of group asterisk;
# outgoing fax archive and the .call file). Previously /var/www/faxes belonged to
# www-data, /var/spool/asterisk/fax to root and the outgoing spool was
# asterisk:root 750: incoming TIFFs could never be written and outgoing .call
# files could not be dropped. setgid (2775): subdirectories inherit group asterisk.
mkdir -p /var/www/faxes/recvd /var/www/faxes/sent /var/spool/asterisk/outgoing
chown -R asterisk:asterisk /var/www/faxes
chmod 2775 /var/www/faxes /var/www/faxes/recvd /var/www/faxes/sent
# Year directories (sent/2026, recvd/2026) were made with the creator's umask
# (0755). After the chown above the portal could not write into them, and every
# fax upload failed with "Permission denied" (live system, 2026-10-22).
find /var/www/faxes -type d -exec chmod 2775 {} +
# /var/spool/asterisk: the Debian asterisk package resets EVERY directory here
# to "asterisk: 750" on each upgrade (postinst) but leaves directories with a
# dpkg-statoverride alone. The permissions the portal needs — reading call
# recordings (monitor) and voicemail, writing .call and outgoing fax files —
# are pinned here. (Previously /var/spool/asterisk was asterisk:root 750:
# recording playback and fax sending never worked for www-data.)
for spec in "0750 /var/spool/asterisk" "2750 /var/spool/asterisk/monitor" \
            "2770 /var/spool/asterisk/outgoing" "2775 /var/spool/asterisk/fax" \
            "2775 /var/spool/asterisk/fax/outgoing"; do
    mode="${spec%% *}"; dir="${spec#* }"
    dpkg-statoverride --list "$dir" >/dev/null 2>&1 && dpkg-statoverride --remove "$dir" >/dev/null 2>&1
    dpkg-statoverride --update --add asterisk asterisk "$mode" "$dir"
done
# Ubuntu 26.04 confines Ghostscript with an AppArmor profile that reaches only
# HOME, /mnt, /media and /tmp: every outgoing fax failed at the PDF -> TIFF step
# ("Could not open the file"), and so did the TIFF -> PDF fallback for received
# faxes. The local include extends the packaged profile instead of replacing it.
if [ -f /etc/apparmor.d/gs ]; then
    mkdir -p /etc/apparmor.d/local
    cat > /etc/apparmor.d/local/gs <<'EOF'
# AiPBX: Ghostscript converts faxes here (PDF -> TIFF to send, TIFF -> PDF for
# received faxes). Written by install.sh; rewritten on every upgrade.
file rw /var/www/faxes/**,
file rw /var/spool/asterisk/fax/**,
EOF
    command -v apparmor_parser >/dev/null 2>&1 && { apparmor_parser -r /etc/apparmor.d/gs 2>/dev/null || true; }
fi
chgrp -R asterisk /var/spool/asterisk/voicemail /var/spool/asterisk/monitor 2>/dev/null || true
chmod -R g+rX /var/spool/asterisk/voicemail /var/spool/asterisk/monitor 2>/dev/null || true
# Portal login failures (read by the aipbx-web fail2ban jail)
[[ -L /var/log/aipbx/web_login_failures.log ]] && rm -f /var/log/aipbx/web_login_failures.log
touch /var/log/aipbx/web_login_failures.log
chown -R www-data:adm /var/log/aipbx
chmod 1750 /var/log/aipbx
chmod 640 /var/log/aipbx/web_login_failures.log
# The fax scripts run as the asterisk user (System()) and write these files;
# they cannot create files in /var/log themselves, so nothing was ever logged.
# The logrotate "create" line keeps logrotate from recreating them as root.
for f in fax_incoming fax_outgoing; do
    touch "/var/log/$f.log"
    chown asterisk:adm "/var/log/$f.log"
    chmod 640 "/var/log/$f.log"
done
cat > /etc/logrotate.d/aipbx-fax << 'ROT'
/var/log/fax_incoming.log /var/log/fax_outgoing.log {
    su root syslog
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 asterisk adm
}
ROT
chmod 755 "$(dirname "$INSTALL_DIR")" "$INSTALL_DIR"

ok "Directory structure created"

# ============================================================================
# STEP 5: ENVIRONMENT FILE
# ============================================================================
step "5. Creating Environment Configuration"

if is_upgrade; then
    # The environment file belongs to the installation (admins edit it):
    # only keys added by newer releases are appended.
    env_ensure ODBC_DB_PASS "$ODBC_PASS"
    # Own key for the mobile/chat sign-in tokens (they were signed with
    # TURN_SECRET). Added once: every app signs in again after this upgrade.
    if [[ -z "$(env_get CHAT_JWT_SECRET)" ]]; then
        sed -i '/^CHAT_JWT_SECRET=$/d' /etc/ai-pbx.env
        echo "CHAT_JWT_SECRET=$(gen_hex 32)" >> /etc/ai-pbx.env
        ok "CHAT_JWT_SECRET added (mobile apps and chat sign in again)"
    fi
    # Older installs sent clients coturn's own port; TURNS goes through the
    # 443 multiplexer now, which also passes networks that only allow 443.
    if [[ "$(env_get TURNS_PORT)" == 5349 ]]; then
        sed -i 's/^TURNS_PORT=5349$/TURNS_PORT=443/' /etc/ai-pbx.env
        ok "TURNS_PORT 5349 → 443 (TURNS through the 443 multiplexer)"
    fi
    ok "Environment file kept (missing keys added)"
else
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
# Signs the mobile/chat sign-in tokens (own key, not shared with TURN).
CHAT_JWT_SECRET=$(gen_hex 32)
# Port given to clients for turns:. coturn listens on 5349; nginx on 443 hands
# TURNS (no ALPN / "stun.turn") to it, so calls work where only 443 is open.
TURNS_PORT=443

# --- Asterisk -> MariaDB (CDR / queue_log through ODBC) ---
ODBC_DB_PASS=${ODBC_PASS}
ENVFILE
fi   # install mode

# Secrets are read by the portal (www-data) AND by the scripts Asterisk runs
# as the asterisk user (feature codes, push wake-up, fax). With root:www-data
# the asterisk-side scripts could not reach the database at all.
# Group "asterisk": Asterisk is started with -G asterisk, which drops every
# supplementary group, so only its primary group works for System() children;
# www-data joins the asterisk group below (usermod -aG asterisk www-data).
chown root:asterisk /etc/ai-pbx.env
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

# App users: created when missing, password (re)applied — safe to re-run
mysql <<EOF
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON asterisk.* TO '${DB_USER}'@'localhost';
CREATE USER IF NOT EXISTS '${MIGRATOR_USER}'@'localhost' IDENTIFIED BY '${MIGRATOR_PASS}';
ALTER USER '${MIGRATOR_USER}'@'localhost' IDENTIFIED BY '${MIGRATOR_PASS}';
GRANT ALL PRIVILEGES ON asterisk.* TO '${MIGRATOR_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

# Load schema and seed (fresh install only)
# Schema, seed data and the admin user are applied after Composer is installed
# (Phinx migrations are the single source of truth for the schema; see
# "Database schema (Phinx migrations)" below).
ok "MariaDB configured"

# ============================================================================
# STEP 7: TLS CERTIFICATE
# ============================================================================
step "7. TLS Certificate Setup"

if is_upgrade; then
    # With /etc/ssl/aipbx/mode the active certificate is kept below (it may be
    # an uploaded one); older installs still point at CERT_FILE.
    [[ -f /etc/ssl/aipbx/mode || ( -f "$CERT_FILE" && -f "$KEY_FILE" ) ]] || error "Certificate not found: $CERT_FILE"
    info "Keeping the existing certificate"
elif [[ "$USE_LETSENCRYPT" == "true" ]]; then
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

if ! is_upgrade && [[ "$USE_SELFSIGNED" == "true" ]]; then
    info "Generating self-signed TLS certificate..."
    mkdir -p /etc/ssl/aipbx

    # Browsers ignore the CN and only look at subjectAltName — without a SAN the
    # certificate is rejected for WSS/TURNS even after "accept the risk".
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

# One active certificate (/etc/ssl/aipbx/active.*) serves Apache, coturn and
# Asterisk; aipbx-cert copies it to each. The Certificates page switches it
# later (Let's Encrypt, uploaded, self-signed), so an upgrade keeps whatever
# is active instead of going back to the installer's choice.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-cert" /usr/local/sbin/aipbx-cert
if is_upgrade && [[ -f /etc/ssl/aipbx/mode && -f /etc/ssl/aipbx/active.crt && -f /etc/ssl/aipbx/active.key ]]; then
    info "Keeping the active certificate ($(cat /etc/ssl/aipbx/mode))"
else
    CERT_MODE=selfsigned
    [[ "$CERT_FILE" == /etc/letsencrypt/* ]] && CERT_MODE=letsencrypt
    /usr/local/sbin/aipbx-cert deploy --no-reload "$CERT_MODE" "$CERT_FILE" "$KEY_FILE" \
        || error "Could not install the TLS certificate ($CERT_FILE)"
fi

# Announce the local .local name on the LAN via mDNS (as an alias, hostname unchanged).
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

a2enmod rewrite proxy proxy_wstunnel proxy_http ssl headers remoteip php* 2>/dev/null || true
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Ubuntu's apache2.service has MemoryDenyWriteExecute=yes: PHP's PCRE JIT cannot
# get executable memory, falls back to the interpreter and logged "Allocation of
# JIT memory failed" for every request. Switch the JIT off for Apache's PHP.
for php_conf_d in /etc/php/*/apache2/conf.d; do
    if [[ -d "$php_conf_d" ]]; then
        printf '; AiPBX (install.sh): apache2.service denies executable memory\npcre.jit=0\n' > "$php_conf_d/99-aipbx.ini"
    fi
done

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

    # Let's Encrypt HTTP-01 challenge pass-through, redirect all other traffic to HTTPS.
    # Desk phone files (/provision/) are served over plain HTTP too: many phones
    # cannot do HTTPS to an IP address or do not follow redirects, and phone
    # networks often have no DNS. The files are still limited to the allowed
    # networks and the per-phone token (PhoneProvisionService).
    # The path is checked after Apache has resolved it (/provision/../login is
    # /login); internal redirects to index.php (REDIRECT_STATUS set) are skipped,
    # otherwise /provision/ would be redirected on its second pass.
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteCond %{ENV:REDIRECT_STATUS} ^\$
    RewriteCond %{REQUEST_URI} !^/provision/
    # Plugin endpoints and the IVR data step lookup stay on plain HTTP only for
    # the PBX itself (Asterisk CURL() to http://127.0.0.1/api/...; CURL() does
    # not follow a redirect); everyone else is sent to HTTPS like the rest of
    # the portal (sign-in callbacks, widgets).
    RewriteCond %{REQUEST_URI} !^/api/(plugins/|ivr_data_lookup\.php\$) [OR]
    RewriteCond %{REMOTE_ADDR} !^(127\.0\.0\.1|::1)\$
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [END,NE,R=permanent]

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

# Security headers for every response (clickjacking, MIME sniffing, version
# disclosure). Referrer-Policy uses setifempty so pages that send their own
# stricter policy (e.g. /mobile-login: no-referrer) are not overridden.
# Ubuntu's security.conf is loaded after ours and would override these two.
sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf 2>/dev/null || true
cat > /etc/apache2/conf-available/aipbx-security.conf << 'SECHDR'
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Content-Security-Policy "frame-ancestors 'self'"
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

# Composer dependencies. Errors were hidden (--quiet 2>/dev/null || true) and
# the install then stopped at the migrations with "vendor/bin/phinx" missing.
info "Installing PHP dependencies (Composer)..."
cd "$INSTALL_DIR/web"   # the migrations below run from here (vendor/bin/phinx)
# `timeout` because Composer has no network timeout of its own (see
# COMPOSER_TIMEOUT), and the log goes to a file rather than straight into
# `tail` so the status is Composer's, not tail's, and a timeout can be named as
# a timeout instead of "failed".
COMPOSER_LOG="$(mktemp)"
composer_status=0
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=300 \
    timeout "$COMPOSER_TIMEOUT" composer install --no-dev --no-interaction --no-progress \
    >"$COMPOSER_LOG" 2>&1 || composer_status=$?
tail -20 "$COMPOSER_LOG" || true
rm -f "$COMPOSER_LOG"
case $composer_status in
    0) ;;
    124) error "Composer install timed out after ${COMPOSER_TIMEOUT}s: packagist or GitHub stopped answering (see output above)" ;;
    *) error "Composer install failed (see output above)" ;;
esac
[[ -x "$INSTALL_DIR/web/vendor/bin/phinx" ]] || error "Composer did not install the PHP dependencies (see output above)"

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
CREATE USER IF NOT EXISTS 'asterisk_odbc'@'localhost' IDENTIFIED BY '${ODBC_PASS}';
ALTER USER 'asterisk_odbc'@'localhost' IDENTIFIED BY '${ODBC_PASS}';
GRANT SELECT, INSERT ON asterisk.asteriskcdr TO 'asterisk_odbc'@'localhost';
GRANT SELECT, INSERT ON asterisk.asteriskqueue TO 'asterisk_odbc'@'localhost';
FLUSH PRIVILEGES;
EOF
ok "Database schema migrated, seed data and ODBC user in place"

# Nginx Stream Multiplexer on Port 443 (ALPN routing: HTTPS/WSS -> Apache 8443, TURNS -> coturn 5349)
# nginx never decrypts TLS here, so the client address reaches Apache only
# through a PROXY protocol header. coturn cannot parse that header, so TURNS
# goes through a local relay (127.0.0.1:15349) that strips it.
cat > /etc/nginx/aipbx-stream.conf << 'STREAM'
# AI PBX edge multiplexer on 443 (managed by install.sh)
stream {
    log_format aipbx_stream '$remote_addr [$time_local] alpn="$ssl_preread_alpn_protocols" backend=$aipbx_backend status=$status bytes_s=$bytes_sent bytes_r=$bytes_received';
    access_log /var/log/nginx/stream.log aipbx_stream;

    map $ssl_preread_alpn_protocols $aipbx_backend {
        default     127.0.0.1:8443;
        # Browsers send no ALPN for TURNS; RFC 7443 clients send stun.turn.
        ""          127.0.0.1:15349;
        ~stun\.turn 127.0.0.1:15349;
    }

    server {
        listen 443;
        listen [::]:443;
        ssl_preread on;
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
InaccessiblePaths=/boot /root -/etc/ssh -/etc/apt -/etc/.git -/etc/.svn
# ProtectSystem=full makes /etc read-only for Apache and for aipbx-priv run
# through sudo inside it: "Apply" writes generated dialplan/endpoint files under /etc/asterisk/pbx and settings in /etc/asterisk.
ReadWritePaths=/etc/asterisk /etc/postfix /etc/fail2ban/jail.d -/etc/chrony/conf.d
APACHEOVERRIDE
systemctl daemon-reload

# Allow web server (www-data) to access Asterisk control socket
usermod -aG asterisk www-data

# Privileged helper: the portal's ONLY root entry point. Every subcommand maps
# to one fixed operation with validated arguments (see conf/sbin/aipbx-priv).
# Copied (not symlinked) so it stays root-owned and outside anything www-data can write.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-priv" /usr/local/sbin/aipbx-priv
# Update command (sudo aipbx-update); the portal calls it through aipbx-priv.
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-update" /usr/local/sbin/aipbx-update
# Daily database/configuration backup (cron below).
install -o root -g root -m 0755 "$INSTALL_DIR/conf/sbin/aipbx-backup" /usr/local/sbin/aipbx-backup
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
# --- signaling capture (opt-in: asterisk-config/hep.conf decides whether
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

[Install]
WantedBy=multi-user.target
WEBHOOKUNIT
systemctl daemon-reload
systemctl enable aipbx-webhook-worker.service >/dev/null 2>&1 || true
systemctl restart aipbx-webhook-worker.service 2>/dev/null || true
cat > /etc/systemd/system/aipbx-dnsmasq.service << 'NETSVC'
[Unit]
Description=AiPBX DHCP/TFTP for desk phones (PBX -> Network services)
After=network-online.target
Wants=network-online.target
ConditionPathExists=/var/lib/aipbx-netsvc/dnsmasq.conf

[Service]
ExecStart=/usr/sbin/dnsmasq --keep-in-foreground --conf-file=/var/lib/aipbx-netsvc/dnsmasq.conf
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
NETSVC
systemctl daemon-reload
systemctl enable aipbx-dnsmasq.service >/dev/null 2>&1 || true
systemctl try-restart aipbx-dnsmasq.service 2>/dev/null || true

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
# The pbx/ files are generated from the database (asterisk_sync.php below);
# the repo copies only seed a fresh install. Overwriting them on upgrade made
# Asterisk restart with no extensions, hints or queue members: phones dropped
# their registration and static queue members stayed "Invalid" (never rung)
# because their hints did not exist yet when the queues were loaded.
for f in "$INSTALL_DIR/asterisk-config/pbx/"*.conf; do
    [[ -e "/etc/asterisk/pbx/$(basename "$f")" ]] || cp "$f" /etc/asterisk/pbx/
done

for f in extensions.conf pjsip.conf pjsip_notify.conf queues.conf musiconhold.conf http.conf rtp.conf hep.conf modules.conf cdr.conf res_odbc.conf cdr_adaptive_odbc.conf extconfig.conf; do
    if [[ -f "$INSTALL_DIR/asterisk-config/$f" ]]; then
        cp "$INSTALL_DIR/asterisk-config/$f" /etc/asterisk/"$f"
    fi
done
# res_odbc.conf ships with a ${ODBC_PASS} placeholder (no secret in the repo).
escaped_odbc_pass="$(printf '%s\n' "$ODBC_PASS" | sed 's/[&/\]/\\&/g')"
sed -i "s/\${ODBC_PASS}/${escaped_odbc_pass}/" /etc/asterisk/res_odbc.conf
chown asterisk:root /etc/asterisk/res_odbc.conf
chmod 600 /etc/asterisk/res_odbc.conf

# ODBC data source "asterisk" used by res_odbc.conf (MariaDB Connector/ODBC).
cat > /etc/odbc.ini << 'ODBCINI'
[asterisk]
Description = AI PBX MariaDB (Asterisk CDR / queue_log)
Driver      = MariaDB Unicode
Socket      = /run/mysqld/mysqld.sock
Server      = localhost
Database    = asterisk
Charset     = utf8mb4
ODBCINI

# seed.sql enables SIP-TLS (5061) and WSS (8089) with /etc/asterisk/keys/
# fullchain.pem and privkey.pem; aipbx-cert wrote them in step 7.

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
chown asterisk:root /etc/asterisk/manager.conf
chmod 600 /etc/asterisk/manager.conf

# Install Asterisk Sound Prompts (Turkish & WebRTC/IVR custom sounds)
info "Installing Asterisk sound prompts (Turkish & WebRTC/IVR sounds)..."
mkdir -p /var/lib/asterisk/sounds/custom /var/lib/asterisk/sounds/tr

if [[ -d "$INSTALL_DIR/sounds/custom" ]]; then
    # --update=none: sounds uploaded from the portal with the same name are kept
    # (upgrade). Same as -n, which newer coreutils warn about.
    cp -a --update=none "$INSTALL_DIR/sounds/custom/"* /var/lib/asterisk/sounds/custom/
fi


# Default prompt language (asterisk.conf) = the system_default_language
# setting (PBX Settings). seed.sql makes it "en" on a fresh install; an
# upgrade keeps whatever the installation uses (e.g. "tr").
SPOKEN_LANG="$(mysql -N -B asterisk -e "SELECT setting_value FROM sys_settings WHERE setting_key='system_default_language'" 2>/dev/null | tr -cd 'a-zA-Z_' || true)"
SPOKEN_LANG="${SPOKEN_LANG:-en}"
if [[ -f /etc/asterisk/asterisk.conf ]]; then
    if grep -q "defaultlanguage" /etc/asterisk/asterisk.conf; then
        sed -i "s/^;*defaultlanguage\s*=.*/defaultlanguage = ${SPOKEN_LANG}/" /etc/asterisk/asterisk.conf
    else
        echo "defaultlanguage = ${SPOKEN_LANG}" >> /etc/asterisk/asterisk.conf
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
# The portal (www-data, in the asterisk group) writes uploaded and AI-made
# announcements into custom/: group-writable, and files it may overwrite too.
chmod 2775 /var/lib/asterisk/sounds/custom
find /var/lib/asterisk/sounds/custom -maxdepth 1 -type f -exec chmod 664 {} +

# Debian's Asterisk searches prompts in /usr/share/asterisk/sounds, where
# "custom" points at /usr/local/share/asterisk/sounds. The portal stores sounds
# in /var/lib/asterisk/sounds/{custom,tr}; without these links every IVR,
# announcement and Turkish prompt failed with "does not exist in any format".
link_sound_dir() {  # link_sound_dir TARGET LINK
    if [[ -d "$2" && ! -L "$2" ]]; then
        cp -an "$2"/. "$1"/ 2>/dev/null || true
        rm -rf "$2"
    fi
    mkdir -p "$(dirname "$2")"
    ln -sfn "$1" "$2"
}
link_sound_dir /var/lib/asterisk/sounds/custom /usr/local/share/asterisk/sounds
link_sound_dir /var/lib/asterisk/sounds/tr /usr/share/asterisk/sounds/tr

# Turkish prompts: AiPBX's own asterisk-core-sounds-tr packages (built by
# scripts/build_tr_sounds.sh, texts in sounds/core-sounds-tr.txt), one per
# format like Asterisk's own sound packages. They are not in git but assets of
# the sounds-tr-<version> GitHub release; aipbx-sounds (installed in step 8)
# downloads and verifies them, so they also show up — and can be removed or
# installed again — on Sounds → Asterisk Sound Packs. Packages built locally
# at the repo root are used instead of downloading. A failed download only
# warns: the install goes on.
# AIPBX_TR_SOUNDS=auto (default) installs them when the prompt language is
# Turkish or they are already installed (an upgrade keeps them current); a
# fresh install is English and leaves them out. yes = always, no = never.
TR_SOUNDS_MODE="${AIPBX_TR_SOUNDS:-auto}"
if [[ "$TR_SOUNDS_MODE" == auto ]]; then
    if [[ "$SPOKEN_LANG" == tr ]] || compgen -G "/var/lib/aipbx/sound-packs/core-tr-*.list" >/dev/null \
       || compgen -G "/var/lib/asterisk/sounds/tr/*.wav" >/dev/null; then
        TR_SOUNDS_MODE=yes
    else
        TR_SOUNDS_MODE=no
    fi
fi
if [[ "$TR_SOUNDS_MODE" == no ]]; then
    info "Turkish prompts not installed — add them any time on Sounds → Asterisk Sound Packs"
else
    TR_SOUNDS_VERSION="$(sed -n 's/^TR_VERSION=//p' /usr/local/sbin/aipbx-sounds)"
    if compgen -G "$INSTALL_DIR/asterisk-core-sounds-tr-*-${TR_SOUNDS_VERSION}.tar.xz" >/dev/null \
       && [[ -f "$INSTALL_DIR/asterisk-core-sounds-tr-${TR_SOUNDS_VERSION}.SHA256SUMS" ]]; then
        mkdir -p "/var/cache/aipbx/sounds-tr-${TR_SOUNDS_VERSION}"
        cp "$INSTALL_DIR"/asterisk-core-sounds-tr-*"${TR_SOUNDS_VERSION}"* "/var/cache/aipbx/sounds-tr-${TR_SOUNDS_VERSION}/"
    fi
    TR_SOUNDS_FAILED=()
    for fmt in wav ulaw alaw gsm g722 sln16; do
        # Already installed in this version (upgrade): nothing to download.
        python3 -c 'import json,sys; d=json.load(open("/var/lib/aipbx/sound-packs.json")); sys.exit(d.get("core-tr-"+sys.argv[1],{}).get("version")!=sys.argv[2])' \
            "$fmt" "$TR_SOUNDS_VERSION" 2>/dev/null && continue
        /usr/local/sbin/aipbx-sounds install core tr "$fmt" >/dev/null 2>&1 || TR_SOUNDS_FAILED+=("$fmt")
    done
    if [[ ${#TR_SOUNDS_FAILED[@]} -eq 0 ]]; then
        ok "Turkish prompts installed (6 formats, sounds-tr-${TR_SOUNDS_VERSION})"
    else
        warn "Turkish prompts not installed for: ${TR_SOUNDS_FAILED[*]} (see /var/log/aipbx/sound-packs.log) — install them later on Sounds → Asterisk Sound Packs"
    fi
fi
chown -R asterisk:asterisk /etc/asterisk/
chown asterisk:asterisk /etc/asterisk && chmod 0755 /etc/asterisk
chown -R asterisk:asterisk /etc/asterisk/pbx
chmod 2775 /etc/asterisk/pbx
chmod 664 /etc/asterisk/pbx/*.conf 2>/dev/null || true
for secret_conf in /etc/asterisk/manager.conf /etc/asterisk/res_odbc.conf /etc/asterisk/keys/privkey.pem /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem; do
    if [[ -f "$secret_conf" ]]; then
        chown asterisk:root "$secret_conf"
        chmod 600 "$secret_conf"
    fi
done

# Voicemail/recording files created by Asterisk are group-writable (UMask 0002)
# so the portal (www-data, group asterisk) can delete voicemail; with 0022
# deletion silently failed.
# Asterisk starts after MariaDB: started together (boot, or needrestart after
# a library update) Asterisk came up first, cdr_adaptive_odbc found no
# connection and wrote no CDRs until the next Asterisk restart (11 hours on
# production, 2026-10-21).
mkdir -p /etc/systemd/system/asterisk.service.d
cat > /etc/systemd/system/asterisk.service.d/aipbx.conf << 'UNIT'
[Unit]
Wants=mariadb.service
After=mariadb.service

[Service]
UMask=0002
UNIT
systemctl daemon-reload

# needrestart (unattended-upgrades) restarted Asterisk after library updates
# at 06:35 and dropped the calls in progress. Asterisk is left out; it picks
# up new libraries on the next update or reboot.
if [[ -d /etc/needrestart ]]; then
    mkdir -p /etc/needrestart/conf.d
    cat > /etc/needrestart/conf.d/aipbx.conf << 'NR'
# Managed by AiPBX install.sh: never restart Asterisk automatically (drops calls).
$nrconf{override_rc}{qr(^asterisk\.service$)} = 0;
NR
fi

# A restart can fail although Asterisk comes up a moment later through the
# unit's Restart=on-failure (seen once on an update: the new process got a
# SIGKILL at start and was running 2 s later). Wait for that before failing
# the whole install — a failed upgrade rolls back.
if is_upgrade && [[ "${AIPBX_ASTERISK_RESTART:-yes}" == no ]] && systemctl is-active --quiet asterisk; then
    # aipbx-update found nothing that needs a restart: reload instead, calls stay up.
    asterisk -rx "core reload" >/dev/null 2>&1 || true
    ok "Asterisk reloaded (no restart needed, calls kept)"
elif ! systemctl restart asterisk; then
    warn "Asterisk did not start on the first try — waiting for the automatic restart"
    for _ in $(seq 1 20); do
        systemctl is-active --quiet asterisk && break
        sleep 1
    done
    systemctl is-active --quiet asterisk || error "Asterisk does not start — see: journalctl -u asterisk"
    ok "Asterisk is running"
fi
systemctl enable asterisk

# Sync initial endpoints, dialplans, and transports from DB to Asterisk
info "Syncing database endpoints and dialplan to Asterisk..."
# Not silent: phones point at generated contexts (from-internal-desktop/-mobile/-web);
# if this fails they cannot call until the configuration is applied from the portal.
php /var/www/html/src/asterisk_sync.php \
    || warn "Asterisk configuration sync reported an error — open the portal and apply the pending changes"

ok "Asterisk configured"

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
# STEP 11: LOCAL AI SERVICE (aipbx-ai)
# ============================================================================
step "11. Local AI Service"

# AI → Local models (roadmap 5): AI models that run on this server, served by
# aipbx-ai on 127.0.0.1:8790. Only the code and the unit are installed here;
# the Python runtime with ONNX Runtime (~160 MB; most PBXs never use it) is created
# by `aipbx-ai-setup install` when the admin asks for it, and only then is the
# service enabled.
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
After=network-online.target
Wants=network-online.target
ConditionPathExists=/opt/aipbx-ai/venv/bin/python

[Service]
Type=simple
User=aipbx-ai
Group=aipbx-ai
WorkingDirectory=/opt/aipbx-ai/app
ExecStart=/opt/aipbx-ai/venv/bin/python -m aipbx_ai
LoadCredential=token:/etc/aipbx/ai.token
Environment=HOME=/var/lib/aipbx-ai AIPBX_AI_DATA=/var/lib/aipbx-ai
Environment=PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
Restart=on-failure
RestartSec=5
# Calls come first: Asterisk keeps the CPU when both want it.
Nice=5
CPUWeight=50
MemoryMax=2G
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
systemctl daemon-reload

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
    ok "Local AI service installed (off — install the runtime on AI → Local models)"
fi

# ============================================================================
# STEP 12: COTURN (WebRTC TURN)
# ============================================================================
step "12. Configuring coturn"

# TURN may relay only to this server. Every AiPBX call goes through Asterisk,
# so the far end of a relay is always one of this server's addresses; private
# and link-local networks are denied otherwise. TURN credentials are handed to
# anonymous website visitors (call widget), and without this a relay could
# reach other hosts of the company network (review of 2026-10-16). Loopback
# is denied by coturn itself. Public addresses stay allowed: the server's own
# public address (NAT) can be a candidate too.
TURN_ALLOWED=""
for ip in $(hostname -I); do
    TURN_ALLOWED+="allowed-peer-ip=${ip}"$'\n'
done

cat > /etc/turnserver.conf.new << TURNCONF
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
# Relay on the server's own address. TURNS arrives through the 443 nginx
# multiplexer (127.0.0.1), and coturn relays on the address the client came
# in on: a loopback relay that Asterisk's media never matched, so calls that
# needed TURN had no audio.
relay-ip=${SERVER_IP}
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.0.0.0-192.0.0.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=198.18.0.0-198.19.255.255
denied-peer-ip=fc00::-fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
denied-peer-ip=fe80::-febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff
${TURN_ALLOWED}
TURNCONF

# coturn's certificate (/etc/coturn/aipbx.*) is the copy aipbx-cert wrote in step 7.

# Let's Encrypt renews every ~60-90 days, but coturn and Asterisk read copies
# and Apache keeps the old certificate in memory: the deploy hook hands each
# renewal to aipbx-cert. Written on every install, because Let's Encrypt can
# be switched on later from the Certificates page.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/aipbx.sh << 'LEHOOK'
#!/bin/bash
# AiPBX: deploy a renewed certificate while Let's Encrypt is the active source.
exec /usr/local/sbin/aipbx-cert renewal-hook
LEHOOK
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/aipbx.sh

mkdir -p /var/log/turnserver
chown turnserver:turnserver /var/log/turnserver 2>/dev/null || true
# Restart only when the configuration changed (or coturn is down): a restart
# drops every WebRTC/app call relayed through TURN, and an update that keeps
# Asterisk running must not drop them here instead.
if cmp -s /etc/turnserver.conf.new /etc/turnserver.conf && systemctl is-active --quiet coturn; then
    rm -f /etc/turnserver.conf.new
else
    mv -f /etc/turnserver.conf.new /etc/turnserver.conf
    systemctl restart coturn 2>/dev/null || true
fi
systemctl enable coturn 2>/dev/null || true

ok "coturn configured"

# ============================================================================
# STEP 13: SECURITY (FIREWALLD & FAIL2BAN)
# ============================================================================
# Time sync: CDRs, TLS, chat tokens and TOTP (30 s window) all need a correct
# clock. Ubuntu's default chrony sources are NTS-only; where TCP 4460 (NTS-KE)
# is blocked the clock never synchronised and drifted by minutes. Add plain
# NTP as a fallback and let chrony select it (NTS stays authenticated when
# reachable).
if [[ -d /etc/chrony ]]; then
    mkdir -p /etc/chrony/sources.d /etc/chrony/conf.d
    cat > /etc/chrony/sources.d/aipbx-fallback.sources << 'NTPSRC'
# AI PBX: plain NTP fallback for networks that block NTS-KE (TCP 4460)
pool pool.ntp.org iburst maxsources 4
NTPSRC
    cat > /etc/chrony/conf.d/aipbx.conf << 'NTPCONF'
# AI PBX: with NTS unreachable, "mix"/"prefer" never select plain NTP sources
authselectmode ignore
NTPCONF
    # aipbx-ntp-server.conf (allow lines for the phones) is written by
    # aipbx-priv netsvc ntp-on when Network services -> NTP server is on.
    systemctl restart chrony 2>/dev/null || true
fi

step "13. Configuring Firewall (firewalld) & Fail2ban"

# 13a. Firewalld configuration
# The portal's Firewall page manages firewalld. Ubuntu also ships ufw; with
# both enabled one closes what the other opens.
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    warn "ufw is active — disabling it; firewalld manages the firewall (portal: Firewall page)"
    ufw disable >/dev/null 2>&1 || true
fi
systemctl disable --now ufw 2>/dev/null || true
systemctl enable firewalld 2>/dev/null || true
systemctl start firewalld 2>/dev/null || true

# Ports are opened on the first install only; on upgrade the admin's own
# choices (portal Firewall page) must not be reverted.
if ! is_upgrade; then
firewall-cmd --permanent --add-service=http 2>/dev/null || true
firewall-cmd --permanent --add-service=https 2>/dev/null || true
firewall-cmd --permanent --add-service=ssh 2>/dev/null || true
firewall-cmd --permanent --add-port=5060/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=5060/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=5061/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=10000-20000/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=4100-4999/udp 2>/dev/null || true   # T.38 (UDPTL, udptl.conf)
firewall-cmd --permanent --add-port=3478/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=3478/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=5349/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=5349/udp 2>/dev/null || true
firewall-cmd --permanent --add-port=49152-65535/udp 2>/dev/null || true
firewall-cmd --reload 2>/dev/null || true
fi   # install mode
# T.38 fax travels on the UDPTL ports (udptl.conf, 4100-4999), which the first
# install never opened: a carrier accepted T.38 and every packet was dropped
# ("Disconnected after permitted retries", live system 2026-10-22). Opened on
# upgrade too, but only where the RTP range is open as installed, so an admin
# who closed media ports on purpose is not overruled.
if command -v firewall-cmd >/dev/null 2>&1 \
   && firewall-cmd --permanent --query-port=10000-20000/udp >/dev/null 2>&1 \
   && ! firewall-cmd --permanent --query-port=4100-4999/udp >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port=4100-4999/udp >/dev/null 2>&1 && firewall-cmd --reload >/dev/null 2>&1 || true
fi

# Asterisk's direct WSS (8089) now listens on loopback only; clients use 443 /ws.
# Close the port that earlier versions opened.
if firewall-cmd --permanent --query-port=8089/tcp >/dev/null 2>&1; then
    firewall-cmd --permanent --remove-port=8089/tcp >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
fi

# 13b. Asterisk security logging
sed -i "s/^;security\.log => security/security.log => security/" /etc/asterisk/logger.conf 2>/dev/null || true
asterisk -rx "logger reload" 2>/dev/null || true

# 13c. Fail2ban configuration
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

# Brute-force protection for the web portal / mobile login. Needs the real
# client address, which Apache gets from nginx via the PROXY protocol.
cat > /etc/fail2ban/filter.d/aipbx-web.conf << 'F2BFILTER'
[Definition]
failregex = ^<HOST> - \[.*\] FAILED_LOGIN user=
ignoreregex =
F2BFILTER

cat > /etc/fail2ban/jail.d/aipbx-web.local << 'JAIL'
[aipbx-web]
enabled  = true
port     = http,https
filter   = aipbx-web
logpath  = /var/log/aipbx/web_login_failures.log
maxretry = 10
bantime  = 3600
findtime = 600
JAIL

# jail.d stays root-owned: fail2ban runs as root and a jail file can define
# the commands it executes, so a web-writable jail.d would be a root shell.
# The panel stages its overrides in /var/lib/aipbx and `aipbx-priv f2b
# install-override` validates and installs them.
chown -R root:root /etc/fail2ban/jail.d
chmod 755 /etc/fail2ban/jail.d
# The panel's overrides must be read last: fail2ban sorts jail.d by name, so
# "99-..." sorted before asterisk.local / aipbx-web.local and was overridden.
if [[ -f /etc/fail2ban/jail.d/99-ai-pbx.local ]]; then
    mv -n /etc/fail2ban/jail.d/99-ai-pbx.local /etc/fail2ban/jail.d/zz-ai-pbx.local
    rm -f /etc/fail2ban/jail.d/99-ai-pbx.local
fi
touch /etc/fail2ban/jail.d/zz-ai-pbx.local
chmod 644 /etc/fail2ban/jail.d/zz-ai-pbx.local

systemctl enable fail2ban 2>/dev/null || true
systemctl restart fail2ban 2>/dev/null || true
ok "firewall and fail2ban configured"

# The installed version, for the bridge check of later releases (see UPGRADE_FROM).
install -d -m 0755 /var/lib/aipbx
tr -d '[:space:]' < "$INSTALL_DIR/VERSION" > /var/lib/aipbx/installed-version
chmod 0644 /var/lib/aipbx/installed-version

if is_upgrade; then
    echo ""
    ok "Upgrade to $(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo '?') applied"
    exit 0
fi

# ============================================================================
# STEP 14: SAVE CREDENTIALS TO FILE
# ============================================================================
CREDS_FILE="/root/aipbx-credentials.txt"
install -m 0600 -o root -g root /dev/null "$CREDS_FILE"

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
    echo -e "     WebRTC TURNS (calls behind strict firewalls) needs a trusted certificate."
    echo -e "     Get one later in the portal: ${CYAN}Security → Certificates${NC}"
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

# Self test (Admin -> Health checks): what this install set up, checked from the outside.
echo -e "  ${BOLD}SELF TEST${NC} (sudo aipbx-selftest)"
if /usr/local/sbin/aipbx-selftest 2>&1 | sed 's/^/  /'; then
    echo -e "  ✅ ${GREEN}No check failed${NC}"
else
    echo -e "  ⚠️  ${YELLOW}A check failed — see above (the line marked FAIL)${NC}"
fi
echo ""

ssh_cfg="$(sshd -T 2>/dev/null)"
if grep -qi '^permitrootlogin yes' <<<"$ssh_cfg" && grep -qi '^passwordauthentication yes' <<<"$ssh_cfg"; then
    echo -e "  ${YELLOW}⚠️  SSH allows root login with a password. Consider key-only logins and${NC}"
    echo -e "  ${YELLOW}   limiting port 22 to trusted networks (see docs/security.md).${NC}"
    echo ""
fi
echo -e "  ${BOLD}📄 All credentials saved to:${NC} ${CYAN}${CREDS_FILE}${NC}"
echo -e "  ${YELLOW}⚠️  Note these credentials now! Delete the file after saving them securely.${NC}"
echo ""
echo -e "  All passwords can be changed later from: ${CYAN}Admin Panel → Settings → System${NC}"
echo ""
