<?php
/** Fixed loopback API for models already installed in the local Ollama runtime. */
require_once dirname(__DIR__) . '/tts/TtsProviders.php';

final class OllamaService
{
    private const URL = 'http://127.0.0.1:11434';

    private static function request(string $method, string $path, ?array $body = null, int $timeout = 3): array
    {
        $r = TtsHttp::request($method, self::URL . $path, ['Content-Type' => 'application/json'],
            $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE), $timeout);
        return TtsHttp::json($r, 'Local Ollama');
    }

    public static function models(): array
    {
        try {
            $tags = self::request('GET', '/api/tags');
        } catch (TtsException $e) {
            return [];
        }
        $out = [];
        foreach ((array) ($tags['models'] ?? []) as $m) {
            $name = (string) ($m['name'] ?? '');
            // Local only: never select Ollama's cloud-proxy models.
            if ($name === '' || str_starts_with($name, 'aipbx-hf-') || strlen($name) > 256 || !empty($m['remote_host']) || !empty($m['remote_model'])
                || preg_match('/(?:-cloud|:cloud)(?:$|:)/i', $name)) {
                continue;
            }
            $out[] = [
                'id' => 'ollama-' . substr(hash('sha256', $name), 0, 32),
                'title' => $name, 'name' => $name, 'kind' => 'llm', 'engine' => 'ollama',
                'state' => 'installed', 'languages' => [], 'license' => 'See model card',
                'license_url' => 'https://ollama.com/library', 'homepage' => 'https://ollama.com/library',
                'download_mb' => (int) ceil((int) ($m['size'] ?? 0) / 1048576),
                'disk_mb' => (int) ceil((int) ($m['size'] ?? 0) / 1048576),
                'memory_mb' => 0, 'progress' => 100, 'error' => null,
                'note' => '', 'gender' => '', 'commercial' => false, 'custom' => true, 'measured' => [],
            ];
        }
        if ($out !== []) {
            try {
                $running = self::request('GET', '/api/ps');
                $names = array_column((array) ($running['models'] ?? []), 'size', 'name');
                foreach ($out as &$model) {
                    if (isset($names[$model['name']])) {
                        $model['state'] = 'ready';
                        $model['memory_mb'] = (int) ceil((int) $names[$model['name']] / 1048576);
                    }
                }
                unset($model);
            } catch (TtsException $e) {
                // Models on disk remain usable even if running-state information is unavailable.
            }
        }
        return $out;
    }

    public static function model(string $id): ?array
    {
        foreach (self::models() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }
        return null;
    }

    public static function chatModels(): array
    {
        return array_values(array_filter(self::models(), static function (array $model): bool {
            try {
                $details = self::request('POST', '/api/show', ['model' => $model['name']]);
                return !isset($details['capabilities']) || in_array('completion', (array) $details['capabilities'], true);
            } catch (TtsException) {
                return false;
            }
        }));
    }

    public static function chat(string $id, string $prompt, int $maxTokens = 300): string
    {
        $model = self::model($id);
        if ($model === null) {
            throw new TtsException('Local Ollama model not found');
        }
        $r = self::request('POST', '/api/chat', [
            'model' => $model['name'], 'messages' => [['role' => 'user', 'content' => $prompt]],
            // Thinking models would spend the whole token budget thinking and answer nothing.
            'stream' => false, 'think' => false, 'options' => ['num_predict' => $maxTokens, 'temperature' => 0],
        ], 180);
        return (string) ($r['message']['content'] ?? '');
    }

    public static function run(string $id, bool $start): void
    {
        $model = self::model($id);
        if ($model === null) {
            throw new TtsException('Local Ollama model not found');
        }
        self::request('POST', '/api/generate', ['model' => $model['name'], 'prompt' => '',
            'keep_alive' => $start ? -1 : 0, 'stream' => false], 180);
    }

    public static function remove(string $id): void
    {
        $model = self::model($id);
        if ($model === null) {
            throw new TtsException('Local Ollama model not found');
        }
        self::request('DELETE', '/api/delete', ['model' => $model['name']], 30);
    }

    /**
     * Cached catalog of downloadable Ollama library models (refreshed only on demand).
     *
     * @return list<array{name:string, title:string, size_label:string, kind:string, description:string}>
     */
    public static function catalog(bool $refresh = false): array
    {
        $cacheFile = sys_get_temp_dir() . '/aipbx_ollama_catalog_v2.json';
        if (!$refresh && is_file($cacheFile)) {
            $decoded = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        $items = [];
        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'user_agent' => 'AiPBX/1.0'],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $html = @file_get_contents('https://ollama.com/library', false, $ctx);
            if (is_string($html) && $html !== '') {
                if (preg_match_all('/<a\b[^>]*href="\/library\/([a-z0-9._-]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
                    $seen = [];
                    foreach ($matches as $m) {
                        $slug = trim($m[1]);
                        if ($slug === '' || isset($seen[$slug])) {
                            continue;
                        }
                        $seen[$slug] = true;
                        $inner = $m[2];
                        $title = $slug;
                        if (preg_match('/<(?:h2|span)[^>]*>([^<]+)<\/(?:h2|span)>/i', $inner, $tm)) {
                            $t = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES, 'UTF-8'));
                            if ($t !== '') {
                                $title = $t;
                            }
                        }
                        $desc = '';
                        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $inner, $dm)) {
                            $desc = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($dm[1]), ENT_QUOTES, 'UTF-8')));
                        }
                        $sizes = [];
                        if (preg_match_all('/<span[^>]*>\s*([0-9]+(?:\.[0-9]+)?b|e[0-9]+b|mini|small|medium|large)\s*<\/span>/i', $inner, $sm)) {
                            $sizes = array_values(array_unique(array_map('strtolower', array_map('trim', $sm[1]))));
                        }
                        $items[] = [
                            'name' => $slug,
                            'title' => $title,
                            'size_label' => $sizes !== [] ? implode(', ', $sizes) : '-',
                            'kind' => str_contains($slug, 'embed') ? 'embedding' : 'llm',
                            'description' => $desc,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to fallback list if offline
        }

        if ($items === []) {
            $items = [
                ['name' => 'llama3.2:3b', 'title' => 'Llama 3.2 (3B)', 'size_label' => '2.0 GB', 'kind' => 'llm', 'description' => 'Meta Llama 3.2 3B'],
                ['name' => 'llama3.1:8b', 'title' => 'Llama 3.1 (8B)', 'size_label' => '4.7 GB', 'kind' => 'llm', 'description' => 'Meta Llama 3.1 8B'],
                ['name' => 'qwen2.5:7b', 'title' => 'Qwen 2.5 (7B)', 'size_label' => '4.7 GB', 'kind' => 'llm', 'description' => 'Alibaba Qwen 2.5 7B'],
                ['name' => 'qwen2.5:3b', 'title' => 'Qwen 2.5 (3B)', 'size_label' => '1.9 GB', 'kind' => 'llm', 'description' => 'Alibaba Qwen 2.5 3B'],
                ['name' => 'gemma2:9b', 'title' => 'Gemma 2 (9B)', 'size_label' => '5.4 GB', 'kind' => 'llm', 'description' => 'Google Gemma 2 9B'],
                ['name' => 'mistral:7b', 'title' => 'Mistral (7B)', 'size_label' => '4.1 GB', 'kind' => 'llm', 'description' => 'Mistral 7B'],
                ['name' => 'phi3:mini', 'title' => 'Phi-3 Mini', 'size_label' => '2.2 GB', 'kind' => 'llm', 'description' => 'Microsoft Phi-3 Mini'],
                ['name' => 'nomic-embed-text', 'title' => 'Nomic Embed Text', 'size_label' => '274 MB', 'kind' => 'embedding', 'description' => 'Text embeddings'],
                ['name' => 'mxbai-embed-large', 'title' => 'MxBai Embed Large', 'size_label' => '670 MB', 'kind' => 'embedding', 'description' => 'Text embeddings'],
            ];
        }

        @file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_UNICODE));
        return $items;
    }

    /**
     * Largest model (billions of parameters) this server runs on its CPU: a small
     * model only (at most 8B), and its ~0.65 GB per billion at Q4 within 30 % of RAM.
     */
    public static function maxParamsB(): float
    {
        $kb = preg_match('/MemTotal:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $m) ? (int) $m[1] : 0;
        return $kb > 0 ? min(8.0, floor($kb / 1048576 * 0.3 / 0.65 * 10) / 10) : 8.0;
    }

    /** A size tag ("3b", "e2b", "270m") that fits maxParamsB(). */
    public static function tagFits(string $tag, float $maxB): bool
    {
        $tag = strtolower(trim($tag));
        if (preg_match('/^e?([0-9]+(?:\.[0-9]+)?)b$/', $tag, $m)) {
            return (float) $m[1] <= $maxB;
        }
        return (bool) preg_match('/^[0-9]+m$/', $tag);
    }

    /**
     * The library catalog with only what runs here: size tags that fit, entries without
     * a known local size (cloud-only) left out; downloaded models always listed.
     * @param list<array<string, mixed>> $catalog
     * @param array<string, array<string, mixed>> $installed name => model
     * @return list<array<string, mixed>>
     */
    public static function runnableCatalog(array $catalog, array $installed): array
    {
        $maxB = self::maxParamsB();
        $byBase = [];
        foreach ($installed as $name => $m) {
            $byBase[strtolower(explode(':', (string) $name)[0])][] = $m;
        }
        $out = [];
        foreach ($catalog as $item) {
            $base = strtolower(explode(':', (string) $item['name'])[0]);
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $item['size_label'])), fn($t) => self::tagFits($t, $maxB)));
            if ($tags === [] && !isset($byBase[$base])) {
                continue;
            }
            $item['size_label'] = $tags !== [] ? implode(', ', $tags) : '-';
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Fetch tag -> disk size map (e.g. ['8b' => '5.2 GB']) from ollama.com/library/<name>/tags.
     * @return array<string, string>
     */
    public static function tags(string $name): array
    {
        $base = strtolower(trim(explode(':', $name)[0]));
        if ($base === '' || strlen($base) > 80 || !preg_match('/^[a-z0-9._-]+$/', $base)) {
            return [];
        }
        $cacheFile = sys_get_temp_dir() . '/aipbx_ollama_tags_v1_' . md5($base) . '.json';
        if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 86400) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'AiPBX/1.0']]);
        $html = @file_get_contents('https://ollama.com/library/' . rawurlencode($base) . '/tags', false, $ctx);
        $map = [];
        if (is_string($html) && $html !== '') {
            $pat = '/' . preg_quote($base, '/') . ':([a-z0-9._-]+).*?([0-9]+(?:\.[0-9]+)?\s*(?:GB|MB|KB))/is';
            if (preg_match_all($pat, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $tag = strtolower($m[1]);
                    if (!isset($map[$tag])) {
                        $map[$tag] = preg_replace('/\s+/', ' ', trim($m[2]));
                    }
                }
            }
        }
        if (!empty($map)) {
            @file_put_contents($cacheFile, json_encode($map, JSON_UNESCAPED_UNICODE));
        }
        return $map;
    }

    public static function pull(string $name): void
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 128 || !preg_match('/^[a-zA-Z0-9._:\/-]+$/', $name)) {
            throw new TtsException('Invalid Ollama model name');
        }
        self::request('POST', '/api/pull', ['name' => $name, 'stream' => false], 600);
    }
}
