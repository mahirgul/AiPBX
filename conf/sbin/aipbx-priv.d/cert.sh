#!/bin/bash
# Handler of `aipbx-priv cert`: read by aipbx-priv itself (load cert), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_cert() {
        # Certificates page. aipbx-cert runs in its own systemd unit: Apache's
        # sandbox keeps /etc read-only, and certbot --apache must edit the
        # Apache configuration while it validates. --wait --pipe returns its
        # output and exit code; the fixed unit name stops parallel runs.
        sub="${1:-}"; [ $# -gt 0 ] && shift
        case "$sub" in
            letsencrypt)
                # cert letsencrypt [--dry-run] [--email ADDR] [--dns-cloudflare] [--wildcard]
                args=()
                while [ $# -gt 0 ]; do
                    case "$1" in
                        --dry-run) args+=(--dry-run) ;;
                        --dns-cloudflare) args+=(--dns-cloudflare) ;;
                        --wildcard) args+=(--wildcard) ;;
                        --email) [ $# -ge 2 ] || die "usage: cert letsencrypt [--dry-run] [--email ADDR] [--dns-cloudflare] [--wildcard]"
                            match "$2" "$RE_EMAIL"; args+=(--email "$2"); shift ;;
                        *) die "unexpected argument: $1" ;;
                    esac
                    shift
                done
                ;;
            renew)
                # cert renew [--dry-run]
                [ $# -le 1 ] || die "usage: cert renew [--dry-run]"
                case "${1:-}" in '') args=() ;; --dry-run) args=(--dry-run) ;; *) die "unexpected argument: $1" ;; esac
                ;;
            custom)
                # cert custom [--allow-mismatch] — files staged in /var/lib/aipbx/cert-stage
                [ $# -le 1 ] || die "usage: cert custom [--allow-mismatch]"
                case "${1:-}" in '') args=() ;; --allow-mismatch) args=(--allow-mismatch) ;; *) die "unexpected argument: $1" ;; esac
                ;;
            selfsigned)
                [ $# -eq 0 ] || die "usage: cert selfsigned"
                args=()
                ;;
            names)
                # cert names MAIN [EXTRA...] — aipbx-cert checks each name again
                [ $# -ge 1 ] && [ $# -le 21 ] || die "usage: cert names MAIN [EXTRA...]"
                args=()
                for n in "$@"; do match "$n" '^[A-Za-z0-9.-]{1,253}$'; args+=("$n"); done
                ;;
            *) die "unknown cert command: $sub" ;;
        esac
        if systemctl is-active --quiet aipbx-cert.service; then
            die "a certificate operation is already running"
        fi
        systemctl reset-failed aipbx-cert.service >/dev/null 2>&1 || true
        exec systemd-run --quiet --wait --pipe --collect --unit=aipbx-cert \
            --description="AiPBX certificate: $sub" /usr/local/sbin/aipbx-cert "$sub" ${args[@]+"${args[@]}"}
}
