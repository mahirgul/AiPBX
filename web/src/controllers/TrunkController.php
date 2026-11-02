<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DialplanRuleService.php';

/**
 * Trunks page (/trunks).
 * Renders templates/views/trunks/index.php with assets/js/trunks.js.
 * Logic: TrunkRepository, TrunkService.
 * POST actions: delete_trunk, save_trunk, toggle_status.
 * Admins only.
 */
class TrunkController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_trunk' => fn() => TrunkService::saveTrunk($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_trunks', $_POST['trunk_id'] ?? 0, static::csrfToken()),
            'delete_trunk' => fn() => TrunkService::deleteTrunk($_POST['trunk_id'] ?? 0, static::csrfToken()),
        ]);

        $trunks = TrunkRepository::allOrdered();
        $trunk_statuses = TrunkRepository::livePjsipStatuses();

        $e164Countries = [];
        foreach (DialplanRuleService::getAllCountries() as $cc => $_country) {
            $plan = DialplanRuleService::getDialPlan((string)$cc);
            if ($plan !== null) {
                $e164Countries[$cc] = $plan + ['name' => DialplanRuleService::getCountryName((string)$cc)];
            }
        }
        uasort($e164Countries, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        $page_title = t('trunks.title');
        static::renderPage('trunks/index', [
            'trunks' => $trunks,
            'trunk_statuses' => $trunk_statuses,
            'e164Countries' => $e164Countries,
        ], ['title' => $page_title] + $notices);
    }
}
