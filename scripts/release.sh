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
# Before any of this, the commit must have passed the Install test and Web & Chat CI
# workflows on GitHub (gh needed; AIPBX_RELEASE_SKIP_CI=1 skips the check).
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

# CI gate: the commit being released must have passed "Install test" and "Web & Chat CI".
# A workflow its path filters did not start for this commit counts by its last run on main.
# AIPBX_RELEASE_SKIP_CI=1 skips the gate (say why in the CHANGELOG).
ci_passed() {
    local wf="$1" head line
    head="$(git rev-parse HEAD)"
    line="$(gh run list --workflow "$wf" --commit "$head" --limit 1 --json status,conclusion \
        --jq '.[0] // empty | .status + " " + (.conclusion // "")' 2>/dev/null || true)"
    if [[ -z "$line" ]]; then
        # Only fall back to the last run on main when this commit truly changes no files matched by $wf.
        local changed_files="" matching=0
        changed_files="$(git diff-tree --no-commit-id --name-only -r "$head" 2>/dev/null || true)"
        if [[ "$wf" == "web-ci.yml" ]]; then
            grep -Eq '^(web/|composer\.|phpcs|phpstan|\.github/workflows/web-ci\.yml)' <<<"$changed_files" && matching=1 || true
        elif [[ "$wf" == "install-test.yml" ]]; then
            grep -Eq '^(install\.sh|scripts/install/|conf/|web/|ai/|chat/|plugins/|\.github/workflows/install-test\.yml)' <<<"$changed_files" && matching=1 || true
        else
            matching=1
        fi
        if [[ "$matching" -eq 1 ]]; then
            echo "$wf: no workflow run found for commit $head yet (wait for CI or check GitHub Actions)" >&2
            return 1
        fi
        line="$(gh run list --workflow "$wf" --branch main --status completed --limit 1 --json conclusion --jq '.[0].conclusion // ""')"
        if [[ "$line" == success ]]; then
            echo "$wf: skipped by path filter for this commit; the last run on main passed"
            return 0
        fi
        echo "$wf: not run for this commit, and the last run on main is '${line:-none}'" >&2
        return 1
    fi
    [[ "$line" == "completed success" ]] && { echo "$wf: passed for this commit"; return 0; }
    echo "$wf for this commit: $line (wait for it, or fix it)" >&2
    return 1
}
if [[ "${AIPBX_RELEASE_SKIP_CI:-}" == 1 ]]; then
    echo "Warning: CI gate skipped (AIPBX_RELEASE_SKIP_CI=1)" >&2
else
    command -v gh >/dev/null 2>&1 || { echo "The CI gate needs gh (GitHub CLI); or set AIPBX_RELEASE_SKIP_CI=1" >&2; exit 1; }
    ci_passed install-test.yml && ci_passed web-ci.yml || { echo "Not released: CI is not green." >&2; exit 1; }
fi

PREV="$(tr -d '[:space:]' < VERSION)"
# VERSION is either the release being published (not tagged yet) or lower.
if [[ "$PREV" != "$VER" && "$(printf '%s\n%s\n' "$PREV" "$VER" | sort -V | tail -1)" != "$VER" ]]; then
    echo "New version ($VER) must be greater than the current one ($PREV)" >&2; exit 1
fi

NOTES="$(awk -v v="## $VER" '$0 == v {f=1; next} /^## / && f {exit} f' CHANGELOG.md | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
# Missing translations do not stop a release (they show in English); broken ones do.
python3 scripts/i18n.py check >/dev/null || { echo "Language files have problems: python3 scripts/i18n.py check" >&2; exit 1; }
[[ -n "${NOTES// }" ]] || { echo "CHANGELOG.md has no (or an empty) '## $VER' section" >&2; exit 1; }

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
