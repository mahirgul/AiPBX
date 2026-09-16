<?php
require_once __DIR__ . '/../asterisk_helper.php';
require_once __DIR__ . '/../text_fax_helper.php';
require_once __DIR__ . '/../db_helper.php';

/**
 * Fax Send (Faks Gönder) Service
 */
class FaxSendService {
    /**
     * PDF yükleyip TIFF G4'e çevirir, fax_sent'e kaydeder ve Asterisk
     * SendFAX() için bir .call spool dosyası üretir. Önceden fax_send.php'nin
     * içine gömülüydü; MVC göçü sırasında (2026-08-22) buraya taşındı,
     * mantık DEĞİŞTİRİLMEDİ (güvenlik notları dahil).
     *
     * @return array{success:bool, message?:string, error?:string}
     */
    public static function sendFax(array $post, array $files, int $userId, string $userExt): array
    {
        $csrf_token = $post['csrf_token'] ?? '';
        // Bu iki alan Asterisk çağrı dosyasına (.call) ham gömülüyor (Channel:/SetVar:
        // satırları) — rakam dışı karakter (özellikle \r\n) kabul edilirse dosyaya
        // keyfi ek direktif (örn. Application: System) enjekte edilebilir, bu yüzden
        // sadece rakamlarla sınırlanıyor.
        $dest_number = preg_replace('/[^0-9]/', '', trim($post['dest_number'] ?? ''));
        // "PDF Yükle" / "Metin Yaz" sekmeleri aynı formu paylaşıyor (fax_send/index.php) —
        // hangi sekmenin aktif olduğu bu alanla geliyor, PDF varsayılan (geriye dönük uyum).
        $compose_mode = (($post['compose_mode'] ?? 'pdf') === 'text') ? 'text' : 'pdf';

        // Gönderen kimliği: sıradan faks kullanıcısı HER ZAMAN kendi dahilisini
        // kullanır — POST'tan farklı bir değer gelse (form salt-okunur ama bu
        // sunucu tarafı bir garanti değil) sessizce yok sayılır. Admin ise
        // panelde bir dropdown'dan (fax_send/index.php) gerçek/aktif bir faks
        // birimi seçip ONUN adına gönderebilir — ama seçim yine de DB'ye karşı
        // doğrulanır, POST manipülasyonuyla var olmayan/pasif bir dahili
        // TSID/başlığa enjekte edilemez (2026-08-31, kullanıcı isteği).
        $requested_sender_did = preg_replace('/[^0-9]/', '', trim($post['sender_did'] ?? ''));
        $current_role = $_SESSION['user_role'] ?? 'fax_user';
        if ($current_role === 'admin' && $requested_sender_did !== '') {
            $valid_ext = DBHelper::fetchColumn(
                "SELECT extension FROM sys_users WHERE extension = ? AND extension_type = 'fax' AND is_active = 1",
                [$requested_sender_did]
            );
            $sender_did = $valid_ext ?: $userExt;
        } else {
            $sender_did = $userExt;
        }

        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => 'Güvenlik doğrulaması (CSRF) başarısız! Lütfen sayfayı yenileyip tekrar deneyin.'];
        }
        if (empty($dest_number)) {
            return ['success' => false, 'error' => 'Lütfen alıcı faks numarasını girin!'];
        }

        $safe_text_html = '';
        if ($compose_mode === 'pdf') {
            if (!isset($files['pdf_file']) || $files['pdf_file']['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Lütfen geçerli bir PDF dosyası yükleyin!'];
            }

            $file_tmp = $files['pdf_file']['tmp_name'];
            $file_name = $files['pdf_file']['name'];
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if ($ext !== 'pdf') {
                return ['success' => false, 'error' => 'Yalnızca PDF formatındaki dosyalar yüklenebilir!'];
            }
        } else {
            // Editörden gelen HTML tarayıcı kaynaklı (POST ile manipüle edilebilir,
            // güvenilmez) — TextFaxHelper::sanitizeHtml() ile whitelist'e göre temizlenir.
            $safe_text_html = TextFaxHelper::sanitizeHtml($post['fax_text_content'] ?? '');
            if (trim(strip_tags($safe_text_html)) === '') {
                return ['success' => false, 'error' => 'Lütfen gönderilecek metni yazın!'];
            }
        }
        if (AsteriskHelper::getPrimaryTrunkName() === null) {
            return ['success' => false, 'error' => 'Tanımlı/aktif bir dış hat (trunk) bulunamadı. Faks gönderebilmek için önce Dış Hat Ayarları\'ndan bir trunk tanımlamalısınız.'];
        }

        $db = getDB();

        // Process Fax Upload
        $timestamp = date('Ymd_His');
        $unique_id = uniqid();
        $year = date('Y');

        // Web-accessible archive directory for sent faxes (yol: /etc/ai-pbx.env)
        $sent_archive_dir = FAX_STORAGE_PATH . "/sent/$year";
        if (!is_dir($sent_archive_dir)) {
            @mkdir($sent_archive_dir, 0775, true);
            @chown($sent_archive_dir, 'asterisk');
        }

        $spool_dir = FAX_OUTGOING_SPOOL; // /etc/ai-pbx.env
        if (!is_dir($spool_dir)) {
            @mkdir($spool_dir, 0775, true);
            @chown($spool_dir, 'asterisk');
        }

        $archived_pdf = "$sent_archive_dir/send_{$userExt}_{$timestamp}_{$unique_id}.pdf";
        $archived_tif = "$sent_archive_dir/send_{$userExt}_{$timestamp}_{$unique_id}.tif";

        if ($compose_mode === 'pdf') {
            if (!move_uploaded_file($file_tmp, $archived_pdf)) {
                return ['success' => false, 'error' => 'Yüklenen dosya sunucuya kaydedilemedi!'];
            }
        } else {
            try {
                TextFaxHelper::htmlToPdf($safe_text_html, $archived_pdf);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Metin PDF\'e dönüştürülürken hata oluştu: ' . $e->getMessage()];
            }
        }
        @chown($archived_pdf, 'asterisk');

        // Convert PDF to Fax TIFF G4 format via Ghostscript (gs)
        $gs_cmd = escapeshellarg(GS_BINARY) . " -q -dNOPAUSE -dBATCH -sDEVICE=tiffg4 -r204x196 -sOutputFile=" . escapeshellarg($archived_tif) . " " . escapeshellarg($archived_pdf) . " 2>&1";
        exec($gs_cmd, $output, $return_code);

        if (!(file_exists($archived_tif) && filesize($archived_tif) > 0)) {
            return ['success' => false, 'error' => "PDF -> TIFF dönüşüm hatası oluştu! " . implode(" ", $output)];
        }
        @chown($archived_tif, 'asterisk');

        // Detect page count using tiffinfo or pdfinfo
        $page_count = 1;
        $tiffinfo_cmd = "tiffinfo " . escapeshellarg($archived_tif) . " | grep -c 'TIFF Directory' 2>/dev/null";
        $detected_pages = intval(trim(shell_exec($tiffinfo_cmd)));
        if ($detected_pages > 0) {
            $page_count = $detected_pages;
        }

        // Insert record into MySQL fax_sent table
        $stmt = $db->prepare('INSERT INTO fax_sent (user_id, sender_extension, destination_number, pdf_path, tif_path, pages, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "PENDING", NOW())');
        $stmt->execute([$userId, $sender_did, $dest_number, $archived_pdf, $archived_tif, $page_count]);
        $fax_id = $db->lastInsertId();

        // Create Asterisk Call File for outbound SendFAX()
        self::submitCallFile($fax_id, $dest_number, $sender_did, $archived_tif);

        return ['success' => true, 'message' => "Faks gönderim kuyruğuna eklendi! (İşlem ID: #$fax_id, Sayfa: $page_count)"];
    }

    /**
     * Verilen fax_sent kaydı için gerçek Asterisk .call spool dosyasını üretip
     * gönderir — sendFax() (yeni gönderim) ve FaxSentService::resendFax()
     * (başarısız bir kaydı aynı TIFF'i yeniden kullanarak tekrar gönderme,
     * 2026-08-31) tarafından ORTAK kullanılıyor. MaxRetries/başlık/TSID
     * mantığının iki ayrı yerde birbirinden sapmaması için tek noktada tutuluyor.
     */
    public static function submitCallFile(int $faxId, string $destNumber, string $senderExt, string $tifPath): void
    {
        $max_retries = intval(getSystemSetting('fax_max_retries', '2'));
        $retry_time = intval(getSystemSetting('fax_retry_time', '60'));
        $wait_time = intval(getSystemSetting('fax_wait_time', '30'));
        $trunk_name = AsteriskHelper::getPrimaryTrunkName();

        // Faks başlığında görünecek gönderen adı (dialplan: FAXOPT(headerinfo) buna
        // ekler) — HER ZAMAN gerçekte gönderen dahilinin (senderExt) sahibi, oturum
        // açan kişinin kendi adı DEĞİL (admin başka bir faks birimi seçmiş olabilir;
        // sıradan faks kullanıcısı için senderExt zaten kendi dahilisi, davranış
        // önceki koddan farksız).
        $db = getDB();
        $stmt = $db->prepare("SELECT full_name, cid_external FROM sys_users WHERE extension = ?");
        $stmt->execute([$senderExt]);
        $sender_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sender_name = trim(preg_replace('/[\r\n]+/', ' ', $sender_row['full_name'] ?? ''));
        $sender_name_var = $sender_name !== '' ? "{$sender_name} ({$senderExt})" : $senderExt;

        // Çağrı dosyası önceden HİÇ CallerID: belirtmiyordu — dış hatta
        // "Anonymous" olarak gidiyor ve trunk tarafından engelleniyordu (2026-08-31).
        // sys_users.cid_external (ör. şehir kodu/önek + dahili) burada
        // kullanılıyor. Boşsa (cid_external ayarlanmamışsa) dahilinin kendisine
        // düşülür (hiç CID göndermemekten iyidir).
        $sender_cid = preg_replace('/[^0-9]/', '', trim($sender_row['cid_external'] ?? ''));
        if ($sender_cid === '') $sender_cid = $senderExt;
        $caller_id_line = "<$sender_cid>";

        // Faks aramaları KESİNLİKLE görünen ad göndermez — bu BİLEREK sabit, Dış Hat
        // Ayarları'ndaki send_caller_name seçeneğine BAKMIYOR (2026-08-31, kullanıcı
        // netleştirdi: "fax aramaları kesinlikle isim göndermesin, diğer aboneler
        // arama yaparken bu ayara baksın" — o ayar sadece normal dahili->dış
        // aramalar için SyncDialplan.php::buildTrunkCallerIdLine()'da uygulanıyor).
        // Faks gönderimi artık doğrudan sabit PJSIP trunk yerine, sistemin gerçek
        // numara planı ve Dış Hat Rotaları (pbx_outbound_routes) üzerinden Native
        // Local kanal (Local/$dest@from-internal-pbx/n) ile çıkış yapar.
        $dest_dial = preg_replace('/[^0-9+*#]/', '', trim($destNumber));

        $call_file_content = "Channel: Local/$dest_dial@from-internal-pbx/n\n" .
                             "CallerID: $caller_id_line\n" .
                             "MaxRetries: $max_retries\n" .
                             "RetryTime: $retry_time\n" .
                             "WaitTime: $wait_time\n" .
                             "Context: outbound-fax\n" .
                             "Extension: s\n" .
                             "Priority: 1\n" .
                             "SetVar: FAX_TIF_PATH=$tifPath\n" .
                             "SetVar: FAX_DEST=$destNumber\n" .
                             "SetVar: FAX_SENDER=$senderExt\n" .
                             "SetVar: FAX_SENDER_NAME=$sender_name_var\n" .
                             "SetVar: FAX_ID=$faxId\n";

        $tmp_call_file = "/tmp/fax_$faxId.call";
        $asterisk_spool = ASTERISK_CALL_SPOOL . "/fax_$faxId.call";

        file_put_contents($tmp_call_file, $call_file_content);
        @chgrp($tmp_call_file, 'asterisk');
        chmod($tmp_call_file, 0666);
        rename($tmp_call_file, $asterisk_spool);
    }

    /**
     * Verilen numarayı, gerçek dahili aramaların [from-internal-outbound]
     * dialplan'ında kullandığı AYNI Dış Hat Rotaları (pbx_outbound_routes)
     * kurallarına göre dönüştürür (prepend/strip_front/strip_back/append) —
     * SyncDialplan.php::__syncOutboundDialplanBody()'deki $dial_num mantığının
     * PHP tarafındaki saf-fonksiyon eşleniği (dialplan'ın kendisi DEĞİŞTİRİLMEDİ,
     * sadece faks çağrı dosyasının Channel: satırı için burada taklit ediliyor).
     * Hiçbir rota eşleşmezse numara DEĞİŞTİRİLMEDEN döner (güvenli varsayılan).
     */
    public static function resolveOutboundDialNumber(string $rawNumber): string {
        $db = getDB();
        $routes = $db->query(
            "SELECT match_pattern, prepend, append, strip_front, strip_back FROM pbx_outbound_routes WHERE is_active = 1 ORDER BY id ASC"
        )->fetchAll();

        foreach ($routes as $r) {
            $pattern = trim($r['match_pattern'] ?? '');
            if ($pattern === '') continue;
            $regex = self::asteriskPatternToRegex($pattern);
            if ($regex === null) continue;
            if (@preg_match($regex, $rawNumber) !== 1) continue;

            $strip_front = intval($r['strip_front'] ?? 0);
            $strip_back = intval($r['strip_back'] ?? 0);
            $core = $rawNumber;
            if ($strip_front > 0) $core = substr($core, $strip_front);
            if ($strip_back > 0) $core = substr($core, 0, max(0, strlen($core) - $strip_back));

            $prepend = preg_replace('/[^0-9]/', '', trim($r['prepend'] ?? ''));
            $append = preg_replace('/[^0-9]/', '', trim($r['append'] ?? ''));
            return $prepend . $core . $append;
        }

        return $rawNumber;
    }

    /**
     * Asterisk dialplan pattern söz dizimini (_X/_Z/_N/./!/[a-b] ve düz rakamlar)
     * bir regex'e çevirir. Yalnızca rakam/joker karakterlerden oluşan basit
     * numara kalıplarını (bu projenin pbx_outbound_routes'ta ürettiği türden)
     * kapsar — SyncDialplan.php zaten match_pattern'i kaydederken
     * `[^0-9NXZnxz.\[\]_!*#-]` dışındaki karakterleri temizliyor, o yüzden
     * burada da aynı karakter kümesi varsayılıyor.
     */
    private static function asteriskPatternToRegex(string $pattern): ?string {
        if ($pattern[0] !== '_') {
            // Joker içermeyen düz numara: tam eşleşme.
            return '/^' . preg_quote($pattern, '/') . '$/';
        }

        $body = substr($pattern, 1);
        $regex = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === 'X' || $ch === 'x') { $regex .= '[0-9]'; }
            elseif ($ch === 'Z' || $ch === 'z') { $regex .= '[1-9]'; }
            elseif ($ch === 'N' || $ch === 'n') { $regex .= '[2-9]'; }
            elseif ($ch === '.') { $regex .= '[0-9]+'; }
            elseif ($ch === '!') { $regex .= '[0-9]*'; }
            elseif ($ch === '[') {
                $close = strpos($body, ']', $i);
                if ($close === false) return null;
                $set = substr($body, $i, $close - $i + 1);
                if (!preg_match('/^\[[0-9\-]+\]$/', $set)) return null;
                $regex .= $set;
                $i = $close;
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }
        return '/^' . $regex . '$/';
    }
}
