#!/bin/bash
# Handler of `aipbx-priv f2b`: read by aipbx-priv itself (load f2b), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_f2b() {
        sub="${1:-}"; [ $# -gt 0 ] && shift
        case "$sub" in
            status)
                # f2b status [JAIL]
                [ $# -le 1 ] || die "usage: f2b status [JAIL]"
                [ $# -eq 1 ] && match "$1" "$RE_JAIL"
                exec fail2ban-client status "$@"
                ;;
            get)
                # f2b get JAIL bantime|findtime|maxretry|ignoreip
                [ $# -eq 2 ] || die "usage: f2b get JAIL KEY"
                match "$1" "$RE_JAIL"
                case "$2" in bantime|findtime|maxretry|ignoreip) ;; *) die "key not allowed: $2" ;; esac
                exec fail2ban-client get "$1" "$2"
                ;;
            set)
                # f2b set JAIL bantime|findtime|maxretry N
                # f2b set JAIL unbanip|addignoreip|delignoreip IP
                [ $# -eq 3 ] || die "usage: f2b set JAIL KEY VALUE"
                match "$1" "$RE_JAIL"
                case "$2" in
                    bantime|findtime|maxretry)       match "$3" "$RE_INT" ;;
                    unbanip|addignoreip|delignoreip) match "$3" "$RE_IP" ;;
                    *) die "key not allowed: $2" ;;
                esac
                exec fail2ban-client set "$1" "$2" "$3"
                ;;
            install-override)
                # Validates persistent settings received on stdin from the panel and
                # installs it into jail.d. jail.d must stay root-owned: a jail file
                # can define commands to run, so a web-writable one meant root.
                [ $# -eq 0 ] || die "usage: f2b install-override"
                tmp="$(mktemp)" || exit 1
                trap 'rm -f "$tmp"' EXIT
                # Capture stdin once; validate and install this private snapshot.
                dd of="$tmp" iflag=fullblock bs=65536 count=1 status=none \
                    || die "staged file could not be read"
                [ "$(stat -c %s "$tmp")" -lt 65536 ] || die "staged file too large"
                [ -s "$tmp" ] || die "empty override input"
                n=0
                while IFS= read -r line || [ -n "$line" ]; do
                    n=$((n + 1))
                    [[ "$line" =~ ^$ ]] && continue
                    [[ "$line" =~ ^#[^[:cntrl:]]*$ ]] && continue
                    [[ "$line" =~ ^\[(DEFAULT|[A-Za-z0-9_-]{1,64})\]$ ]] && continue
                    [[ "$line" =~ ^ignoreip\ =\ [0-9A-Fa-f:./\ ]{1,4000}$ ]] && continue
                    [[ "$line" =~ ^(bantime|findtime|maxretry)\ =\ [0-9]{1,9}$ ]] && continue
                    # Only the line number is reported, never its content.
                    die "line not allowed in staged file: $n"
                done < "$tmp"
                # zz-: fail2ban reads jail.d in name order and this must win.
                install -o root -g root -m 0644 "$tmp" /etc/fail2ban/jail.d/zz-ai-pbx.local
                rm -f /etc/fail2ban/jail.d/99-ai-pbx.local
                ;;
            *) die "unknown f2b command: $sub" ;;
        esac
}
