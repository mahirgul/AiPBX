<?php

declare(strict_types=1);

/**
 * The tools an AI agent's administrator writes into its instructions, in curly brackets with commas:
 *
 *   {transfer, sales, group:2}                      a transfer target: extension "1001", "queue:<name>", "group:<id>"
 *   {http, orders, https://crm.example.com/o?no={order_no}}
 *   {greeting, 1, tr, Merhaba {caller_name}, hoş geldiniz.}   order, language, text (the text may hold commas)
 *   {waiting, One moment, I am checking.}
 *   {db, order_status}                              a saved database query (External databases → Queries)
 *   {knowledge, Price list}                         a knowledge base, by its name
 *   {pbx, find_person}                              a PBX tool
 *   {barge_in, on}                                  callers may talk over the agent (on|off; default on)
 *   {announcements, off}                            the model writes greeting and waiting phrases itself (on|off; default off)
 *   {language, tr}                                  the agent's own language, for calls whose channel has none (default: the first AI language)
 *   {email, calls@example.com}                      every call's conversation and summary go to this address
 *
 * A bracket without a comma is a variable ({caller_name}) and stays as it is; a bracket whose first word is not one of
 * these words is plain text; "\{" writes a literal bracket. The instructions are the only place where an agent's tools
 * are defined: the form's buttons only insert these brackets. Everything is resolved against the PBX when the agent is
 * saved and again when the configuration is applied.
 *
 * The parser takes what it resolves against as arguments (targets, queries, knowledge bases, PBX tools), so it has no
 * database access of its own.
 */
final class AgentInstructions
{
    public const WORDS = ['transfer', 'http', 'greeting', 'waiting', 'db', 'knowledge', 'pbx', 'barge_in', 'announcements', 'email', 'language'];
    /** Names the model already has as tools. */
    public const RESERVED = ['none', 'knowledge', 'lookup', 'end_call', 'call_transfer', 'get_db_value', 'transfer'];
    public const MAX = ['transfer' => 30, 'http' => 5, 'greeting' => 5, 'waiting' => 5, 'knowledge' => 8, 'db' => 10, 'pbx' => 20,
        'barge_in' => 1, 'announcements' => 1, 'email' => 1, 'language' => 1];

    /**
     * @param array<string, array<string, string>> $targets   type (extension|queue|ring_group) => id => name
     * @param array<string, string> $queries                  saved query key => description
     * @param array<string, array{id:int, description:string}> $bases  knowledge base name => id, description
     * @param list<string> $pbxTools                          PBX tool names
     * @param list<string> $languages                         AI language codes
     * @return array{options: array{barge_in: ?bool, announcements: ?bool, email: string, language: string}, text: string, transfers: list<array>, http_tools: string, greetings_by_lang: array<string, list<array{order: int, text: string}>>,
     *               waiting_phrases: list<string>, db_queries: list<string>, knowledge_collections: list<int>,
     *               pbx_tools: list<string>, found: list<array>}
     * @throws InvalidArgumentException with the line number of the first wrong bracket
     */
    public static function parse(string $instructions, array $targets, array $queries, array $bases, array $pbxTools, array $languages): array
    {
        $out = ['options' => ['barge_in' => null, 'announcements' => null, 'email' => '', 'language' => ''], 'transfers' => [], 'http' => [], 'greetings' => [], 'waiting_phrases' => [], 'db_queries' => [],
            'knowledge_collections' => [], 'pbx_tools' => [], 'found' => []];
        $text = '';
        $pos = 0;
        $count = array_fill_keys(self::WORDS, 0);
        $basesLower = [];
        foreach ($bases as $name => $base) {
            $basesLower[mb_strtolower(trim($name))] = $base + ['name' => $name];
        }
        foreach (self::tokens($instructions) as [$start, $end, $word, $parts, $inner]) {
            $rest = static fn (int $n): string => trim(explode(',', $inner, $n + 1)[$n] ?? '');   // the text after the n-th comma, as written
            $line = substr_count($instructions, "\n", 0, $start) + 1;
            $raw = substr($instructions, $start, $end - $start);
            $fail = static fn (string $reason): InvalidArgumentException => new InvalidArgumentException(
                sprintf(t('builtin_ai_agent.err_instruction_tool'), $line, mb_substr($raw, 0, 80), t('builtin_ai_agent.why_' . $reason)));
            if (++$count[$word] > self::MAX[$word]) {
                throw $fail('limit');
            }
            $label = '';
            $sentence = self::sentence($instructions, $start, $end);
            switch ($word) {
                case 'transfer':
                    if (count($parts) !== 3) {
                        throw $fail('format');
                    }
                    $name = self::name($parts[1], $fail);
                    if (isset($out['transfers'][$name])) {
                        throw $fail('duplicate');
                    }
                    [$type, $id] = self::target($parts[2], $targets, $fail);
                    $title = $targets[$type][$id];
                    $out['transfers'][$name] = ['id' => $name, 'type' => $type, 'target' => $id, 'usage' => $sentence, 'title' => $title];
                    $label = "{$name} ({$title})";
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $name, 'resolved' => $title];
                    break;
                case 'http':
                    if (count($parts) < 3) {
                        throw $fail('format');
                    }
                    $name = self::name($parts[1], $fail);
                    $url = $rest(2);
                    try {
                        AiAppService::parseHttpTools($name . ' | ' . mb_substr($sentence, 0, 300) . ' | ' . $url);
                    } catch (\Exception $e) {
                        throw $fail('url');
                    }
                    if (isset($out['http'][$name])) {
                        throw $fail('duplicate');
                    }
                    $out['http'][$name] = $name . ' | ' . mb_substr($sentence, 0, 300) . ' | ' . $url;
                    $label = $name;
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $name, 'resolved' => $url];
                    break;
                case 'greeting':
                    $lang = strtolower($parts[2] ?? '');
                    $say = $rest(3);
                    if (count($parts) < 4 || !ctype_digit($parts[1]) || $say === '' || mb_strlen($say) > 250) {
                        throw $fail('format');
                    }
                    if (!in_array($lang, $languages, true)) {
                        throw $fail('language');
                    }
                    $out['greetings'][$lang][] = [(int) $parts[1], count($out['greetings'][$lang] ?? []), $say];
                    if (count($out['greetings'][$lang]) > self::MAX['greeting']) {
                        throw $fail('limit');
                    }
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $lang . ' ' . $parts[1], 'resolved' => $say];
                    break;
                case 'waiting':
                    $say = $rest(1);
                    if ($say === '' || mb_strlen($say) > 120) {
                        throw $fail('format');
                    }
                    $out['waiting_phrases'][] = $say;
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => '', 'resolved' => $say];
                    break;
                case 'db':
                    $key = $parts[1] ?? '';
                    if (count($parts) !== 2 || !isset($queries[$key])) {
                        throw $fail('unknown');
                    }
                    $out['db_queries'][$key] = $key;
                    $label = $key;
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $key, 'resolved' => $queries[$key]];
                    break;
                case 'knowledge':
                    $base = $basesLower[mb_strtolower($rest(1))] ?? null;
                    if ($base === null) {
                        throw $fail('unknown');
                    }
                    if (trim($base['description']) === '') {
                        throw $fail('description');
                    }
                    $out['knowledge_collections'][$base['id']] = $base['id'];
                    $label = $base['name'];
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $base['name'], 'resolved' => $base['description']];
                    break;
                case 'barge_in':
                case 'announcements':
                    $on = strtolower($parts[1] ?? '');
                    if (count($parts) !== 2 || !in_array($on, ['on', 'off'], true)) {
                        throw $fail('format');
                    }
                    $out['options'][$word] = $on === 'on';
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $on, 'resolved' => ''];
                    break;
                case 'language':
                    $code = strtolower($parts[1] ?? '');
                    if (count($parts) !== 2 || !in_array($code, $languages, true)) {
                        throw $fail('language');
                    }
                    $out['options']['language'] = $code;
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $code, 'resolved' => ''];
                    break;
                case 'email':
                    $mail = $parts[1] ?? '';
                    if (count($parts) !== 2 || mb_strlen($mail) > 254 || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
                        throw $fail('email');
                    }
                    $out['options']['email'] = $mail;
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $mail, 'resolved' => ''];
                    break;
                case 'pbx':
                    $tool = $parts[1] ?? '';
                    if (count($parts) !== 2 || !in_array($tool, $pbxTools, true)) {
                        throw $fail('unknown');
                    }
                    $out['pbx_tools'][$tool] = $tool;
                    $label = $tool;
                    $out['found'][] = ['word' => $word, 'line' => $line, 'name' => $tool, 'resolved' => ''];
                    break;
            }
            $text .= substr($instructions, $pos, $start - $pos) . $label;
            $pos = $end;
        }
        $text .= substr($instructions, $pos);
        $text = str_replace('\\{', '{', $text);
        // A line that held only a greeting or a waiting phrase leaves nothing behind.
        $text = trim((string) preg_replace("/\n[ \t]*(?=\n)/", "\n", (string) preg_replace('/[ \t]+$/m', '', $text)));
        // A heading whose section held only greetings or waiting phrases is left out too.
        $text = (string) preg_replace('/^#[^\n]*\n+(?=#|\z)/m', '', $text . "\n");
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
        // Per language, in order: the AI service picks among the lowest order (higher ones stand in when those do not fit).
        $byLang = [];
        foreach ($out['greetings'] as $lang => $list) {
            usort($list, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $byLang[$lang] = array_map(static fn ($g) => ['order' => min(99, $g[0]), 'text' => $g[2]], $list);
        }
        return [
            'options' => $out['options'],
            'text' => $text . self::actions($out, $queries, $basesLower),
            'transfers' => array_values(array_map(static fn ($t) => array_diff_key($t, ['title' => 1]), $out['transfers'])),
            'http_tools' => implode("\n", $out['http']),
            'greetings_by_lang' => $byLang,
            'waiting_phrases' => $out['waiting_phrases'],
            'db_queries' => array_values($out['db_queries']),
            'knowledge_collections' => array_values($out['knowledge_collections']),
            'pbx_tools' => array_values($out['pbx_tools']),
            'found' => $out['found'],
        ];
    }

    /**
     * The tool brackets in order: [start, end, word, parts]. A URL may hold {variables}, so the outer bracket is matched;
     * "\{" is skipped.
     * @return list<array{0: int, 1: int, 2: string, 3: list<string>, 4: string}>
     */
    private static function tokens(string $s): array
    {
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] !== '{' || ($i > 0 && $s[$i - 1] === '\\')) {
                continue;
            }
            $depth = 0;
            for ($j = $i; $j < $len; $j++) {
                if ($s[$j] === '{' && ($j === 0 || $s[$j - 1] !== '\\')) {
                    $depth++;
                } elseif ($s[$j] === '}') {
                    $depth--;
                }
                if ($depth === 0) {
                    break;
                }
            }
            if ($j >= $len) {
                break;
            }
            $parts = array_map('trim', explode(',', substr($s, $i + 1, $j - $i - 1)));
            $word = strtolower($parts[0]);
            if (count($parts) > 1 && in_array($word, self::WORDS, true)) {
                $out[] = [$i, $j + 1, $word, $parts, substr($s, $i + 1, $j - $i - 1)];
                $i = $j;
            }
        }
        return $out;
    }

    private static function name(string $name, \Closure $fail): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $name)) {
            throw $fail('name');
        }
        if (in_array($name, self::RESERVED, true)) {
            throw $fail('reserved');
        }
        return $name;
    }

    /** "1001" (extension; else a queue or ring group with that id, when only one has it), "queue:<id>", "group:<id>". */
    private static function target(string $target, array $targets, \Closure $fail): array
    {
        if (preg_match('/^(queue|group|extension):(.+)$/', $target, $m)) {
            $type = ['queue' => 'queue', 'group' => 'ring_group', 'extension' => 'extension'][$m[1]];
            if (!isset($targets[$type][$m[2]])) {
                throw $fail('target');
            }
            return [$type, $m[2]];
        }
        $found = array_values(array_filter(['extension', 'queue', 'ring_group'], static fn ($t) => isset($targets[$t][$target])));
        if (count($found) > 1) {
            throw $fail('ambiguous');
        }
        if ($found === []) {
            throw $fail('target');
        }
        return [$found[0], $target];
    }

    /** The sentence (or line) a bracket stands in, brackets written as their names: when the model uses the tool. */
    private static function sentence(string $text, int $start, int $end): string
    {
        $before = substr($text, 0, $start);
        $from = 0;
        if (preg_match_all('/[.!?](\s)|\n/', $before, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $from = $last[1] + strlen($last[0]);
        }
        $rest = substr($text, $end);
        $to = $end + (preg_match('/[.!?](?=\s|$)|\n/', $rest, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] + 1 : strlen($rest));
        $sentence = substr($text, $from, $to - $from);
        foreach (array_reverse(self::tokens($sentence)) as [$s, $e, $word, $parts, $inner]) {
            $sentence = substr($sentence, 0, $s) . ($parts[1] ?? $word) . substr($sentence, $e);
        }
        $sentence = trim((string) preg_replace('/\s+/', ' ', str_replace('\\{', '{', $sentence)));
        return mb_substr((string) preg_replace('/^(?:[-*•]|\d+[.)])\s+/u', '', $sentence), 0, 200);   // without a list mark
    }

    /** The generated list at the end of what the model reads, so no tool is left unexplained by a vague sentence. */
    private static function actions(array $out, array $queries, array $basesLower): string
    {
        $lines = [];
        foreach ($out['transfers'] as $t) {
            $lines[] = "- Transfer `{$t['id']}` to {$t['title']}: {$t['usage']}";
        }
        foreach (array_keys($out['http']) as $name) {
            $lines[] = "- Lookup `{$name}` (web service)";
        }
        foreach ($out['db_queries'] as $key) {
            $lines[] = "- Database query `{$key}`: " . $queries[$key];
        }
        foreach ($basesLower as $base) {
            if (in_array($base['id'], $out['knowledge_collections'], true)) {
                $lines[] = "- Knowledge base `{$base['name']}`: " . $base['description'];
            }
        }
        return $lines ? "\n\n# Actions you can use\n" . implode("\n", $lines) : '';
    }
}
