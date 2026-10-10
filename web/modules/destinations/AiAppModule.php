<?php
namespace PBX\Destinations;

/** AI → Applications (an AI feature answers the call, then its own destination). */
class AiAppModule implements PBXDestinationInterface {
    public function getKey(): string { return 'ai_app'; }
    public function getName(): string { return '🤖 ' . \t('ai_apps.dest_name'); }

    public function getOptions(): array {
        try {
            $stmt = \getDB()->query("SELECT id, title AS name FROM pbx_ai_apps WHERE is_active = 1 ORDER BY title ASC");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];   // before the migration
        }
    }

    public function resolve($agi, string $destId): array {
        return ['type' => 'ai_app', 'id' => $destId];
    }
}

DestinationRegistry::register(new AiAppModule());
