<?php

declare(strict_types=1);

/**
 * Database access and pattern simulation for Ingress Dialplan / Normalization Rules.
 * Table: `pbx_dialplan_rules`.
 */
class DialplanRuleRepository extends BaseRepository
{
    protected static string $table = 'pbx_dialplan_rules';

    public static function allOrdered(?string $clientType = null): array
    {
        if ($clientType !== null && $clientType !== 'all' && $clientType !== '') {
            return static::findWhere('client_type = ? OR client_type = ?', [$clientType, 'all'], 'sort_order ASC, id ASC');
        }
        return static::findAll('client_type ASC, sort_order ASC, id ASC');
    }

    public static function allActiveOrdered(?string $clientType = null): array
    {
        if ($clientType !== null && $clientType !== 'all' && $clientType !== '') {
            return static::findWhere('(client_type = ? OR client_type = ?) AND is_active = 1', [$clientType, 'all'], 'sort_order ASC, id ASC');
        }
        return static::findWhere('is_active = 1', [], 'sort_order ASC, id ASC');
    }

    /**
     * Converts an Asterisk dialplan pattern to a PHP regular expression.
     * e.g. `_+90.` -> `/^\+90.+$/`
     * e.g. `_00[1-9].` -> `/^00[1-9].+$/`
     * e.g. `_5XXXXXXXXX` -> `/^5[0-9]{9}$/`
     */
    public static function asteriskPatternToRegex(string $pattern): string
    {
        $p = trim($pattern);
        if (str_starts_with($p, '_')) {
            $p = substr($p, 1);
        }

        $len = strlen($p);
        $regex = '^';
        $inBracket = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $p[$i];

            if ($ch === '[' && !$inBracket) {
                $inBracket = true;
                $regex .= '[';
                continue;
            }
            if ($ch === ']' && $inBracket) {
                $inBracket = false;
                $regex .= ']';
                continue;
            }
            if ($inBracket) {
                $regex .= $ch;
                continue;
            }

            switch ($ch) {
                case 'X':
                case 'x':
                    $regex .= '[0-9]';
                    break;
                case 'Z':
                case 'z':
                    $regex .= '[1-9]';
                    break;
                case 'N':
                case 'n':
                    $regex .= '[2-9]';
                    break;
                case '.':
                    $regex .= '.+';
                    break;
                case '!':
                    $regex .= '.*';
                    break;
                case '+':
                    $regex .= '\\+';
                    break;
                case '*':
                    $regex .= '\\*';
                    break;
                case '#':
                    $regex .= '\\#';
                    break;
                default:
                    $regex .= preg_quote($ch, '/');
                    break;
            }
        }

        $regex .= '$';
        return '/' . $regex . '/';
    }

    /**
     * Calculates Asterisk dialplan pattern specificity score.
     * Higher score = more specific match.
     * In Asterisk:
     * - Exact characters (0-9, *, #, +) have the highest weight.
     * - Character subsets [1-9] are next.
     * - N (2-9), Z (1-9), X (0-9) are next.
     * - Wildcards (. or !) have the lowest weight.
     */
    public static function calculatePatternSpecificity(string $pattern): int
    {
        $p = trim($pattern);
        if (str_starts_with($p, '_')) {
            $p = substr($p, 1);
        }

        $score = 0;
        $len = strlen($p);
        $inBracket = false;
        $bracketCount = 0;

        for ($i = 0; $i < $len; $i++) {
            $ch = $p[$i];

            if ($ch === '[' && !$inBracket) {
                $inBracket = true;
                $bracketCount = 0;
                continue;
            }
            if ($ch === ']' && $inBracket) {
                $inBracket = false;
                $score += max(52, 90 - ($bracketCount * 4));
                continue;
            }
            if ($inBracket) {
                $bracketCount++;
                continue;
            }

            switch ($ch) {
                case '0': case '1': case '2': case '3': case '4':
                case '5': case '6': case '7': case '8': case '9':
                case '*': case '#': case '+':
                    $score += 100;
                    break;
                case 'N': case 'n':
                    $score += 65;
                    break;
                case 'Z': case 'z':
                    $score += 60;
                    break;
                case 'X': case 'x':
                    $score += 50;
                    break;
                case '.':
                    $score += 15;
                    break;
                case '!':
                    $score += 10;
                    break;
                default:
                    $score += 40;
                    break;
            }
        }

        return $score;
    }

    /**
     * Simulates the dialplan normalization pipeline on an input number for a given client type.
     * In accordance with Asterisk context semantics, candidate patterns that match
     * are ranked by pattern specificity (most specific rule wins, with sort_order as tie-breaker).
     *
     * @return array{
     *   original: string,
     *   client_type: string,
     *   matched: bool,
     *   rule: ?array,
     *   normalized: string,
     *   steps: array<array{rule_name: string, pattern: string, before: string, after: string, specificity?: int}>
     * }
     */
    public static function simulate(string $number, string $clientType = 'mobile'): array
    {
        $current = trim($number);
        $rules = static::allActiveOrdered($clientType);
        $matchedRule = null;
        $steps = [];

        $candidates = [];
        foreach ($rules as $r) {
            $pattern = trim($r['match_pattern'] ?? '');
            if ($pattern === '') continue;

            $regex = static::asteriskPatternToRegex($pattern);
            if (@preg_match($regex, $current)) {
                $candidates[] = [
                    'rule' => $r,
                    'specificity' => static::calculatePatternSpecificity($pattern),
                    'sort_order' => intval($r['sort_order'] ?? 0),
                    'id' => intval($r['id'] ?? 0),
                ];
            }
        }

        if (!empty($candidates)) {
            // Sort by Asterisk pattern specificity (highest specificity first)
            // Equal specificity falls back to sort_order ASC, then id ASC
            usort($candidates, function(array $a, array $b): int {
                if ($a['specificity'] !== $b['specificity']) {
                    return $b['specificity'] <=> $a['specificity'];
                }
                if ($a['sort_order'] !== $b['sort_order']) {
                    return $a['sort_order'] <=> $b['sort_order'];
                }
                return $a['id'] <=> $b['id'];
            });

            $matchedRule = $candidates[0]['rule'];
            $pattern = trim($matchedRule['match_pattern'] ?? '');
            $before = $current;

            $front = max(0, intval($matchedRule['strip_front'] ?? 0));
            $back = max(0, intval($matchedRule['strip_back'] ?? 0));
            $prepend = (string)($matchedRule['prepend'] ?? '');
            $append = (string)($matchedRule['append'] ?? '');

            $core = $current;
            if ($front > 0 || $back > 0) {
                $core = (string)(($back > 0) ? substr($current, $front, -$back) : substr($current, $front));
            }

            $current = $prepend . $core . $append;
            $steps[] = [
                'rule_name' => (string)($matchedRule['rule_name'] ?? ''),
                'pattern' => $pattern,
                'before' => $before,
                'after' => $current,
                'specificity' => $candidates[0]['specificity'],
            ];
        }

        return [
            'original' => trim($number),
            'client_type' => $clientType,
            'matched' => ($matchedRule !== null),
            'rule' => $matchedRule,
            'normalized' => $current,
            'steps' => $steps,
        ];
    }

    /**
     * Simulates dialplan normalization across all three client devices (desktop, mobile, web)
     * simultaneously for the live interactive preview.
     *
     * @return array{
     *   original: string,
     *   enabled: bool,
     *   desktop: array,
     *   mobile: array,
     *   web: array
     * }
     */
    public static function simulateAll(string $number): array
    {
        $num = trim($number);
        return [
            'original' => $num,
            // Switched off as a whole: Asterisk applies no rule, the simulator shows what each rule would do.
            'enabled' => (string)getSystemSetting('dialplan_rules_enabled', '1') !== '0',
            'desktop' => static::simulate($num, 'desktop'),
            'mobile'  => static::simulate($num, 'mobile'),
            'web'     => static::simulate($num, 'web'),
        ];
    }
}
