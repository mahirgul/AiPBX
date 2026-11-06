<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PluginManager.php';

/**
 * /plugins and /plugins/<slug> — Official Plugin Catalog, Custom .zip Upload,
 * and Plugin Configuration Controller (admin only).
 */
class PluginsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';
        $newToken = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                $csrf = static::csrfToken();
                if (isset($_POST['enable_plugin'])) {
                    $res = PluginManager::enable(trim((string)$_POST['enable_plugin']), $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['disable_plugin'])) {
                    $res = PluginManager::disable(trim((string)$_POST['disable_plugin']), $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['uninstall_plugin'])) {
                    $res = PluginManager::uninstall(trim((string)$_POST['uninstall_plugin']), $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['upload_plugin_zip']) && isset($_FILES['plugin_zip'])) {
                    $res = PluginManager::installFromZip($_FILES['plugin_zip'], $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                }
                PluginManager::$menuChanged = !empty($res['success'] ?? false);
            }
        }

        static::renderPage('plugins/index', [
            'plugins'    => PluginManager::allPlugins(),
            'csrf_token' => getCSRFToken(),
            'new_token'  => $newToken,
        ], [
            'title'   => t('plugins.title', 'Plugin Catalog & Manager'),
            'message' => $message,
            'error'   => $error,
        ]);
    }

    /**
     * Render `/plugins/<slug>` settings / custom admin view for an enabled plugin.
     */
    public static function detail(string $slug): void
    {
        static::requireRole('admin');
        $plugin = PluginManager::getPlugin($slug);
        if (!$plugin) {
            http_response_code(404);
            static::renderPage('errors/404', [], ['title' => '404']);
            return;
        }

        $message = '';
        $error = '';

        $manifests = PluginManager::discoverManifests();
        $manifest = $manifests[$slug] ?? [];
        if (!empty($plugin['enabled']) && !empty($manifest['dir'])) {
            try {
                PluginManager::applyPluginSchema($slug, (string)$manifest['dir']);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                $csrf = static::csrfToken();
                if (isset($_POST['save_plugin_settings'])) {
                    $res = PluginManager::savePluginSettings($slug, $_POST, $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                    $plugin = PluginManager::getPlugin($slug) ?? $plugin;
                }
            }
        }

        if (!empty($manifest['dir']) && is_file($manifest['dir'] . '/admin/action.php')) {
            try {
                $actionFn = require $manifest['dir'] . '/admin/action.php';
                if (is_callable($actionFn)) {
                    $actionRes = $actionFn($slug, PluginManager::getAllSettings($slug, false));
                    if (is_array($actionRes)) {
                        if (!empty($actionRes['message'])) {
                            $message = (string)$actionRes['message'];
                        }
                        if (!empty($actionRes['error'])) {
                            $error = (string)$actionRes['error'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $customViewPath = null;
        if (!empty($manifest['dir']) && is_file($manifest['dir'] . '/admin/page.php')) {
            $customViewPath = $manifest['dir'] . '/admin/page.php';
        }

        static::renderPage('plugins/detail', [
            'plugin'           => $plugin,
            'settings'         => PluginManager::getAllSettings($slug, false),
            'masked_settings'  => PluginManager::getAllSettings($slug, true),
            'custom_view_path' => $customViewPath,
            'csrf_token'       => getCSRFToken(),
        ], [
            'title'   => $plugin['name'],
            'message' => $message,
            'error'   => $error,
        ]);
    }

    /**
     * /apps/<slug>: a plugin's page for signed-in users (`user_page` hook). On POST, after the
     * CSRF check, `user/action.php` runs as fn(string $slug, array $settings, array $user): array;
     * ['json' => ...] answers a script, ['message' => ...] or ['error' => ...] a form.
     */
    public static function userPage(string $slug): void
    {
        static::requireLogin();
        $user = (array) getCurrentUser();
        $dir = PluginManager::userPageDir($slug, $user);
        if ($dir === null) {
            http_response_code(404);
            static::renderPage('errors/404', [], ['title' => '404']);
            return;
        }
        $settings = PluginManager::getAllSettings($slug, false);
        $message = $error = '';
        if (static::isPost() && is_file($dir . '/user/action.php')) {
            $res = ['error' => t('common.invalid_csrf')];
            if (static::verifyCsrf()) {
                try {
                    $fn = require $dir . '/user/action.php';
                    $res = is_callable($fn) ? (array) $fn($slug, $settings, $user) : [];
                } catch (\Throwable $e) {
                    $res = ['error' => $e->getMessage()];
                }
            }
            if (array_key_exists('json', $res)) {
                static::json((array) $res['json']);
            }
            $message = (string) ($res['message'] ?? '');
            $error = (string) ($res['error'] ?? '');
        }
        $plugin = PluginManager::getPlugin($slug);
        static::renderPage('plugins/user_page', [
            'page_path'  => $dir . '/user/page.php',
            'slug'       => $slug,
            'settings'   => $settings,
            'user'       => $user,
            'csrf_token' => getCSRFToken(),
        ], [
            'title'   => (string) ($plugin['hooks']['user_page']['label'] ?? $plugin['name'] ?? $slug),
            'message' => $message,
            'error'   => $error,
        ]);
    }
}
