#!/bin/bash
# End-to-end test of the current commit in an LXD container running Ubuntu 26.04.
#
#   scripts/e2e/run.sh fresh     restore snapshot "pristine", install from scratch
#   scripts/e2e/run.sh upgrade   restore snapshot "installed-200" (an older install), install.sh --upgrade
#   scripts/e2e/run.sh           both
#
# Needs: lxc, a container (AIPBX_E2E_CONTAINER, default aipbx-test) with the two
# snapshots, and internet access from the container (packages). The container is
# reset to a snapshot on every run — never point this at a machine you care about.
# Logs: /tmp/aipbx-e2e-<mode>.log

set -uo pipefail

C="${AIPBX_E2E_CONTAINER:-aipbx-test}"
MODE="${1:-both}"
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
SHA="$(git -C "$REPO" rev-parse HEAD)"
BUNDLE="$(mktemp --suffix=.bundle)"
trap 'rm -f "$BUNDLE"' EXIT

if [[ -n "$(git -C "$REPO" status --porcelain --untracked-files=no)" ]]; then
    echo "note: uncommitted changes are NOT tested — only commit ${SHA:0:7}" >&2
fi
git -C "$REPO" bundle create "$BUNDLE" HEAD >/dev/null 2>&1 || { echo "git bundle failed" >&2; exit 1; }

in_c() { lxc exec "$C" -- bash -c "$1"; }

prepare() {
    local snap="$1"
    echo "== [$snap] restoring snapshot"
    lxc restore "$C" "$snap" || return 1
    lxc start "$C" 2>/dev/null || true
    for _ in $(seq 1 60); do in_c 'getent hosts archive.ubuntu.com >/dev/null' 2>/dev/null && break; sleep 2; done
    lxc file push "$BUNDLE" "$C/root/aipbx.bundle" || return 1
    lxc file push "$REPO/scripts/e2e/checks.sh" "$C/root/e2e-checks.sh" || return 1
}

run_mode() {
    local mode="$1" snap cmd log="/tmp/aipbx-e2e-$1.log"
    case "$mode" in
        fresh)
            snap=pristine
            cmd="rm -rf /opt/aipbx && git clone -q /root/aipbx.bundle /opt/aipbx && cd /opt/aipbx && git checkout -q $SHA \
                 && AIPBX_FQDN=aipbx-e2e.local bash install.sh </dev/null" ;;
        upgrade)
            snap=installed-200
            cmd="cd /opt/aipbx && git fetch -q /root/aipbx.bundle HEAD && git checkout -q -f FETCH_HEAD \
                 && bash install.sh --upgrade </dev/null" ;;
        *) echo "unknown mode: $mode" >&2; return 2 ;;
    esac
    prepare "$snap" || { echo "== [$mode] could not prepare the container" >&2; return 1; }
    echo "== [$mode] installing ${SHA:0:7} (log: $log)"
    if ! in_c "$cmd" >"$log" 2>&1; then
        echo "== [$mode] INSTALL FAILED — last lines:"; tail -n 20 "$log" | sed 's/\x1b\[[0-9;]*m//g'
        return 1
    fi
    echo "== [$mode] checks"
    in_c 'bash /root/e2e-checks.sh' 2>&1 | tee -a "$log"
    return "${PIPESTATUS[0]}"
}

rc=0
case "$MODE" in
    both) run_mode fresh || rc=1; run_mode upgrade || rc=1 ;;
    fresh|upgrade) run_mode "$MODE" || rc=1 ;;
    *) echo "usage: $0 [fresh|upgrade|both]" >&2; exit 2 ;;
esac
echo "== result: $([ $rc -eq 0 ] && echo PASSED || echo FAILED)"
exit $rc
