<?php
namespace PBX\Destinations;

require_once __DIR__ . '/PBXDestinationInterface.php';

/**
 * Destination module for IVR Data Steps (Roadmap 2.3).
 * Route to an IVR Data Step from inbound routes, IVR menus, or queue fallbacks.
 */
class IvrDataModule implements PBXDestinationInterface
{
    public function getKey(): string
    {
        return 'ivr_data';
    }

    public function getName(): string
    {
        return '📊 ' . \t('ivr_data.dest_name', 'IVR Data Step');
    }

    public function getOptions(): array
    {
        try {
            $stmt = \getDB()->query('SELECT id, name FROM pbx_ivr_data_steps WHERE is_active = 1 ORDER BY name ASC');
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function resolve($agi, string $destId): array
    {
        return ['type' => 'ivr_data', 'id' => $destId];
    }
}

DestinationRegistry::register(new IvrDataModule());
