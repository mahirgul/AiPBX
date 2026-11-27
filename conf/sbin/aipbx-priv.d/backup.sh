#!/bin/bash
# Handler of `aipbx-priv backup`: read by aipbx-priv itself (load backup), never run on its own.
# Installed by install.sh to /usr/local/lib/aipbx-priv as root:root 0644; the dispatcher refuses a file that is not
# root's or that anybody else may write. It uses the dispatcher's die(), match(), permanent_flag() and RE_* patterns.

priv_backup() {
        # Backup page. Backups hold every secret of the system, so only root reads them.
        RE_BACKUP='^aipbx-[0-9]{8}-[0-9]{4}(-[a-z-]{1,20})?$'
        BACKUP_DIR="$(sed -n 's/^AIPBX_BACKUP_DIR=//p' /etc/ai-pbx.env 2>/dev/null | tail -n 1 | tr -d "\"'")"
        BACKUP_DIR="${BACKUP_DIR:-/var/backups/aipbx-daily}"
        sub="${1:-}"; [ $# -gt 0 ] && shift
        case "$sub" in
            list)
                # One line per backup: name, bytes, epoch, version, uploaded (0/1)
                [ $# -eq 0 ] || die "usage: backup list"
                for d in "$BACKUP_DIR"/aipbx-*/; do
                    [ -f "$d/db.sql.gz" ] || continue
                    n="$(basename "$d")"; [[ "$n" =~ $RE_BACKUP ]] || continue
                    printf '%s\t%s\t%s\t%s\t%s\n' "$n" "$(du -sb "$d" | cut -f1)" "$(stat -c %Y "$d")" \
                        "$(cat "$d/version" 2>/dev/null)" "$([ -f "$d/uploaded" ] && echo 1 || echo 0)"
                done
                ;;
            create)
                [ $# -eq 0 ] || die "usage: backup create"
                systemctl is-active --quiet aipbx-backup-now.service && die "a backup is already running"
                systemctl reset-failed aipbx-backup-now.service >/dev/null 2>&1 || true
                exec systemd-run --quiet --collect --unit=aipbx-backup-now \
                    --description="AiPBX backup" /usr/local/sbin/aipbx-backup
                ;;
            creating)
                [ $# -eq 0 ] || die "usage: backup creating"
                systemctl is-active --quiet aipbx-backup-now.service && echo yes || echo no
                ;;
            delete)
                [ $# -eq 1 ] || die "usage: backup delete NAME"
                match "$1" "$RE_BACKUP"
                [ -d "$BACKUP_DIR/$1" ] || die "no such backup"
                rm -rf "${BACKUP_DIR:?}/$1"
                ;;
            fetch)
                # The archive to stdout (Download).
                [ $# -eq 1 ] || die "usage: backup fetch NAME"
                match "$1" "$RE_BACKUP"
                [ -f "$BACKUP_DIR/$1/db.sql.gz" ] || die "no such backup"
                exec tar czf - -C "$BACKUP_DIR" --exclude=uploaded "$1"
                ;;
            import)
                # An upload the portal collected in its own folder. The name is not a
                # path, and aipbx-restore refuses a link.
                [ $# -eq 1 ] || die "usage: backup import FILE"
                match "$1" '^[0-9a-f]{32}\.part$'
                exec /usr/local/sbin/aipbx-restore --import "/var/lib/aipbx/backup-upload/$1"
                ;;
            restore)
                # backup restore NAME TOKENHASH [--db-only] [--allow-calls]. Its own unit:
                # Apache restarts during a restore.
                [ $# -ge 2 ] || die "usage: backup restore NAME TOKENHASH [--db-only] [--allow-calls]"
                match "$1" "$RE_BACKUP"; name="$1"
                match "$2" '^[0-9a-f]{64}$'; hash="$2"
                shift 2
                extra=()
                for a in "$@"; do
                    case "$a" in --db-only|--allow-calls) extra+=("$a") ;; *) die "unexpected argument: $a" ;; esac
                done
                systemctl is-active --quiet aipbx-restore.service && die "a restore is already running"
                systemctl is-active --quiet aipbx-update.service && die "an update is running"
                systemctl reset-failed aipbx-restore.service >/dev/null 2>&1 || true
                exec systemd-run --quiet --collect --unit=aipbx-restore \
                    --setenv=HOME=/root --description="AiPBX restore" \
                    /usr/local/sbin/aipbx-restore "$name" --token-hash "$hash" "${extra[@]}"
                ;;
            keep)
                [ $# -eq 1 ] || die "usage: backup keep DAYS"
                match "$1" '^[1-9][0-9]{0,2}$'
                exec /usr/local/sbin/aipbx-system-state keep "$1"
                ;;
            *) die "unknown backup command: $sub" ;;
        esac
}
