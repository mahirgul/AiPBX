<?php
namespace PBX\Destinations;

/**
 * Destination type 'call_modifier': adjusts channel language or prepends a
 * Caller ID name prefix, then continues to the next destination.
 */
class CallModifierModule implements PBXDestinationInterface
{
    public function getKey(): string { return 'call_modifier'; }
    public function getName(): string { return '🏷️ ' . \t('dest.call_modifier'); }

    public function getOptions(): array
    {
        try {
            $stmt = \getDB()->query("SELECT id, title AS name FROM pbx_call_modifiers WHERE is_active = 1 ORDER BY title ASC");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function resolve($agi, string $destId): array
    {
        return ['type' => 'call_modifier', 'id' => $destId];
    }
}

DestinationRegistry::register(new CallModifierModule());
