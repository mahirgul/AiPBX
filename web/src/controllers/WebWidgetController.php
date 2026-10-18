<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/WebWidgetService.php';

use PBX\Destinations\DestinationRegistry;

/** Integrations → Web widgets: website call widgets and the call-back form. */
class WebWidgetController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // "Try it": a bare page with the widget's embed code, on the portal's
        // own origin (always allowed).
        if (isset($_GET['try'])) {
            $widget = WebWidgetService::getWidget((int) $_GET['try']);
            if ($widget === null) {
                http_response_code(404);
                exit;
            }
            static::render('web_widgets/try', ['widget' => $widget, 'embed' => WebWidgetService::embedCode($widget)]);
            exit;
        }

        if (($_GET['download'] ?? '') === 'wordpress') {
            self::downloadWordPressPlugin();
            exit;
        }

        $csrf = static::csrfToken();
        $notices = static::handlePost([
            'save_widget' => fn() => WebWidgetService::saveWidget($_POST),
            'toggle_widget' => fn() => WebWidgetService::toggleWidget((int) ($_POST['widget_id'] ?? 0), $csrf),
            'delete_widget' => fn() => WebWidgetService::deleteWidget((int) ($_POST['widget_id'] ?? 0), $csrf),
        ]);

        $widgets = WebWidgetService::listWidgets();
        foreach ($widgets as &$w) {
            $w['embed'] = WebWidgetService::embedCode($w);
            unset($w['sip_secret']);
        }
        unset($w);

        $modules = array_values(array_filter(
            DestinationRegistry::getModuleList(),
            fn($m) => in_array($m['key'], WebWidgetService::DEST_TYPES, true)
        ));

        static::renderPage('web_widgets/index', [
            'widgets' => $widgets,
            'modules' => $modules,
            'routes' => WebWidgetService::outboundRoutes(),
            'log' => WebWidgetService::recentSessions(50),
        ], ['title' => t('web_widgets.title')] + $notices);
    }

    private static function downloadWordPressPlugin(): void
    {
        $wpDir = dirname(__DIR__, 3) . '/plugins/web-widgets/wordpress/aipbx-call-widget';
        if (!is_dir($wpDir) || !class_exists('ZipArchive')) {
            http_response_code(404);
            echo 'WordPress plugin directory or ZipArchive extension not found.';
            return;
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'aipbx_wp_');
        if ($tmpZip === false) {
            http_response_code(500);
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            http_response_code(500);
            return;
        }

        foreach (glob($wpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $zip->addFile($file, 'aipbx-call-widget/' . basename($file));
            }
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="aipbx-call-widget.zip"');
        header('Content-Length: ' . (string) filesize($tmpZip));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($tmpZip);
        @unlink($tmpZip);
    }
}
