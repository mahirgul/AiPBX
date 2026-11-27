#!/bin/bash
# Handler of `aipbx-priv fw`: read by aipbx-priv itself (load fw), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_fw() {
        sub="${1:-}"; [ $# -gt 0 ] && shift
        RE_FW_IFACE='^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$'
        RE_FW_ZONE='^[A-Za-z0-9_-]{1,17}$'
        # fw zone ZONE <add-port|remove-port|add-source-rule|remove-rich-rule|remove-service> ...
        zone_arg=()
        if [ "$sub" = zone ]; then
            [ $# -ge 2 ] || die "usage: fw zone ZONE COMMAND ..."
            match "$1" "$RE_FW_ZONE"
            zone_arg=("--zone=$1"); sub="$2"; shift 2
            case "$sub" in add-port|remove-port|add-source-rule|remove-rich-rule|add-service|remove-service) ;; *) die "unknown fw zone command: $sub" ;; esac
        fi
        case "$sub" in
            overview)
                # The default zone, the zone of every interface and the rules of those zones.
                [ $# -eq 0 ] || die "usage: fw overview"
                def="$(firewall-cmd --get-default-zone)"
                echo "default $def"
                declare -A zone_of=()
                cur=""
                while read -r first rest; do
                    if [ "$first" = "interfaces:" ]; then
                        for i in $rest; do zone_of[$i]="$cur"; done
                    elif [ "$first" != "sources:" ]; then
                        cur="$first"
                    fi
                done < <(firewall-cmd --get-active-zones)
                zones="$def"
                for d in /sys/class/net/*; do
                    i="${d##*/}"
                    [ "$i" != lo ] && [[ "$i" =~ $RE_FW_IFACE ]] || continue
                    z="${zone_of[$i]:-$def}"
                    echo "iface $i $z"
                    zones="$zones $z"
                done
                for z in $(printf '%s\n' $zones | sort -u); do
                    echo "### zone $z"
                    firewall-cmd --zone="$z" --list-all
                done
                ;;
            split)
                # fw split IFACE: the interface gets a zone of its own, a copy of the rules it
                # has now. The copy is the file itself, so nothing of the old zone is lost.
                [ $# -eq 1 ] || die "usage: fw split IFACE"
                match "$1" "$RE_FW_IFACE"
                src="$(firewall-cmd --get-zone-of-interface="$1" 2>/dev/null)" || src=""
                [ -n "$src" ] && [ "$src" != "no zone" ] || src="$(firewall-cmd --get-default-zone)"
                file="$(firewall-cmd --permanent --path-zone="$src" 2>/dev/null)" || file=""
                [ -f "$file" ] || file="/usr/lib/firewalld/zones/$src.xml"
                [ -f "$file" ] || die "zone file of $src not found"
                existing=" $(firewall-cmd --permanent --get-zones) "
                n=1
                while [[ "$existing" == *" aipbx-card$n "* ]]; do n=$((n + 1)); done
                new="aipbx-card$n"
                # firewalld takes the zone name from the file too: no dots in the file name.
                tmpd="$(mktemp -d)"
                sed -E '/<(interface|source)[ >]/d' "$file" > "$tmpd/$new.xml"
                firewall-cmd --permanent --new-zone-from-file="$tmpd/$new.xml" --name="$new" >/dev/null || { rm -rf "$tmpd"; die "could not create $new"; }
                rm -rf "$tmpd"
                firewall-cmd --reload >/dev/null || die "firewall reload failed"
                firewall-cmd --zone="$new" --change-interface="$1" >/dev/null || die "could not move $1 to $new"
                cur="$(firewall-cmd --permanent --get-zone-of-interface="$1" 2>/dev/null)" || cur=""
                if [ -n "$cur" ] && [ "$cur" != "no zone" ]; then
                    firewall-cmd --permanent --zone="$new" --change-interface="$1" >/dev/null || die "could not keep $1 in $new"
                else
                    firewall-cmd --permanent --zone="$new" --add-interface="$1" >/dev/null || die "could not keep $1 in $new"
                fi
                echo "$new"
                ;;
            merge)
                # fw merge IFACE: back to the default zone; its own zone goes when it is empty.
                [ $# -eq 1 ] || die "usage: fw merge IFACE"
                match "$1" "$RE_FW_IFACE"
                z="$(firewall-cmd --get-zone-of-interface="$1" 2>/dev/null)" || z=""
                [[ "$z" =~ ^aipbx-card[0-9]+$ ]] || die "$1 has no zone of its own"
                def="$(firewall-cmd --get-default-zone)"
                firewall-cmd --zone="$def" --change-interface="$1" >/dev/null || die "could not move $1 to $def"
                firewall-cmd --permanent --zone="$z" --remove-interface="$1" >/dev/null 2>&1 || true
                if [ -z "$(firewall-cmd --permanent --zone="$z" --list-interfaces)" ]; then
                    firewall-cmd --permanent --delete-zone="$z" >/dev/null || die "could not delete $z"
                    firewall-cmd --reload >/dev/null || die "firewall reload failed"
                fi
                ;;
            add-service|remove-service)
                # fw add-service|remove-service NAME [--permanent] — add: a rule turned back on (Firewall page)
                [ $# -eq 1 ] || [ $# -eq 2 ] || die "usage: fw $sub NAME [--permanent]"
                match "$1" '^[A-Za-z0-9_-]{1,64}$'
                perm="$(permanent_flag "${2:-}")" || exit 64
                exec firewall-cmd "${zone_arg[@]}" "--$sub=$1" ${perm:+"$perm"}
                ;;
            get-default-zone)
                [ $# -eq 0 ] || die "usage: fw get-default-zone"
                exec firewall-cmd --get-default-zone
                ;;
            list-all)
                [ $# -eq 0 ] || die "usage: fw list-all"
                exec firewall-cmd --list-all
                ;;
            add-port|remove-port)
                # fw add-port|remove-port PORT PROTO [--permanent]
                [ $# -eq 2 ] || [ $# -eq 3 ] || die "usage: fw $sub PORT PROTO [--permanent]"
                match "$1" "$RE_PORT"; match "$2" "$RE_PROTO"
                perm="$(permanent_flag "${3:-}")" || exit 64
                exec firewall-cmd "${zone_arg[@]}" "--$sub=$1/$2" ${perm:+"$perm"}
                ;;
            add-source-rule)
                # fw add-source-rule SUBNET PORT PROTO [--permanent]
                [ $# -eq 3 ] || [ $# -eq 4 ] || die "usage: fw add-source-rule SUBNET PORT PROTO [--permanent]"
                match "$1" "$RE_IPV4_CIDR"; match "$2" "$RE_PORT"; match "$3" "$RE_PROTO"
                perm="$(permanent_flag "${4:-}")" || exit 64
                exec firewall-cmd "${zone_arg[@]}" "--add-rich-rule=rule family=\"ipv4\" source address=\"$1\" port port=\"$2\" protocol=\"$3\" accept" ${perm:+"$perm"}
                ;;
            remove-rich-rule)
                # fw remove-rich-rule RULE [--permanent] — RULE is the text shown by `list-all`
                [ $# -eq 1 ] || [ $# -eq 2 ] || die "usage: fw remove-rich-rule RULE [--permanent]"
                match "$1" "$RE_RICH_RULE"
                perm="$(permanent_flag "${2:-}")" || exit 64
                exec firewall-cmd "${zone_arg[@]}" "--remove-rich-rule=$1" ${perm:+"$perm"}
                ;;
            *) die "unknown fw command: $sub" ;;
        esac
}
