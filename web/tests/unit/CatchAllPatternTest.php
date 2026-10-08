<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * The generated dialplan must not use the '_.' pattern (Asterisk warns about
 * it on every reload, and it also matches h/i/t) — yet every number '_.'
 * caught must still be caught: a plain DID, a +E.164 DID, a dialled number.
 */
final class CatchAllPatternTest extends TestCase
{
    private const PERM_GROUP_NAME = 'CatchAllPatternTest group';

    /** Numbers a trunk or a phone sends in practice. */
    private const NUMBERS = [
        Fixtures::DID_NUMBER,   // plain DID
        '+90' . Fixtures::DID_NUMBER, // the same DID in E.164
        '+4930123456',
        '00905551234567',
        '5',                    // one digit ('.' alone needs two characters)
        '+',
        '*21',
        '#',
    ];

    protected function setUp(): void
    {
        Fixtures::load();
    }

    protected function tearDown(): void
    {
        getDB()->prepare('DELETE FROM pbx_permission_groups WHERE group_name = ?')
            ->execute([self::PERM_GROUP_NAME]);
    }

    public function testInboundDialplanHasNoUnderscoreDotPattern(): void
    {
        syncInboundDialplan();
        $conf = $this->read('extensions_inbound.conf');

        $this->assertDoesNotMatchRegularExpression('/^exten\s*=>\s*_\.\s*,/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^exten\s*=>\s*_!\s*,/m', $conf);
    }

    public function testPermissionDialplanHasNoUnderscoreDotPattern(): void
    {
        foreach (['allow', 'deny'] as $default) {
            $this->addPermissionGroup($default);
            syncPermissions();
            $conf = $this->read('extensions_permissions.conf');

            $this->assertStringContainsString('[perm-group-', $conf);
            $this->assertDoesNotMatchRegularExpression('/^exten\s*=>\s*_\.\s*,/m', $conf);
            $this->tearDown();
        }
    }

    public function testCatchAllPatternsMatchWhatUnderscoreDotMatched(): void
    {
        foreach (self::NUMBERS as $number) {
            $this->assertTrue(self::matches('_.', $number), "'_.' should match {$number}");
            $this->assertTrue(self::matchesAny(CATCH_ALL_EXTEN_PATTERNS, $number),
                "the catch-all patterns no longer match {$number}");
        }
        // The special extensions are not numbers: '_.' caught them, the new patterns do not.
        foreach (['h', 'i', 't', 's'] as $special) {
            $this->assertFalse(self::matchesAny(CATCH_ALL_EXTEN_PATTERNS, $special),
                "the catch-all patterns must not match the special extension '{$special}'");
        }
    }

    public function testPlainAndPlusDidsReachTheSameDestinationsAsBefore(): void
    {
        syncInboundDialplan();
        $conf = $this->read('extensions_inbound.conf');
        $trunk = 'from-trunk-' . Fixtures::TRUNK_NAME;

        // Trunk context: every DID is taken in and sent on to the route context.
        $trunkCtx = $this->context($conf, $trunk);
        foreach (self::NUMBERS as $number) {
            $block = $this->blockFor($trunkCtx, $number);
            $this->assertNotNull($block, "{$trunk} does not take in {$number}");
            $this->assertStringContainsString("Goto({$trunk}-route,\${NORMALIZED_DID},1)", $block);
        }

        // The configured plain DID still has its own route (an exact entry wins over patterns).
        $inbound = $this->context($conf, 'from-trunk-inbound');
        $this->assertStringContainsString('exten => ' . Fixtures::DID_NUMBER . ',1,', $inbound);
        $this->assertStringContainsString('Queue(' . Fixtures::QUEUE_NAME, $this->blockFor($inbound, Fixtures::DID_NUMBER));

        // A '+' DID was never stripped by the inbound routes: like before, it
        // reaches the "no matching route" context, which still catches it.
        $this->assertNull($this->blockFor($inbound, '+90' . Fixtures::DID_NUMBER));
        $notFound = $this->context($conf, 'from-trunk-notfound');
        foreach (self::NUMBERS as $number) {
            $block = $this->blockFor($notFound, $number);
            $this->assertNotNull($block, "from-trunk-notfound does not catch {$number}");
            $this->assertStringContainsString('Congestion(', $block);
        }
    }

    public function testPermissionDefaultStillAppliesToEveryNumber(): void
    {
        foreach (['allow' => 'Return()', 'deny' => 'Goto(sub-permission-denied,s,1)'] as $default => $app) {
            $gid = $this->addPermissionGroup($default);
            syncPermissions();
            $ctx = $this->context($this->read('extensions_permissions.conf'), "perm-group-{$gid}");

            foreach (self::NUMBERS as $number) {
                $block = $this->blockFor($ctx, $number);
                $this->assertNotNull($block, "perm-group-{$gid} ({$default}) does not match {$number}");
                $this->assertStringContainsString($app, $block);
            }
            $this->tearDown();
        }
    }

    // --- helpers -------------------------------------------------------------

    private function addPermissionGroup(string $default): int
    {
        $db = getDB();
        $db->prepare('INSERT INTO pbx_permission_groups (group_name, default_action, is_active) VALUES (?, ?, 1)')
           ->execute([self::PERM_GROUP_NAME, $default]);
        return (int) $db->lastInsertId();
    }

    private function read(string $file): string
    {
        $path = ASTERISK_PBX_DIR . '/' . $file;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /** The lines of one [context] section. */
    private function context(string $conf, string $name): string
    {
        $start = strpos($conf, "[{$name}]\n");
        $this->assertNotFalse($start, "context [{$name}] missing");
        $body = substr($conf, $start + strlen($name) + 3);
        $next = preg_match('/^\[/m', $body, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($body);
        return substr($body, 0, $next);
    }

    /**
     * The block (priority 1 line and its "same =>" lines) Asterisk would run
     * for $number in this context: an exact entry first, then the patterns.
     */
    private function blockFor(string $context, string $number): ?string
    {
        preg_match_all('/^exten\s*=>\s*([^,]+),1,.*(?:\n\s*same\s*=>.*)*/m', $context, $m, PREG_SET_ORDER);
        foreach ($m as [$block, $ext]) {
            if ($ext === $number) {
                return $block;
            }
        }
        foreach ($m as [$block, $ext]) {
            if (str_starts_with($ext, '_') && self::matches($ext, $number)) {
                return $block;
            }
        }
        return null;
    }

    private static function matchesAny(array $patterns, string $number): bool
    {
        foreach ($patterns as $p) {
            if (self::matches($p, $number)) {
                return true;
            }
        }
        return false;
    }

    /** Asterisk extension pattern match for X Z N [set] . ! and literals. */
    private static function matches(string $pattern, string $number): bool
    {
        if (!str_starts_with($pattern, '_')) {
            return $pattern === $number;
        }
        $regex = '';
        $p = substr($pattern, 1);
        for ($i = 0, $n = strlen($p); $i < $n; $i++) {
            $c = $p[$i];
            if ($c === 'X') { $regex .= '[0-9]'; }
            elseif ($c === 'Z') { $regex .= '[1-9]'; }
            elseif ($c === 'N') { $regex .= '[2-9]'; }
            elseif ($c === '.') { $regex .= '.+'; }
            elseif ($c === '!') { $regex .= '.*'; }
            elseif ($c === '[') {
                $end = strpos($p, ']', $i);
                $set = substr($p, $i + 1, $end - $i - 1);
                $regex .= '[' . preg_replace('/[\\\\\]^]/', '\\\\$0', $set) . ']';
                $i = $end;
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        return (bool) preg_match('/^' . $regex . '$/s', $number);
    }
}
