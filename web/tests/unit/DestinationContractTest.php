<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PBX\Destinations\DestinationRegistry;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';

/**
 * Every destination type offered in the UI must work end to end: its option
 * list loads, saving keeps the type, and the dialplan can be generated.
 * Runs over all registered modules, so a new module is covered automatically.
 */
final class DestinationContractTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        getDB()->prepare(
            "INSERT INTO pbx_outbound_routes (route_name, match_pattern, prepend, append, strip_front, strip_back, is_active, route_group, trunks_json)
             VALUES ('ContractRoute', '_9XXX', '', '', 0, 0, 1, 1, ?)"
        )->execute([json_encode([['trunk_name' => Fixtures::TRUNK_NAME]])]);
    }

    /** @return array<string, array{string}> */
    public static function destinationTypes(): array
    {
        $out = [];
        foreach (DestinationRegistry::getModuleList() as $m) {
            $out[$m['key']] = [$m['key']];
        }
        return $out;
    }

    #[DataProvider('destinationTypes')]
    public function testOptionListLoads(string $type): void
    {
        $options = DestinationRegistry::getOptionsFor($type);
        $this->assertIsArray($options, "options of '{$type}' did not load");
        foreach ($options as $o) {
            $this->assertArrayHasKey('id', $o, "'{$type}' option without id");
            $this->assertArrayHasKey('name', $o, "'{$type}' option without name");
        }
    }

    #[DataProvider('destinationTypes')]
    public function testSavingKeepsTheType(string $type): void
    {
        $this->assertSame($type, sanitizeDestType($type),
            "'{$type}' is offered in the UI but saving turns it into '" . sanitizeDestType($type) . "'");
    }

    #[DataProvider('destinationTypes')]
    public function testDialplanIsGenerated(string $type): void
    {
        $options = DestinationRegistry::getOptionsFor($type);
        $id = $options ? (string)$options[0]['id'] : '';
        $lines = buildDestinationLines($type, $id, '1234');
        $this->assertNotSame('', trim((string)$lines), "no dialplan for destination '{$type}'");
    }

    public function testOutboundRouteDestinationTargetsTheChosenRoute(): void
    {
        $id = (int)getDB()->query("SELECT id FROM pbx_outbound_routes WHERE route_name = 'ContractRoute'")->fetchColumn();
        $lines = buildDestinationLines('outbound_route', (string)$id, '8200');
        $this->assertStringContainsString("Goto(outbound-route-{$id},8200,1)", $lines);

        syncOutboundDialplan();
        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
        $this->assertStringContainsString("[outbound-route-{$id}]", $conf);
    }
}
