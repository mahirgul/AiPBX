<?php
require_once __DIR__ . '/../helpers.php';

use PBX\Destinations\DestinationRegistry;

class TimeConditionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_time_condition'])) {
                $res = PBXHelper::saveTimeCondition($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_time_conditions', $_POST['tc_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_time_condition'])) {
                $res = PBXHelper::deleteTimeCondition($_POST['tc_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['save_time_group'])) {
                $res = PBXHelper::saveTimeGroup($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_tg_status'])) {
                $res = PBXHelper::toggleStatus('pbx_time_groups', $_POST['tg_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_time_group'])) {
                $res = PBXHelper::deleteTimeGroup($_POST['tg_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $tcs = TimeConditionRepository::allWithGroupInfo();
        $time_groups = TimeConditionRepository::allTimeGroups();
        $tg_map = TimeConditionRepository::timeGroupMap($time_groups);
        $modules = DestinationRegistry::getModuleList();
        $day_names = [1 => t('tc.day_mon'), 2 => t('tc.day_tue'), 3 => t('tc.day_wed'), 4 => t('tc.day_thu'), 5 => t('tc.day_fri'), 6 => t('tc.day_sat'), 7 => t('tc.day_sun')];

        $page_title = t('tc.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('time_conditions/index', [
            'tcs' => $tcs,
            'time_groups' => $time_groups,
            'tg_map' => $tg_map,
            'modules' => $modules,
            'day_names' => $day_names,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
