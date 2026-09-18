#!/bin/bash
# ==========================================================
# Fax Retention & Spool Cleanup
# fax_retention_days: arşivlenmiş faks kayıtlarını (DB + dosya) siler
# sys_spool_cleanup_days: /var/spool/asterisk/fax/ altında kalan
# başıboş geçici dosyaları siler
# Ayarlar dinamik olarak sys_settings tablosundan okunur.
# ==========================================================

LOGFILE="/var/log/fax_cleanup.log"
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAX-CLEANUP] $*" >> "$LOGFILE"
}

MYSQL_CMD="mysql --defaults-extra-file=/root/.my.cnf -N -s asterisk"

RETENTION_DAYS=$($MYSQL_CMD -e "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_retention_days' LIMIT 1;" 2>/dev/null)
if ! [[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]]; then RETENTION_DAYS=60; fi

SPOOL_DAYS=$($MYSQL_CMD -e "SELECT setting_value FROM sys_settings WHERE setting_key = 'sys_spool_cleanup_days' LIMIT 1;" 2>/dev/null)
if ! [[ "$SPOOL_DAYS" =~ ^[0-9]+$ ]] || [ "$SPOOL_DAYS" -lt 1 ]; then SPOOL_DAYS=7; fi

log "=== Cleanup start: retention=${RETENTION_DAYS}d spool=${SPOOL_DAYS}d ==="

# 1. Arşiv saklama süresi (0 = sınırsız, dokunma)
if [ "$RETENTION_DAYS" -gt 0 ]; then
    for TABLE_COL in "fax_received:received_at" "fax_sent:created_at"; do
        TABLE="${TABLE_COL%%:*}"
        COL="${TABLE_COL##*:}"
        # status != 'PENDING': henuz sonuclanmamis (hedef hic cevap vermemis,
        # h extension tetiklenmemis) giden faks kayitlari sessizce silinmez —
        # once fax_pending_sweep.sh bunlari FAILED'e cevirir, sonra normal
        # saklama suresine tabi olurlar.
        OLD_FILES=$($MYSQL_CMD -e "SELECT pdf_path FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND pdf_path != '' AND status != 'PENDING';" 2>/dev/null)
        OLD_TIFS=$($MYSQL_CMD -e "SELECT tif_path FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND tif_path != '' AND status != 'PENDING';" 2>/dev/null)
        COUNT=$($MYSQL_CMD -e "SELECT COUNT(*) FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND status != 'PENDING';" 2>/dev/null)

        if [ -n "$OLD_FILES" ]; then
            echo "$OLD_FILES" | while IFS= read -r f; do [ -n "$f" ] && rm -f "$f"; done
        fi
        if [ -n "$OLD_TIFS" ]; then
            echo "$OLD_TIFS" | while IFS= read -r f; do [ -n "$f" ] && rm -f "$f"; done
        fi

        $MYSQL_CMD -e "DELETE FROM $TABLE WHERE $COL < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY) AND status != 'PENDING';" 2>/dev/null
        log "$TABLE: $COUNT eski kayit silindi (retention=${RETENTION_DAYS}d)"
    done
else
    log "Retention sinirsiz (0), arsiv temizligi atlandi"
fi

# 2. Spool'da kalan başıboş geçici dosyalar (arşivlenmemiş/temizlenmemiş artıklar)
SPOOL_REMOVED=0
for DIR in /var/spool/asterisk/fax /var/spool/asterisk/fax/incoming /var/spool/asterisk/fax/outgoing; do
    [ -d "$DIR" ] || continue
    while IFS= read -r -d '' f; do
        rm -f "$f"
        SPOOL_REMOVED=$((SPOOL_REMOVED + 1))
    done < <(find "$DIR" -maxdepth 1 -type f \( -name '*.tif' -o -name '*.pdf' -o -name '*.call' \) -mtime "+${SPOOL_DAYS}" -print0 2>/dev/null)
done
log "Spool temizligi: $SPOOL_REMOVED dosya silindi (>${SPOOL_DAYS}d)"

log "=== Cleanup complete ==="
