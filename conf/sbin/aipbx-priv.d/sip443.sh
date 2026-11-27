#!/bin/bash
# Handler of `aipbx-priv sip443`: read by aipbx-priv itself (load sip443), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_sip443() {
        # sip443 set < lines — "name HOST" or "addr ADDRESS[/PREFIX]" per line (none: SIP on 443
        # off). nginx on 443 sends a TLS connection naming HOST, or one without ALPN from ADDRESS,
        # to Asterisk's TLS transport instead of Apache or coturn (aipbx-stream.conf includes both
        # files). Phone regions in the portal give the lines; Apache's sandbox lets only this
        # folder under /etc/nginx be written.
        [ "${1:-}" = set ] && [ $# -eq 1 ] || die "usage: sip443 set < lines"
        names=/etc/nginx/aipbx-sip443/names.conf addrs=/etc/nginx/aipbx-sip443/addresses.conf
        tn="$(mktemp)" ta="$(mktemp)"
        trap 'rm -f "$tn" "$ta"' EXIT
        echo "# Host names of phone regions with SIP on 443 (Provisioning → Regions; written by aipbx-priv)" > "$tn"
        echo "# Addresses of phone regions with SIP on 443 and no own name (Provisioning → Regions; written by aipbx-priv)" > "$ta"
        n=0
        while read -r kind value rest; do
            [ -n "$kind" ] || continue
            [ -z "$rest" ] && [ $((n += 1)) -le 1000 ] || die "invalid line or too many lines"
            case "$kind" in
                name) [[ "$value" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || die "invalid name: $value"
                      echo "$value 1;" >> "$tn" ;;
                addr) match "$value" "$RE_IP"; echo "$value 1;" >> "$ta" ;;
                *) die "invalid line: $kind" ;;
            esac
        done
        backup="$(mktemp -d "$(dirname "$names")/.sip443.XXXXXX")" || die "cannot stage SIP 443 lists"
        trap 'rm -f "$tn" "$ta"; rm -rf "$backup"' EXIT
        for f in "$names" "$addrs"; do
            if [ -f "$f" ]; then cp -p "$f" "$backup/${f##*/}" || die "cannot back up $f"; fi
        done
        restore_sip443() {
            local f
            for f in "$names" "$addrs"; do
                if [ -f "$backup/${f##*/}" ]; then
                    mv -f "$backup/${f##*/}" "$f" || return 1
                else
                    rm -f "$f" || return 1
                fi
            done
        }
        if ! install -m 0644 "$tn" "$names" || ! install -m 0644 "$ta" "$addrs" || ! nginx -t -q 2>/dev/null; then
            restore_sip443 || die "could not restore SIP 443 lists"
            die "could not install valid SIP 443 lists; restored previous files"
        fi
        if ! systemctl reload nginx; then
            restore_sip443 || die "could not restore SIP 443 lists"
            systemctl reload nginx || die "nginx could not reload the restored SIP 443 lists"
            die "nginx did not reload the new SIP 443 lists; restored previous files"
        fi
}
