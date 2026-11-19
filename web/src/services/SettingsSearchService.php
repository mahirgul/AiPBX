<?php

/**
 * Searches across permitted sidebar pages and their view setting labels/texts.
 * Never returns pages the current user is not allowed to see in sidebar_menu.php.
 */
class SettingsSearchService
{
    /**
     * @param string $query
     * @param string $role
     * @param array<string, mixed> $user
     * @param int $limit
     * @return array<int, array{title:string, group:string, href:string, icon:string, snippets:array<int, string>, score:int}>
     */
    public static function search(string $query, string $role, array $user, int $limit = 15): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', self::lower($q), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return [];
        }

        $pages = self::permittedPages($role, $user);
        $viewTexts = self::loadViewTextIndex();

        $results = [];
        foreach ($pages as $page) {
            $texts = [];
            foreach ($page['views'] as $viewKey) {
                if (isset($viewTexts[$viewKey])) {
                    foreach ($viewTexts[$viewKey] as $txt) {
                        $texts[] = $txt;
                    }
                }
            }
            $texts = array_values(array_unique($texts));

            $titleLower = self::lower($page['title']);
            $groupLower = self::lower($page['group']);
            $hrefLower = self::lower($page['href']);

            $score = 0;
            $matchedSnippets = [];
            $allTokensMatched = true;

            foreach ($tokens as $tok) {
                $tokMatched = false;
                if (str_contains($titleLower, $tok)) {
                    $score += 40;
                    $tokMatched = true;
                }
                if (str_contains($hrefLower, $tok)) {
                    $score += 25;
                    $tokMatched = true;
                }
                if (str_contains($groupLower, $tok)) {
                    $score += 10;
                    $tokMatched = true;
                }
                $snippetHits = 0;
                foreach ($texts as $txt) {
                    if (str_contains(self::lower($txt), $tok)) {
                        $tokMatched = true;
                        $snippetHits++;
                        if (count($matchedSnippets) < 4 && !in_array($txt, $matchedSnippets, true)) {
                            $matchedSnippets[] = $txt;
                        }
                    }
                }
                if ($snippetHits > 0) {
                    $score += min(30, $snippetHits * 6);
                }
                if (!$tokMatched) {
                    $allTokensMatched = false;
                    break;
                }
            }

            if (!$allTokensMatched || $score <= 0) {
                continue;
            }

            $sep = str_contains($page['href'], '?') ? '&' : '?';
            $results[] = [
                'title' => $page['title'],
                'group' => $page['group'],
                'href' => $page['href'] . $sep . 'highlight=' . rawurlencode($q),
                'icon' => $page['icon'],
                'snippets' => $matchedSnippets,
                'score' => $score,
            ];
        }

        usort($results, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * Evaluates templates/sidebar_menu.php in the current user context.
     *
     * @return array<int, array{title:string, group:string, href:string, icon:string, views:array<int, string>}>
     */
    private static function permittedPages(string $role, array $user): array
    {
        $menuFile = dirname(__DIR__, 2) . '/templates/sidebar_menu.php';
        if (!is_file($menuFile)) {
            return [];
        }

        $groups = (static function (string $file, string $role, array $user): array {
            $res = require $file;
            return is_array($res) ? $res : [];
        })($menuFile, $role, $user);

        $labelFn = static fn($l): string => is_array($l) ? t($l[0], $l[1] ?? null) : t((string) $l);
        $pages = [];

        foreach ($groups as $group) {
            $groupTitle = $labelFn($group['label'] ?? '');
            foreach (($group['items'] ?? []) as $item) {
                if (empty($item['show'])) {
                    continue;
                }
                $href = (string) ($item['href'] ?? '');
                if ($href === '') {
                    continue;
                }
                $viewKeys = [];
                foreach (($item['pages'] ?? []) as $p) {
                    $base = preg_replace('/\.php$/', '', (string) $p);
                    if ($base !== '') {
                        $viewKeys[] = $base;
                    }
                }
                $slug = trim((string) parse_url($href, PHP_URL_PATH), '/');
                if ($slug !== '') {
                    $viewKeys[] = str_replace('-', '_', $slug);
                }

                $pages[] = [
                    'title' => $labelFn($item['label'] ?? $href),
                    'group' => $groupTitle,
                    'href' => $href,
                    'icon' => (string) ($item['icon'] ?? 'fas fa-sliders-h'),
                    'views' => array_values(array_unique($viewKeys)),
                ];
            }
        }

        return $pages;
    }

    /**
     * Extracts translated labels/strings from templates/views/<dir>/*.php and caches per language.
     *
     * @return array<string, array<int, string>>
     */
    private static function loadViewTextIndex(): array
    {
        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
        $viewsDir = dirname(__DIR__, 2) . '/templates/views';
        if (!is_dir($viewsDir)) {
            return [];
        }

        $langFile = dirname(__DIR__, 2) . '/lang/' . $lang . '.php';
        $langMtime = is_file($langFile) ? (int) filemtime($langFile) : 0;
        $cacheFile = sys_get_temp_dir() . '/aipbx_settings_idx_' . preg_replace('/[^a-z0-9_-]/i', '', $lang) . '.json';

        $dirs = glob($viewsDir . '/*', GLOB_ONLYDIR) ?: [];
        $latestViewMtime = $langMtime;
        foreach ($dirs as $d) {
            foreach (glob($d . '/*.php') ?: [] as $f) {
                $mt = (int) filemtime($f);
                if ($mt > $latestViewMtime) {
                    $latestViewMtime = $mt;
                }
            }
        }

        if (is_file($cacheFile) && (int) filemtime($cacheFile) >= $latestViewMtime) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $index = [];
        foreach ($dirs as $d) {
            $viewKey = basename($d);
            $strings = [];
            foreach (glob($d . '/*.php') ?: [] as $f) {
                $src = @file_get_contents($f);
                if ($src === false || $src === '') {
                    continue;
                }
                if (preg_match_all("/\\bt\\(\\s*['\"]([a-zA-Z0-9_.-]+)['\"](?:\\s*,\\s*['\"]([^'\"]*)['\"])?\\s*\\)/", $src, $m, PREG_SET_ORDER)) {
                    foreach ($m as $hit) {
                        $val = t($hit[1], $hit[2] ?? null);
                        $val = trim(strip_tags((string) $val));
                        if ($val !== '' && $val !== $hit[1] && mb_strlen($val, 'UTF-8') <= 140) {
                            $strings[$val] = true;
                        }
                    }
                }
            }
            if ($strings) {
                $index[$viewKey] = array_keys($strings);
            }
        }

        @file_put_contents($cacheFile, json_encode($index, JSON_UNESCAPED_UNICODE));
        return $index;
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
