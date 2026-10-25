#!/bin/bash
# ==========================================================
# Fax Retention & Spool Cleanup
# fax_retention_days: deletes archived fax records (DB + file)
# sys_spool_cleanup_days: deletes stray temporary files left
# under /var/spool/asterisk/fax/
# The settings are read dynamically from the sys_settings table.
# ==========================================================

LOGFILE="/var/log/fax_cleanup.log"
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAX-CLEANUP] $*" >> "$LOGFILE"
}

# Root logs in to MariaDB over its socket (unix_socket); /root/.my.cnf is used
# when there is one. install.sh never wrote that file, so until 1.20.0 these
# queries failed silently and nothing was cleaned up or swept.
if [ -r /root/.my.cnf ]; then
    MYSQL_CMD="mysql --defaults-extra-file=/root/.my.cnf -N -s asterisk"
else
    MYSQL_CMD="mysql -N -s asterisk"
fi

RETENTION_DAYS=$($MYSQL_CMD -e "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_retention_days' LIMIT 1;" 2>/dev/null)
if ! [[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]]; then RETENTION_DAYS=60; fi

SPOOL_DAYS=$($MYSQL_CMD -e "SELECT setting_value FROM sys_settings WHERE setting_key = 'sys_spool_cleanup_days' LIMIT 1;" 2>/dev/null)
if ! [[ "$SPOOL_DAYS" =~ ^[0-9]+$ ]] || [ "$SPOOL_DAYS" -lt 1 ]; then SPOOL_DAYS=7; fi

log "=== Cleanup start: retention=${RETENTION_DAYS}d spool=${SPOOL_DAYS}d ==="

# 1. Archive retention (0 = unlimited, leave alone)
if [ "$RETENTION_DAYS" -gt 0 ]; then
    for TABLE_COL in "fax_received:received_at" "fax_sent:created_at"; do
        TABLE="${TABLE_COL%%:*}"
        COL="${TABLE_COL##*:}"
        # status != 'PENDING': outgoing fax records that have not finished yet
        # (the target never answered, the h extension never fired) are not
        # deleted silently — fax_pending_sweep.sh first turns them into
        # FAILED, then they fall under the normal retention period.
        OLD_FILES=$($MYSQL_CMD -e "SELECT pdf_path FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND pdf_path != '' AND status != 'PENDING';" 2>/dev/null)
        OLD_TIFS=$($MYSQL_CMD -e "SELECT tif_path FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND tif_path != '' AND status != 'PENDING';" 2>/dev/null)
        COUNT=$($MYSQL_CMD -e "SELECT COUNT(*) FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND status != 'PENDING';" 2>/dev/null)

        # Local files are removed here; files in the S3 bucket ("s3:faxes/…", #69) by fax_to_bucket.php.
        for LIST in "$OLD_FILES" "$OLD_TIFS"; do
            [ -n "$LIST" ] || continue
            echo "$LIST" | while IFS= read -r f; do
                case "$f" in
                    '') ;;
                    s3:*) /usr/local/bin/fax_to_bucket.php --delete "$f" >/dev/null 2>&1 ;;
                    *) rm -f "$f" ;;
                esac
            done
        done

        $MYSQL_CMD -e "DELETE FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND status != 'PENDING';" 2>/dev/null
        log "$TABLE: $COUNT old records deleted (retention=${RETENTION_DAYS}d)"
    done
else
    log "Retention unlimited (0), archive cleanup skipped"
fi

# 2. Stray temporary files left in the spool (unarchived/uncleaned leftovers)
SPOOL_REMOVED=0
for DIR in /var/spool/asterisk/fax /var/spool/asterisk/fax/incoming /var/spool/asterisk/fax/outgoing; do
    [ -d "$DIR" ] || continue
    while IFS= read -r -d '' f; do
        rm -f "$f"
        SPOOL_REMOVED=$((SPOOL_REMOVED + 1))
    done < <(find "$DIR" -maxdepth 1 -type f \( -name '*.tif' -o -name '*.pdf' -o -name '*.call' \) -mtime "+${SPOOL_DAYS}" -print0 2>/dev/null)
done
log "Spool cleanup: $SPOOL_REMOVED files removed (>${SPOOL_DAYS}d)"

log "=== Cleanup complete ==="
