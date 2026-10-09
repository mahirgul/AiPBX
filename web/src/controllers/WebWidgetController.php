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
}
