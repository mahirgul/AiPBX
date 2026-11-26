<?php

declare(strict_types=1);

require_once __DIR__ . '/AiGateway.php';

/**
 * Faz 8: TXT-only Knowledge Base (RAG) service.
 * Stores collections, versioned TXT documents, and chunk embeddings in MariaDB,
 * enforces role permissions before retrieval, and grounds answers with citations.
 */
final class AiKnowledgeService
{
    public const MAX_TXT_BYTES = 2097152; // 2 MB
    public const CHUNK_CHARS = 600;
    public const CHUNK_OVERLAP = 80;
    public const MIN_SCORE = 0.18;
    public const TEXT_FILE = 'knowledge.txt';
    public const MAX_TEXT_CHARS = 200000;

    /** @var callable|null */
    public static $embedder = null;

    /** @var callable|null */
    public static $chatter = null;

    /** @return list<array<string, mixed>> */
    public static function collections(): array
    {
        $db = getDB();
        return $db->query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM ai_knowledge_docs d WHERE d.collection_id = c.id AND d.is_active = 1) AS doc_count,
                    (SELECT COUNT(*) FROM ai_knowledge_chunks k WHERE k.collection_id = c.id AND k.index_version = c.active_index_version) AS chunk_count
             FROM ai_knowledge_collections c
             ORDER BY c.id ASC"
        )->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function collection(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM ai_knowledge_collections WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param list<string> $allowedRoles */
    public static function saveCollection(int $id, string $name, string $description = '', array $allowedRoles = [], string $embeddingBinding = '', ?string $assistantLanguage = null): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Collection name is required (max 120 chars).');
        }
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-') ?: ('col-' . bin2hex(random_bytes(3)));
        $slug = substr($slug, 0, 72);

        $roles = array_values(array_unique(array_filter(array_map('trim', $allowedRoles), fn($r) => $r !== '')));
        $rolesJson = $roles !== [] ? (json_encode($roles, JSON_UNESCAPED_UNICODE) ?: '[]') : null;
        $binding = trim($embeddingBinding) !== '' ? trim($embeddingBinding) : null;
        // null keeps the Assistant language of an existing collection (it is chosen on the Assistant settings page)
        $lang = preg_match('/^[a-z]{2,3}$/', (string) $assistantLanguage) ? $assistantLanguage : null;
        if ($assistantLanguage === null && $id > 0) {
            $lang = self::collection($id)['assistant_language'] ?? null;
        }
        $now = date('Y-m-d H:i:s');
        $db = getDB();

        if ($id > 0) {
            $prev = self::collection($id);
            if ($prev === null) {
                throw new \RuntimeException('Collection not found.');
            }
            $st = $db->prepare(
                'UPDATE ai_knowledge_collections SET name = ?, description = ?, allowed_roles_json = ?, assistant_language = ?, updated_at = ? WHERE id = ?'
            );
            $st->execute([$name, mb_substr(trim($description), 0, 255), $rolesJson, $lang, $now, $id]);
            // A new embedding model takes over only with an index built by it: if building
            // fails, the collection keeps its old model and its old, searchable index.
            if ((string) ($prev['embedding_binding'] ?? '') !== (string) ($binding ?? '')) {
                self::reindexCollection($id, $binding ?? '');
            }
            return $id;
        }

        // Ensure unique slug
        $chk = $db->prepare('SELECT id FROM ai_knowledge_collections WHERE slug = ? LIMIT 1');
        $chk->execute([$slug]);
        if ($chk->fetchColumn() !== false) {
            $slug = substr($slug, 0, 64) . '-' . bin2hex(random_bytes(3));
        }

        $st = $db->prepare(
            'INSERT INTO ai_knowledge_collections (name, slug, description, allowed_roles_json, embedding_binding, assistant_language, active_index_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $st->execute([$name, $slug, mb_substr(trim($description), 0, 255), $rolesJson, $binding, $lang, $now, $now]);
        return (int) $db->lastInsertId();
    }

    /** The one text of a collection: the content of its single document ('' when none yet). */
    public static function text(int $collectionId): string
    {
        $st = getDB()->prepare('SELECT content_text FROM ai_knowledge_docs WHERE collection_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
        $st->execute([$collectionId]);
        return (string) ($st->fetchColumn() ?: '');
    }

    /**
     * Saves a knowledge base: name, a description (the AI chooses a base by it), its one text and the account that
     * builds the meaning index (empty: keyword search). Only chunks of a changed text are built again by the
     * document update; a changed account re-indexes everything.
     */
    public static function saveWithText(int $id, string $name, string $description, string $text, string $embeddingBinding = '', array $roles = ['*']): int
    {
        if (trim($description) === '' || mb_strlen(trim($description)) > 200) {
            throw new \InvalidArgumentException(t('ai_knowledge.err_description'));
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (mb_strlen($text) > self::MAX_TEXT_CHARS || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException(t('ai_knowledge.err_text'));
        }
        $id = self::saveCollection($id, $name, $description, $roles, $embeddingBinding);
        $st = getDB()->prepare('SELECT id, version, sha256 FROM ai_knowledge_docs WHERE collection_id = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$id]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if ($text === '') {
            if ($doc) {
                self::deleteDocument((int) $doc['id']);
            }
        } elseif (!$doc || $doc['sha256'] !== hash('sha256', $text)) {
            self::addDocument($id, $name, self::TEXT_FILE, $text, $doc ? (int) $doc['id'] : 0, $doc ? (int) $doc['version'] : null);
        }
        return $id;
    }

    /** The short AiPBX guides shipped for the PBX Assistant: slug => [language, name, file]. */
    public const BUILT_IN = [
        'aipbx-help-en' => ['en', 'AiPBX Help (English)', 'aipbx-help-en.txt'],
        'aipbx-help-tr' => ['tr', 'AiPBX Yardım (Türkçe)', 'aipbx-help-tr.txt'],
    ];

    /**
     * Creates the built-in guide collections once (a deleted one stays deleted) and
     * refreshes their document when the shipped file changed (an update).
     */
    public static function ensureBuiltIn(): void
    {
        $dir = dirname(__DIR__, 4) . '/plugins/ai-knowledge/guides/';
        $db = getDB();
        $seeded = json_decode((string) getSystemSetting('ai_knowledge_builtin', '[]'), true) ?: [];
        $shipped = json_decode((string) getSystemSetting('ai_knowledge_builtin_sha', '{}'), true) ?: [];
        $changed = false;
        foreach (self::BUILT_IN as $slug => [$lang, $name, $file]) {
            $raw = @file_get_contents($dir . $file);
            if ($raw === false) {
                continue;
            }
            $st = $db->prepare('SELECT id FROM ai_knowledge_collections WHERE slug = ? LIMIT 1');
            $st->execute([$slug]);
            $id = (int) $st->fetchColumn();
            if ($id === 0) {
                if (in_array($slug, $seeded, true)) {
                    continue;          // the administrator removed it
                }
                $db->prepare('INSERT INTO ai_knowledge_collections (name, slug, description, allowed_roles_json, embedding_binding, assistant_language, active_index_version, created_at, updated_at)
                              VALUES (?, ?, ?, ?, NULL, ?, 1, NOW(), NOW())')
                   ->execute([$name, $slug, 'Built-in guide of the PBX Assistant', '["*"]', $lang]);
                $id = (int) $db->lastInsertId();
                $seeded[] = $slug;
                $changed = true;
            }
            $sha = hash('sha256', self::validateTxt($file, $raw));
            if (($shipped[$slug] ?? '') === $sha) {
                continue;              // this version is already in place (or was edited on purpose)
            }
            $st = $db->prepare('SELECT id, sha256 FROM ai_knowledge_docs WHERE collection_id = ? AND filename = ? LIMIT 1');
            $st->execute([$id, $file]);
            $doc = $st->fetch();
            if (!$doc) {
                self::addDocument($id, $name, $file, $raw);
            } elseif (!isset($shipped[$slug]) || $doc['sha256'] === $shipped[$slug]) {
                // a new shipped version replaces the guide only when the administrator did not edit it
                self::addDocument($id, $name, $file, $raw, (int) $doc['id']);
            }
            $shipped[$slug] = $sha;
            $changed = true;
        }
        if ($changed) {
            setSystemSetting('ai_knowledge_builtin', (string) json_encode(array_values(array_unique($seeded))));
            setSystemSetting('ai_knowledge_builtin_sha', (string) json_encode($shipped));
        }
    }

    /**
     * The collections the PBX Assistant uses for a portal language: those marked for
     * it, else the English ones.
     * @return list<int>
     */
    public static function assistantCollections(string $language): array
    {
        $ids = static function (string $lang): array {
            $st = getDB()->prepare('SELECT id FROM ai_knowledge_collections WHERE assistant_language = ?');
            $st->execute([$lang]);
            return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        };
        return $ids($language) ?: ($language !== 'en' ? $ids('en') : []);
    }

    /**
     * Which collections the PBX Assistant uses, and for which portal language.
     * @param array<int|string, string> $languages collection id => language code ('' = not used)
     */
    public static function setAssistantLanguages(array $languages): void
    {
        $st = getDB()->prepare('UPDATE ai_knowledge_collections SET assistant_language = ?, updated_at = NOW() WHERE id = ?');
        foreach ($languages as $id => $lang) {
            $lang = (string) $lang;
            $st->execute([isset(UI_LANGUAGES[$lang]) ? $lang : null, (int) $id]);
        }
    }

    public static function deleteCollection(int $id): void
    {
        $st = getDB()->prepare('DELETE FROM ai_knowledge_collections WHERE id = ?');
        $st->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public static function documents(?int $collectionId = null): array
    {
        $db = getDB();
        if ($collectionId !== null && $collectionId > 0) {
            $st = $db->prepare(
                'SELECT d.*, c.name AS collection_name
                 FROM ai_knowledge_docs d
                 JOIN ai_knowledge_collections c ON c.id = d.collection_id
                 WHERE d.collection_id = ?
                 ORDER BY d.id DESC'
            );
            $st->execute([$collectionId]);
            return $st->fetchAll() ?: [];
        }
        return $db->query(
            'SELECT d.*, c.name AS collection_name
             FROM ai_knowledge_docs d
             JOIN ai_knowledge_collections c ON c.id = d.collection_id
             ORDER BY d.id DESC'
        )->fetchAll() ?: [];
    }

    public static function validateTxt(string $filename, string $rawContent): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            throw new \InvalidArgumentException('Only .txt files are supported.');
        }
        if ($rawContent === '' || strlen($rawContent) > self::MAX_TXT_BYTES) {
            throw new \InvalidArgumentException('TXT file must be between 1 byte and 2 MB.');
        }
        if (str_contains($rawContent, "\0")) {
            throw new \InvalidArgumentException('Binary content is not allowed in TXT documents.');
        }
        if (!mb_check_encoding($rawContent, 'UTF-8')) {
            throw new \InvalidArgumentException('TXT file must be valid UTF-8.');
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", $rawContent);
        $trimmed = trim($normalized);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('TXT document is empty.');
        }
        return $trimmed;
    }

    /** @return list<string> */
    /** "Q: …" + "A: …" (or Turkish "S:" + "C:") in one paragraph: one question and its answer. */
    private const QA = '/^\s*(?:Q|S):\s*(.+?)\n\s*(?:A|C):\s*(.+)$/su';

    /**
     * Paragraphs packed up to $maxChars; a question-and-answer paragraph is a chunk of its own,
     * a paragraph longer than $maxChars is cut with overlap.
     * @return list<string>
     */
    public static function chunkText(string $text, int $maxChars = self::CHUNK_CHARS, int $overlap = self::CHUNK_OVERLAP): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $maxChars = max(120, $maxChars);
        $chunks = [];
        $buf = '';
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $long = mb_strlen($para, 'UTF-8') > $maxChars;
            $own = $long || preg_match(self::QA, $para);
            if ($buf !== '' && ($own || mb_strlen($buf . "\n\n" . $para, 'UTF-8') > $maxChars)) {
                $chunks[] = $buf;
                $buf = '';
            }
            if ($long) {
                array_push($chunks, ...self::chunkLong($para, $maxChars, $overlap));
            } elseif ($own) {
                $chunks[] = $para;
            } else {
                $buf = $buf === '' ? $para : $buf . "\n\n" . $para;
            }
        }
        if ($buf !== '') {
            $chunks[] = $buf;
        }
        return $chunks;
    }

    private static function chunkLong(string $text, int $maxChars = self::CHUNK_CHARS, int $overlap = self::CHUNK_OVERLAP): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }
        $maxChars = max(120, $maxChars);
        $overlap = max(0, min($overlap, (int) ($maxChars / 2)));
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        $pos = 0;
        while ($pos < $len) {
            $slice = mb_substr($text, $pos, $maxChars, 'UTF-8');
            if ($pos + $maxChars < $len) {
                $breakPos = max(
                    (int) (mb_strrpos($slice, "\n\n", 0, 'UTF-8') ?: 0),
                    (int) (mb_strrpos($slice, "\n", 0, 'UTF-8') ?: 0),
                    (int) (mb_strrpos($slice, '. ', 0, 'UTF-8') ?: 0)
                );
                if ($breakPos > (int) ($maxChars * 0.4)) {
                    $slice = mb_substr($slice, 0, $breakPos + 1, 'UTF-8');
                }
            }
            $clean = trim($slice);
            if ($clean !== '') {
                $chunks[] = $clean;
            }
            $step = max(1, mb_strlen($slice, 'UTF-8') - $overlap);
            $pos += $step;
        }
        return $chunks;
    }

    public static function addDocument(int $collectionId, string $title, string $filename, string $rawContent, int $docId = 0, ?int $expectedVersion = null): int
    {
        $col = self::collection($collectionId);
        if ($col === null) {
            throw new \RuntimeException('Collection not found.');
        }
        $text = self::validateTxt($filename, $rawContent);
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 160) : mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, 160);
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'document.txt';
        $sha = hash('sha256', $text);
        $now = date('Y-m-d H:i:s');
        $db = getDB();
        if ($docId > 0) {
            $st = $db->prepare('SELECT version FROM ai_knowledge_docs WHERE id = ? AND collection_id = ? LIMIT 1');
            $st->execute([$docId, $collectionId]);
            $version = $st->fetchColumn();
            if ($version === false) {
                throw new \RuntimeException('Document not found.');
            }
            $expectedVersion ??= (int) $version;
            if ($expectedVersion !== (int) $version) {
                throw new \RuntimeException(t('ai_knowledge.err_document_changed'));
            }
        }

        // Embeddings first: when the provider fails, nothing has changed and the old
        // text stays searchable. Then the document and its chunks change together.
        $rows = self::buildChunks((string) ($col['embedding_binding'] ?? ''), $text);
        $db->beginTransaction();
        try {
            self::assertCurrentIndex($db, $col);
            if ($docId > 0) {
                $st = $db->prepare(
                    'UPDATE ai_knowledge_docs SET title = ?, filename = ?, content_text = ?, sha256 = ?, version = version + 1, is_active = 1, updated_at = ?
                     WHERE id = ? AND collection_id = ? AND version = ?'
                );
                $st->execute([$title, $safeName, $text, $sha, $now, $docId, $collectionId, $expectedVersion]);
                if ($st->rowCount() !== 1) {
                    throw new \RuntimeException(t('ai_knowledge.err_document_changed'));
                }
            } else {
                $db->prepare(
                    'INSERT INTO ai_knowledge_docs (collection_id, title, filename, content_text, sha256, version, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)'
                )->execute([$collectionId, $title, $safeName, $text, $sha, $now, $now]);
                $docId = (int) $db->lastInsertId();
            }
            self::writeChunks($collectionId, $docId, (int) $col['active_index_version'], $rows, true);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $docId;
    }

    /** @return array<string, mixed>|null */
    public static function document(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM ai_knowledge_docs WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    public static function setDocumentActive(int $docId, bool $active): void
    {
        $doc = self::document($docId);
        if ($doc === null || !$active) {
            $st = getDB()->prepare('UPDATE ai_knowledge_docs SET is_active = ?, updated_at = ? WHERE id = ?');
            $st->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $docId]);
            return;
        }
        // An older rebuild may have removed this inactive document's chunks.
        $db = getDB();
        $col = self::collection((int) $doc['collection_id']);
        if ($col === null) {
            throw new \RuntimeException('Collection not found.');
        }
        $chunk = $db->prepare('SELECT 1 FROM ai_knowledge_chunks WHERE doc_id = ? AND index_version = ? LIMIT 1');
        $chunk->execute([$docId, $col['active_index_version']]);
        $rows = $chunk->fetchColumn() ? null : self::buildChunks((string) ($col['embedding_binding'] ?? ''), (string) $doc['content_text']);
        $db->beginTransaction();
        try {
            self::assertCurrentIndex($db, $col);
            $current = $db->prepare('SELECT version FROM ai_knowledge_docs WHERE id = ? FOR UPDATE');
            $current->execute([$docId]);
            if ((int) $current->fetchColumn() !== (int) $doc['version']) {
                throw new \RuntimeException('The document changed while it was being indexed. Try again.');
            }
            if ($rows !== null) {
                self::writeChunks((int) $col['id'], $docId, (int) $col['active_index_version'], $rows, true);
            }
            $db->prepare('UPDATE ai_knowledge_docs SET is_active = 1, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $docId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function deleteDocument(int $docId): void
    {
        $st = getDB()->prepare('DELETE FROM ai_knowledge_docs WHERE id = ?');
        $st->execute([$docId]);
    }

    /**
     * Rebuilds all active documents of a collection under a new index version and swaps on
     * success. With $binding the collection moves to that embedding model in the same swap.
     * The embeddings are computed before anything is written: a provider error leaves the
     * collection, its model and its active index as they were.
     */
    public static function reindexCollection(int $collectionId, ?string $binding = null): int
    {
        $col = self::collection($collectionId);
        if ($col === null) {
            throw new \RuntimeException('Collection not found.');
        }
        $db = getDB();
        $newVer = (int) $col['active_index_version'] + 1;
        $binding = $binding ?? (string) ($col['embedding_binding'] ?? '');

        $st = $db->prepare('SELECT id, version, filename, content_text FROM ai_knowledge_docs WHERE collection_id = ? AND is_active = 1 ORDER BY id ASC');
        $st->execute([$collectionId]);
        $docs = $st->fetchAll() ?: [];
        $built = [];
        foreach ($docs as $d) {
            $built[(int) $d['id']] = self::buildChunks($binding, (string) $d['content_text']);
        }

        $db->beginTransaction();
        try {
            self::assertCurrentIndex($db, $col);
            $check = $db->prepare('SELECT id, version FROM ai_knowledge_docs WHERE collection_id = ? AND is_active = 1 ORDER BY id ASC FOR UPDATE');
            $check->execute([$collectionId]);
            if ($check->fetchAll(PDO::FETCH_KEY_PAIR) !== array_column($docs, 'version', 'id')) {
                throw new \RuntimeException('Documents changed while the collection was being indexed. Try again.');
            }
            foreach ($built as $docId => $rows) {
                self::writeChunks($collectionId, $docId, $newVer, $rows, false);
            }
            $upd = $db->prepare('UPDATE ai_knowledge_collections SET active_index_version = ?, embedding_binding = ?, updated_at = ? WHERE id = ?');
            $upd->execute([$newVer, trim($binding) !== '' ? trim($binding) : null, date('Y-m-d H:i:s'), $collectionId]);
            $del = $db->prepare('DELETE FROM ai_knowledge_chunks WHERE collection_id = ? AND index_version < ?');
            $del->execute([$collectionId, $newVer]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return count($docs);
    }

    /** Locks the collection before installing chunks built outside the transaction. */
    private static function assertCurrentIndex(PDO $db, array $snapshot): void
    {
        $st = $db->prepare('SELECT active_index_version, embedding_binding FROM ai_knowledge_collections WHERE id = ? FOR UPDATE');
        $st->execute([$snapshot['id']]);
        $current = $st->fetch(PDO::FETCH_ASSOC);
        if (!$current || (int) $current['active_index_version'] !== (int) $snapshot['active_index_version']
            || $current['embedding_binding'] !== $snapshot['embedding_binding']) {
            throw new \RuntimeException('The collection changed while it was being indexed. Try again.');
        }
    }

    /**
     * The chunks of a text with their embeddings (none without a model). Throws when the
     * provider fails; writes nothing.
     *
     * @return list<array{0: string, 1: ?string, 2: ?string, 3: int}> chunk, vector JSON, model, dimensions
     */
    private static function buildChunks(string $binding, string $text): array
    {
        $binding = trim($binding);
        $rows = [];
        foreach (self::chunkText($text) as $chunk) {
            $vecJson = $modelRef = null;
            $dims = 0;
            if ($binding !== '') {
                $emb = self::computeEmbedding($binding, $chunk);
                if ($emb['embedding'] !== []) {
                    $vecJson = json_encode($emb['embedding']) ?: null;
                    $modelRef = $emb['resolved_model_ref'];
                    $dims = $emb['dimensions'];
                }
            }
            $rows[] = [$chunk, $vecJson, $modelRef, $dims];
        }
        return $rows;
    }

    /** @param list<array{0: string, 1: ?string, 2: ?string, 3: int}> $rows */
    private static function writeChunks(int $colId, int $docId, int $indexVersion, array $rows, bool $replaceDocChunks): void
    {
        $db = getDB();
        if ($replaceDocChunks) {
            $db->prepare('DELETE FROM ai_knowledge_chunks WHERE doc_id = ? AND index_version = ?')->execute([$docId, $indexVersion]);
        }
        $ins = $db->prepare(
            'INSERT INTO ai_knowledge_chunks (collection_id, doc_id, index_version, chunk_index, chunk_text, embedding_json, model_ref, dimensions, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $idx => [$chunk, $vecJson, $modelRef, $dims]) {
            $ins->execute([$colId, $docId, $indexVersion, $idx, $chunk, $vecJson, $modelRef, $dims, $now]);
        }
    }

    /** @return array{embedding: list<float>, dimensions: int, resolved_model_ref: string} */
    private static function computeEmbedding(string $binding, string $text, string $feature = 'knowledge'): array
    {
        if (self::$embedder !== null) {
            $res = (self::$embedder)($binding, $text);
            $vec = array_map('floatval', (array) ($res['embedding'] ?? []));
            return [
                'embedding' => $vec,
                'dimensions' => (int) ($res['dimensions'] ?? count($vec)),
                'resolved_model_ref' => (string) ($res['resolved_model_ref'] ?? $binding),
            ];
        }
        $res = AiGateway::embed($binding, $text, $feature);
        return [
            'embedding' => $res['embedding'],
            'dimensions' => $res['dimensions'],
            'resolved_model_ref' => $res['resolved_model_ref'],
        ];
    }

    /**
     * Checks collection role access BEFORE retrieving chunks.
     * @param array<string, mixed> $col
     */
    public static function canRoleAccess(array $col, string $role): bool
    {
        if ($role === 'admin') {
            return true;
        }
        $raw = (string) ($col['allowed_roles_json'] ?? '');
        if ($raw === '') {
            return false;
        }
        $roles = json_decode($raw, true);
        return is_array($roles) && (in_array('*', $roles, true) || in_array($role, $roles, true));
    }

    /**
     * Permission-filtered hybrid (cosine + lexical) search.
     * @return array{hits: list<array{doc_id: int, doc_title: string, collection_id: int, collection_name: string, chunk_text: string, score: float}>, grounded: bool}
     */
    public static function search(string $query, string $role = 'admin', int|array|null $collectionId = null, int $topK = 3, float $minScore = self::MIN_SCORE, string $feature = 'knowledge'): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['hits' => [], 'grounded' => false];
        }
        $db = getDB();
        $cols = self::collections();
        $allowedCols = [];
        foreach ($cols as $c) {
            $cid = (int) $c['id'];
            if (is_array($collectionId) ? !in_array($cid, $collectionId, true) : ($collectionId !== null && $collectionId > 0 && $cid !== $collectionId)) {
                continue;
            }
            if (self::canRoleAccess($c, $role)) {
                $allowedCols[$cid] = $c;
            }
        }
        if ($allowedCols === []) {
            return ['hits' => [], 'grounded' => false];
        }

        // Compute query embedding once per unique active binding in allowed collections.
        $queryVectors = [];
        foreach ($allowedCols as $c) {
            $b = trim((string) ($c['embedding_binding'] ?? ''));
            if ($b !== '' && !isset($queryVectors[$b])) {
                try {
                    $queryVectors[$b] = self::computeEmbedding($b, $query, $feature);
                } catch (\Throwable) {
                    // Fall back to lexical scoring if query embedding fails at search time.
                    $queryVectors[$b] = ['embedding' => [], 'dimensions' => 0, 'resolved_model_ref' => $b];
                }
            }
        }

        $qTokens = self::tokenize($query);
        $hits = [];

        $st = $db->prepare(
            'SELECT k.collection_id, k.doc_id, k.chunk_text, k.embedding_json, k.model_ref, k.dimensions,
                    d.title AS doc_title
             FROM ai_knowledge_chunks k
             JOIN ai_knowledge_docs d ON d.id = k.doc_id
             WHERE k.collection_id = ? AND k.index_version = ? AND d.is_active = 1'
        );

        foreach ($allowedCols as $cid => $c) {
            $st->execute([$cid, (int) $c['active_index_version']]);
            $b = trim((string) ($c['embedding_binding'] ?? ''));
            $qv = $b !== '' ? ($queryVectors[$b] ?? null) : null;

            while ($row = $st->fetch()) {
                $chunkText = (string) $row['chunk_text'];
                $lex = self::lexicalScore($qTokens, $chunkText);
                $vecScore = null;
                // Vectors of two models are not comparable even at the same size.
                if ($qv !== null && $qv['dimensions'] > 0 && (int) $row['dimensions'] === $qv['dimensions'] && !empty($row['embedding_json'])
                    && (string) $row['model_ref'] === (string) $qv['resolved_model_ref']) {
                    $cVec = json_decode((string) $row['embedding_json'], true);
                    if (is_array($cVec) && count($cVec) === $qv['dimensions']) {
                        $vecScore = max(0.0, self::cosine($qv['embedding'], $cVec));
                    }
                }
                $score = $vecScore !== null ? (0.7 * $vecScore + 0.3 * $lex) : $lex;
                if ($score >= $minScore) {
                    $hits[] = [
                        'doc_id' => (int) $row['doc_id'],
                        'doc_title' => (string) $row['doc_title'],
                        'collection_id' => $cid,
                        'collection_name' => (string) $c['name'],
                        'chunk_text' => $chunkText,
                        'score' => round($score, 4),
                    ];
                }
            }
        }

        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($hits, 0, max(1, $topK));
        return ['hits' => $top, 'grounded' => $top !== []];
    }

    /**
     * Answers a question strictly from permitted TXT chunks; refuses to guess if no chunks match.
     * @return array{answer: string, grounded: bool, sources: list<array<string, mixed>>}
     */
    public static function askWithContext(string $question, string $role = 'admin', int|array|null $collectionId = null, string $llmBinding = '', string $feature = 'knowledge'): array
    {
        $res = self::search($question, $role, $collectionId, 3, self::MIN_SCORE, $feature);
        if (!$res['grounded']) {
            return [
                'answer' => t('ai_knowledge.insufficient_info', 'No matching information was found in the permitted knowledge base.'),
                'grounded' => false,
                'sources' => [],
            ];
        }

        $direct = self::directAnswer($res);
        if ($direct !== null) {
            return $direct;
        }

        $lang = function_exists('getUserLanguage') ? (string) getUserLanguage() : 'en';
        $langName = defined('UI_LANGUAGES') ? (UI_LANGUAGES[$lang] ?? 'English') : 'English';
        $ctxLines = [];
        foreach ($res['hits'] as $i => $h) {
            $n = $i + 1;
            $ctxLines[] = "[{$n}] ({$h['doc_title']}): {$h['chunk_text']}";
        }
        $prompt = "Answer the user's question ONLY using the numbered knowledge base excerpts below. "
            . "Write the answer in {$langName}, in at most three short sentences, and cite the source number like [1]. "
            . "Do not explain your reasoning and do not translate menu names; copy them as written. "
            . "If the excerpts do not contain the answer, reply strictly that the information is not in the knowledge base.\n\n"
            . "Excerpts:\n" . implode("\n\n", $ctxLines) . "\n\nQuestion: {$question}";

        if (self::$chatter !== null) {
            $answer = (string) (self::$chatter)($llmBinding, $prompt);
        } else {
            if ($llmBinding === '') {
                throw new \RuntimeException('Select a language model to answer questions.');
            }
            $answer = AiGateway::chat($llmBinding, $prompt, $feature, 250);
        }

        return [
            'answer' => trim($answer),
            'grounded' => true,
            'sources' => $res['hits'],
        ];
    }

    /**
     * The best match is one question of a guide: its own answer, no model needed.
     * @param array{hits: list<array<string, mixed>>, grounded: bool} $res a search() result
     * @return array{answer: string, grounded: bool, sources: list<array<string, mixed>>}|null
     */
    public static function directAnswer(array $res): ?array
    {
        $top = $res['hits'][0] ?? null;
        if ($top === null || $top['score'] < 0.6 || !preg_match(self::QA, (string) $top['chunk_text'], $qa)) {
            return null;
        }
        return ['answer' => trim($qa[2]), 'grounded' => true, 'sources' => [$top]];
    }

    /** @return list<string> */
    private static function tokenize(string $text): array
    {
        $low = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $low, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($parts, fn($w) => mb_strlen($w, 'UTF-8') >= 2)));
    }

    /** @param list<string> $qTokens */
    private static function lexicalScore(array $qTokens, string $chunk): float
    {
        if ($qTokens === []) {
            return 0.0;
        }
        $cLow = mb_strtolower($chunk, 'UTF-8');
        $matched = 0;
        foreach ($qTokens as $tok) {
            if (str_contains($cLow, $tok)) {
                $matched++;
            }
        }
        return $matched / count($qTokens);
    }

    /**
     * @param list<float> $a
     * @param list<float|int> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $va) {
            $vb = (float) ($b[$i] ?? 0.0);
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
