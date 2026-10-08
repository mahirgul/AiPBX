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

# Environment fallbacks — no static values in code (rule: AGENTS.md)
if [ -r /etc/ai-pbx.env ]; then
    . /etc/ai-pbx.env
fi

TIF_FILE="$1"
# The values go into SQL and file paths below: the dialplan already filters
# them, and here too only the expected characters are kept (second line of defence).
EXTEN="$(printf '%s' "$2" | tr -cd '0-9+')"
CALLERID="$(printf '%s' "$3" | tr -cd '0-9+')"
if ! [[ "$TIF_FILE" =~ ^/var/spool/asterisk/fax/[A-Za-z0-9_.+-]+\.tif$ ]]; then
    log "ERROR: unexpected TIF path rejected"
    exit 1
fi
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

# The password goes in the environment, not on the command line: -p<password> was visible to everyone in `ps`.
export MYSQL_PWD="${DB_PASS}"
MYSQL_EXEC="mysql -h${DB_HOST:-localhost} -u${DB_USER} ${DB_NAME:-asterisk}"
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
RX_ENABLED=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_rx_enabled' LIMIT 1;" 2>/dev/null)
if [ -z "$RX_ENABLED" ]; then RX_ENABLED="yes"; fi

ATTACH_PDF_ENABLED=$($MYSQL_QUERY -e \
  "SELECT setting_value FROM sys_settings WHERE setting_key = 'fax_email_rx_attach_pdf' LIMIT 1;" 2>/dev/null)
if [ -z "$ATTACH_PDF_ENABLED" ]; then ATTACH_PDF_ENABLED="yes"; fi

# The notification email and unit name follow a three-level priority (the
# 2026-08-19 "1 prefix" revision: the DID (e.g. 19276) and the fax user's
# extension (e.g. 9276) no longer match textually — the "1" stays only on the
# DID side):
# 1) sys_did_mappings.notification_email/department_name (exact DID match, entered explicitly by the admin)
# 2) sys_did_mappings.assigned_user_id -> sys_users (the fax user linked on the Fax Units page)
# 3) drop the leading "1" of EXTEN and match sys_users.extension (a safety net for new DIDs never saved in sys_did_mappings)
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
    DEPT_NAME="$EXTEN"
fi

log "Department: $DEPT_NAME | Email: ${NOTIFY_EMAIL:-NONE} | RX_Enabled: $RX_ENABLED"

# 5. Send email notification if enabled
if [ "$RX_ENABLED" = "yes" ] && [ -n "$NOTIFY_EMAIL" ] && [[ "$NOTIFY_EMAIL" =~ @ ]]; then

    TIMESTAMP=$(date '+%d.%m.%Y %H:%M:%S')
    ATTACH_NAME="fax_${EXTEN}_$(date +%Y%m%d_%H%M%S).pdf"

    # PORTAL_DOMAIN: sys_settings pjsip_external_domain → the env fallback sourced above
    # (the query returns empty if the key is not in the DB; override the env value ONLY when filled, so the link does not break)
    _db_portal_domain=$($MYSQL_QUERY -e \
      "SELECT setting_value FROM sys_settings WHERE setting_key = 'pjsip_external_domain' LIMIT 1;" 2>/dev/null)
    if [ -n "$_db_portal_domain" ]; then
        PORTAL_DOMAIN="$_db_portal_domain"
    fi

    # The text is the "fax_received" e-mail template (Admin → E-Mail →
    # Templates), in the recipient's language, with the PDF attached when enabled.
    ATTACH_ARG=()
    if [ "$ATTACH_PDF_ENABLED" = "yes" ] && [ -f "$ARCHIVE_PDF" ]; then
        ATTACH_ARG=("--attach=$ARCHIVE_PDF|$ATTACH_NAME|application/pdf")
    fi
    /usr/bin/php "$(dirname "$(readlink -f "$0")")/send_template_mail.php" fax_received "$NOTIFY_EMAIL" --sender=fax \
        "${ATTACH_ARG[@]}" \
        "department=$DEPT_NAME" "did=$EXTEN" "caller=$CALLERID" "pages=$PAGES" "date=$TIMESTAMP" \
        "portal_link=https://${PORTAL_DOMAIN}/fax-inbox" >>"$LOGFILE" 2>&1

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
