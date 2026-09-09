<?php
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

class IvrRepository extends BaseRepository
{
    protected static string $table = 'pbx_ivrs';

    public static function allOrderedByTitle(): array
    {
        return static::findAll('title ASC');
    }

    public static function entriesByIvr(): array
    {
        $entries_by_ivr = [];
        $raw_entries = static::db()->query('SELECT * FROM pbx_ivr_entries ORDER BY digit ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw_entries as $e) {
            $entries_by_ivr[$e['ivr_id']][] = $e;
        }
        return $entries_by_ivr;
    }

    public static function activeAnnouncements(): array
    {
        return static::db()->query('SELECT id, title, audio_file FROM pbx_announcements ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * dest_type/dest_id çiftini renkli, okunabilir bir badge'e çevirir
     * ("type [id]" ham gösterimi yerine). Önceden ivrs.php'nin içinde
     * bağımsız bir fonksiyondu.
     */
    public static function destBadge(?string $destType, $destId, array &$cache): string
    {
        if (empty($destType)) return '<span style="color: var(--text-muted); font-size: 12px;">-</span>';
        $mod = DestinationRegistry::getModule($destType);
        $modName = $mod ? $mod->getName() : $destType;
        $label = DestinationRegistry::resolveLabel($destType, $destId, $cache);
        $cls = DestinationRegistry::badgeClassFor($destType);
        if ($label !== null) {
            return '<span class="badge ' . $cls . '" style="font-size: 10px;">' . htmlspecialchars($modName) . ': ' . htmlspecialchars($label) . '</span>';
        }
        return '<span class="badge badge-danger" style="font-size: 10px;" title="' . htmlspecialchars(sprintf(t('ivr.target_not_found_tooltip'), $destId)) . '"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($modName) . ' ' . htmlspecialchars(t('ivr.target_not_found')) . '</span>';
    }
}
