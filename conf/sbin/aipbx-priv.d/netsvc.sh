#!/bin/bash
# Handler of `aipbx-priv netsvc`: read by aipbx-priv itself (load netsvc), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_netsvc() {
        # PBX → Network services (roadmap 10): dnsmasq as a DHCP server for a
        # phone network and/or a TFTP server, in its own unit (aipbx-dnsmasq).
        # The configuration is written HERE from validated KEY=VALUE arguments,
        # into a root-owned folder: a dnsmasq file can run commands as root
        # (dhcp-script), so the portal must never be able to write one.
        NETSVC_DIR=/var/lib/aipbx-netsvc
        NETSVC_CONF="$NETSVC_DIR/dnsmasq.conf"
        NETSVC_FW="$NETSVC_DIR/firewall.state"
        RE_IPV4='^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$'
        RE_IFACE='^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$'
        RE_ZONE='^[A-Za-z0-9_-]{1,32}$'
        RE_PROV_URL='^https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?/provision/$'

        fw_running() { firewall-cmd --state >/dev/null 2>&1; }
        # firewall-cmd, retried once after a reload: a change that failed half way leaves
        # firewalld's runtime state broken ("COMMAND_FAILED: 'handle'") until it is reloaded.
        fw_add() { firewall-cmd "$@" >/dev/null 2>&1 || { firewall-cmd --reload >/dev/null 2>&1; firewall-cmd "$@" >/dev/null; }; }
        # A card with its own firewall rules (Firewall page) has a zone aipbx-cardN. The openings
        # below are for local networks, so they go to those zones as well as the default one.
        card_zones() { firewall-cmd --get-zones 2>/dev/null | tr ' ' '\n' | grep -E '^aipbx-card[0-9]+$' || true; }
        fw_add_all() { fw_add "$@" || return 1; local z; for z in $(card_zones); do fw_add --zone="$z" "$@" || return 1; done; }
        fw_rm_all() { firewall-cmd "$@" >/dev/null 2>&1; local z; for z in $(card_zones); do firewall-cmd --zone="$z" "$@" >/dev/null 2>&1; done; return 0; }
        # UDP 69 only: the firewalld "tftp" service needs the nf_conntrack_tftp kernel module,
        # which is missing in containers and some kernels; a TFTP server does not need it
        # (its replies open the conntrack entries the client's packets match).
        tftp_rule() { echo "rule family=\"ipv4\" source address=\"$1\" port port=\"69\" protocol=\"udp\" accept"; }
        tftp_rule_old() { echo "rule family=\"ipv4\" source address=\"$1\" service name=\"tftp\" accept"; }

        # Removes the firewall openings of the last apply (listed in the state file).
        netsvc_fw_clear() {
            [ -f "$NETSVC_FW" ] || return 0
            if fw_running; then
                while read -r kind value; do
                    case "$kind" in
                        tftp) [[ "$value" =~ $RE_IPV4_CIDR ]] || continue
                            for p in "" --permanent; do
                                fw_rm_all $p --remove-rich-rule="$(tftp_rule "$value")"
                                # Rule of releases up to 1.15 (the tftp service).
                                fw_rm_all $p --remove-rich-rule="$(tftp_rule_old "$value")"
                            done ;;
                        dhcp) [[ "$value" =~ $RE_ZONE ]] || continue
                            for p in "" --permanent; do firewall-cmd $p --zone="$value" --remove-service=dhcp >/dev/null 2>&1; done ;;
                    esac
                done < "$NETSVC_FW"
            fi
            rm -f "$NETSVC_FW"
        }

        FTP_FW="$NETSVC_DIR/ftp-firewall.state"
        ftp_rule() { echo "rule family=\"ipv4\" source address=\"$1\" port port=\"$2\" protocol=\"tcp\" accept"; }
        ftp_fw_clear() {
            [ -f "$FTP_FW" ] || return 0
            if fw_running; then
                while read -r kind value; do
                    [ "$kind" = ftp ] && [[ "$value" =~ $RE_IPV4_CIDR ]] || continue
                    for r in "$(ftp_rule "$value" 21)" "$(ftp_rule "$value" 40000-40049)"; do
                        for p in "" --permanent; do fw_rm_all $p --remove-rich-rule="$r"; done
                    done
                done < "$FTP_FW"
            fi
            rm -f "$FTP_FW"
        }

        SYSLOG_FW="$NETSVC_DIR/syslog-firewall.state"
        syslog_rule() { echo "rule family=\"ipv4\" source address=\"$1\" port port=\"514\" protocol=\"udp\" accept"; }
        syslog_fw_clear() {
            [ -f "$SYSLOG_FW" ] || return 0
            if fw_running; then
                while read -r kind value; do
                    [ "$kind" = syslog ] && [[ "$value" =~ $RE_IPV4_CIDR ]] || continue
                    for p in "" --permanent; do fw_rm_all $p --remove-rich-rule="$(syslog_rule "$value")"; done
                done < "$SYSLOG_FW"
            fi
            rm -f "$SYSLOG_FW"
        }

        NTP_CONF=/etc/chrony/conf.d/aipbx-ntp-server.conf
        NTP_FW="$NETSVC_DIR/ntp-firewall.state"
        ntp_rule() { echo "rule family=\"ipv4\" source address=\"$1\" port port=\"123\" protocol=\"udp\" accept"; }
        ntp_fw_clear() {
            [ -f "$NTP_FW" ] || return 0
            if fw_running; then
                while read -r kind value; do
                    [ "$kind" = ntp ] && [[ "$value" =~ $RE_IPV4_CIDR ]] || continue
                    for p in "" --permanent; do fw_rm_all $p --remove-rich-rule="$(ntp_rule "$value")"; done
                done < "$NTP_FW"
            fi
            rm -f "$NTP_FW"
        }

        sub="${1:-}"; [ $# -gt 0 ] && shift
        case "$sub" in
            status)
                [ $# -eq 0 ] || die "usage: netsvc status"
                systemctl is-active aipbx-dnsmasq.service 2>/dev/null
                exit 0
                ;;
            probe)
                # netsvc probe IFACE — DHCP servers answering on IFACE (DHCPDISCOVER).
                [ $# -eq 1 ] || die "usage: netsvc probe IFACE"
                match "$1" "$RE_IFACE"
                [ -e "/sys/class/net/$1" ] || die "no such interface: $1"
                exec /usr/local/sbin/aipbx-dhcp-probe "$1"
                ;;
            off)
                [ $# -eq 0 ] || die "usage: netsvc off"
                systemctl stop aipbx-dnsmasq.service >/dev/null 2>&1
                rm -f "$NETSVC_CONF"
                netsvc_fw_clear
                exit 0
                ;;
            apply)
                # netsvc apply mode=tftp|dhcp [iface=] [start= end= mask= gw= lease=HOURS]
                #   [dns=IP,IP] [ntp=IP,IP] [url=http(s)://HOST/provision/] [tftp=0|1] [net=CIDR]...
                #   [opt=CODE,VALUE]...  (extra DHCP options; the same code replaces AiPBX's own)
                mode=""; iface=""; start=""; end=""; mask=""; gw=""; lease=""; dns=""; ntp=""; url=""; tftp=1
                nets=(); opts=(); declare -A optcode=()
                for kv in "$@"; do
                    k="${kv%%=*}"; v="${kv#*=}"
                    [ "$k" != "$kv" ] || die "expected KEY=VALUE: $kv"
                    case "$k" in
                        mode) case "$v" in tftp|dhcp) mode="$v" ;; *) die "invalid mode: $v" ;; esac ;;
                        iface) match "$v" "$RE_IFACE"; iface="$v" ;;
                        start) match "$v" "$RE_IPV4"; start="$v" ;;
                        end) match "$v" "$RE_IPV4"; end="$v" ;;
                        mask) match "$v" "$RE_IPV4"; mask="$v" ;;
                        gw) match "$v" "$RE_IPV4"; gw="$v" ;;
                        lease) match "$v" '^([1-9]|[1-9][0-9]|1[0-6][0-9])$'; lease="$v" ;;
                        dns|ntp)
                            IFS=',' read -r -a list <<< "$v"
                            [ "${#list[@]}" -ge 1 ] && [ "${#list[@]}" -le 3 ] || die "invalid $k list"
                            for ip in "${list[@]}"; do match "$ip" "$RE_IPV4"; done
                            [ "$k" = dns ] && dns="$v" || ntp="$v" ;;
                        url) match "$v" "$RE_PROV_URL"; url="$v" ;;
                        opt)
                            # CODE 1-254, a value without quotes, #, backslash or line breaks.
                            match "$v" '^([1-9][0-9]?|1[0-9][0-9]|2[0-4][0-9]|25[0-4]),[A-Za-z0-9 .:/_@,+=%~!$&()*;?-]{1,200}$'
                            [ "${#opts[@]}" -lt 20 ] || die "too many DHCP options"
                            opts+=("$v"); optcode["${v%%,*}"]=1 ;;
                        tftp) case "$v" in 0|1) tftp="$v" ;; *) die "invalid tftp: $v" ;; esac ;;
                        net) match "$v" "$RE_IPV4_CIDR"; [ "${#nets[@]}" -lt 32 ] || die "too many networks"; nets+=("$v") ;;
                        *) die "unknown key: $k" ;;
                    esac
                done
                [ -n "$mode" ] || die "mode= is required"
                if [ "$mode" = tftp ]; then
                    tftp=1
                else
                    [ -n "$iface" ] && [ -n "$start" ] && [ -n "$end" ] && [ -n "$mask" ] && [ -n "$lease" ] \
                        || die "dhcp needs iface, start, end, mask and lease"
                    [ -e "/sys/class/net/$iface" ] || die "no such interface: $iface"
                fi
                # TFTP answers only the configured local networks (firewall).
                [ "$tftp" = 0 ] || [ "${#nets[@]}" -gt 0 ] || die "tftp needs at least one net="

                user=nobody
                id -u dnsmasq >/dev/null 2>&1 && user=dnsmasq
                mkdir -p "$NETSVC_DIR" "$NETSVC_DIR/leases"
                [ -d /var/lib/aipbx/tftp ] || install -d -o www-data -g www-data -m 0755 /var/lib/aipbx/tftp
                chown root:root "$NETSVC_DIR"; chmod 0755 "$NETSVC_DIR"
                chown "$user:" "$NETSVC_DIR/leases"; chmod 0755 "$NETSVC_DIR/leases"
                # Another program on the port makes dnsmasq fail and restart for ever, while
                # phones fetch that program's files. Say so instead of switching on.
                own_pid="$(systemctl show -p MainPID --value aipbx-dnsmasq.service 2>/dev/null)"
                for port_check in "$( [ "$tftp" = 1 ] && echo 69 )" "$( [ "$mode" = dhcp ] && echo 67 )"; do
                    [ -n "$port_check" ] || continue
                    holder="$(ss -Hlunp "sport = :$port_check" 2>/dev/null | grep -v "pid=${own_pid:-0}," | head -1)"
                    [ -z "$holder" ] && continue
                    holder_name="$(printf '%s' "$holder" | sed -n 's/.*users:(("\([^"]*\)".*/\1/p')"
                    die "UDP port $port_check is in use by ${holder_name:-another program}. Stop that program first: phones get its files, not the ones on this page."
                done
                tmp="$(mktemp "$NETSVC_DIR/.dnsmasq.XXXXXX")" || exit 1
                trap 'rm -f "$tmp"' EXIT
                {
                    echo "# Written by aipbx-priv (PBX -> Network services). Do not edit: the next apply replaces it."
                    echo "port=0"
                    echo "user=$user"
                    if [ "$mode" = dhcp ]; then
                        echo "interface=$iface"
                        echo "bind-dynamic"
                        echo "dhcp-range=$start,$end,$mask,${lease}h"
                        echo "dhcp-leasefile=$NETSVC_DIR/leases/dnsmasq.leases"
                        [ -n "$gw" ] && [ -z "${optcode[3]:-}" ] && echo "dhcp-option=option:router,$gw"
                        [ -n "$dns" ] && [ -z "${optcode[6]:-}" ] && echo "dhcp-option=option:dns-server,$dns"
                        [ -n "$ntp" ] && [ -z "${optcode[42]:-}" ] && echo "dhcp-option=option:ntp-server,$ntp"
                        if [ -n "$url" ]; then
                            # 66: Yealink, Grandstream, Fanvil, Snom, Cisco; 160: Poly.
                            [ -z "${optcode[66]:-}" ] && echo "dhcp-option=66,\"$url\""
                            [ -z "${optcode[160]:-}" ] && echo "dhcp-option=160,\"$url\""
                        fi
                        # 150: Cisco TFTP server; 0.0.0.0 = this server.
                        [ "$tftp" = 1 ] && [ -z "${optcode[150]:-}" ] && echo "dhcp-option=150,0.0.0.0"
                        # Extra options are sent even when the phone does not ask for them.
                        for o in "${opts[@]}"; do echo "dhcp-option-force=$o"; done
                    fi
                    if [ "$tftp" = 1 ]; then
                        echo "enable-tftp"
                        echo "tftp-root=/var/lib/aipbx/tftp"
                    fi
                } > "$tmp"
                dnsmasq --test --conf-file="$tmp" >/dev/null 2>&1 || die "dnsmasq rejected the configuration"
                install -o root -g root -m 0644 "$tmp" "$NETSVC_CONF"

                netsvc_fw_clear
                if fw_running; then
                    : > "$NETSVC_FW"
                    if [ "$tftp" = 1 ]; then
                        for n in "${nets[@]}"; do
                            for p in "" --permanent; do fw_add_all $p --add-rich-rule="$(tftp_rule "$n")" || die "firewall: tftp $n"; done
                            echo "tftp $n" >> "$NETSVC_FW"
                        done
                    fi
                    if [ "$mode" = dhcp ]; then
                        # UDP 67 in the zone of the chosen interface only.
                        zone="$(firewall-cmd --get-zone-of-interface="$iface" 2>/dev/null || firewall-cmd --get-default-zone)"
                        match "$zone" "$RE_ZONE"
                        for p in "" --permanent; do fw_add $p --zone="$zone" --add-service=dhcp || die "firewall: dhcp $zone"; done
                        echo "dhcp $zone" >> "$NETSVC_FW"
                    fi
                fi
                rm -f "$tmp"
                exec systemctl restart aipbx-dnsmasq.service
                ;;
            ftp-on)
                # netsvc ftp-on [user=NAME:CRYPT]... [anonymous=0|1] net=CIDR...
                # Read-only FTP (vsftpd, unit aipbx-vsftpd) of the TFTP folder for
                # phones that fetch their files over FTP (e.g. NEC DT700: user
                # NECSDT700). Every user, NECSDT700 included, logs in with a
                # password from ftp.passwd (pam_pwdfile, guest = ftp; no system
                # accounts) and may only read. Anonymous login (no password) only
                # with anonymous=1 (the page's switch, off by default): the folder
                # holds the phones' files with their SIP passwords.
                ftpusers=(); nets=(); anon=0
                for kv in "$@"; do
                    k="${kv%%=*}"; v="${kv#*=}"
                    [ "$k" != "$kv" ] || die "expected KEY=VALUE: $kv"
                    case "$k" in
                        user) match "$v" '^[A-Za-z0-9._-]{1,32}:\$6\$[A-Za-z0-9./]{1,16}\$[A-Za-z0-9./]{86}$'
                            [ "${#ftpusers[@]}" -lt 21 ] || die "too many FTP users"; ftpusers+=("$v") ;;
                        anonymous) case "$v" in 0|1) anon="$v" ;; *) die "invalid anonymous: $v" ;; esac ;;
                        net) match "$v" "$RE_IPV4_CIDR"; [ "${#nets[@]}" -lt 32 ] || die "too many networks"; nets+=("$v") ;;
                        *) die "unknown key: $k" ;;
                    esac
                done
                [ "${#nets[@]}" -gt 0 ] || die "ftp needs at least one net="
                [ -x /usr/sbin/vsftpd ] || die "vsftpd is not installed (run install.sh --upgrade)"
                id -u ftp >/dev/null 2>&1 || die "the ftp system user is missing"

                mkdir -p "$NETSVC_DIR"; chown root:root "$NETSVC_DIR"; chmod 0755 "$NETSVC_DIR"
                [ -d /var/lib/aipbx/tftp ] || install -d -o www-data -g www-data -m 0755 /var/lib/aipbx/tftp
                install -d -o root -g root -m 0755 /var/run/vsftpd/empty
                umask 077
                # FTP users with their password hashes (NECSDT700 and the extra ones).
                : > "$NETSVC_DIR/ftp.passwd.tmp"
                [ "${#ftpusers[@]}" -eq 0 ] || printf '%s\n' "${ftpusers[@]}" > "$NETSVC_DIR/ftp.passwd.tmp"
                install -o root -g root -m 0600 "$NETSVC_DIR/ftp.passwd.tmp" "$NETSVC_DIR/ftp.passwd"
                rm -f "$NETSVC_DIR/ftp.passwd.tmp"
                umask 022
                cat > "$NETSVC_DIR/vsftpd.conf.tmp" << 'FTPCONF'
# Written by aipbx-priv (PBX -> Network services). Do not edit: the next apply replaces it.
listen=YES
listen_ipv6=NO
background=NO
ftpd_banner=AiPBX
# Read only. Anonymous login only when switched on (the folder holds the
# phones' SIP passwords).
write_enable=NO
anonymous_enable=NO
anon_upload_enable=NO
anon_mkdir_write_enable=NO
anon_other_write_enable=NO
no_anon_password=YES
anon_root=/var/lib/aipbx/tftp
anon_world_readable_only=YES
# The users: checked against ftp.passwd (PAM aipbx-ftp), then mapped to "ftp".
local_enable=YES
guest_enable=YES
guest_username=ftp
local_root=/var/lib/aipbx/tftp
chroot_local_user=YES
pam_service_name=aipbx-ftp
hide_ids=YES
secure_chroot_dir=/var/run/vsftpd/empty
pasv_enable=YES
pasv_min_port=40000
pasv_max_port=40049
xferlog_enable=YES
vsftpd_log_file=/var/log/aipbx-ftp.log
FTPCONF
                [ "$anon" = 1 ] && sed -i 's/^anonymous_enable=NO$/anonymous_enable=YES/' "$NETSVC_DIR/vsftpd.conf.tmp"
                install -o root -g root -m 0644 "$NETSVC_DIR/vsftpd.conf.tmp" "$NETSVC_DIR/vsftpd.conf"
                rm -f "$NETSVC_DIR/vsftpd.conf.tmp"

                # Port 21 and the passive ports, from the allowed networks only.
                ftp_fw_clear
                if fw_running; then
                    : > "$FTP_FW"
                    for n in "${nets[@]}"; do
                        for r in "$(ftp_rule "$n" 21)" "$(ftp_rule "$n" 40000-40049)"; do
                            for p in "" --permanent; do fw_add_all $p --add-rich-rule="$r" || die "firewall: ftp $n"; done
                        done
                        echo "ftp $n" >> "$FTP_FW"
                    done
                fi
                systemctl enable aipbx-vsftpd.service >/dev/null 2>&1
                exec systemctl restart aipbx-vsftpd.service
                ;;
            ftp-off)
                [ $# -eq 0 ] || die "usage: netsvc ftp-off"
                systemctl stop aipbx-vsftpd.service >/dev/null 2>&1
                rm -f "$NETSVC_DIR/vsftpd.conf" "$NETSVC_DIR/ftp.passwd"
                ftp_fw_clear
                exit 0
                ;;
            log)
                # netsvc log ftp|tftp|dhcp — the newest lines of what the phones
                # fetched (PBX -> Phone logs). Read only: FTP's transfer log
                # (root 0600), TFTP and DHCP from dnsmasq's journal.
                [ $# -eq 1 ] || die "usage: netsvc log ftp|tftp|dhcp"
                case "$1" in
                    ftp) [ -f /var/log/aipbx-ftp.log ] || exit 0
                         exec tail -n 2000 /var/log/aipbx-ftp.log ;;
                    tftp) exec journalctl -u aipbx-dnsmasq.service -t dnsmasq-tftp -n 2000 -o short-iso --no-pager -q ;;
                    dhcp) exec journalctl -u aipbx-dnsmasq.service -t dnsmasq-dhcp -n 2000 -o short-iso --no-pager -q ;;
                    *) die "invalid log: $1" ;;
                esac
                ;;
            ftp-status)
                [ $# -eq 0 ] || die "usage: netsvc ftp-status"
                systemctl is-active aipbx-vsftpd.service 2>/dev/null
                exit 0
                ;;
            syslog-on)
                # netsvc syslog-on net=CIDR... — receive the phones' logs (syslog,
                # UDP 514) from the allowed networks into /var/log/aipbx-phones/
                # (PBX -> Phone logs): aipbx-syslogd (unit aipbx-syslog), which also
                # drops senders outside these networks itself.
                nets=()
                for kv in "$@"; do
                    k="${kv%%=*}"; v="${kv#*=}"
                    [ "$k" != "$kv" ] || die "expected KEY=VALUE: $kv"
                    case "$k" in
                        net) match "$v" "$RE_IPV4_CIDR"; [ "${#nets[@]}" -lt 32 ] || die "too many networks"; nets+=("$v") ;;
                        *) die "unknown key: $k" ;;
                    esac
                done
                [ "${#nets[@]}" -gt 0 ] || die "syslog needs at least one net="
                [ -x /usr/local/sbin/aipbx-syslogd ] || die "aipbx-syslogd is missing (run install.sh --upgrade)"
                mkdir -p "$NETSVC_DIR"; chown root:root "$NETSVC_DIR"; chmod 0755 "$NETSVC_DIR"
                {
                    echo "# Written by aipbx-priv (PBX -> Network services). Do not edit: the next apply replaces it."
                    printf 'net=%s\n' "${nets[@]}"
                } > "$NETSVC_DIR/syslog.conf.tmp"
                install -o root -g root -m 0644 "$NETSVC_DIR/syslog.conf.tmp" "$NETSVC_DIR/syslog.conf"
                rm -f "$NETSVC_DIR/syslog.conf.tmp"

                syslog_fw_clear
                if fw_running; then
                    : > "$SYSLOG_FW"
                    for n in "${nets[@]}"; do
                        for p in "" --permanent; do fw_add_all $p --add-rich-rule="$(syslog_rule "$n")" || die "firewall: syslog $n"; done
                        echo "syslog $n" >> "$SYSLOG_FW"
                    done
                fi
                systemctl enable aipbx-syslog.service >/dev/null 2>&1
                exec systemctl restart aipbx-syslog.service
                ;;
            syslog-off)
                [ $# -eq 0 ] || die "usage: netsvc syslog-off"
                systemctl disable --now aipbx-syslog.service >/dev/null 2>&1
                rm -f "$NETSVC_DIR/syslog.conf"
                syslog_fw_clear
                exit 0
                ;;
            syslog-status)
                [ $# -eq 0 ] || die "usage: netsvc syslog-status"
                systemctl is-active aipbx-syslog.service 2>/dev/null
                exit 0
                ;;
            ntp-on)
                # netsvc ntp-on net=CIDR... — this server gives the time (NTP, UDP 123)
                # to the allowed networks: chrony (the system's own time sync) gets
                # "allow" lines for them, and keeps serving from its own clock when
                # the internet sources are unreachable (local stratum 10).
                nets=()
                for kv in "$@"; do
                    k="${kv%%=*}"; v="${kv#*=}"
                    [ "$k" != "$kv" ] || die "expected KEY=VALUE: $kv"
                    case "$k" in
                        net) match "$v" "$RE_IPV4_CIDR"; [ "${#nets[@]}" -lt 32 ] || die "too many networks"; nets+=("$v") ;;
                        *) die "unknown key: $k" ;;
                    esac
                done
                [ "${#nets[@]}" -gt 0 ] || die "ntp needs at least one net="
                command -v chronyd >/dev/null 2>&1 && [ -d /etc/chrony/conf.d ] || die "chrony is not installed"
                {
                    echo "# Written by aipbx-priv (PBX -> Network services). Do not edit: the next apply replaces it."
                    printf 'allow %s\n' "${nets[@]}"
                    echo "local stratum 10"
                } > "$NTP_CONF.tmp"
                install -o root -g root -m 0644 "$NTP_CONF.tmp" "$NTP_CONF"
                rm -f "$NTP_CONF.tmp"
                mkdir -p "$NETSVC_DIR"
                ntp_fw_clear
                if fw_running; then
                    : > "$NTP_FW"
                    for n in "${nets[@]}"; do
                        for p in "" --permanent; do fw_add_all $p --add-rich-rule="$(ntp_rule "$n")" || die "firewall: ntp $n"; done
                        echo "ntp $n" >> "$NTP_FW"
                    done
                fi
                exec systemctl restart chrony.service
                ;;
            ntp-off)
                [ $# -eq 0 ] || die "usage: netsvc ntp-off"
                ntp_fw_clear
                if [ -f "$NTP_CONF" ]; then
                    rm -f "$NTP_CONF"
                    systemctl restart chrony.service >/dev/null 2>&1
                fi
                exit 0
                ;;
            ntp-status)
                # active = chrony runs and serves the networks (the allow file is there).
                [ $# -eq 0 ] || die "usage: netsvc ntp-status"
                if [ -f "$NTP_CONF" ]; then systemctl is-active chrony.service 2>/dev/null; else echo inactive; fi
                exit 0
                ;;
            *) die "unknown netsvc command: $sub" ;;
        esac
}
