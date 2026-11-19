<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AgiScriptService.php';

/**
 * PBX -> AGI Scripts (/agi-scripts): scripts written here run through the
 * aipbx-agi service in a sandbox. Admin only: a script runs during calls.
 */
class AgiScriptsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if (static::isPost() && isset($_POST['ajax'])) {
            if (!static::verifyCsrf()) {
                static::json(['ok' => false, 'error' => t('common.invalid_csrf')]);
            }
            $id = (int) ($_POST['id'] ?? 0);
            switch ((string) $_POST['ajax']) {
                case 'get':
                    $s = AgiScriptService::find($id);
                    static::json($s ? ['ok' => true, 'script' => $s] : ['ok' => false]);
                case 'versions':
                    static::json(['ok' => true, 'versions' => AgiScriptService::versions($id)]);
                case 'version':
                    $v = AgiScriptService::version($id, (int) ($_POST['version_id'] ?? 0));
                    static::json($v ? ['ok' => true, 'language' => $v['language'], 'content' => $v['content']] : ['ok' => false]);
                case 'test':
                    $s = AgiScriptService::find($id);
                    if (!$s) {
                        static::json(['ok' => false, 'error' => t('agi_scripts.err_not_found')]);
                    }
                    $vars = [];
                    foreach ((array) ($_POST['sample'] ?? []) as $k => $v) {
                        if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k)) {
                            $vars[$k] = mb_substr((string) $v, 0, 256);
                        }
                    }
                    static::json(AgiScriptService::test((string) $s['name'], $vars));
            }
            static::json(['ok' => false]);
        }

        $notices = static::handlePost([
            'save_script' => function () use ($userId) {
                if (!static::verifyCsrf()) {
                    return ['error' => t('common.invalid_csrf')];
                }
                $res = AgiScriptService::save($_POST, $userId);
                return $res['ok'] ? ['success' => true, 'message' => t('agi_scripts.saved')] : ['error' => implode(' ', $res['errors'])];
            },
            'restore_version' => function () use ($userId) {
                if (!static::verifyCsrf()) {
                    return ['error' => t('common.invalid_csrf')];
                }
                $res = AgiScriptService::restore((int) ($_POST['id'] ?? 0), (int) ($_POST['version_id'] ?? 0), $userId);
                return $res['ok'] ? ['success' => true, 'message' => t('agi_scripts.restored')] : ['error' => implode(' ', $res['errors'])];
            },
            'delete_script' => function () use ($userId) {
                if (!static::verifyCsrf()) {
                    return ['error' => t('common.invalid_csrf')];
                }
                $s = AgiScriptService::find((int) ($_POST['id'] ?? 0));
                if ($s && ($used = AgiScriptService::usedBy((string) $s['name']))) {
                    return ['error' => sprintf(t('agi_scripts.err_in_use'), implode(', ', $used))];
                }
                return AgiScriptService::delete((int) ($_POST['id'] ?? 0), $userId) ? ['success' => true, 'message' => t('agi_scripts.deleted')] : [];
            },
            'toggle_status' => function () use ($userId) {
                $s = static::verifyCsrf() ? AgiScriptService::find((int) ($_POST['id'] ?? 0)) : null;
                if ($s) {
                    AgiScriptService::save(['is_active' => empty($s['is_active']) ? 1 : 0] + $s, $userId);
                }
                return ['success' => true];
            },
        ]);

        $scripts = AgiScriptService::all();
        foreach ($scripts as &$s) {
            $s['used_by'] = AgiScriptService::usedBy((string) $s['name']);
        }
        unset($s);
        static::renderPage('agi_scripts/index', [
            'scripts' => $scripts,
            'agi_bin' => AgiScriptService::agiBinScripts(),
            'templates' => array_combine(AgiScriptService::LANGUAGES, array_map([AgiScriptService::class, 'template'], AgiScriptService::LANGUAGES)),
        ], ['title' => t('agi_scripts.title')] + $notices);
    }
}
