#!/bin/bash
# Publishes an AiPBX server release (developer tool).
#
#   scripts/release.sh 1.0.1
#
# 1. CHANGELOG.md must contain a "## 1.0.1" section (the release notes).
# 2. VERSION is updated and committed.
# 3. An annotated git tag v1.0.1 is created (message = the CHANGELOG section;
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

# The Android app has the same version and goes to Google Play — but only when
# android/ changed since the last release (the CI skips the upload otherwise,
# see .github/workflows/android-build.yml). Then its "What's new" must be
# written for this version (else Play shows a generic text).
LAST_TAG="$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -1)"
if [[ -n "$LAST_TAG" ]] && git diff --quiet "$LAST_TAG" HEAD -- android ':!android/play-release-notes.json'; then
    echo "Android: no change since $LAST_TAG -> no app build, no Google Play upload (the APK is carried over)."
else
    PLAY_VER="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("version",""))' android/play-release-notes.json)"
    [[ "$PLAY_VER" == "$VER" ]] || { echo "android/play-release-notes.json is for '$PLAY_VER': write the Play notes for $VER (en-US + tr-TR) and commit them first" >&2; exit 1; }
fi

# Say in the notes whether updating to this release restarts Asterisk (the
# updater decides the same way, scripts/asterisk-restart-needed.sh).
PREV_TAG="$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -1)"
if [[ -n "$PREV_TAG" ]]; then
    RESTART="$(scripts/asterisk-restart-needed.sh "$ROOT" "$PREV_TAG" HEAD)"
    if [[ "$RESTART" == no ]]; then
        NOTES+=$'\n\n'"Update from $PREV_TAG: Asterisk is reloaded, not restarted; calls in progress stay up."
    else
        NOTES+=$'\n\n'"Update from $PREV_TAG: Asterisk is restarted (${RESTART#yes: }); calls in progress drop."
    fi
    echo "Asterisk on update from $PREV_TAG: $RESTART"
fi

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
