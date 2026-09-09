<?php
require_once __DIR__ . '/../asterisk_helper.php';

class TrunkRepository extends BaseRepository
{
    protected static string $table = 'pbx_trunks';

    public static function allOrdered(): array
    {
        return static::findAll('id ASC');
    }

    public static function livePjsipStatuses(): array
    {
        return AsteriskHelper::getPJSIPStatuses();
    }
}
