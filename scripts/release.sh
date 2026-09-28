#!/bin/bash
# Publishes an AiPBX server release (developer tool).
#
#   scripts/release.sh 2.1.0
#
# 1. CHANGELOG.md must contain a "## 2.1.0" section (the release notes).
# 2. VERSION is updated and committed.
# 3. An annotated git tag v2.1.0 is created (message = the CHANGELOG section;
#    installations show it via `aipbx-update --check` and the portal's "What's new").
# 4. Commit and tag are pushed to GitHub and a GitHub Release is created (if gh exists).
#
# Version numbers: MAJOR.MINOR.PATCH — backwards-incompatible change (e.g. a
# migration that drops a column, a removed API) = MAJOR; new feature = MINOR; fix = PATCH.
set -euo pipefail

VER="${1:-}"
[[ "$VER" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: $0 X.Y.Z" >&2; exit 64; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

[[ "$(git rev-parse --abbrev-ref HEAD)" == main ]] || { echo "Run on the main branch" >&2; exit 1; }
# The release commit contains only VERSION + CHANGELOG.md: any other staged
# change would be mixed into it. Uncommitted work in other files (e.g. docs/)
# does not block a release, but it is not part of it either.
git diff --cached --quiet || { echo "There are staged changes; commit or unstage them first" >&2; exit 1; }
if ! git diff --quiet -- . ':!VERSION' ':!CHANGELOG.md'; then
    echo "Warning: uncommitted changes are NOT part of this release:" >&2
    git diff --name-only -- . ':!VERSION' ':!CHANGELOG.md' | sed 's/^/  /' >&2
fi
git fetch -q origin main
[[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] || { echo "Local main differs from origin/main (push/pull first)" >&2; exit 1; }
git rev-parse -q --verify "refs/tags/v$VER" >/dev/null && { echo "v$VER already exists" >&2; exit 1; }

PREV="$(tr -d '[:space:]' < VERSION)"
# VERSION is either the release being published (not tagged yet) or lower.
if [[ "$PREV" != "$VER" && "$(printf '%s\n%s\n' "$PREV" "$VER" | sort -V | tail -1)" != "$VER" ]]; then
    echo "New version ($VER) must be greater than the current one ($PREV)" >&2; exit 1
fi

NOTES="$(awk -v v="## $VER" '$0 == v {f=1; next} /^## / && f {exit} f' CHANGELOG.md | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
[[ -n "${NOTES// }" ]] || { echo "CHANGELOG.md has no (or an empty) '## $VER' section" >&2; exit 1; }

echo "$VER" > VERSION
git add VERSION CHANGELOG.md
git diff --cached --quiet || git commit -q -m "release: v$VER"
git tag -a "v$VER" -m "$NOTES"
git push -q origin main
git push -q origin "v$VER"
if command -v gh >/dev/null 2>&1; then
    gh release create "v$VER" --title "AiPBX v$VER" --notes "$NOTES" >/dev/null && echo "GitHub Release created."
fi
echo "v$VER published. Installations: sudo aipbx-update (or portal → System Update)"
