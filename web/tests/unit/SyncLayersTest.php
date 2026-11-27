<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * The layers of the Asterisk sync code (see AGENTS.md, "Architectural Coupling"):
 *
 *   ConfWriter.php  <  Sync*.php modules  <  asterisk_sync.php (the orchestrator)
 *
 * A module may use the write layer below it and never the orchestrator above it, and Apply runs the domains
 * in the order of PENDING_SYNC_DOMAIN_MAP, the order in which one domain's files need the other's.
 */
final class SyncLayersTest extends TestCase
{
    /** @return list<string> the names a file calls as plain functions (comments and strings are not looked at) */
    private static function calledFunctions(string $file): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
        ));
        $out = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $id = is_array($before) ? $before[0] : $before;
            if (in_array($id, [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $out[$t[1]] = true;
        }
        return array_keys($out);
    }

    /** @return list<string> functions the file defines */
    private static function definedFunctions(string $file): array
    {
        preg_match_all('/^function\s+(\w+)\s*\(/m', (string) file_get_contents($file), $m);
        return $m[1];
    }

    public function testNoSyncModuleUsesTheOrchestratorAboveIt(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $orchestrator = array_diff(self::definedFunctions($src . '/asterisk_sync.php'), []);
        $this->assertNotEmpty($orchestrator);
        foreach (glob($src . '/sync/*.php') as $module) {
            $this->assertDoesNotMatchRegularExpression('/^\s*require(_once)?\b[^\n;]*asterisk_sync\.php/m', (string) file_get_contents($module), basename($module) . ' includes the orchestrator');
            $up = array_values(array_intersect(self::calledFunctions($module), $orchestrator));
            $this->assertSame([], $up, basename($module) . ' calls the orchestrator: ' . implode(', ', $up));
        }
    }

    public function testTheWriteLayerKnowsNoModule(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $modules = [];
        foreach (glob($src . '/sync/*.php') as $f) {
            if (basename($f) !== 'ConfWriter.php') {
                $modules = array_merge($modules, self::definedFunctions($f));
            }
        }
        $used = array_values(array_intersect(self::calledFunctions($src . '/sync/ConfWriter.php'), $modules));
        $this->assertSame([], $used, 'ConfWriter.php calls: ' . implode(', ', $used));
        $this->assertSame([], array_values(array_intersect(
            self::calledFunctions($src . '/sync/DialplanLines.php'),
            array_merge($modules, self::definedFunctions($src . '/asterisk_sync.php'))
        )));
    }

    public function testEveryDomainRunsAfterTheOnesItNeeds(): void
    {
        $order = array_flip(array_keys(PENDING_SYNC_DOMAIN_MAP));
        $this->assertSame('readdress', array_key_first(PENDING_SYNC_DOMAIN_MAP), 'readdress regenerates the extensions itself');
        // [domain, must come before]
        foreach ([
            ['transports', 'extensions'], ['extensions', 'advanced_pjsip'], ['trunks', 'advanced_pjsip'],
            ['outbound_dialplan', 'widgets'],
            ['internal_numbers', 'general_dialplan'], ['custom_dialplan', 'general_dialplan'],
            ['inbound_dialplan', 'general_dialplan'], ['ai_apps', 'general_dialplan'],
        ] as [$first, $then]) {
            $this->assertLessThan($order[$then], $order[$first], "{$first} must run before {$then}");
        }
    }

    public function testEveryDomainHasItsFunction(): void
    {
        foreach (PENDING_SYNC_DOMAIN_MAP as $domain => $fn) {
            $this->assertTrue(function_exists($fn), "{$domain}: {$fn}() does not exist");
        }
    }
}
