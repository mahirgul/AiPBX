#!/bin/bash
# Handler of `aipbx-priv net`: read by aipbx-priv itself (load net), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_net() {
        # The server's own address in the files outside the database (PBX ->
        # Network settings, roadmap 12): coturn, the mDNS alias and a
        # self-signed certificate. The database side is the portal's.
        [ $# -ge 1 ] || die "usage: net status|readdress"
        sub="$1"; shift
        ipv4() {
            [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || die "invalid address: $1"
            local o
            for o in "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}"; do
                (( 10#$o >= 0 && 10#$o <= 255 )) || die "invalid address: $1"
            done
        }
        case "$sub" in
            status)
                # net status — key=value lines, read only.
                [ $# -eq 0 ] || die "usage: net status"
                echo "addresses=$(hostname -I 2>/dev/null | xargs)"
                if [ -r /etc/turnserver.conf ]; then
                    echo "coturn_relay=$(awk -F= '/^relay-ip=/{print $2}' /etc/turnserver.conf | head -1)"
                    echo "coturn_allowed=$(awk -F= '/^allowed-peer-ip=/{printf "%s ", $2}' /etc/turnserver.conf | xargs)"
                fi
                if [ -r /etc/systemd/system/aipbx-mdns.service ]; then
                    echo "mdns=$(awk '/^ExecStart=/{print $NF}' /etc/systemd/system/aipbx-mdns.service)"
                fi
                if [ -r /etc/ssl/aipbx/mode ] && [ "$(cat /etc/ssl/aipbx/mode)" = selfsigned ] && [ -r /etc/ssl/aipbx/aipbx.crt ]; then
                    echo "cert_ips=$(openssl x509 -noout -ext subjectAltName -in /etc/ssl/aipbx/aipbx.crt 2>/dev/null | grep -oE 'IP Address:[0-9.]+' | cut -d: -f2 | xargs)"
                fi
                exit 0
                ;;
            readdress)
                # net readdress old=IP new=IP — runs readdress-run below in a
                # transient unit: Apache's sandbox (ProtectSystem=full) keeps /etc
                # read-only for the portal and for this script started by it, so
                # coturn, the mDNS unit and the certificate could not be written
                # from the portal (they could from the command line).
                for kv in "$@"; do
                    case "${kv%%=*}" in old|new) ipv4 "${kv#*=}" ;; *) die "unknown key: ${kv%%=*}" ;; esac
                done
                [ $# -eq 2 ] || die "usage: net readdress old=IP new=IP"
                if systemctl is-active --quiet aipbx-readdress.service; then
                    die "an address change is already running"
                fi
                systemctl reset-failed aipbx-readdress.service >/dev/null 2>&1 || true
                exec systemd-run --quiet --wait --pipe --collect --unit=aipbx-readdress \
                    --description="AiPBX: server address change" /usr/local/sbin/aipbx-priv net readdress-run "$@"
                ;;
            readdress-run)
                # net readdress-run old=IP new=IP — puts the new address where the old
                # one was: coturn relay-ip (and allowed-peer-ip = every address of
                # this server), the mDNS alias, a self-signed certificate naming the
                # old IP. coturn restarts once no call is up (a restart drops calls
                # that go through TURN); everything else at once.
                old=""; new=""
                for kv in "$@"; do
                    k="${kv%%=*}"; v="${kv#*=}"
                    case "$k" in
                        old) ipv4 "$v"; old="$v" ;;
                        new) ipv4 "$v"; new="$v" ;;
                        *) die "unknown key: $k" ;;
                    esac
                done
                [ -n "$old" ] && [ -n "$new" ] && [ "$old" != "$new" ] || die "usage: net readdress old=IP new=IP"
                if [ -f /etc/turnserver.conf ]; then
                    [ ! -L /etc/turnserver.conf ] || die "unsafe coturn configuration"
                    chmod 0600 /etc/turnserver.conf && chown root:turnserver /etc/turnserver.conf && chmod 0640 /etc/turnserver.conf || die "cannot secure coturn configuration"
                    # Secure a legacy backup before cp can overwrite its contents.
                    if [ -e /etc/turnserver.conf.before-readdress ] || [ -L /etc/turnserver.conf.before-readdress ]; then
                        [ -f /etc/turnserver.conf.before-readdress ] && [ ! -L /etc/turnserver.conf.before-readdress ] || die "unsafe coturn backup"
                        chmod 0600 /etc/turnserver.conf.before-readdress && chown root:turnserver /etc/turnserver.conf.before-readdress && chmod 0640 /etc/turnserver.conf.before-readdress || die "cannot secure coturn backup"
                    fi
                    cp -p /etc/turnserver.conf "/etc/turnserver.conf.before-readdress" || die "cannot back up coturn configuration"
                    sed -i "s/^relay-ip=${old//./\\.}\$/relay-ip=${new}/" /etc/turnserver.conf
                    sed -i '/^allowed-peer-ip=/d' /etc/turnserver.conf
                    for ip in $(hostname -I); do echo "allowed-peer-ip=$ip"; done >> /etc/turnserver.conf
                    systemctl stop aipbx-coturn-restart.service >/dev/null 2>&1 || true
                    systemd-run --quiet --collect --unit=aipbx-coturn-restart /bin/bash -c \
                        'for i in $(seq 1 2880); do n=$(asterisk -rx "core show channels count" 2>/dev/null | awk "/active call/{print \$1}"); { [ "$n" = 0 ] || [[ "$(systemctl show -p ActiveState --value asterisk)" =~ ^(inactive|failed)$ ]]; } && exec systemctl restart coturn; sleep 15; done' \
                        && echo "coturn: restarts when no call is up"
                fi
                if [ -f /etc/systemd/system/aipbx-mdns.service ] && grep -q " ${old}\$" /etc/systemd/system/aipbx-mdns.service; then
                    sed -i "s/ ${old//./\\.}\$/ ${new}/; s/-> ${old//./\\.})/-> ${new})/" /etc/systemd/system/aipbx-mdns.service
                    systemctl daemon-reload; systemctl restart aipbx-mdns.service >/dev/null 2>&1 || true
                    echo "mdns: alias now on $new"
                fi
                if [ -r /etc/ssl/aipbx/mode ] && [ "$(cat /etc/ssl/aipbx/mode)" = selfsigned ] \
                   && openssl x509 -noout -ext subjectAltName -in /etc/ssl/aipbx/aipbx.crt 2>/dev/null | grep -q "IP Address:${old}\$\|IP Address:${old},"; then
                    rm -f /etc/ssl/aipbx/aipbx.crt /etc/ssl/aipbx/aipbx.key
                    /usr/local/sbin/aipbx-cert selfsigned >/dev/null 2>&1 && echo "certificate: self-signed certificate issued for $new" \
                        || echo "certificate: FAILED to issue a new self-signed certificate (run: aipbx-cert selfsigned)"
                fi
                exit 0
                ;;
            *) die "unknown net command: $sub" ;;
        esac
}
