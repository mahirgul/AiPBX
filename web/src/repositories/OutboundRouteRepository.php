<?php
class OutboundRouteRepository extends BaseRepository
{
    protected static string $table = 'pbx_outbound_routes';

    public static function allOrdered(): array
    {
        return static::findAll('id ASC');
    }

    public static function activeTrunksForDropdown(): array
    {
        return static::db()->query("SELECT trunk_name, title FROM pbx_trunks WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}
