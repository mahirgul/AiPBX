<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/DocsService.php';

/** Admin → Help: the docs/ Markdown renderer, document list and search. */
final class DocsServiceTest extends TestCase
{
    public function testEscapesHtmlEverywhere(): void
    {
        $html = DocsService::render("# <b>x</b>\n\nText <script>alert(1)</script> `<i>`\n\n| a | <img src=x> |\n|---|---|\n| 1 | 2 |\n\n```\n<?php echo 1; ?>\n```");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<code>&lt;i&gt;</code>', $html);
        $this->assertStringContainsString('&lt;?php echo 1; ?&gt;', $html);
    }

    public function testLinksBetweenDocuments(): void
    {
        $html = DocsService::inline('[Troubleshooting](troubleshooting.md#capturing-sip), [web](https://example.com), [x](javascript:alert(1))');
        $this->assertStringContainsString('<a href="/help?doc=troubleshooting#capturing-sip">Troubleshooting</a>', $html);
        $this->assertStringContainsString('href="https://example.com" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
    }

    public function testBareHtmlLineIsDropped(): void
    {
        $html = DocsService::render("<p align=\"center\"><img src=\"logo.png\" alt=\"AiPBX\" width=\"96\"></p>\n\n# Title");
        $this->assertSame("<h1 id=\"title\">Title</h1>\n", $html);
        $this->assertStringContainsString('&lt;b&gt;', DocsService::render('<b>bold</b> text'));
    }

    public function testWebsiteLinkSettings(): void
    {
        DocsService::$docUrl = '/docs/';
        DocsService::$repoUrl = 'https://github.com/mahirgul/AiPBX/blob/main';
        try {
            $html = DocsService::inline('[map](system-map.md#logs), [sync](../web/src/sync/SyncTrunks.php)');
            $this->assertStringContainsString('<a href="/docs/system-map#logs">map</a>', $html);
            $this->assertStringContainsString('href="https://github.com/mahirgul/AiPBX/blob/main/web/src/sync/SyncTrunks.php"', $html);
        } finally {
            DocsService::$docUrl = '/help?doc=';
            DocsService::$repoUrl = null;
        }
        $this->assertStringContainsString('sync <code>web/src/sync/SyncTrunks.php</code>', DocsService::inline('[sync](../web/src/sync/SyncTrunks.php)'));
    }

    public function testBlocks(): void
    {
        $md = "## Syslog (phone logs)\n\n- one\n  continued\n- two\n  - nested\n\n1. first\n2. second\n\n> quote\n\n| A | B |\n|---|---|\n| `a|b` | **x** |\n\n---\n";
        $html = DocsService::render($md);
        $this->assertStringContainsString('<h2 id="syslog-phone-logs">', $html);
        $this->assertStringContainsString('<li>one continued</li>', $html);
        $this->assertStringContainsString('<li>two<ul><li>nested</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<blockquote><p>quote</p>', $html);
        $this->assertStringContainsString('<td><code>a|b</code></td><td><strong>x</strong></td>', $html);
        $this->assertStringContainsString('<hr>', $html);
    }

    public function testDiagramIsInlinedOnlyFromDocsImg(): void
    {
        $html = DocsService::render("![System map](img/system-map.svg)");
        $this->assertStringStartsWith('<figure class="doc-diagram" role="img" aria-label="System map"><svg', $html);
        $this->assertStringContainsString('id="diagram-system-map"', $html);
        $this->assertMatchesRegularExpression('/<svg [^>]*style="width: clamp\(\d+px, 100%, \d+px\); max-width: none; /', $html);
        // Only names under docs/img: no path tricks, no other file types.
        foreach (['![x](img/../../web/config.svg)', '![x](/etc/img/a.svg)', '![x](img/logo.png)'] as $md) {
            $this->assertStringNotContainsString('<svg', DocsService::render($md));
        }
        $this->assertSame("<p><em>Missing</em></p>\n", DocsService::render('![Missing](img/no-such-diagram.svg)'));
    }

    public function testSlugMatchesGithub(): void
    {
        $this->assertSame('ntp-server-time', DocsService::slug('NTP server (time)'));
        $this->assertSame('5-apply-from-the-database-to-asterisk', DocsService::slug('5. Apply: from the database to Asterisk'));
        $this->assertSame('where-is--feature--code', DocsService::slug('Where is …? (feature → code)'));
    }

    public function testRealDocumentsRenderAndListAndSearch(): void
    {
        $docs = DocsService::list();
        $this->assertSame('system-map', $docs[0]['name']);
        $this->assertSame('System map (for administrators)', $docs[0]['title']);
        foreach ($docs as $d) {
            $src = (string) DocsService::source($d['name']);
            $html = DocsService::render($src);
            $this->assertNotSame('', $html, $d['name']);
            $this->assertStringNotContainsString('<script', $html, $d['name']);
            if (preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $src, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    if (preg_match('~^(?:\.\./)?(?:docs/)?([A-Za-z0-9_-]+)\.md(#[A-Za-z0-9_-]*)?$~', $m[2], $mm)) {
                        $this->assertNotNull(
                            DocsService::source($mm[1]),
                            "Broken doc link {$m[2]} in {$d['name']}.md"
                        );
                    }
                }
            }
        }
        $this->assertNotNull(DocsService::source('INSTALL'));
        $this->assertNotNull(DocsService::source('ARCHITECTURE'));
        $this->assertNotNull(DocsService::source('CHANGELOG'));
        $this->assertNull(DocsService::source('../web/config'));
        $this->assertNull(DocsService::source('nope'));
        $found = DocsService::search('aipbx-selftest');
        $this->assertContains('system-map', array_column($found, 'name'));
        $this->assertSame('aipbx-selftest — Runs every health check', mb_substr(DocsService::plain('| `aipbx-selftest` | Runs every health check now |'), 0, 40));
        $this->assertSame('Start with aipbx-selftest and the *Health* page', DocsService::plain('**Start with** `aipbx-selftest` and the *Health* page'));
    }
}
