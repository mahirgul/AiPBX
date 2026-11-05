<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/AdvancedPjsipService.php';

/**
 * PBX → Advanced configuration (/advanced-config): the administrator's own Asterisk options, which
 * survive regeneration and updates, their versions, and generated files changed by hand.
 * Renders templates/views/advanced_config/index.php. Admin only.
 */
class AdvancedConfigController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        if (($_GET['export'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="aipbx-advanced-config-' . date('Ymd-His') . '.json"');
            echo AdvancedPjsipService::exportJson();
            exit;
        }

        $notices = static::handlePost([
            'save_entry' => fn() => AdvancedPjsipService::save($_POST),
            'delete_entry' => fn() => AdvancedPjsipService::delete($_POST),
            'restore_version' => fn() => AdvancedPjsipService::restore($_POST),
            'import_entries' => fn() => AdvancedPjsipService::import($_POST, $_FILES['import_file'] ?? null),
        ]);

        $entries = AdvancedPjsipService::all();
        $edit = null;
        $editId = (int) ($_GET['edit'] ?? 0);
        foreach ($entries as $e) {
            if ((int) $e['id'] === $editId) {
                $edit = $e;
            }
        }

        $generated = advancedPjsipObjects();
        $objects = advancedObjectsWithOwn($entries, $generated);
        sort($objects);
        $groups = advancedPjsipGroups();
        $sections = self::sections();
        $pjsipValues = pjsipObjectValues(advancedPjsipSourceFiles());
        $states = [];
        $replaced = [];
        foreach ($entries as $e) {
            $id = (int) $e['id'];
            $states[$id] = advancedEntryState($e, $objects, $generated, $groups, $sections);
            if ($e['config_file'] === 'pjsip') {
                $first = advancedEntryObjects($e, $generated, $groups)[0] ?? '';
                $values = $pjsipValues[$e['object_type'] . ':' . $first] ?? [];
            } else {
                $values = configSectionValues(advancedFileContents((string) $e['config_file']))[$e['object_name']] ?? [];
            }
            $replaced[$id] = AdvancedPjsipService::replacedValues($e, $values);
        }
        static::renderPage('advanced_config/index', [
            'csrf' => getCSRFToken(),
            'entries' => $entries,
            'edit' => $edit,
            'objects' => $objects,
            'types' => array_keys(AdvancedPjsipService::OBJECT_TYPES),
            'files' => array_keys(AdvancedPjsipService::CONFIG_FILES),
            'targets' => AdvancedPjsipService::TARGETS,
            'models' => AdvancedPjsipService::phoneModels(),
            'states' => $states,
            'replaced' => $replaced,
            'sections' => $sections,
            'recipes' => AdvancedPjsipService::RECIPES,
            'help' => AdvancedPjsipService::helpCatalog(),
            'applied' => json_decode((string) getSystemSetting(ADVANCED_PJSIP_APPLIED, '[]'), true) ?: [],
            'versions' => AdvancedPjsipService::versions(),
            'edited' => handEditedConfCopies(),
        ] + $notices, ['title' => t('advanced_config.title')]);
    }

    /** @return array<string, list<string>> sections an entry can add to, by file */
    private static function sections(): array
    {
        $sections = [];
        foreach (array_keys(AdvancedPjsipService::CONFIG_FILES) as $file) {
            if ($file !== 'pjsip') {
                $sections[$file] = array_values(array_filter(advancedFileSections($file), static fn(string $s): bool => !AdvancedPjsipService::sectionDenied($file, $s)));
            }
        }
        return $sections;
    }
}
