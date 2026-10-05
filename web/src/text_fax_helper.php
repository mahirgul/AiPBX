<?php
/**
 * Helper for sending a fax by typing text
 * Sanitizes the HTML from the WYSIWYG editor (Quill) and turns it into a real
 * PDF with mPDF — the rest (Ghostscript PDF->TIFF, spool, record) is shared
 * with the existing PDF-upload pipeline in FaxSendService.
 */
class TextFaxHelper {

    /**
     * mPDF's built-in DejaVu font family (embedded TTF, Turkish characters
     * guaranteed) — the font picker in the editor can only produce one of
     * these three names (assets/js/fax_send.js). The standard PDF core fonts
     * (Arial/Times/Courier) are NOT offered ON PURPOSE: they are not embedded
     * and do not support Turkish characters (ç,ğ,ı,ö,ş,ü).
     */
    const ALLOWED_FONTS = ['dejavusans', 'dejavuserif', 'dejavusansmono'];

    /** Allowed tags and (for the style attribute only) the allowed CSS properties. */
    const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'ol', 'ul', 'li', 'span'];
    const ALLOWED_STYLE_PROPS = ['font-family', 'font-size', 'text-align', 'font-weight', 'font-style', 'text-decoration'];

    /** These are not unwrapped (to keep the text) — they are deleted WITH THEIR CONTENT. */
    const DROP_ENTIRELY_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math', 'img', 'form', 'input', 'button'];

    /**
     * Cleans the raw HTML from the editor (it comes from the browser and can
     * be tampered with in the POST, untrusted) against a whitelist: everything
     * outside the allowed tags (script/img/iframe/on* etc.) is dropped
     * completely (its content is unwrapped into place to keep the text), and a
     * style may only carry the properties in ALLOWED_STYLE_PROPS.
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
                // Dangerous/meaningless tag: deleted completely WITH its content
                // (so not even the text of <script>alert(1)</script> leaks as
                // plain text — full removal, not unwrap).
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // An unknown but harmless tag (e.g. <font>/<a> pasted from
                // Word): the tag itself is removed, the text/child tags inside
                // move up to the parent and are kept (the user's content is
                // not lost).
                //
                // IMPORTANT (a SECURITY HOLE found in the 2026-08-31 audit): the
                // subtree must be cleaned BEFORE it is moved. This loop walks a
                // snapshot of the parent's child list taken BEFORE THE MUTATION
                // (iterator_to_array), so the nodes moved up were never checked
                // again — a single <div> wrapper completely bypassed the
                // <img>/<script> protection (authenticated SSRF).
                self::cleanNode($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            if ($child instanceof \DOMElement && $child->hasAttributes()) {
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
     * Writes the sanitized HTML into a real A4 PDF file.
     * It uses mPDF's own embedded DejaVu fonts, so it is completely
     * independent of the system fonts on the server (fc-list).
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
