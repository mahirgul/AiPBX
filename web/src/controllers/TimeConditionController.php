<?php
require_once __DIR__ . '/../helpers.php';

use PBX\Destinations\DestinationRegistry;

class TimeConditionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_time_condition' => fn() => TimeConditionService::saveTimeCondition($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_time_conditions', $_POST['tc_id'] ?? 0, static::csrfToken()),
            'delete_time_condition' => fn() => TimeConditionService::deleteTimeCondition($_POST['tc_id'] ?? 0, static::csrfToken()),
            'save_time_group' => fn() => TimeConditionService::saveTimeGroup($_POST),
            'toggle_tg_status' => fn() => PBXHelper::toggleStatus('pbx_time_groups', $_POST['tg_id'] ?? 0, static::csrfToken()),
            'delete_time_group' => fn() => TimeConditionService::deleteTimeGroup($_POST['tg_id'] ?? 0, static::csrfToken()),
        ]);

        $tcs = TimeConditionRepository::allWithGroupInfo();
        $time_groups = TimeConditionRepository::allTimeGroups();
        $tg_map = TimeConditionRepository::timeGroupMap($time_groups);
        $modules = DestinationRegistry::getModuleList();
        $day_names = [1 => t('tc.day_mon'), 2 => t('tc.day_tue'), 3 => t('tc.day_wed'), 4 => t('tc.day_thu'), 5 => t('tc.day_fri'), 6 => t('tc.day_sat'), 7 => t('tc.day_sun')];

        $page_title = t('tc.title');
        static::renderPage('time_conditions/index', [
            'tcs' => $tcs,
            'time_groups' => $time_groups,
            'tg_map' => $tg_map,
            'modules' => $modules,
            'day_names' => $day_names,
        ], ['title' => $page_title] + $notices);
    }
}
