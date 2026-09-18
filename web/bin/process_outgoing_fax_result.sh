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

# Ortam fallback'leri — kodda statik değer yok (kural: AGENTS.md)
if [ -r /etc/ai-pbx.env ]; then
    . /etc/ai-pbx.env
elif [ -r /etc/kbu-portal.env ]; then
    . /etc/kbu-portal.env
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

ESCAPED_ERR=$(echo "$ERROR_MSG" | sed "s/'/''/g" | tr -d '\r\n')

MYSQL_EXEC="mysql -h${DB_HOST:-localhost} -u${DB_USER:-kbu_portal} -p${DB_PASS} ${DB_NAME:-asterisk}"
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
FROM_ADDR=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_from_address' LIMIT 1;" 2>/dev/null)
if [ -z "$FROM_ADDR" ]; then FROM_ADDR="${MAIL_FROM_ADDRESS:-}"; fi

FROM_NAME_RAW=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_from_name' LIMIT 1;" 2>/dev/null)
if [ -z "$FROM_NAME_RAW" ]; then FROM_NAME_RAW="${MAIL_FROM_NAME:-}"; fi

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
        FROM_NAME="=?UTF-8?B?$(echo -n "$FROM_NAME_RAW" | base64)?="
        
        if [ "$ST" = "SUCCESS" ]; then
            SUBJECT="=?UTF-8?B?$(echo -n "Faks İletildi - Alıcı: $DEST_NUM (#$FAX_ID)" | base64)?="
            STATUS_BG="#10b981"
            STATUS_TXT="Başarıyla İletildi"
            BODY_MSG="Gönderdiğiniz faks alıcıya sorunsuz bir şekilde iletilmiştir."
        else
            SUBJECT="=?UTF-8?B?$(echo -n "Faks İletilemedi - Alıcı: $DEST_NUM (#$FAX_ID)" | base64)?="
            STATUS_BG="#ef4444"
            STATUS_TXT="Başarısız / İletilemedi"
            BODY_MSG="Gönderdiğiniz faks iletilemedi.<br><strong>Hata Detayı:</strong> ${ERROR_MSG:-Hata belirtilmedi}"
        fi
        
        {
            echo "From: $FROM_NAME <$FROM_ADDR>"
            echo "To: $SENDER_EMAIL"
            echo "Subject: $SUBJECT"
            echo "MIME-Version: 1.0"
            echo "Content-Type: text/html; charset=UTF-8"
            echo "Content-Transfer-Encoding: base64"
            echo ""
            cat <<HTMLBODY | base64
<div style="font-family: 'Segoe UI', Arial, sans-serif; max-width: 600px; margin: 0 auto;">
  <div style="background: ${STATUS_BG}; padding: 24px; border-radius: 12px 12px 0 0; text-align: center;">
    <h2 style="color: #fff; margin: 0;">📠 Giden Faks Durumu: ${STATUS_TXT}</h2>
  </div>
  <div style="background: #f8fafc; padding: 24px; border: 1px solid #e2e8f0; border-radius: 0 0 12px 12px;">
    <p style="font-size: 14px; color: #334155;">${BODY_MSG}</p>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 16px;">
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">İşlem ID:</td><td style="padding: 8px;">#${FAX_ID}</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Gönderen Dahili:</td><td style="padding: 8px;">${SENDER_EXT}</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Alıcı Numara:</td><td style="padding: 8px;">${DEST_NUM}</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Sayfa Sayısı:</td><td style="padding: 8px;">${PAGES} sayfa</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Tarih:</td><td style="padding: 8px;">${TIMESTAMP}</td></tr>
    </table>
  </div>
</div>
HTMLBODY
        } | /usr/sbin/sendmail -f "$FROM_ADDR" "$SENDER_EMAIL"
        
        log "Sent status notification email to $SENDER_EMAIL for FAX #$FAX_ID"
    fi
fi

log "=== Completed Outgoing FAX result for #$FAX_ID ==="
