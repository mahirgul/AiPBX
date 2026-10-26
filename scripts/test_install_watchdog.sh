#!/usr/bin/env bash
# ============================================================================
# Tests for `stream_bounded` in install.sh — the helper that runs apt-get with
# its output streaming and kills it on a wall clock or on silence.
#
# Why this exists: on 2026-10-26 the install test sat inside install.sh for 45
# minutes and left an empty log, three times. The helper is what turns that
# into a named failure in minutes (see docs/health.md). It is easy to break it
# again without noticing — a pipe that buffers the output, a clock that never
# fires, a kill that leaves dpkg behind, or the two apt-get calls quietly going
# back to `| tail -5` — because none of that shows up in a healthy install. The
# five cases below fail loudly instead.
#
# Usage:
#   bash scripts/test_install_watchdog.sh            # install.sh in the repo root
#   bash scripts/test_install_watchdog.sh /path/to/install.sh
#
# Runtime is about 80 seconds, most of it case 3: the helper gives a child that
# ignores TERM 60 seconds before it SIGKILLs the process group.
# ============================================================================
set -uo pipefail   # deliberately no -e: the cases assert on failure statuses

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_SH="${1:-$ROOT/install.sh}"
[ -f "$INSTALL_SH" ] || { echo "FAIL: no such file: $INSTALL_SH"; exit 2; }

# The cases need timeout(1). Linux has it (GNU coreutils or uutils); a macOS
# checkout only has it with coreutils installed, so say so instead of failing.
if ! command -v timeout >/dev/null 2>&1; then
    echo "SKIP: this test needs timeout(1); on macOS: brew install coreutils"
    exit 77
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
info() { echo "[INFO]  $*"; }
ok()   { echo "[ OK ]  $*"; }
warn() { echo "[WARN]  $*"; }

fail=0
pass() { printf 'PASS  %s\n' "$*"; }
bad()  { printf 'FAIL  %s\n' "$*"; fail=1; }
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

echo '--- 1. install.sh still routes apt through the helper'
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
echo '--- 3. a stuck package script is killed by the silence clock'
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
if [ "$fail" -eq 0 ]; then
    echo 'ALL PASS'
else
    echo 'FAILURES ABOVE'
fi
exit "$fail"
