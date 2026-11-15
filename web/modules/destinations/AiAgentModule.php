<?php
namespace PBX\Destinations;

/** AI → External agents as a destination. */
class AiAgentModule implements PBXDestinationInterface {
    public function getKey(): string { return 'ai_agent'; }
    public function getName(): string { return '🛰️ ' . \t('ai_agents.dest_name'); }
    public function getOptions(): array {
        try {
            $stmt = \getDB()->query("SELECT id, title AS name FROM pbx_ai_agents WHERE is_active = 1 ORDER BY title ASC");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    public function resolve($agi, string $destId): array {
        return ['type' => 'ai_agent', 'id' => $destId];
    }
}

DestinationRegistry::register(new AiAgentModule());
