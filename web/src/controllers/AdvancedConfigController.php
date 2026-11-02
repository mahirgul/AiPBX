<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/AdvancedPjsipService.php';

/**
 * PBX → Advanced configuration (/advanced-config): the administrator's own PJSIP options, which
 * survive regeneration and updates, their versions, and generated files changed by hand.
 * Renders templates/views/advanced_config/index.php. Admin only.
 */
class AdvancedConfigController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_entry' => fn() => AdvancedPjsipService::save($_POST),
            'delete_entry' => fn() => AdvancedPjsipService::delete($_POST),
            'restore_version' => fn() => AdvancedPjsipService::restore($_POST),
        ]);

        $entries = AdvancedPjsipService::all();
        $edit = null;
        $editId = (int) ($_GET['edit'] ?? 0);
        foreach ($entries as $e) {
            if ((int) $e['id'] === $editId) {
                $edit = $e;
            }
        }

        $objects = advancedPjsipObjects();
        sort($objects);
        static::renderPage('advanced_config/index', [
            'csrf' => getCSRFToken(),
            'entries' => $entries,
            'edit' => $edit,
            'objects' => $objects,
            'types' => array_keys(AdvancedPjsipService::OBJECT_TYPES),
            'applied' => json_decode((string) getSystemSetting(ADVANCED_PJSIP_APPLIED, '[]'), true) ?: [],
            'versions' => AdvancedPjsipService::versions(),
            'edited' => handEditedConfCopies(),
        ] + $notices, ['title' => t('advanced_config.title')]);
    }
}
