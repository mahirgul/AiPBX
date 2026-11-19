<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ai/AiKnowledgeService.php';

final class AiPhase8KnowledgeTest extends TestCase
{
    protected function setUp(): void
    {
        AiKnowledgeService::$embedder = null;
        AiKnowledgeService::$chatter = null;
        $db = getDB();
        $db->exec('DELETE FROM ai_knowledge_chunks');
        $db->exec('DELETE FROM ai_knowledge_docs');
        $db->exec('DELETE FROM ai_knowledge_collections');
    }

    protected function tearDown(): void
    {
        AiKnowledgeService::$embedder = null;
        AiKnowledgeService::$chatter = null;
        $db = getDB();
        $db->exec('DELETE FROM ai_knowledge_chunks');
        $db->exec('DELETE FROM ai_knowledge_docs');
        $db->exec('DELETE FROM ai_knowledge_collections');
    }

    public function testTxtValidationRejectsNonTxtAndBinary(): void
    {
        $this->assertSame("Hello\nWorld", AiKnowledgeService::validateTxt('faq.txt', "Hello\r\nWorld\n"));

        foreach ([
            ['doc.pdf', '%PDF-1.4'],
            ['doc.docx', 'PK'],
            ['bin.txt', "abc\0def"],
            ['empty.txt', "   \n  "],
        ] as [$name, $raw]) {
            try {
                AiKnowledgeService::validateTxt($name, $raw);
                $this->fail("Expected InvalidArgumentException for {$name}");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testEmbedsIndexesSearchesEnforcesRolesAndReindexesAtomically(): void
    {
        AiKnowledgeService::$embedder = static function (string $binding, string $text): array {
            $low = mb_strtolower($text, 'UTF-8');
            $v = [
                str_contains($low, 'garanti') || str_contains($low, 'warranty') ? 1.0 : 0.0,
                str_contains($low, 'fatura') || str_contains($low, 'invoice') ? 1.0 : 0.0,
            ];
            return ['embedding' => $v, 'dimensions' => 2, 'resolved_model_ref' => $binding];
        };
        AiKnowledgeService::$chatter = static fn(string $binding, string $prompt): string => 'Garanti suresi 2 yildir [1].';

        $publicCol = AiKnowledgeService::saveCollection(0, 'Musteri SSS', 'Public', ['user', 'cc_agent'], 'local:e5-small-multilingual');
        $adminCol = AiKnowledgeService::saveCollection(0, 'Ic Politika', 'Admin only', [], 'local:e5-small-multilingual');

        $doc1 = AiKnowledgeService::addDocument($publicCol, 'Garanti Sartlari', 'garanti.txt', 'Tum cihazlarda garanti suresi 2 yildir.');
        AiKnowledgeService::addDocument($adminCol, 'Yonetici Notu', 'admin.txt', 'Ozel fatura ve garanti indirimi yalniz yoneticilere aciktir.');

        // Role 'user' sees only publicCol, never adminCol
        $userRes = AiKnowledgeService::search('garanti suresi', 'user');
        $this->assertTrue($userRes['grounded']);
        $this->assertCount(1, $userRes['hits']);
        $this->assertSame($publicCol, $userRes['hits'][0]['collection_id']);

        // Grounded answer cites source; unknown topic refuses
        $ans = AiKnowledgeService::askWithContext('garanti suresi nedir?', 'user', $publicCol, 'cloud:openai');
        $this->assertTrue($ans['grounded']);
        $this->assertStringContainsString('[1]', $ans['answer']);

        $unknown = AiKnowledgeService::askWithContext('uzay gemisi motoru nasil calisir?', 'user', $publicCol, 'cloud:openai');
        $this->assertFalse($unknown['grounded']);
        $this->assertSame([], $unknown['sources']);

        // Deactivating document immediately excludes it
        AiKnowledgeService::setDocumentActive($doc1, false);
        $afterDisable = AiKnowledgeService::search('garanti suresi', 'user');
        $this->assertFalse($afterDisable['grounded']);

        // Re-enable and atomic reindex bumps active_index_version cleanly
        AiKnowledgeService::setDocumentActive($doc1, true);
        $reindexed = AiKnowledgeService::reindexCollection($publicCol);
        $this->assertSame(1, $reindexed);
        $colRow = AiKnowledgeService::collection($publicCol);
        $this->assertSame(2, (int) $colRow['active_index_version']);
    }

    public function testStaleDocumentEditCannotReplaceNewerContentOrChunks(): void
    {
        $colId = AiKnowledgeService::saveCollection(0, 'Edit conflict', embeddingBinding: 'local:test');
        AiKnowledgeService::$embedder = static fn(): array => ['embedding' => [1.0]];
        $docId = AiKnowledgeService::addDocument($colId, 'Policy', 'policy.txt', 'Original');

        AiKnowledgeService::$embedder = static function (string $binding, string $text) use ($colId, $docId): array {
            if ($text === 'Slow edit') {
                AiKnowledgeService::addDocument($colId, 'Policy', 'policy.txt', 'Newer edit', $docId, 1);
            }
            return ['embedding' => [1.0]];
        };

        try {
            AiKnowledgeService::addDocument($colId, 'Policy', 'policy.txt', 'Slow edit', $docId, 1);
            $this->fail('The document changed while the slow edit was being indexed.');
        } catch (\RuntimeException $e) {
            $this->assertSame(t('ai_knowledge.err_document_changed'), $e->getMessage());
        }

        $this->assertSame('Newer edit', AiKnowledgeService::document($docId)['content_text']);
        $st = getDB()->prepare('SELECT chunk_text FROM ai_knowledge_chunks WHERE doc_id = ?');
        $st->execute([$docId]);
        $this->assertSame(['Newer edit'], $st->fetchAll(PDO::FETCH_COLUMN));

        AiKnowledgeService::$embedder = static function (): never {
            throw new \LogicException('A stale form must be rejected before indexing.');
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(t('ai_knowledge.err_document_changed'));
        AiKnowledgeService::addDocument($colId, 'Policy', 'policy.txt', 'Stale form', $docId, 1);
    }
}
