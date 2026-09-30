<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * Generates the whole configuration from fixture data and checks that every
 * context the dialplan jumps to (include, Goto, Gosub, Local/...@ctx, endpoint
 * context=) is defined. A missing context means calls routed there are dropped.
 */
final class DialplanIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->prepare(
            "INSERT INTO pbx_outbound_routes (route_name, match_pattern, prepend, append, strip_front, strip_back, is_active, route_group, trunks_json)
             VALUES ('IntegrityRoute', '_0X.', '', '', 1, 0, 1, 1, ?)"
        )->execute([json_encode([['trunk_name' => Fixtures::TRUNK_NAME]])]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO pbx_dids (did_number, title, dest_type, dest_id, is_active) VALUES ('8200', 'Integrity', 'outbound_route', ?, 1)")
           ->execute([(string)$routeId]);
    }

    /** Splits the argument list of an application call at top-level commas. */
    private static function args(string $s): array
    {
        $out = [];
        $depth = 0;
        $cur = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '(' || $c === '{' || $c === '[') $depth++;
            if ($c === ')' || $c === '}' || $c === ']') $depth--;
            if ($c === ',' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }

    /** Returns the text inside the parentheses that start at $open. */
    private static function inner(string $line, int $open): string
    {
        $depth = 0;
        for ($i = $open, $n = strlen($line); $i < $n; $i++) {
            if ($line[$i] === '(') $depth++;
            if ($line[$i] === ')' && --$depth === 0) return substr($line, $open + 1, $i - $open - 1);
        }
        return substr($line, $open + 1);
    }

    public function testEveryReferencedContextExists(): void
    {
        syncEverything();

        $files = glob(ASTERISK_PBX_DIR . '/*.conf');
        // Seed files shipped by the installer but not regenerated here.
        foreach (glob(dirname(__DIR__, 3) . '/asterisk-config/pbx/*.conf') as $seed) {
            if (!is_file(ASTERISK_PBX_DIR . '/' . basename($seed))) $files[] = $seed;
        }

        $defined = [];
        $refs = [];
        foreach ($files as $f) {
            $isDialplan = str_starts_with(basename($f), 'extensions');
            foreach (file($f) as $no => $raw) {
                $line = preg_replace('/\s*;.*$/', '', rtrim($raw));
                if ($line === '') continue;
                $where = basename($f) . ':' . ($no + 1);

                if ($isDialplan && preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                    $defined[$m[1]] = true;
                    continue;
                }
                if ($isDialplan && preg_match('/^include\s*=>\s*([A-Za-z0-9_-]+)\s*$/', $line, $m)) {
                    $refs[] = [$m[1], $where];
                }
                if (preg_match('/^context\s*=\s*([A-Za-z0-9_-]+)\s*$/', $line, $m)) {
                    $refs[] = [$m[1], $where];
                }
                if (preg_match_all('#Local/[^@,)\s]+@([A-Za-z0-9_-]+)#', $line, $mm)) {
                    foreach ($mm[1] as $ctx) $refs[] = [$ctx, $where];
                }
                if ($isDialplan && preg_match_all('/\b(Goto|Gosub)\(/', $line, $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[0] as $hit) {
                        $args = self::args(self::inner($line, $hit[1] + strlen($hit[0]) - 1));
                        if (count($args) === 3 && !str_contains($args[0], '$')) {
                            $refs[] = [trim($args[0]), $where];
                        }
                    }
                }
            }
        }

        $missing = [];
        foreach ($refs as [$ctx, $where]) {
            if (!isset($defined[$ctx])) $missing[] = "{$ctx} (used at {$where})";
        }
        $this->assertNotEmpty($defined, 'no dialplan was generated');
        $this->assertSame([], array_values(array_unique($missing)), "Dialplan references undefined contexts:\n" . implode("\n", array_unique($missing)));
    }
}
