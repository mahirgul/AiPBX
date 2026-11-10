#!/usr/bin/env bash
# ============================================================================
# AI PBX — Fresh Install Script
# Supported: Ubuntu 26.04 LTS+, Debian 13+, 64-bit Raspberry Pi OS / Armbian
# Architectures: x86_64 (amd64), aarch64 (arm64)
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
# --- Output style ---
# Colours only on a terminal (not in a log file, CI or with NO_COLOR); Unicode marks only with a
# UTF-8 locale, plain ASCII otherwise. The words of every message stay the same either way.
if [[ -t 1 && -z "${NO_COLOR:-}" && "${TERM:-dumb}" != dumb ]]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
    BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
    GRAD=('\033[38;5;33m' '\033[38;5;39m' '\033[38;5;38m' '\033[38;5;37m' '\033[38;5;36m' '\033[38;5;35m')
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; CYAN=''; BOLD=''; DIM=''; NC=''
    GRAD=('' '' '' '' '' '')
fi
case "${LC_ALL:-${LC_CTYPE:-${LANG:-}}}" in
    *UTF-8*|*utf-8*|*UTF8*|*utf8*)
        I_OK='✔'; I_WARN='⚠'; I_INFO='ℹ'; I_ERR='✖'; BAR_ON='▰'; BAR_OFF='▱'; SEP='·'
        B_TL='╭'; B_TR='╮'; B_BL='╰'; B_BR='╯'; B_H='─'; B_V='│' ;;
    *)
        I_OK='+'; I_WARN='!'; I_INFO='i'; I_ERR='x'; BAR_ON='#'; BAR_OFF='.'; SEP='-'
        B_TL='+'; B_TR='+'; B_BL='+'; B_BR='+'; B_H='-'; B_V='|' ;;
esac
# The number of `step "N. ..."` lines below, counted so a new step keeps the bar right. Run as
# `curl ... | sudo bash` there is no file to count (and BASH_SOURCE is unset under set -u): 13.
TOTAL_STEPS=$(grep -c '^step "[0-9]*\. ' "${BASH_SOURCE[0]:-}" 2>/dev/null) || TOTAL_STEPS=13
START_SECONDS=$SECONDS

info()  { echo -e "   ${BLUE}${I_INFO}${NC} $*"; }
ok()    { echo -e "   ${GREEN}${I_OK}${NC} $*"; }
warn()  { echo -e "   ${YELLOW}${I_WARN} $*${NC}"; }
error() { echo -e "   ${RED}${BOLD}${I_ERR} $*${NC}"; exit 1; }
# step "3. Installing System Packages": a progress bar and 3/13 before the title.
step() {
    local n="${1%%.*}" title="${1#*. }" bar="" i
    if [[ "$n" =~ ^[0-9]+$ ]]; then
        for ((i = 1; i <= TOTAL_STEPS; i++)); do
            if ((i <= n)); then bar+="$BAR_ON"; else bar+="$BAR_OFF"; fi
        done
        echo -e "\n ${CYAN}${bar}${NC} ${BOLD}$(printf '%2d' "$n")/${TOTAL_STEPS}  ${title}${NC}"
    else
        echo -e "\n ${CYAN}${BOLD}${BAR_ON} $*${NC}"
    fi
}
# box "Title" "line" ...: a rounded frame 60 columns wide; lines may carry colour codes.
box() {
    local title="$1" line plain pad w=58
    shift
    # As wide as the longest line, at least 58 columns.
    for line in "$@"; do
        plain="$(echo -e "$line" | sed 's/\x1b\[[0-9;]*m//g')"
        ((${#plain} + 4 > w)) && w=$((${#plain} + 4))
    done
    ((${#title} + 4 > w)) && w=$((${#title} + 4))
    printf ' %s%s %s %s%s\n' "$B_TL" "$B_H" "$(echo -e "${BOLD}${title}${NC}")" "$(printf "%*s" $((w - ${#title} - 3)) '' | sed "s/ /$B_H/g")" "$B_TR"
    for line in "$@"; do
        plain="$(echo -e "$line" | sed 's/\x1b\[[0-9;]*m//g')"
        pad=$((w - 2 - ${#plain}))
        ((pad < 0)) && pad=0
        printf ' %s  %s%*s%s\n' "$B_V" "$(echo -e "$line")" "$pad" '' "$B_V"
    done
    printf ' %s%s%s\n' "$B_BL" "$(printf "%*s" "$w" '' | sed "s/ /$B_H/g")" "$B_BR"
}

# --- Root check ---
[[ $EUID -ne 0 ]] && error "Run this script as root: sudo bash install.sh"

# --- Architecture check: 64-bit (x86_64, aarch64/arm64) only ---
ARCH="$(uname -m)"
case "$ARCH" in
    x86_64|amd64|aarch64|arm64)
        ;;
    *)
        error "AiPBX requires a 64-bit architecture (x86_64 or aarch64/arm64); detected: ${ARCH}. 32-bit systems (such as 32-bit Raspbian/armv7l or i686) are not supported. Please install a 64-bit operating system."
        ;;
esac

# --- Supported OS: Ubuntu 26.04 LTS+, Debian 13+, or Debian-derivatives (Raspberry Pi OS 64-bit, Armbian) ---
# The PHP dependencies (Symfony 8 via Phinx, composer.lock) need PHP >= 8.4.1
# and the portal targets Asterisk 22. Ubuntu 26.04+ and Debian 13+ (Trixie) provide
# these out of the box. Other Debian derivatives are supported if PHP >= 8.4 is present.
# Set AIPBX_SKIP_OS_CHECK=1 to bypass.
if [[ "${AIPBX_SKIP_OS_CHECK:-0}" != 1 ]]; then
    OS_ID="$(. /etc/os-release 2>/dev/null; echo "${ID:-unknown}")"
    OS_ID_LIKE="$(. /etc/os-release 2>/dev/null; echo "${ID_LIKE:-}")"
    OS_VER="$(. /etc/os-release 2>/dev/null; echo "${VERSION_ID:-0}")"

    IS_SUPPORTED=0
    if [[ "$OS_ID" == "ubuntu" ]]; then
        if [[ "$(printf '%s\n' 26.04 "$OS_VER" | sort -V | head -1)" == 26.04 ]]; then
            IS_SUPPORTED=1
        fi
    elif [[ "$OS_ID" == "debian" || "$OS_ID" == "raspbian" || "$OS_ID" == "armbian" || "$OS_ID_LIKE" =~ (debian|ubuntu) ]]; then
        if [[ "$OS_VER" =~ ^[0-9]+$ ]] && [[ "$OS_VER" -ge 13 ]]; then
            IS_SUPPORTED=1
        elif [[ "$OS_VER" == "0" || -z "$OS_VER" ]]; then
            # Debian testing/sid has VERSION_ID empty or 0
            IS_SUPPORTED=1
        fi
    fi

    # Fallback: if on a Debian-family OS and PHP >= 8.4 is already installed (e.g. via Sury PPA on Debian 12 / RPi OS)
    if [[ "$IS_SUPPORTED" -eq 0 ]] && command -v php >/dev/null 2>&1; then
        PHP_VER="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 0)"
        if [[ "$(printf '%s\n' 8.4.1 "$PHP_VER" | sort -V | head -1)" == 8.4.1 ]]; then
            IS_SUPPORTED=1
        fi
    fi

    if [[ "$IS_SUPPORTED" -eq 0 ]]; then
        error "AiPBX officially supports 64-bit Ubuntu 26.04 LTS+, Debian 13 (Trixie)+, or 64-bit Raspberry Pi OS / Armbian (detected: ${OS_ID} ${OS_VER} on ${ARCH}). Prerequisites: PHP >= 8.4 and Asterisk 22. If your system meets these prerequisites (e.g. via Sury PPA or backports), set AIPBX_SKIP_OS_CHECK=1 to try anyway."
    fi
fi

# Raise this number in a change that needs Asterisk RESTARTED on update (a new
# Asterisk package or module, the service unit, anything read only at start).
# aipbx-update compares it between releases (scripts/asterisk-restart-needed.sh):
# when it and conf/asterisk/ are unchanged, Asterisk is only reloaded and
# calls in progress stay up.
# shellcheck disable=SC2034 # Read externally by scripts/asterisk-restart-needed.sh from git tree
ASTERISK_RESTART_EPOCH=2

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
# not get lock" message); Composer has no such knob, so the whole command is
# bounded here instead (see COMPOSER_TIMEOUT).
#
# Neither of those covers the third way this can stop: apt's own configure stage
# runs postinst scripts that can block for ever, with nothing printed and no
# timeout of apt's own. That is why the package phase below also runs under
# stream_bounded (see APT_* there): a wall-clock budget for the phase, and a
# bound on how long its output may stand still.
APT_LOCK_WAIT=120      # seconds apt-get may wait for the dpkg lock
COMPOSER_TIMEOUT=900   # seconds the whole `composer install` may take
COMPOSER_SILENCE=300   # no line and no download progress for this long means a real stall

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
#
# Standing still is judged on progress, not on lines alone. A download prints
# nothing while it arrives, and on 2026-10-26 that failed the install test: apt's
# last line was 301 s old — "Get:147 ... golang-1.26-src [24.4 MB]", a 24 MB
# source package still coming down a slow mirror — and the silence clock killed a
# phase that was working (994 s in). Both apt calls therefore name the directory
# apt writes into (--progress=), bytes landing there reset the same clock, and a
# long download now shows in the log instead of looking like silence. A postinst
# that hangs prints nothing AND writes nothing, so it is still killed at the bound.
APT_UPDATE_TIMEOUT=300    # apt-get update: a mirror that cannot answer in 5 min is down
APT_INSTALL_TIMEOUT=1200  # apt-get install of every package; the healthy run took ~2.5 min
APT_SILENCE=300           # no line and no download progress for this long means a real stall

# stream_bounded <label> <budget-seconds> <silence-seconds> [--progress=DIR] -- <command...>
# Runs the command under both clocks, streaming its output. Returns the
# command's own status, or
#   124  the wall-clock budget ran out
#   125  the output stood still for the silence bound, with nothing arriving in DIR
#
# --progress is optional, for callers whose work lands in a directory (apt's
# downloads): growth of that directory counts as progress, so a slow download is
# not mistaken for a hang. Without it the silence bound is measured on output alone.
stream_bounded() {
    local label="$1" budget="$2" quiet="$3"
    local log rc=0 shown=0 lines=0 start last_out last_prog reason="" pid i elapsed
    local progress="" bytes=0 seen=0 waited=0 quiet_at=0
    shift 3
    if [[ "${1:-}" == --progress=* ]]; then
        progress="${1#--progress=}"
        shift
    fi
    [[ "${1:-}" == "--" ]] && shift

    log="$(mktemp)" || { "$@"; return $?; }
    start=$SECONDS
    last_out=$start
    last_prog=$start
    if [ -n "$progress" ]; then
        info "$label — up to ${budget}s, ${quiet}s without a line or a byte counts as stuck"
    else
        info "$label — up to ${budget}s, ${quiet}s without a line counts as stuck"
    fi

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
        if [ -n "$progress" ]; then
            # du, not a stat of one file: apt renames each download as it finishes,
            # so the directory's total size is the measure that does not blink.
            bytes=$({ du -sk "$progress" 2>/dev/null || true; } | awk '{print $1}')
            [ -n "$bytes" ] || bytes=0
            if [ "$bytes" != "$seen" ]; then
                seen=$bytes
                last_prog=$SECONDS
                # Say it once per quiet window: without this a slow download
                # reads exactly like the silence this clock exists to catch.
                if [ $((SECONDS - last_out)) -ge "$quiet" ] && [ $((SECONDS - waited)) -ge "$quiet" ]; then
                    waited=$SECONDS
                    info "$label: no output for $((SECONDS - last_out))s, but $((bytes / 1024)) MB is now in ${progress} — still downloading"
                fi
            fi
        fi
        # Stuck means neither the output nor the downloads have moved.
        quiet_at=$last_out
        [ "$last_prog" -gt "$quiet_at" ] && quiet_at=$last_prog
        if [ $((SECONDS - quiet_at)) -ge "$quiet" ]; then
            if [ -n "$progress" ]; then
                reason="printed nothing for $((SECONDS - last_out))s and received nothing for $((SECONDS - last_prog))s"
            else
                reason="went silent for $((SECONDS - last_out))s"
            fi
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
        --reinstall) AIPBX_REINSTALL=1 ;;
        *) error "Unknown argument: $arg (supported: --upgrade, --reinstall)" ;;
    esac
done
if [[ "$AIPBX_MODE" == "upgrade" && ! -f /etc/ai-pbx.env ]]; then
    error "--upgrade needs an existing installation (/etc/ai-pbx.env not found)"
fi
# A plain run on an installed system would reset the admin password and make new
# secrets: one forgotten word on a live server. An install that stopped half way
# can go again with --reinstall.
if [[ "$AIPBX_MODE" == "install" && -f /etc/ai-pbx.env && "${AIPBX_REINSTALL:-0}" != "1" ]]; then
    error "AiPBX is already installed here (/etc/ai-pbx.env). To update it: bash install.sh --upgrade. To install over it, which resets the admin password and the secrets: bash install.sh --reinstall"
fi
is_upgrade() { [[ "$AIPBX_MODE" == "upgrade" ]]; }
# A value for inside '...' in SQL: a password an admin set may hold a quote or a backslash.
sql_q() { local v="${1//\\/\\\\}"; printf '%s' "${v//\'/\'\'}"; }

# Reads KEY=value from the existing environment file (upgrade mode).
env_get() {
    (grep -m1 "^$1=" /etc/ai-pbx.env 2>/dev/null || true) | cut -d= -f2-
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
# every successful install/upgrade in /var/lib/aipbx-system/installed-version.
if is_upgrade && [[ -f "$INSTALL_DIR/UPGRADE_FROM" ]]; then
    need_from="$(tr -d '[:space:]' < "$INSTALL_DIR/UPGRADE_FROM")"
    version_marker=/var/lib/aipbx-system/installed-version
    [[ -e "$version_marker" ]] || version_marker=/var/lib/aipbx/installed-version
    have="$(dd if="$version_marker" iflag=nofollow,nonblock bs=64 count=1 status=none 2>/dev/null | tr -d '[:space:]' || true)"
    [[ "$have" =~ ^[0-9]{1,5}\.[0-9]{1,5}\.[0-9]{1,5}$ ]] || have=""
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
# RUN INSTALLATION STEPS
# ============================================================================
INSTALL_STEPS_DIR="$SCRIPT_DIR/scripts/install"
if [[ ! -d "$INSTALL_STEPS_DIR" && -d "$INSTALL_DIR/scripts/install" ]]; then
    INSTALL_STEPS_DIR="$INSTALL_DIR/scripts/install"
fi

INSTALL_STEPS=(
    "00-banner.sh"
    "01-domain.sh"
    "02-credentials.sh"
    "03-packages.sh"
    "04-directories.sh"
    "05-environment.sh"
    "06-mariadb.sh"
    "07-certificates.sh"
    "08-webservers.sh"
    "09-asterisk.sh"
    "10-chat.sh"
    "11-ai.sh"
    "12-coturn.sh"
    "13-security.sh"
    "14-summary.sh"
)

for step in "${INSTALL_STEPS[@]}"; do
    step_path="$INSTALL_STEPS_DIR/$step"
    if [[ ! -f "$step_path" ]]; then
        error "Installation step script not found: $step_path"
        exit 1
    fi
    # Sourced so environment variables and shell options persist across steps
    # shellcheck source=/dev/null
    source "$step_path"
done
