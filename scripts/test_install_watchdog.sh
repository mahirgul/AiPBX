#!/usr/bin/env bash
# ============================================================================
# Tests for `stream_bounded` in install.sh — the helper that runs apt-get and
# composer with their output streaming and kills them on a wall clock or on
# silence.
#
# Why this exists: on 2026-10-26 the install test sat inside install.sh for 45
# minutes and left an empty log, three times. The helper is what turns that
# into a named failure in minutes (see docs/health.md). It is easy to break it
# again without noticing — a pipe that buffers the output, a clock that never
# fires, a kill that leaves dpkg behind, the two apt-get calls quietly going back
# to `| tail -5`, or a silence clock that forgot a download prints nothing while
# it arrives (that last one failed the install test on 2026-10-26: a slow 24 MB
# package was killed as if the mirror had died) — because none of that shows up
# in a healthy install. Composer is bounded the same way and case 1 checks that
# it stays that way, with its cache as the progress directory. The cases below
# fail loudly instead.
#
# Usage:
#   bash scripts/test_install_watchdog.sh            # install.sh in the repo root
#   bash scripts/test_install_watchdog.sh /path/to/install.sh
#
# Runtime is about 100 seconds, most of it case 3: the helper gives a child that
# ignores TERM 60 seconds before it SIGKILLs the process group.
# ============================================================================
set -uo pipefail   # deliberately no -e: the cases assert on failure statuses

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_SH="${1:-$ROOT/install.sh}"
[ -f "$INSTALL_SH" ] || { echo "FAIL: no such file: $INSTALL_SH"; exit 2; }

fail=0
pass() { printf 'PASS  %s\n' "$*"; }
bad()  { printf 'FAIL  %s\n' "$*"; fail=1; }

echo '--- 0. the SSH advisory cannot fail a finished install'
# `sshd -T` is how the end of install.sh finds out whether the host still takes
# passwords over SSH. On a host with no SSH server (a container, a minimal cloud
# image) it exits 127, and on one with no host key yet it exits non-zero too —
# under `set -e` that status ended the script after the last check had passed, so
# a finished install reported failure and aipbx-update rolled the box back over
# an advisory. The block is extracted from install.sh rather than copied, so a
# revert to the bare `sshd -T` fails here.
advisory="$(awk '/^ssh_cfg=/{p=1} p&&/All credentials saved to:/{exit} p{print}' "$INSTALL_SH")"
if [ -z "$advisory" ]; then
    bad 'the SSH advisory is gone from install.sh (deleting it is not a fix)'
else
    # A PATH with the shell tools but no sshd: Ubuntu keeps it in /usr/sbin,
    # which this one leaves out.
    no_sshd=$(mktemp -d)
    mkdir -p "$no_sshd/bin"
    for tool in bash grep cat; do
        if path=$(command -v "$tool"); then ln -s "$path" "$no_sshd/bin/$tool"; fi
    done

    # The advisory's echo lines use install.sh's colour variables.
    run_advisory() {  # run_advisory PATH -> prints what the block prints
        # shellcheck disable=SC2034 # Variables mocked for the subshell eval of the advisory
        ( set -euo pipefail; YELLOW=; BOLD=; NC=; PATH="$1"; eval "$advisory" )
    }

    if PATH="$no_sshd/bin" command -v sshd >/dev/null 2>&1; then
        bad 'cannot build the no-sshd case here: sshd is on the trimmed PATH'
    else
        # Status is read from a plain assignment on purpose: with `if
        # run_advisory ...`, bash turns errexit off for the list in the
        # condition, and the subshell would carry on after the failing line —
        # the case would pass whatever install.sh did.
        out=$(run_advisory "$no_sshd/bin" 2>&1)
        status=$?
        if [ "$status" -ne 0 ]; then
            bad 'install.sh still exits non-zero when there is no sshd'
        elif [ -n "$out" ]; then
            bad "no sshd, but the advisory printed: $out"
        else
            pass 'a host with no sshd still finishes the install, silently'
        fi
    fi

    cat > "$no_sshd/bin/sshd" <<'STUB'
#!/bin/sh
# An sshd that cannot answer: no host key yet, so it prints nothing and fails.
exit 1
STUB
    chmod +x "$no_sshd/bin/sshd"
    out=$(run_advisory "$no_sshd/bin:/usr/bin:/bin" 2>&1)
    status=$?
    if [ "$status" -ne 0 ]; then
        bad 'an sshd that cannot read its config still fails the install'
    else
        pass 'an sshd that fails for another reason does not fail the install'
    fi

    cat > "$no_sshd/bin/sshd" <<'STUB'
#!/bin/sh
printf 'permitrootlogin yes\npasswordauthentication yes\n'
STUB
    chmod +x "$no_sshd/bin/sshd"
    out=$(run_advisory "$no_sshd/bin:/usr/bin:/bin" 2>&1)
    status=$?
    if [ "$status" -ne 0 ]; then
        bad 'sshd answered, but the advisory failed the install'
    elif ! grep -q SSH <<<"$out"; then
        bad "sshd reports a password root login but the advisory said: $out"
    else
        pass 'the advisory still speaks when sshd takes passwords'
    fi

    cat > "$no_sshd/bin/sshd" <<'STUB'
#!/bin/sh
printf 'permitrootlogin prohibit-password\npasswordauthentication no\n'
STUB
    chmod +x "$no_sshd/bin/sshd"
    out=$(run_advisory "$no_sshd/bin:/usr/bin:/bin" 2>&1)
    if [ -n "$out" ]; then
        bad "a key-only host was told: $out"
    else
        pass 'a key-only host gets no advisory'
    fi
    rm -rf "$no_sshd"
fi

# The cases below need timeout(1). Linux has it (GNU coreutils or uutils); a
# macOS checkout only has it with coreutils installed, so say so instead of
# failing — but never instead of reporting a failure already found.
if ! command -v timeout >/dev/null 2>&1; then
    echo "SKIP: the streaming cases need timeout(1); on macOS: brew install coreutils"
    if [ "$fail" -eq 0 ]; then exit 77; else exit 1; fi
fi

# --- the helper under test, taken from install.sh itself ---------------------
# Extracted, not copied: a copy would keep passing after the original changed.
helper="$(awk '/^stream_bounded\(\) \{/{p=1} p{print} p&&/^\}$/{exit}' "$INSTALL_SH")"
if [ -z "$helper" ]; then
    echo "FAIL: stream_bounded() not found in $INSTALL_SH"
    exit 1
fi
eval "$helper"
if ! declare -f stream_bounded >/dev/null; then
    echo "FAIL: stream_bounded() did not parse"
    exit 1
fi

# The helper reports through install.sh's own output functions.
# shellcheck disable=SC2329 # Invoked dynamically by the evaluated stream_bounded helper
info() { echo "[INFO]  $*"; }
ok()   { echo "[ OK ]  $*"; }
warn() { echo "[WARN]  $*"; }
status_is() {
    if [ "$2" = "$3" ]; then pass "$1 (status $3)"; else bad "$1: expected $2, got $3"; fi
}
# Is pid still alive? Asked by pid rather than by command-line search: `pgrep -a`
# means something else on macOS, and matching on text would also match this
# script's own arguments.
gone() {
    local pid="$1" what="$2"
    if [ -z "$pid" ]; then bad "$what: the case never reported a pid"; return; fi
    if kill -0 "$pid" 2>/dev/null; then
        bad "$what (pid $pid) is still running"
        kill -9 "$pid" 2>/dev/null   # do not leak it into the next case
    else
        pass "$what (pid $pid) is gone"
    fi
}
pid_from() { sed -n "s/^$1: \([0-9][0-9]*\)$/\1/p" "$2" | head -1; }

echo '--- 1. install.sh still routes apt and Composer through the helper'
# The helper can be perfect and still guard nothing if the calls are reverted
# to `apt-get ... | tail -5`, which is the form that hid the stall.
grep -q 'stream_bounded "apt-get update"' "$INSTALL_SH" \
    && pass 'apt-get update goes through stream_bounded' \
    || bad 'apt-get update no longer goes through stream_bounded'
grep -q 'stream_bounded "apt-get install' "$INSTALL_SH" \
    && pass 'apt-get install goes through stream_bounded' \
    || bad 'apt-get install no longer goes through stream_bounded'
if grep -nE '^[[:space:]]*([A-Za-z_]+=[^[:space:]]+[[:space:]]+)*apt-get\b[^#]*\|[[:space:]]*tail' "$INSTALL_SH"; then
    bad 'apt-get output is piped into tail again'
else
    pass 'no apt-get output piped into tail'
fi
# Composer prints nothing while a dist file comes down, so it is only bounded
# correctly if it also names the directory its downloads land in: without it a
# slow packagist fetch is silence again and a working install gets killed.
grep -q 'stream_bounded "composer install"' "$INSTALL_SH" \
    && pass 'composer install goes through stream_bounded' \
    || bad 'composer install no longer goes through stream_bounded'
grep -qE 'COMPOSER_CACHE="\$\{COMPOSER_CACHE_DIR:-\$COMPOSER_HOME/cache\}"' "$INSTALL_SH" \
    && pass 'the progress directory is the Composer cache' \
    || bad 'the progress directory is not the Composer cache any more'
grep -q -- '--progress="\$COMPOSER_CACHE"' "$INSTALL_SH" \
    && pass 'composer install is told where its progress lands' \
    || bad 'composer install passes no progress directory'
if grep -nE '^[[:space:]]*([A-Za-z_]+=[^[:space:]]+[[:space:]]+)*composer\b[^#]*\|[[:space:]]*tail' "$INSTALL_SH"; then
    bad 'composer output is piped into tail again'
else
    pass 'no composer output piped into tail'
fi

echo
echo '--- 2. output arrives while the command is running'
log="$(mktemp)"
( stream_bounded stream 60 20 -- bash -c 'echo line-1; sleep 3; echo line-2; sleep 3; echo line-3' >"$log" 2>&1 ) &
pid=$!
sleep 4
if grep -q '^line-1$' "$log"; then
    pass 'first line is readable after 4 s, not held until the end'
else
    bad 'first line is not readable after 4 s: the output is being buffered'
fi
wait "$pid"; rc=$?
status_is 'streaming case' 0 "$rc"
grep -q '^line-3$' "$log" && pass 'last line present' || bad 'last line missing'
rm -f "$log"

log="$(mktemp)"
echo
echo '--- 3. a stuck package script is killed by the silence clock (no progress dir declared)'
start=$SECONDS
rc=0
stream_bounded silent 300 6 -- \
    bash -c 'echo "Setting up mariadb-server ..."; sleep 641 & echo "grandchild: $!"; wait' \
    >"$log" 2>&1 || rc=$?
elapsed=$((SECONDS - start))
status_is 'silence case' 125 "$rc"
if [ "$elapsed" -lt 30 ]; then pass "killed after ${elapsed}s"; else bad "took ${elapsed}s to notice 6 s of silence"; fi
gone "$(pid_from grandchild "$log")" 'the grandchild of a stalled postinst'
rm -f "$log"

echo
echo '--- 4. the wall clock kills a child that ignores TERM, and its grandchild'
log="$(mktemp)"
start=$SECONDS
rc=0
stream_bounded budget 5 300 -- \
    bash -c 'trap "" TERM; (trap "" TERM; sleep 313) & echo "grandchild: $!"; wait' \
    >"$log" 2>&1 || rc=$?
elapsed=$((SECONDS - start))
status_is 'budget case' 124 "$rc"
if [ "$elapsed" -lt 120 ]; then pass "killed after ${elapsed}s"; else bad "took ${elapsed}s, expected under 120"; fi
sleep 2   # the SIGKILL of the group lands with the timeout, but be kind to it
gone "$(pid_from grandchild "$log")" 'a grandchild that ignored TERM'
rm -f "$log"

echo
echo '--- 5. an ordinary failure keeps its own status'
rc=0
stream_bounded fail 60 20 -- bash -c 'echo first; sleep 1; echo boom; exit 3' || rc=$?
status_is 'failure case' 3 "$rc"

echo
echo '--- 6. a silent download that is still arriving is not a stall'
# The install test died on this: apt printed nothing for 301 s while a 24 MB
# source package came down a slow mirror, and the silence clock killed a phase
# that was working. Bytes landing in the progress directory are progress.
work="$(mktemp -d)"
log="$(mktemp)"
start=$SECONDS
rc=0
stream_bounded slow 120 6 --progress="$work" -- \
    bash -c "for i in 1 2 3 4 5 6; do dd if=/dev/zero bs=1024 count=64 >>'$work/part.deb' 2>/dev/null; sleep 2; done" \
    >"$log" 2>&1 || rc=$?
elapsed=$((SECONDS - start))
status_is 'progress case' 0 "$rc"
if [ "$elapsed" -ge 8 ]; then
    pass "waited ${elapsed}s through silence that was still moving"
else
    bad "finished in ${elapsed}s — the case did not outlive the 6 s silence bound"
fi
grep -q 'still downloading' "$log" \
    && pass 'the log says the silent download was still running' \
    || bad 'no note that a silent download was still arriving'
rm -rf "$work" "$log"

echo
echo '--- 7. with a progress directory declared, a real stall is still killed'
# Declaring --progress must not blind the clock: a postinst that prints nothing
# and writes nothing is exactly what the bound is for.
work="$(mktemp -d)"
log="$(mktemp)"
start=$SECONDS
rc=0
stream_bounded stalled 300 6 --progress="$work" -- \
    bash -c 'echo "Setting up mariadb-server ..."; sleep 61 & echo "grandchild: $!"; wait' \
    >"$log" 2>&1 || rc=$?
elapsed=$((SECONDS - start))
status_is 'stall with progress dir' 125 "$rc"
if [ "$elapsed" -lt 30 ]; then
    pass "killed after ${elapsed}s"
else
    bad "took ${elapsed}s to notice 6 s without a line or a byte"
fi
gone "$(pid_from grandchild "$log")" 'the grandchild of the stall'
rm -rf "$work" "$log"

echo
if [ "$fail" -eq 0 ]; then
    echo 'ALL PASS'
else
    echo 'FAILURES ABOVE'
fi
exit "$fail"
