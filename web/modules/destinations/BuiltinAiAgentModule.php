<?php

namespace PBX\Destinations;

final class BuiltinAiAgentModule implements PBXDestinationInterface
{
    public function getKey(): string { return 'builtin_ai_agent'; }
    public function getName(): string { return \t('builtin_ai_agent.title'); }

    public function getOptions(): array
    {
        try {
            $stmt = \getDB()->query("SELECT id, title AS name FROM pbx_ai_apps WHERE is_active = 1 AND app_type = 'builtin_agent' ORDER BY title ASC");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    public function resolve($agi, string $destId): array
    {
        return ['type' => $this->getKey(), 'id' => $destId];
    }
}

DestinationRegistry::register(new BuiltinAiAgentModule());
