#!/bin/bash
# Does updating from one AiPBX release to another need an Asterisk restart?
#
#   scripts/asterisk-restart-needed.sh <repo> <from-commit> <to-commit>
#
# Prints "no", or "yes: <reason>". Used by aipbx-update (to keep calls up when
# nothing Asterisk loads at start has changed) and by release.sh (to say so in
# the release notes).
#
# A restart is needed when
#   - an Asterisk configuration file the installer copies changed
#     (conf/asterisk/ or legacy asterisk-config/, except pbx/: those are generated from the database and
#     loaded with a reload),
#   - ASTERISK_RESTART_EPOCH in install.sh changed: raise it whenever an
#     installer change needs Asterisk restarted (a new Asterisk package or
#     module, the service unit, anything loaded only at start),
#   - the target is a bridge release (UPGRADE_FROM),
#   - anything cannot be determined (older releases, missing commits).
# Everything else (portal code, migrations, dialplan generators, plugins,
# languages, docs) takes effect with a reload; calls are not dropped.

repo="${1:?repo}"; from="${2:?from}"; to="${3:?to}"
g() { git -c safe.directory="$repo" -C "$repo" "$@"; }

if ! status_lines="$(g diff --name-status -M "$from" "$to" 2>/dev/null)"; then
    echo "yes: cannot compare $from and $to"; exit 0
fi
epoch() { g show "$1:install.sh" 2>/dev/null | sed -n 's/^ASTERISK_RESTART_EPOCH=\([0-9][0-9]*\).*/\1/p' | head -1; }
e_from="$(epoch "$from")"; e_to="$(epoch "$to")"
if [[ -z "$e_from" || -z "$e_to" ]]; then
    echo "yes: release without ASTERISK_RESTART_EPOCH"; exit 0
fi
if [[ "$e_from" != "$e_to" ]]; then
    echo "yes: the installer asks for it (ASTERISK_RESTART_EPOCH $e_from -> $e_to)"; exit 0
fi
# Extract modified/added/deleted Asterisk config paths, ignoring pure R100 directory moves
# between asterisk-config/<subpath> and conf/asterisk/<subpath>.
changed_paths="$(awk -F'\t' '
    $1 == "R100" {
        a = $2; b = $3;
        sub(/^(conf\/asterisk|asterisk-config)\//, "", a);
        sub(/^(conf\/asterisk|asterisk-config)\//, "", b);
        if (a == b) next;
    }
    { print ($3 != "" ? $3 : $2) }
' <<<"$status_lines")"
conf="$(grep -E '^(conf/asterisk/|asterisk-config/)' <<<"$changed_paths" | grep -v -E '^(conf/asterisk/|asterisk-config/)pbx/' | head -3 | tr '\n' ' ')"
if [[ -n "$conf" ]]; then
    echo "yes: Asterisk configuration changed (${conf% })"; exit 0
fi
if grep -qx 'UPGRADE_FROM' <<<"$changed_paths"; then
    echo "yes: bridge release"; exit 0
fi
echo "no"
