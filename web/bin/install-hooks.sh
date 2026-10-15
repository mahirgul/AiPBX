#!/bin/bash
# AI PBX — git hook installation.
#
# Usage: bash bin/install-hooks.sh
#
# Git hooks are not versioned in the repo (.git/hooks is not part of git),
# so this has to run once on every clone/server.
set -e

REPO="$(cd "$(dirname "$0")/.." && pwd)"
HOOK="$REPO/.git/hooks/pre-commit"

if [ ! -d "$REPO/.git" ]; then
    echo "ERROR: $REPO is not a git repository." >&2
    exit 1
fi

cat > "$HOOK" <<'HOOKEOF'
#!/bin/bash
# AI PBX smoke test — installed by bin/install-hooks.sh.
# To skip: git commit --no-verify   (do not make it a habit!)
REPO="$(git rev-parse --show-toplevel)"

echo "Running the smoke test..."
if ! php "$REPO/bin/smoke.php"; then
    echo ""
    echo "COMMIT STOPPED: the smoke test failed (see the errors above)."
    echo "Gerçekten gerekiyorsa: git commit --no-verify"
    exit 1
fi

# Unit tests are CONDITIONAL: the hook must not block commits on a clone
# without the test database (bin/setup-test-db.sh may not have run yet).
if [ -x "$REPO/vendor/bin/phpunit" ] && [ -f "$REPO/tests/.env.test" ]; then
    echo "Running the unit tests..."
    if ! php "$REPO/vendor/bin/phpunit"; then
        echo ""
        echo "COMMIT STOPPED: the unit tests failed."
        echo "Gerçekten gerekiyorsa: git commit --no-verify"
        exit 1
    fi
fi
HOOKEOF

chmod +x "$HOOK"
echo "Installed: $HOOK"
