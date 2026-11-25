<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/doc_links.php';
require_once dirname(__DIR__, 2) . '/src/services/DocsService.php';

final class DocLinksTest extends TestCase
{
    public function testEveryLinkNamesAnExistingDocumentAndHeading(): void
    {
        foreach (DOC_LINKS as $page => $target) {
            [$doc, $anchor] = array_pad(explode('#', $target, 2), 2, '');
            $source = DocsService::source($doc);
            $this->assertNotNull($source, "$page: docs/$doc.md does not exist");
            if ($anchor !== '') {
                $anchors = [];
                foreach (explode("\n", (string) $source) as $line) {
                    if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                        $anchors[] = DocsService::slug($m[1]);
                    }
                }
                $this->assertContains($anchor, $anchors, "$page: no heading #$anchor in docs/$doc.md");
            }
        }
    }

    public function testAdministratorsReadTheLocalHelpOthersTheWebsite(): void
    {
        $role = $_SESSION['user_role'] ?? null;
        try {
            $_SESSION['user_role'] = 'admin';
            $this->assertStringContainsString('href="/help?doc=number-normalization#incoming-call"', uiDocLink('inbound_normalization'));
            $_SESSION['user_role'] = 'user';
            $this->assertStringContainsString('href="https://aipbx.bid/docs/queues#agent-workflow" target="_blank"', uiDocLink('cc_agent'));
            $this->assertSame('', uiDocLink('ui_kit'));
        } finally {
            if ($role === null) { unset($_SESSION['user_role']); } else { $_SESSION['user_role'] = $role; }
        }
    }
}
