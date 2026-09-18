#!/bin/bash
# ==========================================================
# Stale Outgoing FAX PENDING Sweeper
# Asterisk call-file mekanizmasinda hedef HIC cevap vermezse
# (tum MaxRetries denemeleri tukenirse) dialplan'a hic girilmez,
# process_outgoing_fax_result.sh tetiklenmez ve fax_sent.status
# sonsuza kadar PENDING kalir. Bu script bu tur kayitlari bulup
# FAILED olarak kapatir ki fax_retention_days temizligi bunlari
# sessizce silmesin ve kullaniciya gercek durum gorunsun.
# ==========================================================

LOGFILE="/var/log/fax_cleanup.log"
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAX-SWEEP] $*" >> "$LOGFILE"
}

MYSQL_CMD="mysql --defaults-extra-file=/root/.my.cnf -N -s asterisk"

# Asterisk call-file worst-case suresi: (MaxRetries+1) * RetryTime + WaitTime.
# fax_max_retries dinamik olabildiginden guvenli bir sabit esik (15 dk) kullanilir.
STALE_MINUTES=15

STALE_IDS=$($MYSQL_CMD -e "SELECT id FROM fax_sent WHERE status = 'PENDING' AND created_at < DATE_SUB(NOW(), INTERVAL $STALE_MINUTES MINUTE);" 2>/dev/null)

if [ -z "$STALE_IDS" ]; then
    exit 0
fi

echo "$STALE_IDS" | while IFS= read -r id; do
    [ -z "$id" ] && continue
    $MYSQL_CMD -e "UPDATE fax_sent SET status = 'FAILED', error_message = 'Hedef cevap vermedi / zaman asimi (otomatik kapatildi)', completed_at = NOW() WHERE id = $id AND status = 'PENDING';" 2>/dev/null
    log "FAX #$id: PENDING -> FAILED (>${STALE_MINUTES}dk cevapsiz, otomatik kapatildi)"
done
