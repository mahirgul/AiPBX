#!/bin/bash
# ==========================================================
# Stale Outgoing FAX PENDING Sweeper
# With Asterisk's call-file mechanism, if the target NEVER answers
# (all MaxRetries attempts are used up) the dialplan is never entered,
# process_outgoing_fax_result.sh is not triggered and fax_sent.status
# stays PENDING forever. This script finds such records and closes them
# as FAILED, so the fax_retention_days cleanup does not delete them
# silently and the user sees the real state.
# ==========================================================

LOGFILE="/var/log/fax_cleanup.log"
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAX-SWEEP] $*" >> "$LOGFILE"
}

# Root logs in to MariaDB over its socket (unix_socket); /root/.my.cnf is used
# when there is one. install.sh never wrote that file, so until 1.20.0 these
# queries failed silently and nothing was cleaned up or swept.
if [ -r /root/.my.cnf ]; then
    MYSQL_CMD="mysql --defaults-extra-file=/root/.my.cnf -N -s asterisk"
else
    MYSQL_CMD="mysql -N -s asterisk"
fi

# Asterisk call-file worst-case suresi: (MaxRetries+1) * RetryTime + WaitTime.
# fax_max_retries can change, so a safe fixed threshold (15 min) is used.
STALE_MINUTES=15

STALE_IDS=$($MYSQL_CMD -e "SELECT id FROM fax_sent WHERE status = 'PENDING' AND created_at < DATE_SUB(NOW(), INTERVAL $STALE_MINUTES MINUTE);" 2>/dev/null)

if [ -z "$STALE_IDS" ]; then
    exit 0
fi

echo "$STALE_IDS" | while IFS= read -r id; do
    [ -z "$id" ] && continue
    $MYSQL_CMD -e "UPDATE fax_sent SET status = 'FAILED', error_message = 'Hedef cevap vermedi / zaman asimi (otomatik kapatildi)', completed_at = NOW() WHERE id = $id AND status = 'PENDING';" 2>/dev/null
    log "FAX #$id: PENDING -> FAILED (no answer for >${STALE_MINUTES} min, closed automatically)"
done
