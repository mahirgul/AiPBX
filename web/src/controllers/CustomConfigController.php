<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/CustomConfigService.php';

/**
 * Custom Asterisk Configs editor (/custom-configs): edits *_custom.conf files.
 * Admins only.
 */
class CustomConfigController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $selected = trim((string) ($_POST['config_key'] ?? $_GET['file'] ?? 'pjsip'));
        if (!isset(CustomConfigService::FILES[$selected])) {
            $selected = 'pjsip';
        }

        $notices = static::handlePost([
            'save_custom_config' => fn() => CustomConfigService::save(
                $selected,
                (string) ($_POST['content'] ?? ''),
                (string) ($_POST['csrf_token'] ?? '')
            ),
        ]);

        $files = CustomConfigService::listFiles();
        $content = (!empty($notices['error']) && isset($_POST['content']))
            ? (string) $_POST['content']
            : CustomConfigService::read($selected);

        static::renderPage('custom_configs/index', [
            'files' => $files,
            'selected' => $selected,
            'selected_meta' => $files[$selected],
            'content' => $content,
        ], ['title' => t('custom_configs.title')] + $notices);
    }
}
