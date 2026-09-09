<?php
/**
 * Metin Yazarak Faks Gönderme Yardımcısı
 * WYSIWYG editörden (Quill) gelen HTML'i güvenli hale getirip mPDF ile
 * gerçek bir PDF'e çevirir — sonrası (Ghostscript PDF->TIFF, spool, kayıt)
 * FaxSendService'teki mevcut PDF-yükleme hattıyla aynen paylaşılır.
 */
class TextFaxHelper {

    /**
     * mPDF'in gömülü DejaVu font ailesi (embedded TTF, Türkçe karakter
     * garantili) — editördeki font seçici de sadece bu üç isimden birini
     * üretebiliyor (assets/js/fax_send.js). Standart PDF çekirdek fontları
     * (Arial/Times/Courier) BİLEREK sunulmuyor: bunlar embed edilmez ve
     * Türkçe (ç,ğ,ı,ö,ş,ü) karakterlerini desteklemez.
     */
    const ALLOWED_FONTS = ['dejavusans', 'dejavuserif', 'dejavusansmono'];

    /** İzin verilen etiketler ve (sadece style özniteliği için) izinli CSS özellikleri. */
    const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'ol', 'ul', 'li', 'span'];
    const ALLOWED_STYLE_PROPS = ['font-family', 'font-size', 'text-align', 'font-weight', 'font-style', 'text-decoration'];

    /** Bunlar unwrap edilmez (metni korumak için) — doğrudan İÇERİKLERİYLE BİRLİKTE silinir. */
    const DROP_ENTIRELY_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math', 'img', 'form', 'input', 'button'];

    /**
     * Editörden gelen ham HTML'i (tarayıcıdan geldiği için POST üzerinden
     * manipüle edilebilir, güvenilmez) whitelist'e göre temizler: izinli
     * etiket dışındaki her şey (script/img/iframe/on* vb.) tamamen atılır
     * (metni korumak için içeriği açılıp yerine taşınır), stil sadece
     * ALLOWED_STYLE_PROPS'taki özellikleri taşıyabilir.
     */
    public static function sanitizeHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="__aipbx_root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__aipbx_root');
        if (!$root) return '';

        self::cleanNode($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function cleanNode(\DOMNode $node): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP_ENTIRELY_TAGS, true)) {
                // Tehlikeli/anlamsız etiket: içeriğiyle BİRLİKTE tamamen silinir
                // (ör. <script>alert(1)</script>'in metnini düz yazı olarak bile
                // sızdırmamak için — unwrap değil, tam kaldırma).
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Bilinmeyen ama zararsız bir etiket (ör. Word'den yapıştırılan
                // <font>/<a>): kendisi silinir, içindeki metin/alt-etiketler
                // ebeveyne taşınarak korunur (kullanıcının yazdığı içerik kaybolmaz).
                //
                // ÖNEMLİ (2026-08-31 denetiminde bulunan GÜVENLİK AÇIĞI): alt ağaç
                // taşınmadan ÖNCE temizlenmeli. Bu döngü ebeveynin çocuk listesinin
                // MUTASYONDAN ÖNCE alınmış anlık görüntüsü (iterator_to_array)
                // üzerinde ilerlediği için, yukarı taşınan düğümler bir daha
                // denetlenmiyordu — tek bir <div> sarmalayıcı <img>/<script>
                // korumasını tamamen deliyordu (kimliği doğrulanmış SSRF).
                self::cleanNode($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            if ($child->hasAttributes()) {
                foreach (iterator_to_array($child->attributes) as $attr) {
                    if (strtolower($attr->name) !== 'style') {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($child->hasAttribute('style')) {
                    $clean = self::filterStyle($child->getAttribute('style'));
                    if ($clean !== '') {
                        $child->setAttribute('style', $clean);
                    } else {
                        $child->removeAttribute('style');
                    }
                }
            }

            self::cleanNode($child);
        }
    }

    private static function filterStyle(string $style): string {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) continue;
            $prop = strtolower(trim($parts[0]));
            $val = trim($parts[1]);
            if ($val === '' || !in_array($prop, self::ALLOWED_STYLE_PROPS, true)) continue;
            if (preg_match('/url\s*\(|expression\s*\(|javascript:/i', $val)) continue;
            if ($prop === 'font-family' && !in_array(strtolower($val), self::ALLOWED_FONTS, true)) continue;
            $out[] = $prop . ': ' . $val;
        }
        return implode('; ', $out);
    }

    /**
     * Temizlenmiş HTML'i A4 boyutunda gerçek bir PDF dosyasına yazar.
     * mPDF'in kendi gömülü DejaVu fontları kullanıldığı için sunucudaki
     * sistem fontlarından (fc-list) tamamen bağımsızdır.
     */
    public static function htmlToPdf(string $safeHtml, string $outputPath): void {
        $tempDir = sys_get_temp_dir() . '/aipbx_mpdf';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0770, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A4',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusans',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
        ]);

        $mpdf->WriteHTML('<div style="font-family: dejavusans; font-size: 12pt;">' . $safeHtml . '</div>');
        $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
    }
}
