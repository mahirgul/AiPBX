<?php
/**
 * E-mail templates: every mail AiPBX sends (invitation, password reset, test
 * mail, voicemail, fax notifications) is built here from an editable template
 * inside one branded frame (logo, title, colour from Brand settings).
 *
 * - The built-in text of a template is in the language files
 *   (mailtpl.<key>.subject / .body), so each recipient gets it in their own
 *   language. An administrator can override it per language (mail_templates).
 * - A body is a small HTML subset (paragraphs, bold, italic, links, lists);
 *   anything else is removed when it is saved. {variables} are replaced when
 *   the mail is sent; values are escaped, only the "block" variables (buttons,
 *   the account box) insert markup that this class builds itself.
 */
class MailTemplateService
{
    /**
     * Template key => variables it offers. Variables marked with * are blocks
     * (ready-made HTML, e.g. a button); the others are plain text.
     */
    const TEMPLATES = [
        'invite_new' => ['name', 'username', 'extension', 'email', 'brand', '*account_details', 'reset_link', '*reset_button', '*mobile_section'],
        'invite' => ['name', 'username', 'extension', 'email', 'brand', '*account_details', 'reset_link', '*reset_button', '*mobile_section'],
        'password_reset' => ['name', 'username', 'brand', 'reset_link', '*reset_button'],
        'voicemail' => ['name', 'mailbox', 'caller', 'caller_name', 'caller_number', 'date', 'duration', 'brand', 'portal_link'],
        'fax_received' => ['department', 'did', 'caller', 'pages', 'date', 'brand', 'portal_link'],
        'fax_sent' => ['destination', 'fax_id', 'pages', 'date', 'brand', 'portal_link'],
        'fax_failed' => ['destination', 'fax_id', 'pages', 'date', 'error', 'brand', 'portal_link'],
        'test' => ['brand', 'date', 'from_name', 'from_address', 'to', 'host'],
    ];

    /** Tags a template body may contain; everything else is unwrapped (its text kept). */
    const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'h3', 'hr', 'blockquote'];

    const DEFAULT_COLOR = '#2563eb';

    /* ------------------------------------------------------------------ */
    /* Templates: built-in text, overrides                                 */
    /* ------------------------------------------------------------------ */

    public static function exists(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    /** @return list<string> variable names without the block marker */
    public static function variables(string $key): array
    {
        return array_map(fn($v) => ltrim($v, '*'), self::TEMPLATES[$key] ?? []);
    }

    /** The built-in subject/body of a template in a language (English where that language lacks it). */
    public static function builtIn(string $key, string $lang): array
    {
        return self::inLanguage($lang, fn() => [
            'subject' => t("mailtpl.{$key}.subject"),
            'body' => t("mailtpl.{$key}.body"),
        ]);
    }

    /** The administrator's version for this language, or null. */
    public static function override(string $key, string $lang): ?array
    {
        $st = getDB()->prepare('SELECT subject, body, updated_at FROM mail_templates WHERE template_key = ? AND lang = ?');
        $st->execute([$key, $lang]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** What is sent in this language: the override, else the built-in text. */
    public static function get(string $key, string $lang): array
    {
        $o = self::override($key, $lang);
        return $o
            ? ['subject' => $o['subject'], 'body' => $o['body'], 'custom' => true]
            : self::builtIn($key, $lang) + ['custom' => false];
    }

    /** @return array<string, list<string>> template key => languages with an override */
    public static function customizedLanguages(): array
    {
        $out = [];
        foreach (getDB()->query('SELECT template_key, lang FROM mail_templates ORDER BY lang') as $r) {
            $out[$r['template_key']][] = $r['lang'];
        }
        return $out;
    }

    public static function save(string $key, string $lang, string $subject, string $body): array
    {
        if (!self::exists($key) || !isset(UI_LANGUAGES[$lang])) {
            return ['success' => false, 'error' => t('mail_templates.err_unknown')];
        }
        $subject = self::cleanSubject($subject);
        $body = self::sanitize($body);
        if ($subject === '' || trim(strip_tags($body)) === '') {
            return ['success' => false, 'error' => t('mail_templates.err_empty')];
        }
        getDB()->prepare(
            'INSERT INTO mail_templates (template_key, lang, subject, body, updated_at, updated_by) VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_at = NOW(), updated_by = VALUES(updated_by)'
        )->execute([$key, $lang, $subject, $body, $_SESSION['user_id'] ?? null]);
        writeAuditLog(null, 'mail_templates', $key, "E-mail template saved: {$key} ({$lang})", 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('mail_templates.saved'), 'body' => $body, 'subject' => $subject];
    }

    public static function reset(string $key, string $lang): array
    {
        getDB()->prepare('DELETE FROM mail_templates WHERE template_key = ? AND lang = ?')->execute([$key, $lang]);
        writeAuditLog(null, 'mail_templates', $key, "E-mail template reset to default: {$key} ({$lang})", 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('mail_templates.reset_done')] + self::builtIn($key, $lang);
    }

    /* ------------------------------------------------------------------ */
    /* Language of a recipient                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A portal user gets mail in their own interface language. Anyone else
     * (a fax unit's address, an unknown voicemail address) gets the mail
     * language of the system: the setting on the templates page, else the
     * language of the first administrator, so an existing Turkish
     * installation keeps sending Turkish mail.
     */
    public static function languageFor(?string $email = null, ?int $userId = null): string
    {
        $db = getDB();
        $lang = null;
        if ($userId) {
            $st = $db->prepare('SELECT language_preference FROM sys_users WHERE id = ?');
            $st->execute([$userId]);
            $lang = $st->fetchColumn() ?: null;
        } elseif ($email !== null && $email !== '') {
            $st = $db->prepare('SELECT language_preference FROM sys_users WHERE email = ? AND is_active = 1 ORDER BY id LIMIT 1');
            $st->execute([$email]);
            $lang = $st->fetchColumn() ?: null;
        }
        return ($lang && isset(UI_LANGUAGES[$lang])) ? $lang : self::systemLanguage();
    }

    public static function systemLanguage(): string
    {
        $lang = (string) getSystemSetting('mail_default_language', '');
        if (isset(UI_LANGUAGES[$lang])) {
            return $lang;
        }
        $admin = getDB()->query("SELECT language_preference FROM sys_users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        return ($admin && isset(UI_LANGUAGES[$admin])) ? $admin : DEFAULT_UI_LANGUAGE;
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Builds subject, HTML and plain text of a template.
     *
     * @param array<string, string> $vars plain values (escaped here)
     * @param array<string, array{html: string, text: string}> $blocks ready-made parts for the * variables
     * @param array{subject: string, body: string}|null $template a template to use instead of the stored one (preview)
     * @return array{subject: string, html: string, text: string, lang: string}
     */
    public static function render(string $key, string $lang, array $vars, array $blocks = [], ?array $template = null): array
    {
        return self::inLanguage($lang, function () use ($key, $lang, $vars, $blocks, $template) {
            $tpl = $template ?? self::get($key, $lang);
            $brand = self::brand();
            $vars += ['brand' => $brand['title']];

            $subject = self::cleanSubject(self::fill($tpl['subject'], $vars, [], false));
            $bodyHtml = self::fill(self::sanitize($tpl['body']), $vars, array_map(fn($b) => $b['html'], $blocks), true);
            $html = self::frame($subject, $bodyHtml, $brand, $lang);

            $text = self::htmlToText(self::fill(self::sanitize($tpl['body']), $vars, array_map(fn($b) => '[[BLOCK:' . base64_encode($b['text']) . ']]', $blocks), true));
            // A block (button, box) stands on its own lines in the text version.
            $text = preg_replace_callback('/\[\[BLOCK:([A-Za-z0-9+\/=]*)\]\]/', fn($m) => ($m[1] === '' ? '' : "\n\n" . base64_decode($m[1]) . "\n\n"), $text);
            $text = preg_replace("/[ \t]+\n/", "\n", $text);
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
            $text = trim($text) . "\n\n-- \n" . $brand['title'] . "\n";

            return ['subject' => $subject, 'html' => $html, 'text' => $text, 'lang' => $lang];
        });
    }

    /** Replaces {variables}; unknown ones stay visible so a typo is noticed. */
    private static function fill(string $s, array $vars, array $blocks, bool $html): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars, $blocks, $html) {
            $k = $m[1];
            if (array_key_exists($k, $blocks)) {
                return $blocks[$k];
            }
            if (array_key_exists($k, $vars)) {
                return $html ? htmlspecialchars((string) $vars[$k], ENT_QUOTES, 'UTF-8') : (string) $vars[$k];
            }
            return $m[0];
        }, $s);
    }

    private static function cleanSubject(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    }

    /**
     * Keeps only ALLOWED_TAGS and, on links, an http(s)/mailto href or one
     * that is a {variable}. Scripts, styles and comments are dropped whole.
     */
    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="aipbx-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('aipbx-root');
        if (!$root) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }
        self::cleanNode($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        // Some libxml/PHP versions (PHP 8.4 in CI) write href="{reset_link}"
        // as href="%7Breset_link%7D", and the value was then never filled in.
        $out = preg_replace('/href="%7B([a-z_]+)%7D"/i', 'href="{$1}"', $out);
        return trim($out);
    }

    private static function cleanNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link', 'form'], true)) {
                $node->removeChild($child);
                continue;
            }
            self::cleanNode($child);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->nodeName);
            }
            if ($tag === 'a') {
                if (preg_match('#^(https?://|mailto:)#i', $href) || preg_match('/^\{[a-z_]+\}$/', $href)) {
                    $child->setAttribute('href', $href);
                }
            }
        }
    }

    /** One block element per line, for the editor (the built-in texts are stored on one line). */
    public static function pretty(string $body): string
    {
        $body = preg_replace('#(</p>|</ul>|</ol>|<ul>|<ol>|</li>|</h3>|<hr>|</blockquote>)\s*(?=\S)#', "$1\n", $body);
        $body = preg_replace('#(\})(?=<(p|ul|ol|h3|hr|blockquote)\b)#', "$1\n", $body);
        $body = preg_replace('#(</p>|</ul>|</ol>)\n?(\{[a-z_]+\})#', "$1\n$2", $body);
        return preg_replace('#(<ul>|<ol>)\n#', "$1\n", $body);
    }

    /** Plain-text version of a body: paragraphs, line breaks, list bullets, link targets. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#<li[^>]*>#i', "\n- ", $html);
        $html = preg_replace('#</(p|h3|ul|ol|blockquote)>#i', "\n\n", $html);
        $html = preg_replace('#<hr[^>]*>#i', "\n----\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // "text (url)" where the text already is the url
        $text = preg_replace('#(\S+) \(\1\)#', '$1', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /* ------------------------------------------------------------------ */
    /* Blocks used by several templates                                    */
    /* ------------------------------------------------------------------ */

    /** A big button (HTML) / the bare link (text). */
    public static function buttonBlock(string $url, string $label): array
    {
        $color = self::brand()['color'];
        $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return [
            'html' => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px auto;"><tr><td style="border-radius:8px;background:' . $color . ';">'
                // style first: frame() colours body links by matching '<a href=', the button keeps its own colour.
                . '<a style="display:inline-block;padding:13px 30px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;" href="' . $u . '" target="_blank">' . $l . '</a>'
                . '</td></tr></table>',
            'text' => $label . ': ' . $url,
        ];
    }

    /** A grey box with label: value lines. @param array<string, string> $rows */
    public static function detailsBlock(string $title, array $rows): array
    {
        $color = self::brand()['color'];
        $html = '<div style="background:#f8fafc;border-left:4px solid ' . $color . ';padding:12px 16px;margin:18px 0;border-radius:4px;font-size:14px;">';
        if ($title !== '') {
            $html .= '<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong><br>';
        }
        $text = $title !== '' ? $title . "\n" : '';
        foreach ($rows as $label => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $html .= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': <strong>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</strong><br>';
            $text .= "- {$label}: {$value}\n";
        }
        return ['html' => $html . '</div>', 'text' => rtrim($text)];
    }

    /* ------------------------------------------------------------------ */
    /* Frame                                                               */
    /* ------------------------------------------------------------------ */

    /** @return array{title: string, sub: string, color: string, logo: ?string, show_title: bool} */
    public static function brand(): array
    {
        static $brand = null;
        if ($brand !== null) {
            return $brand;
        }
        $color = trim((string) getSystemSetting('brand_color_primary', ''));
        $logo = null;
        if (getSystemSetting('site_logo_type', 'image') === 'image') {
            $url = (string) getSystemSetting('site_logo_image', '') ?: BRAND_DEFAULT_LOGO_URL;
            // The header is the brand colour: the dark-theme logo (light
            // lettering) often reads better on it — the admin chooses.
            $darkUrl = (string) getSystemSetting('site_logo_image_dark', '');
            if ($darkUrl !== '' && getSystemSetting('mail_logo_variant', 'light') === 'dark') {
                $url = $darkUrl;
            }
            $path = realpath(dirname(__DIR__, 2) . parse_url($url, PHP_URL_PATH));
            // Mail clients show png/jpg/gif; an svg or webp logo is left out.
            if ($path && is_file($path) && preg_match('/\.(png|jpe?g|gif)$/i', $path)) {
                $logo = $path;
            }
        }
        return $brand = [
            'title' => (string) getSystemSetting('brand_title', 'AiPBX'),
            'sub' => (string) getSystemSetting('brand_sub', ''),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR,
            'logo' => $logo,
            // Off when the logo already spells the name; without a logo the name always shows.
            'show_title' => $logo === null || getSystemSetting('mail_show_brand_title', '1') !== '0',
        ];
    }

    /** The common layout around every body. The logo is an inline image (cid:logo). */
    private static function frame(string $subject, string $bodyHtml, array $brand, string $lang): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $logo = $brand['logo'] ? '<img src="cid:aipbx-logo" alt="" height="44" style="height:44px;max-width:180px;display:block;margin:0 auto 10px auto;border:0;">' : '';
        $sub = $brand['sub'] !== '' ? '<div style="font-size:13px;opacity:.9;margin-top:4px;">' . $e($brand['sub']) . '</div>' : '';
        $footer = $e(sprintf(t('mail_templates.footer'), $brand['title']));
        // Paragraph spacing inside the body (mail clients drop <style> blocks unevenly; inline is safest).
        $bodyHtml = preg_replace('/<p>/', '<p style="margin:0 0 14px 0;">', $bodyHtml);
        $bodyHtml = preg_replace('/<a href=/', '<a style="color:' . $brand['color'] . ';" href=', $bodyHtml);

        return '<!DOCTYPE html><html lang="' . $e($lang) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1e293b;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="background:' . $brand['color'] . ';padding:26px 24px;text-align:center;color:#ffffff;">' . $logo
            . ($brand['show_title'] ? '<div style="font-size:22px;font-weight:700;">' . $e($brand['title']) . '</div>' : '') . $sub . '</td></tr>'
            . '<tr><td style="padding:28px 28px 12px 28px;font-size:15px;line-height:1.6;">' . $bodyHtml . '</td></tr>'
            . '<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:16px;text-align:center;font-size:12px;color:#94a3b8;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /* ------------------------------------------------------------------ */
    /* Sending                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Renders and sends a template.
     *
     * @param string $sender 'portal' or 'fax' (which From address of the E-Mail settings)
     * @param list<array{path: string, name: string, type: string}> $attachments
     * @param array<string, string> $extraHeaders e.g. Message-ID
     * @return array{success: bool, error?: string}
     */
    public static function send(string $key, string $to, string $lang, array $vars, array $blocks = [], array $attachments = [], string $sender = 'portal', array $extraHeaders = []): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => t('srv_mail.err_to')];
        }
        $mail = self::render($key, $lang, $vars, $blocks);
        [$fromAddress, $fromName] = self::sender($sender);
        [$headers, $body] = self::buildMime($mail, $fromAddress, $fromName, $attachments, $extraHeaders);

        $ok = @mail($to, self::encodeHeader($mail['subject']), $body, $headers, '-f ' . $fromAddress);
        if (!$ok) {
            $err = error_get_last()['message'] ?? t('srv_mail.err_unknown');
            return ['success' => false, 'error' => sprintf(t('srv_mail.err_send'), $err)];
        }
        return ['success' => true];
    }

    /** @return array{0: string, 1: string} from address, from name */
    public static function sender(string $sender): array
    {
        $clean = fn($s) => preg_replace('/[\r\n]+/', '', (string) $s);
        if ($sender === 'fax') {
            $addr = getSystemSetting('fax_email_from_address', '') ?: getSystemSetting('mail_from_address', 'no-reply@example.com');
            $name = getSystemSetting('fax_email_from_name', '') ?: getSystemSetting('mail_from_name', 'AiPBX');
        } else {
            $addr = getSystemSetting('mail_from_address', getSystemSetting('portal_email_from_address', 'no-reply@example.com'));
            $name = getSystemSetting('mail_from_name', getSystemSetting('portal_email_from_name', 'AiPBX'));
        }
        return [$clean($addr), $clean($name)];
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    /**
     * multipart/mixed (attachments) > multipart/related (inline logo) >
     * multipart/alternative (text, html). Empty levels are left out.
     *
     * @return array{0: string, 1: string} headers, body
     */
    public static function buildMime(array $mail, string $fromAddress, string $fromName, array $attachments = [], array $extraHeaders = []): array
    {
        $b64 = fn($s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        $bnd = fn($p) => '=_aipbx_' . $p . '_' . bin2hex(random_bytes(8));

        $alt = $bnd('alt');
        $part = "Content-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n"
            . "--{$alt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($mail['text']) . "\r\n"
            . "--{$alt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($mail['html']) . "\r\n"
            . "--{$alt}--\r\n";

        $logo = self::brand()['logo'];
        if ($logo && str_contains($mail['html'], 'cid:aipbx-logo')) {
            $rel = $bnd('rel');
            $mime = mime_content_type($logo) ?: 'image/png';
            $part = "Content-Type: multipart/related; boundary=\"{$rel}\"\r\n\r\n"
                . "--{$rel}\r\n" . $part
                . "--{$rel}\r\nContent-Type: {$mime}\r\nContent-ID: <aipbx-logo>\r\nContent-Disposition: inline; filename=\"" . basename($logo) . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . $b64((string) file_get_contents($logo)) . "\r\n"
                . "--{$rel}--\r\n";
        }

        $files = array_filter($attachments, fn($a) => is_file($a['path'] ?? '') && is_readable($a['path']));
        if ($files) {
            $mix = $bnd('mix');
            $out = "Content-Type: multipart/mixed; boundary=\"{$mix}\"\r\n\r\n--{$mix}\r\n" . $part;
            foreach ($files as $a) {
                $name = str_replace(['"', "\r", "\n"], '', $a['name'] ?? basename($a['path']));
                $type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $a['type'] ?? '') ? $a['type'] : 'application/octet-stream';
                $out .= "--{$mix}\r\nContent-Type: {$type}; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . $b64((string) file_get_contents($a['path'])) . "\r\n";
            }
            $part = $out . "--{$mix}--\r\n";
        }

        // The top level Content-Type goes into the headers, the rest is the body.
        [$topType, $body] = explode("\r\n\r\n", $part, 2);
        $headers = 'From: ' . self::encodeHeader($fromName) . " <{$fromAddress}>\r\n"
            . "Reply-To: {$fromAddress}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "X-Mailer: AiPBX\r\n";
        foreach ($extraHeaders as $h => $v) {
            $headers .= $h . ': ' . preg_replace('/[\r\n]+/', '', $v) . "\r\n";
        }
        return [$headers . $topType, $body];
    }

    /* ------------------------------------------------------------------ */
    /* Preview                                                             */
    /* ------------------------------------------------------------------ */

    /** Example values shown in the editor's preview and the test mail. */
    public static function sampleData(string $key, string $lang): array
    {
        return self::inLanguage($lang, function () use ($key) {
            $brand = self::brand()['title'];
            $link = 'https://' . (getSystemSetting('pjsip_external_domain', '') ?: 'pbx.example.com');
            $vars = [
                'name' => 'Alex Morgan', 'username' => 'alex', 'extension' => '1001', 'email' => 'alex@example.com',
                'brand' => $brand, 'reset_link' => $link . '/reset-password?token=…',
                'mailbox' => '1001', 'caller' => 'John Smith <+43 1 234 5678>', 'caller_name' => 'John Smith', 'caller_number' => '+43 1 234 5678',
                'date' => date('d.m.Y H:i'), 'duration' => '0:42', 'portal_link' => $link . '/my-phone?tab=voicemail',
                'department' => 'Sales', 'did' => '19276', 'pages' => '3', 'destination' => '+43 1 999 0000', 'fax_id' => '128',
                'error' => 'NO ANSWER', 'from_name' => 'AiPBX', 'from_address' => 'no-reply@example.com', 'to' => 'admin@example.com',
                'host' => gethostname() ?: 'pbx',
            ];
            if (in_array($key, ['fax_received', 'fax_sent', 'fax_failed'], true)) {
                $vars['portal_link'] = $link . ($key === 'fax_received' ? '/fax-inbox' : '/fax-sent');
            }
            return ['vars' => $vars, 'blocks' => self::sampleBlocks($key, $vars)];
        });
    }

    private static function sampleBlocks(string $key, array $vars): array
    {
        if (!in_array($key, ['invite', 'invite_new', 'password_reset'], true)) {
            return [];
        }
        $blocks = ['reset_button' => self::buttonBlock($vars['reset_link'], t('srv_invite.button'))];
        if ($key !== 'password_reset') {
            $blocks['account_details'] = UserInvitationService::accountDetails($vars['username'], $vars['extension'], $vars['email']);
            $blocks['mobile_section'] = UserInvitationService::mobileSection('https://pbx.example.com/m/…');
        }
        return $blocks;
    }

    /** Runs $fn with t() answering in $lang (the request's own language is restored afterwards). */
    public static function inLanguage(string $lang, callable $fn)
    {
        $had = array_key_exists('AIPBX_REQUEST_LANGUAGE', $GLOBALS);
        $prev = $GLOBALS['AIPBX_REQUEST_LANGUAGE'] ?? null;
        $GLOBALS['AIPBX_REQUEST_LANGUAGE'] = isset(UI_LANGUAGES[$lang]) ? $lang : DEFAULT_UI_LANGUAGE;
        try {
            return $fn();
        } finally {
            if ($had) {
                $GLOBALS['AIPBX_REQUEST_LANGUAGE'] = $prev;
            } else {
                unset($GLOBALS['AIPBX_REQUEST_LANGUAGE']);
            }
        }
    }
}
