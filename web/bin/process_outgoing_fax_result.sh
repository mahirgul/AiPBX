#!/bin/bash
# ==========================================================
# Outgoing FAX Result Processor - Asterisk SendFAX()
# Updates MySQL fax_sent table, sends notification email to
# sender with result status via Postfix
# 100% Dynamic Configuration from MySQL sys_settings
# ==========================================================

LOGFILE="/var/log/fax_outgoing.log"
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAX-TX] $*" | tee -a "$LOGFILE"
}

# Environment fallbacks — no static values in code (rule: AGENTS.md)
if [ -r /etc/ai-pbx.env ]; then
    . /etc/ai-pbx.env
fi

FAX_ID="$1"
STATUS="$2"
ERROR_MSG="$3"
PAGES="${4:-1}"

log "=== Processing Outgoing FAX result: ID=$FAX_ID STATUS=$STATUS ERROR=$ERROR_MSG PAGES=$PAGES ==="

if [ -z "$FAX_ID" ] || ! [[ "$FAX_ID" =~ ^[0-9]+$ ]]; then
    log "ERROR: Invalid or missing FAX_ID: $FAX_ID"
    exit 1
fi

if ! [[ "$PAGES" =~ ^[0-9]+$ ]] || [ "$PAGES" -le 0 ]; then
    PAGES=1
fi

ST="SUCCESS"
if [ "$STATUS" != "SUCCESS" ]; then
    ST="FAILED"
fi

# Backslashes are dropped too: MariaDB treats \' as an escape, which would break the '' doubling.
ESCAPED_ERR=$(printf '%s' "$ERROR_MSG" | tr -d '\r\n\\' | sed "s/'/''/g")

# The password goes in the environment, not on the command line: -p<password> was visible to everyone in `ps`.
export MYSQL_PWD="${DB_PASS}"
MYSQL_EXEC="mysql -h${DB_HOST:-localhost} -u${DB_USER} ${DB_NAME:-asterisk}"
MYSQL_QUERY="$MYSQL_EXEC -N -s"

# 1. Update MySQL fax_sent table
$MYSQL_EXEC <<EOF 2>/dev/null
UPDATE fax_sent 
SET status = '$ST', 
    error_message = '$ESCAPED_ERR', 
    pages = $PAGES, 
    completed_at = NOW() 
WHERE id = $FAX_ID;
EOF

log "Updated DB record #$FAX_ID: status=$ST"

# 2. Fetch dynamic mail configuration from sys_settings
TX_ENABLED=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_tx_enabled' LIMIT 1;" 2>/dev/null)
if [ -z "$TX_ENABLED" ]; then TX_ENABLED="yes"; fi

# Lookup FAX details and sender email address
FAX_INFO=$($MYSQL_QUERY -e \
  "SELECT s.sender_extension, s.destination_number, s.pdf_path, u.email, u.full_name 
   FROM fax_sent s 
   LEFT JOIN sys_users u ON s.user_id = u.id 
   WHERE s.id = $FAX_ID LIMIT 1;" 2>/dev/null)

if [ -n "$FAX_INFO" ]; then
    SENDER_EXT=$(echo "$FAX_INFO" | awk '{print $1}')
    DEST_NUM=$(echo "$FAX_INFO" | awk '{print $2}')
    PDF_PATH=$(echo "$FAX_INFO" | awk '{print $3}')
    SENDER_EMAIL=$(echo "$FAX_INFO" | awk '{print $4}')
    
    log "FAX #$FAX_ID Info -> Sender DID: $SENDER_EXT | Dest: $DEST_NUM | Email: ${SENDER_EMAIL:-NONE}"
    
    # 3. Send email notification to sender if enabled
    if [ "$TX_ENABLED" = "yes" ] && [ -n "$SENDER_EMAIL" ] && [[ "$SENDER_EMAIL" =~ @ ]]; then
        TIMESTAMP=$(date '+%d.%m.%Y %H:%M:%S')
        _db_portal_domain=$($MYSQL_QUERY -e \
          "SELECT setting_value FROM sys_settings WHERE setting_key = 'pjsip_external_domain' LIMIT 1;" 2>/dev/null)
        if [ -n "$_db_portal_domain" ]; then
            PORTAL_DOMAIN="$_db_portal_domain"
        fi
        # "fax_sent" / "fax_failed" e-mail templates (Admin → E-Mail → Templates), in the sender's language.
        TEMPLATE="fax_sent"
        if [ "$ST" != "SUCCESS" ]; then
            TEMPLATE="fax_failed"
        fi
        /usr/bin/php "$(dirname "$(readlink -f "$0")")/send_template_mail.php" "$TEMPLATE" "$SENDER_EMAIL" --sender=fax \
            "destination=$DEST_NUM" "fax_id=$FAX_ID" "pages=$PAGES" "date=$TIMESTAMP" "error=${ERROR_MSG:--}" \
            "portal_link=https://${PORTAL_DOMAIN}/fax-sent" >>"$LOGFILE" 2>&1 \
            || log "ERROR: status e-mail for FAX #$FAX_ID could not be sent"

        log "Sent status notification email to $SENDER_EMAIL for FAX #$FAX_ID"
    fi
fi

log "=== Completed Outgoing FAX result for #$FAX_ID ==="
