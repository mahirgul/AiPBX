#!/usr/bin/env bash
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
  php-ldap
  php-odbc
  # External databases (Integration -> Database Connections) offer PostgreSQL too.
  php-pgsql
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
  libsox-fmt-all
  rsync
  libtiff-tools
  postfix
  libsasl2-modules
  mailutils
  certbot
  python3-certbot-apache
  python3-certbot-dns-cloudflare
  openssl
  # Health checks (Admin -> Health checks) shell out to curl; a minimal
  # Ubuntu image does not have it, and the check then reports a false FAIL.
  curl
  # Problem recording (System -> Problem recording) captures RTP audio with it.
  tcpdump
  # Reads /etc/cron.d/aipbx (queue sync, the 02:30 backup, phone discovery,
  # retention). Ubuntu server images ship it; a minimal one — a cloud image,
  # the container — does not, and then every scheduled job silently never runs.
  cron
  avahi-daemon
  avahi-utils
  dnsmasq-base
  vsftpd
  libpam-pwdfile
  # Network -> VPN Server and VPN Client. Both stay unused until those pages start
  # them, in units of their own (aipbx-vpn@*).
  wireguard-tools
  python3-yaml
  openvpn
)

if [[ -d "$INSTALL_DIR/engine" || -d "$INSTALL_DIR/signaling" ]]; then
    PACKAGES+=(python3-pymysql)
fi

# An upgrade only installs packages a newer release added: system package
# updates are the OS's job, and on servers that cannot reach the Ubuntu
# mirrors (e.g. behind a stalling proxy) apt-get used to hang the update for
# many minutes. Short timeouts so an unreachable mirror fails fast, and a bound
# on the dpkg lock (see APT_LOCK_WAIT) so a lock held by something else fails
# with apt's own message instead of waiting out the caller's timeout.
APT_NET=(-o Acquire::http::Timeout=20 -o Acquire::https::Timeout=20 -o Acquire::Retries=1
         -o "DPkg::Lock::Timeout=$APT_LOCK_WAIT")
# --- Repairing what an interrupted run left behind ----------------------------
# An install or update stopped half-way (closed terminal, reboot, a killed apt)
# leaves dpkg half-done: apt then refuses with "dpkg was interrupted, you must
# manually run 'dpkg --configure -a'" or "unmet dependencies, try 'apt --fix-broken
# install'", and a fresh cloud server is often still running unattended-upgrades,
# which holds the lock. apt_heal waits for the lock (naming who holds it), finishes
# an interrupted dpkg run and repairs broken dependencies. It never kills a running
# apt/dpkg: stopping one half-way is what breaks a system.
APT_HEAL_WAIT=1800  # safety bound: a package job of another process that has not ended in 30 min is stuck
APT_FIX=(-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

# "PID command" of a process holding an apt/dpkg lock (from /proc/locks: no psmisc needed).
apt_lock_holder() {
    local f ino pid
    for f in /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock; do
        [[ -e "$f" ]] || continue
        ino=$(stat -c %i "$f")
        pid=$(awk -v i="$ino" '{ n = split($6, a, ":"); if (a[n] == i && $5 > 0) { print $5; exit } }' /proc/locks)
        if [[ -n "$pid" && -r "/proc/$pid/cmdline" ]]; then
            echo "$pid $(tr '\0' ' ' < "/proc/$pid/cmdline" | cut -c1-100)"
            return 0
        fi
    done
    return 1
}

# Waits on this step while another process installs or updates packages (often
# unattended-upgrades on a new server): one message, then a spinner on the same line.
apt_wait_lock() {
    local holder waited=0 dots
    holder=$(apt_lock_holder) || return 0
    warn "Another process is installing or updating packages (PID ${holder%% *}: ${holder#* })."
    info "Waiting here for it to finish — the installation goes on by itself afterwards."
    info "To see what it does: ${CYAN}ps -fp ${holder%% *}${NC}  ·  progress: ${CYAN}tail -f /var/log/apt/term.log${NC}"
    while apt_lock_holder >/dev/null; do
        if (( waited >= APT_HEAL_WAIT )); then
            echo ""
            error "The other package job (PID ${holder%% *}) has not ended in $((APT_HEAL_WAIT / 60)) min and looks stuck. Stop it with ${CYAN}sudo kill ${holder%% *}${NC}, then run the installer again."
        fi
        if [[ -t 1 ]]; then
            dots=$(( waited % 3 + 1 ))
            printf '\r   %-3s %dm %02ds ' "$(printf '%.*s' "$dots" '...')" $((waited / 60)) $((waited % 60))
        elif (( waited % 10 == 0 )); then
            printf '.'
        fi
        sleep 1
        waited=$((waited + 1))
    done
    if [[ -t 1 ]]; then printf '\r\033[K'; else echo ""; fi
    ok "The other package job finished after $((waited / 60)) min $((waited % 60)) s — going on"
}

apt_heal() {
    apt_wait_lock
    if [[ -n "$(ls -A /var/lib/dpkg/updates 2>/dev/null)" || -n "$(dpkg --audit 2>/dev/null)" ]]; then
        warn "An earlier package installation was interrupted — finishing it (dpkg --configure -a)"
        DEBIAN_FRONTEND=noninteractive timeout "$APT_INSTALL_TIMEOUT" dpkg --force-confdef --force-confold --configure -a \
            || warn "dpkg --configure -a did not finish cleanly"
    fi
    if ! apt-get check >/dev/null 2>&1; then
        warn "Broken package dependencies — repairing them (apt-get -f install)"
        DEBIAN_FRONTEND=noninteractive timeout "$APT_INSTALL_TIMEOUT" apt-get "${APT_NET[@]}" "${APT_FIX[@]}" -f install -y \
            || warn "apt-get -f install did not finish cleanly"
    fi
    return 0
}

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
    # was what made this phase look like nothing at all for 45 minutes. Both apt
    # calls hand over the directory they fill, so a slow download is progress.
    apt_heal
    apt_update() {
        apt_rc=0
        stream_bounded "apt-get update" "$APT_UPDATE_TIMEOUT" "$APT_SILENCE" \
            --progress=/var/lib/apt/lists -- \
            apt-get "${APT_NET[@]}" update -qq || apt_rc=$?
    }
    apt_update
    if [[ $apt_rc -ne 0 && $apt_rc -ne 124 && $apt_rc -ne 125 ]]; then
        # A half-downloaded list (e.g. "Hash Sum mismatch") stays in partial/ and fails every next run.
        warn "apt-get update failed — clearing half-downloaded package lists and trying once more"
        rm -rf /var/lib/apt/lists/partial/*
        apt_update
    fi
    case $apt_rc in
        0) ;;
        124|125) error "apt-get update stopped making progress (see above) — the mirror or the dpkg lock is wedged" ;;
        *) warn "apt-get update failed — using the existing package lists" ;;
    esac

    if is_upgrade; then WANT=("${MISSING[@]}"); else WANT=("${PACKAGES[@]}"); fi
    apt_install() {
        apt_rc=0
        stream_bounded "apt-get install (${#WANT[@]} packages)" \
            "$APT_INSTALL_TIMEOUT" "$APT_SILENCE" \
            --progress=/var/cache/apt/archives -- \
            apt-get "${APT_NET[@]}" install -y "${WANT[@]}" || apt_rc=$?
    }
    apt_install
    if [[ $apt_rc -ne 0 && $apt_rc -ne 124 && $apt_rc -ne 125 ]]; then
        warn "apt-get install failed (status $apt_rc) — repairing apt/dpkg and trying once more"
        apt_heal
        apt_install
    fi
    case $apt_rc in
        0) ;;
        124) error "apt-get install hit the ${APT_INSTALL_TIMEOUT}s budget — dpkg was still working (see above)" ;;
        125) error "apt-get install printed nothing and received nothing for ${APT_SILENCE}s — the mirror or a postinst script is stuck (see above)" ;;
        *) error "apt-get install failed (status $apt_rc): the packages above are missing" ;;
    esac
fi

ok "System packages installed"

# Remove default Nginx site immediately so it does not conflict with Apache on port 80
rm -f /etc/nginx/sites-enabled/default
systemctl reload nginx 2>/dev/null || true
