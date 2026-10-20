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
     * Simulates the dialplan normalization pipeline on an input number for a given client type.
     *
     * @return array{
     *   original: string,
     *   client_type: string,
     *   matched: bool,
     *   rule: ?array,
     *   normalized: string,
     *   steps: array<array{rule_name: string, pattern: string, before: string, after: string}>
     * }
     */
    public static function simulate(string $number, string $clientType = 'mobile'): array
    {
        $current = trim($number);
        $rules = static::allActiveOrdered($clientType);
        $matchedRule = null;
        $steps = [];

        foreach ($rules as $r) {
            $pattern = trim($r['match_pattern'] ?? '');
            if ($pattern === '') continue;

            $regex = static::asteriskPatternToRegex($pattern);
            if (@preg_match($regex, $current)) {
                $matchedRule = $r;
                $before = $current;

                $front = max(0, intval($r['strip_front'] ?? 0));
                $back = max(0, intval($r['strip_back'] ?? 0));
                $prepend = (string)($r['prepend'] ?? '');
                $append = (string)($r['append'] ?? '');

                $core = $current;
                if ($front > 0 || $back > 0) {
                    $core = (string)(($back > 0) ? substr($current, $front, -$back) : substr($current, $front));
                }

                $current = $prepend . $core . $append;
                $steps[] = [
                    'rule_name' => (string)($r['rule_name'] ?? ''),
                    'pattern' => $pattern,
                    'before' => $before,
                    'after' => $current,
                ];

                // In Asterisk dialplan, the first matching pattern triggers Goto() and exits rule pipeline
                break;
            }
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
}
