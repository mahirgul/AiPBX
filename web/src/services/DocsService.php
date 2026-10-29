<?php
/**
 * Admin → Help: the documents of docs/ shown in the portal.
 *
 * Why: an AiPBX server is often installed where there is no internet access
 * (hospitals, factories, closed networks). Whoever debugs it needs the
 * documentation on the server itself, next to the logs; docs/*.md ships with
 * every installation (/opt/aipbx/docs), so the portal reads them from disk.
 *
 * The Markdown is turned into HTML here (no library, no CDN): only what the
 * documents use — headings with GitHub-style anchors, paragraphs, lists (one
 * level of nesting), tables, fenced code, block quotes, rules, inline code,
 * bold, italics, links and diagrams (docs/img/*.svg). All text is HTML-escaped
 * first, so a document can never inject markup. Links to other documents (troubleshooting.md#capturing-sip)
 * become /help?doc=troubleshooting#capturing-sip; other links open in a new tab.
 *
 * Single source: the same docs/*.md are read on GitHub, in the portal and on
 * the website (aipbx.bid/docs). The website copies this file and docs/ from the
 * repository and only changes $docUrl and $repoUrl, so a fix to a document
 * reaches all three places.
 */
class DocsService
{
    /** Shown first in the document list; core docs follow alphabetically, then PLUGINS at the bottom. */
    private const FIRST = ['system-map', 'troubleshooting', 'README'];

    /** Documentation pages for optional plugins, grouped at the bottom of the Help sidebar. */
    private const PLUGINS = [
        'plugin-development',
        'auto-dialer',
        'ai-apps',
        'assistant',
        'cloud-ai',
        'cloud-tts',
        'local-ai',
        'signaling',
        'web-widgets',
    ];

    /** Prefix of a link to another document: the portal uses /help?doc=, the website /docs/. */
    public static string $docUrl = '/help?doc=';

    /**
     * Where links to repository files (../web/src/x.php) point. Null in the portal:
     * the files are on the server's disk, so they are shown as a path. The website
     * sets the GitHub tree URL.
     */
    public static ?string $repoUrl = null;

    public static function dir(): string
    {
        return getenv('AIPBX_DOCS_DIR') ?: dirname(__DIR__, 3) . '/docs';
    }

    /**
     * @return list<array{name: string, title: string, plugin?: bool}>
     */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            $out[$name] = [
                'name' => $name,
                'title' => self::title($file) ?: $name,
                'plugin' => in_array($name, self::PLUGINS, true),
            ];
        }
        uksort($out, function (string $a, string $b): int {
            $ia = array_search($a, self::FIRST, true);
            $ib = array_search($b, self::FIRST, true);
            if ($ia !== false || $ib !== false) {
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            }
            $pa = in_array($a, self::PLUGINS, true);
            $pb = in_array($b, self::PLUGINS, true);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            if ($pa && $pb) {
                if ($a === 'plugin-development' || $b === 'plugin-development') {
                    return $a === 'plugin-development' ? -1 : 1;
                }
            }
            return strcasecmp($a, $b);
        });
        return array_values($out);
    }

    /** Root-level Markdown files linked from docs/*.md as ../INSTALL.md etc. */
    private const ROOT_DOCS = ['INSTALL', 'ARCHITECTURE', 'CHANGELOG', 'CONTRIBUTING', 'SECURITY'];

    /** The Markdown of a document, or null (unknown name or not a docs/ file). */
    public static function source(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return null;
        }
        $file = self::dir() . '/' . $name . '.md';
        if (is_file($file)) {
            return (string) file_get_contents($file);
        }
        if (in_array($name, self::ROOT_DOCS, true)) {
            $rootFile = dirname(self::dir()) . '/' . $name . '.md';
            if (is_file($rootFile)) {
                return (string) file_get_contents($rootFile);
            }
        }
        return null;
    }

    /**
     * Lines containing all words of $query (case-insensitive), per document.
     *
     * @return list<array{name: string, title: string, hits: list<array{line: string, anchor: string}>}>
     */
    public static function search(string $query, int $maxPerDoc = 8): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: []));
        if ($words === []) {
            return [];
        }
        $out = [];
        foreach (self::list() as $doc) {
            $anchor = '';
            $hits = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) self::source($doc['name'])) ?: [] as $line) {
                if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                    $anchor = self::slug($m[1]);
                }
                $low = mb_strtolower($line);
                foreach ($words as $w) {
                    if (!str_contains($low, $w)) {
                        continue 2;
                    }
                }
                $hits[] = ['line' => self::plain($line), 'anchor' => $anchor];
                if (count($hits) >= $maxPerDoc) {
                    break;
                }
            }
            if ($hits !== []) {
                $out[] = ['name' => $doc['name'], 'title' => $doc['title'], 'hits' => $hits];
            }
        }
        return $out;
    }

    /** A Markdown line as plain text for search results: no markers, table cells joined. */
    public static function plain(string $line): string
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = implode(' — ', array_filter(self::cells($line), fn($c) => $c !== ''));
        }
        $line = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $line);
        $line = (string) preg_replace('/^(#{1,6}|[-*+]|\d+[.)]|>)\s+/', '', $line);
        return trim(str_replace(['**', '`'], '', $line));
    }

    /** First "# " heading of a file. */
    private static function title(string $file): string
    {
        $f = @fopen($file, 'rb');
        if ($f === false) {
            return '';
        }
        $title = '';
        while (($line = fgets($f)) !== false) {
            if (preg_match('/^#\s+(.+)$/', trim($line), $m)) {
                $title = trim($m[1]);
                break;
            }
        }
        fclose($f);
        return $title;
    }

    /** GitHub's heading anchor: lowercase, punctuation dropped, spaces to dashes. */
    public static function slug(string $heading): string
    {
        $text = strip_tags(preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading) ?? $heading);
        $text = str_replace('`', '', mb_strtolower(trim($text)));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text);
        return (string) preg_replace('/\s/u', '-', $text);
    }

    // ------------------------------------------------------------ Markdown

    public static function render(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = '';
        $n = count($lines);
        for ($i = 0; $i < $n;) {
            $line = $lines[$i];

            // Fenced code: kept verbatim.
            if (preg_match('/^\s*```\s*([A-Za-z0-9_+-]*)\s*$/', $line, $m)) {
                $code = [];
                for ($i++; $i < $n && !preg_match('/^\s*```\s*$/', $lines[$i]); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $cls = $m[1] !== '' ? ' class="lang-' . self::e($m[1]) . '"' : '';
                $html .= '<pre class="doc-code"><code' . $cls . '>' . self::e(implode("\n", $code)) . "</code></pre>\n";
                continue;
            }
            if (trim($line) === '') {
                $i++;
                continue;
            }
            // A line of bare HTML with no text (the centred logo of README.md): GitHub
            // shows it, here it is left out rather than printed as escaped markup.
            if (preg_match('/^\s*<(p|div|img|picture|br)\b[^>]*>/i', $line) && trim(strip_tags($line)) === '') {
                $i++;
                continue;
            }
            // A diagram on its own line: ![alt](img/name.svg).
            if (preg_match('~^\s*!\[([^\]]*)\]\((?:\.\./)?(?:docs/)?img/([a-z0-9-]+)\.svg\)\s*$~', $line, $m)) {
                $html .= self::diagram($m[2], $m[1]);
                $i++;
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $html .= sprintf("<h%d id=\"%s\">%s</h%d>\n", $level, self::e(self::slug($m[2])), self::inline($m[2]), $level);
                $i++;
                continue;
            }
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $html .= "<hr>\n";
                $i++;
                continue;
            }
            if (str_starts_with(ltrim($line), '>')) {
                $quote = [];
                for (; $i < $n && str_starts_with(ltrim($lines[$i]), '>'); $i++) {
                    $quote[] = (string) preg_replace('/^\s*>\s?/', '', $lines[$i]);
                }
                $html .= '<blockquote>' . self::render(implode("\n", $quote)) . "</blockquote>\n";
                continue;
            }
            // Table: a "|" row followed by a |---| separator row.
            if (str_starts_with(trim($line), '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1])) {
                $head = self::cells($line);
                $i += 2;
                $html .= "<div class=\"table-responsive\"><table class=\"doc-table\"><thead><tr>";
                foreach ($head as $c) {
                    $html .= '<th>' . self::inline($c) . '</th>';
                }
                $html .= "</tr></thead><tbody>\n";
                for (; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++) {
                    $html .= '<tr>';
                    foreach (self::cells($lines[$i]) as $c) {
                        $html .= '<td>' . self::inline($c) . '</td>';
                    }
                    $html .= "</tr>\n";
                }
                $html .= "</tbody></table></div>\n";
                continue;
            }
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                [$block, $i] = self::listBlock($lines, $i);
                $html .= $block;
                continue;
            }
            // Paragraph: consume first line, then subsequent lines until a blank line or another block.
            $para = [trim($lines[$i++])];
            for (; $i < $n; $i++) {
                $l = $lines[$i];
                if (trim($l) === '' || preg_match('/^(#{1,6}\s|\s*```|\s*>|\s*([-*+]|\d+[.)])\s+|\s*\|)/', $l)) {
                    break;
                }
                $para[] = trim($l);
            }
            $html .= '<p>' . self::inline(implode(' ', $para)) . "</p>\n";
        }
        return $html;
    }

    /**
     * A list starting at $i: items with their continuation lines; indented
     * items form a nested list inside the previous item.
     *
     * @param list<string> $lines
     * @return array{0: string, 1: int} HTML and the index after the list
     */
    private static function listBlock(array $lines, int $i): array
    {
        $n = count($lines);
        preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i], $m);
        $indent = strlen($m[1]);
        $ordered = ctype_digit($m[2][0]);
        $items = [];
        while ($i < $n) {
            $line = $lines[$i];
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $mm) && strlen($mm[1]) === $indent) {
                $items[] = ['text' => [trim($mm[3])], 'sub' => ''];
                $i++;
                continue;
            }
            if ($items === []) {
                break;
            }
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line, $mm) && strlen($mm[1]) > $indent) {
                [$sub, $i] = self::listBlock($lines, $i);
                $items[count($items) - 1]['sub'] .= $sub;
                continue;
            }
            // Continuation line of the item (indented), or the list ends.
            if (trim($line) !== '' && strlen($line) - strlen(ltrim($line)) > $indent) {
                $items[count($items) - 1]['text'][] = trim($line);
                $i++;
                continue;
            }
            // A blank line inside the list: it goes on with an item of the same kind.
            if (trim($line) === '' && $i + 1 < $n
                && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i + 1], $mm) && strlen($mm[1]) >= $indent
                && (strlen($mm[1]) > $indent || ctype_digit($mm[2][0]) === $ordered)) {
                $i++;
                continue;
            }
            break;
        }
        $tag = $ordered ? 'ol' : 'ul';
        $html = "<$tag>";
        foreach ($items as $it) {
            $html .= '<li>' . self::inline(implode(' ', $it['text'])) . $it['sub'] . '</li>';
        }
        return [$html . "</$tag>\n", $i];
    }

    /** @return list<string> */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = (string) preg_replace('/^\||\|$/', '', $row);
        // A "|" inside `code` is not a column separator.
        $cells = [];
        $cur = '';
        $inCode = false;
        for ($k = 0, $len = strlen($row); $k < $len; $k++) {
            $ch = $row[$k];
            if ($ch === '`') {
                $inCode = !$inCode;
            }
            if ($ch === '\\' && $k + 1 < $len && $row[$k + 1] === '|') {
                $cur .= '|';
                $k++;
                continue;
            }
            if ($ch === '|' && !$inCode) {
                $cells[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $cells[] = trim($cur);
        return $cells;
    }

    /** Inline Markdown of already plain text: escaped first, code spans kept apart. */
    public static function inline(string $text): string
    {
        // Links first (their label is rendered on its own), then code spans.
        $links = [];
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use (&$links) {
            $links[] = self::link($m[2], $m[1]);
            return "\x02" . (count($links) - 1) . "\x02";
        }, $text);
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $codes[] = '<code>' . self::e($m[1]) . '</code>';
            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text);
        $text = self::e($text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w])_(?!\s)(.+?)(?<!\s)_(?![\w])/', '<em>$1</em>', $text);
        $text = (string) preg_replace_callback("/\x01(\d+)\x01/", fn($m) => $codes[(int) $m[1]], $text);
        return (string) preg_replace_callback("/\x02(\d+)\x02/", fn($m) => $links[(int) $m[1]], $text);
    }

    private static function link(string $url, string $label): string
    {
        $inner = self::inline($label);
        if (preg_match('~^(?:\.\./)?(?:docs/)?([A-Za-z0-9_-]+)\.md(#[A-Za-z0-9_-]*)?$~', $url, $m)) {
            return '<a href="' . self::e(self::$docUrl . $m[1]) . self::e($m[2] ?? '') . '">' . $inner . '</a>';
        }
        if (str_starts_with($url, '#')) {
            return '<a href="' . self::e($url) . '">' . $inner . '</a>';
        }
        if (preg_match('~^https?://~i', $url)) {
            return '<a href="' . self::e($url) . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';
        }
        // Repository files (../web/..., ../CHANGELOG.md): on disk in the portal, on GitHub on the website.
        $path = ltrim($url, './');
        if (self::$repoUrl !== null) {
            return '<a href="' . self::e(rtrim(self::$repoUrl, '/') . '/' . $path) . '" target="_blank" rel="noopener noreferrer">'
                . $inner . '</a>';
        }
        return $inner . ' <code>' . self::e($path) . '</code>';
    }

    /**
     * docs/img/<name>.svg, rendered from docs/diagrams/<name>.mmd by
     * scripts/render-diagrams.sh, put into the page as it is: the portal does not
     * serve docs/ as files, and an inline SVG needs no extra URL on the website.
     * Only a plain SVG is taken (no script, event handler or embedded HTML);
     * anything else is shown as its alt text.
     */
    private static function diagram(string $name, string $alt): string
    {
        $svg = @file_get_contents(self::dir() . '/img/' . $name . '.svg');
        $svg = is_string($svg) ? trim((string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg)) : '';
        if (!str_starts_with($svg, '<svg') || preg_match('/<script|<foreignObject|\son\w+\s*=|javascript:/i', $svg)) {
            return '<p><em>' . self::e($alt !== '' ? $alt : $name) . "</em></p>\n";
        }
        // Fit the column, but never below 80 % of the drawn size: the text would
        // become unreadable; the figure scrolls sideways instead.
        $svg = (string) preg_replace_callback('/style="max-width:\s*([\d.]+)px;\s*/', function ($m) {
            $w = (float) $m[1];
            return sprintf('style="width: clamp(%dpx, 100%%, %dpx); max-width: none; ', (int) ($w * 0.8), (int) ceil($w));
        }, $svg, 1);
        $label = $alt !== '' ? ' role="img" aria-label="' . self::e($alt) . '"' : '';
        return '<figure class="doc-diagram"' . $label . '>' . $svg . "</figure>\n";
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
