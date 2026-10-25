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

# ---- Faxes that never got through the handshake --------------------------
# A fax session that has not moved a single page two minutes after it started
# will not: the two ends failed to agree and then wait for each other for many
# minutes, keeping the line busy (live system, 2026-10-22: seven minutes until
# it was hung up by hand). A normal fax completes its handshake in 15-20 s.
# Only sessions with 0 pages are ended; a fax that has sent or received a page
# is left alone however long it runs. Hanging up lets the dialplan record the
# fax as FAILED with Asterisk's reason, as for any other failure.
# This runs once a minute, so a stalled session ends 2-3 minutes after it began.
STALL_SECONDS=120
STALL_STATE=/var/lib/aipbx/fax-stall.state
if command -v asterisk >/dev/null 2>&1; then
    now=$(date +%s)
    new_state=""
    for faxid in $(asterisk -rx "fax show sessions" 2>/dev/null | awk '$3 ~ /^[0-9]+$/ {print $3}'); do
        details=$(asterisk -rx "fax show session $faxid" 2>/dev/null)
        chan=$(awk -F' : ' '/^channel /{print $2; exit}' <<<"$details")
        [ -z "$chan" ] && continue
        pages=$(awk -F: '/^(Tx|Rx) Pages/{gsub(/ /, "", $2); s += $2} END {print s + 0}' <<<"$details")
        first=$(awk -v k="$faxid" '$1 == k {print $2; exit}' "$STALL_STATE" 2>/dev/null)
        first=${first:-$now}
        new_state+="$faxid $first"$'\n'
        if [ "$pages" -eq 0 ] && [ $((now - first)) -ge $STALL_SECONDS ]; then
            asterisk -rx "channel request hangup $chan" >/dev/null 2>&1
            log "FAX session $faxid on $chan: no page after $((now - first)) s, handshake failed; hung up"
        fi
    done
    printf '%s' "$new_state" > "$STALL_STATE" 2>/dev/null
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
    $MYSQL_CMD -e "UPDATE fax_sent SET status = 'FAILED', error_message = 'No answer / timeout (closed automatically)', completed_at = NOW() WHERE id = $id AND status = 'PENDING';" 2>/dev/null
    log "FAX #$id: PENDING -> FAILED (no answer for >${STALE_MINUTES} min, closed automatically)"
done
