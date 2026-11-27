#!/bin/bash
# Handler of `aipbx-priv postfix`: read by aipbx-priv itself (load postfix), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_postfix() {
        sub="${1:-}"; [ $# -gt 0 ] && shift
        case "$sub" in
            relay)
                [ $# -eq 2 ] || die "usage: postfix relay HOST PORT"
                match "$1" "$RE_HOST"; match "$2" "$RE_INT"
                exec postconf -e "relayhost = [$1]:$2"
                ;;
            relay-clear)
                [ $# -eq 0 ] || die "usage: postfix relay-clear"
                exec postconf -e "relayhost ="
                ;;
            tls)
                # may/none: plain SMTP with optional STARTTLS (ports 25/587);
                # wrapper: TLS from the first byte (port 465, "SSL") — without
                # wrappermode postfix speaks plain SMTP there and times out.
                [ $# -eq 1 ] || die "usage: postfix tls may|none|wrapper"
                case "$1" in
                    may|none) exec postconf -e "smtp_tls_security_level = $1" "smtp_tls_wrappermode = no" ;;
                    wrapper)  exec postconf -e "smtp_tls_security_level = encrypt" "smtp_tls_wrappermode = yes" ;;
                    *) die "invalid value: $1" ;;
                esac
                ;;
            sasl)
                [ $# -eq 1 ] || die "usage: postfix sasl on|off"
                case "$1" in
                    on)  exec postconf -e "smtp_sasl_auth_enable = yes" \
                                          "smtp_sasl_security_options = noanonymous" \
                                          "smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd" ;;
                    off) exec postconf -e "smtp_sasl_auth_enable = no" ;;
                    *)   die "invalid value: $1" ;;
                esac
                ;;
            postmap)
                [ $# -eq 0 ] || die "usage: postfix postmap"
                # Validate exactly the bytes received, in a root-only directory.
                tmpdir="$(mktemp -d /run/aipbx-postmap.XXXXXXXX)" || exit 1
                trap 'rm -rf "$tmpdir"' EXIT
                src="$tmpdir/input"
                dd of="$src" bs=4097 count=1 iflag=fullblock status=none || die "credential input failed"
                [ "$(stat -c %s "$src")" -gt 0 ] && [ "$(stat -c %s "$src")" -le 4096 ] || die "invalid credential size"
                ! tr -d '\n' < "$src" | LC_ALL=C grep -q '[[:cntrl:]]' || die "control characters in credentials"
                lines="$(grep -cve '^[[:space:]]*$' "$src" || true)"
                [ "$lines" -eq 1 ] || die "expected a single relay entry"
                line="$(grep -m1 -ve '^[[:space:]]*$' "$src")"
                [[ "$line" =~ ^\[[A-Za-z0-9.:_-]{1,253}\]:[0-9]{1,5}\ [^[:space:][:cntrl:]]+:[^[:cntrl:]]+$ ]] || die "invalid relay entry"
                install -o root -g root -m 0600 "$src" /etc/postfix/sasl_passwd || exit 1
                postmap /etc/postfix/sasl_passwd || exit $?
                chown root:root /etc/postfix/sasl_passwd /etc/postfix/sasl_passwd.db 2>/dev/null || true
                chmod 0600 /etc/postfix/sasl_passwd /etc/postfix/sasl_passwd.db 2>/dev/null || true
                exit 0
                ;;
            trace)
                # postfix trace TOKEN — delivery result of the message whose
                # Message-ID starts with TOKEN (the portal's test e-mail):
                # prints its "status=" log lines, nothing while it is pending.
                [ $# -eq 1 ] || die "usage: postfix trace TOKEN"
                match "$1" '^aipbx-test-[0-9a-f]{16}$'
                qid="$(journalctl -q --since=-15min -o cat -t postfix/cleanup 2>/dev/null \
                       | grep -F "message-id=<$1@" | tail -1 | cut -d: -f1)"
                [ -n "$qid" ] || exit 0
                match "$qid" '^[0-9A-Za-z]{6,20}$'
                journalctl -q --since=-15min -o cat -t postfix/smtp -t postfix/local \
                           -t postfix/error -t postfix/qmgr 2>/dev/null \
                    | grep -F "$qid: " | grep -E "status=|SASL|lost connection|timed out" | tail -5
                exit 0
                ;;
            *) die "unknown postfix command: $sub" ;;
        esac
}
