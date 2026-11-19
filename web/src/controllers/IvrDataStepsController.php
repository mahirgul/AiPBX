<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/IvrDataStepService.php';
require_once __DIR__ . '/../services/DataSourceService.php';
require_once __DIR__ . '/../services/AgiScriptService.php';
require_once __DIR__ . '/../repositories/IvrDataStepRepository.php';
require_once __DIR__ . '/../repositories/ExternalDbRepository.php';
require_once __DIR__ . '/../repositories/IvrRepository.php';

/**
 * PBX -> Dynamic Routing (/dynamic-routing; /ivr-data-steps is the old address).
 */
class IvrDataStepsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        static::requireModule('ivrs', 'view');

        if (static::isPost() && isset($_POST['test_step'])) {
            static::requireModule('ivrs', 'edit');
            if (!static::verifyCsrf()) {
                static::json(['ok' => false, 'error' => t('common.invalid_csrf')]);
            }
            static::json(self::test($_POST));
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $csrf = fn (): bool => static::verifyCsrf();
        $notices = static::handlePost([
            'save_step' => function () use ($userId, $csrf) {
                static::requireModule('ivrs', 'edit');
                if (!$csrf()) {
                    return ['error' => t('common.invalid_csrf')];
                }
                if (($_POST['data_source'] ?? '') === 'database') {
                    static::requireModule('external_dbs', 'view');
                }
                $res = IvrDataStepService::saveStep($_POST, $userId);
                return $res['ok'] ? ['success' => true, 'message' => t('ivr_data.saved_successfully')] : ['error' => implode(' ', $res['errors'])];
            },
            'delete_step' => function () use ($csrf) {
                static::requireModule('ivrs', 'delete');
                if (!$csrf()) {
                    return ['error' => t('common.invalid_csrf')];
                }
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0 && ($used = IvrDataStepService::usageCount($id)) > 0) {
                    return ['error' => sprintf(t('ivr_data.err_in_use'), $used)];
                }
                return IvrDataStepService::deleteStep($id) ? ['success' => true, 'message' => t('ivr_data.deleted_successfully')] : [];
            },
            'toggle_status' => function () use ($csrf) {
                static::requireModule('ivrs', 'edit');
                $id = (int) ($_POST['id'] ?? 0);
                $step = $csrf() ? IvrDataStepRepository::findById($id) : null;
                if ($step) {
                    IvrDataStepService::toggleActive($id, empty($step['is_active']));
                }
                return ['success' => true];
            },
        ]);

        require_once __DIR__ . '/../services/ExternalDbQueryService.php';
        static::renderPage('ivr_data_steps/index', [
            'steps' => array_map([self::class, 'forPage'], IvrDataStepRepository::findAll('name ASC')),
            'dbs' => ExternalDbRepository::findActive(),
            'saved_queries' => array_filter(ExternalDbQueryService::all(), static fn ($q) => ExternalDbQueryService::available($q) && $q['access_mode'] === 'public'),
            'agi_choices' => AgiScriptService::choices(),
            'announcements' => IvrRepository::activeAnnouncements(),
            'dest_modules' => \PBX\Destinations\DestinationRegistry::getModules(),
            'vars' => DataSourceService::VARS,
        ], ['title' => t('dynamic_routing.title')] + $notices);
    }

    /**
     * A step for the edit form: the saved headers stay on the server (they carry tokens).
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function forPage(array $step): array
    {
        $step['has_headers'] = !empty($step['http_headers_enc']) ? 1 : 0;
        $step['rules'] = IvrDataStepService::decodeRules((string) ($step['rules_json'] ?? ''));
        unset($step['http_headers_enc'], $step['rules_json']);
        return $step;
    }

    /**
     * "Test": runs the source of the form (saved or not) with sample values.
     * Saved headers are used when the form leaves the field empty.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function test(array $post): array
    {
        $values = [];
        foreach ((array) ($post['sample'] ?? []) as $k => $v) {
            if (is_string($k) && DataSourceService::isVar($k)) {
                $values[$k] = mb_substr((string) $v, 0, 256);
            }
        }
        $source = (string) ($post['data_source'] ?? '');
        if ($source === 'none') {
            $v = $values['input'] ?? '';
            return ['ok' => true, 'status' => $v === '' ? 'not_found' : 'found', 'value' => $v, 'vars' => [], 'ms' => 0, 'error' => ''];
        }
        if ($source === 'agi') {
            $choice = (string) ($post['agi_script'] ?? '');
            if (!str_starts_with($choice, 'script:')) {
                return ['ok' => false, 'error' => t('dynamic_routing.err_test_agibin')];
            }
            $vars = $values + ['DR_INPUT' => $values['input'] ?? '', 'agi_arg_1' => $values['input'] ?? ''];
            $r = AgiScriptService::test(substr($choice, 7), $vars);
            $value = (string) ($r['set']['AGI_RESULT'] ?? '');
            return ['ok' => $r['ok'], 'status' => $value === '' ? 'not_found' : 'found', 'value' => $value,
                'vars' => $r['set'], 'ms' => $r['ms'], 'error' => $r['error'], 'log' => $r['log']];
        }
        $id = (int) ($post['id'] ?? 0);
        $saved = $id > 0 ? IvrDataStepRepository::findById($id) : null;
        $headers = trim((string) ($post['http_headers'] ?? ''));
        $s = IvrDataStepService::sourceOf([
            'data_source' => $source, 'external_db_id' => $post['external_db_id'] ?? 0, 'query_sql' => $post['query_sql'] ?? '',
            'saved_query_id' => $post['saved_query_id'] ?? null,
            'http_url' => $post['http_url'] ?? '', 'http_method' => $post['http_method'] ?? 'GET', 'http_body' => $post['http_body'] ?? '',
            'json_path' => $post['json_path'] ?? '', 'value_template' => $post['value_template'] ?? '',
            'http_headers_enc' => $headers !== '' ? DataSourceService::sealHeaders($headers) : (string) ($saved['http_headers_enc'] ?? ''),
        ]);
        $errors = DataSourceService::validate($s + ['headers' => $headers]);
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        $r = DataSourceService::run($s, $values);
        return ['ok' => $r['status'] !== 'timeout'] + $r;
    }
}
