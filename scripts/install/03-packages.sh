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
  python3-certbot-dns-cloudflare
  openssl
  # Health checks (Admin -> Health checks) shell out to curl; a minimal
  # Ubuntu image does not have it, and the check then reports a false FAIL.
  curl
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
    # was what made this phase look like nothing at all for 45 minutes. Both apt
    # calls hand over the directory they fill, so a slow download is progress.
    apt_rc=0
    stream_bounded "apt-get update" "$APT_UPDATE_TIMEOUT" "$APT_SILENCE" \
        --progress=/var/lib/apt/lists -- \
        apt-get "${APT_NET[@]}" update -qq || apt_rc=$?
    case $apt_rc in
        0) ;;
        124|125) error "apt-get update stopped making progress (see above) — the mirror or the dpkg lock is wedged" ;;
        *) warn "apt-get update failed — using the existing package lists" ;;
    esac

    apt_rc=0
    if is_upgrade; then
        stream_bounded "apt-get install (${#MISSING[@]} new packages)" \
            "$APT_INSTALL_TIMEOUT" "$APT_SILENCE" \
            --progress=/var/cache/apt/archives -- \
            apt-get "${APT_NET[@]}" install -y "${MISSING[@]}" || apt_rc=$?
    else
        stream_bounded "apt-get install (${#PACKAGES[@]} packages)" \
            "$APT_INSTALL_TIMEOUT" "$APT_SILENCE" \
            --progress=/var/cache/apt/archives -- \
            apt-get "${APT_NET[@]}" install -y "${PACKAGES[@]}" || apt_rc=$?
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
