<?php

declare(strict_types=1);

/**
 * Repository for pbx_ivr_data_steps (Roadmap 2.3).
 */
class IvrDataStepRepository extends BaseRepository
{
    protected static string $table = 'pbx_ivr_data_steps';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findActive(): array
    {
        return self::findWhere('is_active = 1', [], 'name ASC');
    }
}
