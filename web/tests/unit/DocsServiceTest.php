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
            $html = DocsService::render((string) DocsService::source($d['name']));
            $this->assertNotSame('', $html, $d['name']);
            $this->assertStringNotContainsString('<script', $html, $d['name']);
        }
        $this->assertNull(DocsService::source('../web/config'));
        $this->assertNull(DocsService::source('nope'));
        $found = DocsService::search('aipbx-selftest');
        $this->assertContains('system-map', array_column($found, 'name'));
    }
}
