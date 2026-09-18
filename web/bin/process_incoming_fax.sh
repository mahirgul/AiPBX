#!/bin/bash
# ==========================================================
# Incoming FAX Processor - Asterisk Native ReceiveFAX()
# Converts TIF to PDF, inserts into MySQL, sends email with
# PDF attachment via sendmail (Postfix relay to configured IP)
# 100% Dynamic Configuration from MySQL sys_settings
# ==========================================================

LOGFILE="/var/log/fax_incoming.log"
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAX-RX] $*" | tee -a "$LOGFILE"
}

# Ortam fallback'leri — kodda statik değer yok (kural: AGENTS.md)
if [ -r /etc/ai-pbx.env ]; then
    . /etc/ai-pbx.env
elif [ -r /etc/kbu-portal.env ]; then
    . /etc/kbu-portal.env
fi

TIF_FILE="$1"
EXTEN="$2"
CALLERID="$3"
PAGES="${4:-1}"
FAX_STATUS="${5:-SUCCESS}"
if [ "$FAX_STATUS" != "SUCCESS" ]; then
    FAX_STATUS="FAILED"
fi

log "=== New incoming FAX: DID=$EXTEN CALLERID=$CALLERID PAGES=$PAGES STATUS=$FAX_STATUS TIF=$TIF_FILE ==="

if [ -z "$TIF_FILE" ] || [ ! -f "$TIF_FILE" ]; then
    log "ERROR: TIF file not found: $TIF_FILE"
    exit 1
fi

if ! [[ "$PAGES" =~ ^[0-9]+$ ]]; then
    PAGES=1
fi

# 1. Convert TIF to PDF
PDF_FILE="${TIF_FILE%.*}.pdf"
/usr/bin/tiff2pdf -o "$PDF_FILE" "$TIF_FILE" 2>/dev/null

if [ ! -f "$PDF_FILE" ]; then
    log "WARNING: PDF conversion failed, trying ghostscript..."
    /usr/bin/gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile="$PDF_FILE" "$TIF_FILE" 2>/dev/null
fi

if [ ! -f "$PDF_FILE" ]; then
    log "ERROR: All PDF conversion methods failed for $TIF_FILE"
    PDF_FILE="$TIF_FILE"
fi

FILE_SIZE=$(stat -c %s "$PDF_FILE" 2>/dev/null || echo 0)
if ! [[ "$FILE_SIZE" =~ ^[0-9]+$ ]]; then
    FILE_SIZE=0
fi

# 2. Archive to web-accessible directory (/var/www/faxes/recvd/YYYY/)
YEAR=$(date +%Y)
ARCHIVE_DIR="/var/www/faxes/recvd/$YEAR"
mkdir -p "$ARCHIVE_DIR"

ARCHIVE_TIF="$ARCHIVE_DIR/$(basename "$TIF_FILE")"
ARCHIVE_PDF="$ARCHIVE_DIR/$(basename "$PDF_FILE")"

cp -f "$TIF_FILE" "$ARCHIVE_TIF" 2>/dev/null
cp -f "$PDF_FILE" "$ARCHIVE_PDF" 2>/dev/null
chown asterisk:asterisk "$ARCHIVE_TIF" "$ARCHIVE_PDF" 2>/dev/null

log "Archived: $ARCHIVE_PDF"

MYSQL_EXEC="mysql -h${DB_HOST:-localhost} -u${DB_USER:-kbu_portal} -p${DB_PASS} ${DB_NAME:-asterisk}"
MYSQL_QUERY="$MYSQL_EXEC -N -s"

# 3. Insert record into MySQL 'asterisk' database (use archived paths)
$MYSQL_EXEC <<EOF 2>/dev/null
INSERT INTO fax_received (did_extension, caller_id, pages, tif_path, pdf_path, file_size, status, is_read, email_sent)
VALUES ('$EXTEN', '$CALLERID', $PAGES, '$ARCHIVE_TIF', '$ARCHIVE_PDF', $FILE_SIZE, '$FAX_STATUS', 0, 0);
EOF

FAX_DB_ID=$($MYSQL_QUERY -e \
  "SELECT id FROM fax_received WHERE pdf_path = '$ARCHIVE_PDF' ORDER BY id DESC LIMIT 1;" 2>/dev/null)

log "DB Record ID: #$FAX_DB_ID"

# 4. Fetch dynamic mail configuration from sys_settings
FROM_ADDR=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_from_address' LIMIT 1;" 2>/dev/null)
if [ -z "$FROM_ADDR" ]; then FROM_ADDR="${MAIL_FROM_ADDRESS:-}"; fi

FROM_NAME_RAW=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_from_name' LIMIT 1;" 2>/dev/null)
if [ -z "$FROM_NAME_RAW" ]; then FROM_NAME_RAW="${MAIL_FROM_NAME:-}"; fi

RX_ENABLED=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_rx_enabled' LIMIT 1;" 2>/dev/null)
if [ -z "$RX_ENABLED" ]; then RX_ENABLED="yes"; fi

ATTACH_PDF_ENABLED=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_rx_attach_pdf' LIMIT 1;" 2>/dev/null)
if [ -z "$ATTACH_PDF_ENABLED" ]; then ATTACH_PDF_ENABLED="yes"; fi

# Bildirim e-postası ve birim adı üç kademeli önceliktir (2026-08-19 "1 öneki"
# revizyonu: DID (ör. 19276) ile faks kullanıcısının dahilisi (ör. 9276) artık
# metinsel olarak eşleşmiyor — "1" yalnızca DID tarafında kalıyor):
# 1) sys_did_mappings.notification_email/department_name (DID ile birebir eşleşen, admin tarafından açıkça girilmiş)
# 2) sys_did_mappings.assigned_user_id -> sys_users (Faks Birimleri sayfasından bağlanmış faks kullanıcısı)
# 3) EXTEN başındaki "1" düşürülüp sys_users.extension ile eşleştirme (henüz sys_did_mappings'e hiç kaydedilmemiş yeni DID'ler için güvenlik ağı)
NOTIFY_EMAIL=$($MYSQL_QUERY -e \
  "SELECT notification_email FROM sys_did_mappings WHERE did_extension = '$EXTEN' AND is_active = 1 AND notification_email != '' LIMIT 1;" 2>/dev/null)

DEPT_NAME=$($MYSQL_QUERY -e \
  "SELECT department_name FROM sys_did_mappings WHERE did_extension = '$EXTEN' AND is_active = 1 LIMIT 1;" 2>/dev/null)

if [ -z "$NOTIFY_EMAIL" ] || [ -z "$DEPT_NAME" ]; then
    ASSIGNED_ROW=$($MYSQL_QUERY -e \
      "SELECT u.email, u.full_name FROM sys_did_mappings m JOIN sys_users u ON u.id = m.assigned_user_id WHERE m.did_extension = '$EXTEN' AND m.is_active = 1 LIMIT 1;" 2>/dev/null)
    ASSIGNED_EMAIL=$(echo "$ASSIGNED_ROW" | cut -f1)
    ASSIGNED_NAME=$(echo "$ASSIGNED_ROW" | cut -f2)

    if [ -z "$NOTIFY_EMAIL" ] && [ -n "$ASSIGNED_EMAIL" ]; then NOTIFY_EMAIL="$ASSIGNED_EMAIL"; fi
    if [ -z "$DEPT_NAME" ] && [ -n "$ASSIGNED_NAME" ]; then DEPT_NAME="$ASSIGNED_NAME"; fi
fi

if [ -z "$NOTIFY_EMAIL" ] || [ -z "$DEPT_NAME" ]; then
    STRIPPED_EXTEN="$EXTEN"
    if [[ "$EXTEN" =~ ^1[0-9]{4}$ ]]; then
        STRIPPED_EXTEN="${EXTEN:1}"
    fi
    FALLBACK_ROW=$($MYSQL_QUERY -e \
      "SELECT email, full_name FROM sys_users WHERE extension = '$STRIPPED_EXTEN' AND extension_type = 'fax' LIMIT 1;" 2>/dev/null)
    FALLBACK_EMAIL=$(echo "$FALLBACK_ROW" | cut -f1)
    FALLBACK_NAME=$(echo "$FALLBACK_ROW" | cut -f2)

    if [ -z "$NOTIFY_EMAIL" ] && [ -n "$FALLBACK_EMAIL" ]; then NOTIFY_EMAIL="$FALLBACK_EMAIL"; fi
    if [ -z "$DEPT_NAME" ] && [ -n "$FALLBACK_NAME" ]; then DEPT_NAME="$FALLBACK_NAME"; fi
fi

if [ -z "$DEPT_NAME" ]; then
    DEPT_NAME="Dahili $EXTEN"
fi

log "Department: $DEPT_NAME | Email: ${NOTIFY_EMAIL:-NONE} | RX_Enabled: $RX_ENABLED"

# 5. Send email notification if enabled
if [ "$RX_ENABLED" = "yes" ] && [ -n "$NOTIFY_EMAIL" ] && [[ "$NOTIFY_EMAIL" =~ @ ]]; then

    TIMESTAMP=$(date '+%d.%m.%Y %H:%M:%S')
    BOUNDARY="----FaxBoundary$(date +%s%N)"
    ATTACH_FILE="$ARCHIVE_PDF"
    ATTACH_NAME="fax_${EXTEN}_$(date +%Y%m%d_%H%M%S).pdf"
    FROM_NAME="=?UTF-8?B?$(echo -n "$FROM_NAME_RAW" | base64)?="
    SUBJECT="=?UTF-8?B?$(echo -n "Yeni Faks Alindi - $DEPT_NAME ($EXTEN)" | base64)?="

    # PORTAL_DOMAIN: sys_settings pjsip_external_domain → yukarıda source edilen env fallback'i
    # (anahtar DB'de tanımlı değilse sorgu boş döner; env değerini SADECE doluysa ez ki link kırılmasın)
    _db_portal_domain=$($MYSQL_QUERY -e \
      "SELECT setting_value FROM sys_settings WHERE setting_key = 'pjsip_external_domain' LIMIT 1;" 2>/dev/null)
    if [ -n "$_db_portal_domain" ]; then
        PORTAL_DOMAIN="$_db_portal_domain"
    fi

    # Build MIME email with optional PDF attachment
    {
        echo "From: $FROM_NAME <$FROM_ADDR>"
        echo "To: $NOTIFY_EMAIL"
        echo "Subject: $SUBJECT"
        echo "MIME-Version: 1.0"
        echo "Content-Type: multipart/mixed; boundary=\"$BOUNDARY\""
        echo ""
        echo "--$BOUNDARY"
        echo "Content-Type: text/html; charset=UTF-8"
        echo "Content-Transfer-Encoding: base64"
        echo ""
        # Base64 encode HTML body
        cat <<HTMLBODY | base64
<div style="font-family: 'Segoe UI', Arial, sans-serif; max-width: 600px; margin: 0 auto;">
  <div style="background: linear-gradient(135deg, #0284c7, #3b82f6); padding: 24px; border-radius: 12px 12px 0 0; text-align: center;">
    <h2 style="color: #fff; margin: 0;">&#128224; Yeni Faks Al&#305;nd&#305;</h2>
  </div>
  <div style="background: #f8fafc; padding: 24px; border: 1px solid #e2e8f0; border-radius: 0 0 12px 12px;">
    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Birim / B&#246;l&#252;m:</td><td style="padding: 8px;">${DEPT_NAME}</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Dahili Numara:</td><td style="padding: 8px;">${EXTEN}</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">G&#246;nderen Numara:</td><td style="padding: 8px;">${CALLERID}</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Sayfa Say&#305;s&#305;:</td><td style="padding: 8px;">${PAGES} sayfa</td></tr>
      <tr><td style="padding: 8px; font-weight: 600; color: #64748b;">Al&#305;nma Zaman&#305;:</td><td style="padding: 8px;">${TIMESTAMP}</td></tr>
    </table>
    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 16px 0;">
    <p style="font-size: 13px; color: #64748b;">Faks kayd&#305;n&#305; <a href="https://${PORTAL_DOMAIN}/fax-inbox" style="color: #0284c7;">Faks Portal&#305;</a> &#252;zerinden g&#246;r&#252;nt&#252;leyebilirsiniz.</p>
  </div>
</div>
HTMLBODY

        # Attach PDF file if enabled
        if [ "$ATTACH_PDF_ENABLED" = "yes" ] && [ -f "$ATTACH_FILE" ]; then
            echo ""
            echo "--$BOUNDARY"
            echo "Content-Type: application/pdf; name=\"$ATTACH_NAME\""
            echo "Content-Disposition: attachment; filename=\"$ATTACH_NAME\""
            echo "Content-Transfer-Encoding: base64"
            echo ""
            base64 "$ATTACH_FILE"
        fi

        echo ""
        echo "--${BOUNDARY}--"

    } | /usr/sbin/sendmail -f "$FROM_ADDR" "$NOTIFY_EMAIL"

    MAIL_STATUS=$?

    if [ $MAIL_STATUS -eq 0 ]; then
        $MYSQL_QUERY -e \
          "UPDATE fax_received SET email_sent = 1 WHERE id = $FAX_DB_ID;" 2>/dev/null
        log "SUCCESS: Email sent to $NOTIFY_EMAIL (FAX #$FAX_DB_ID)"
    else
        log "ERROR: Email send failed (exit code: $MAIL_STATUS) to $NOTIFY_EMAIL"
    fi
else
    log "SKIP: Notification email disabled or no email configured for DID $EXTEN"
fi

# 6. Clean up original spool files
if [ -f "$ARCHIVE_PDF" ] && [ "$TIF_FILE" != "$ARCHIVE_TIF" ]; then
    rm -f "$TIF_FILE" "${TIF_FILE%.*}.pdf" 2>/dev/null
    log "Cleaned up spool files"
fi

log "=== FAX processing complete: #$FAX_DB_ID ($ARCHIVE_PDF) ==="
