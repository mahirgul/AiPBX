<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/CallModifierService.php';

/**
 * Call Modifiers page (/call-modifiers): Language switch & Caller ID prefix destinations.
 * Admins only.
 */
class CallModifierController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_modifier' => fn() => CallModifierService::saveModifier($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_call_modifiers', $_POST['modifier_id'] ?? 0, static::csrfToken()),
            'delete_modifier' => fn() => CallModifierService::deleteModifier($_POST['modifier_id'] ?? 0, static::csrfToken()),
        ]);

        $modifiers = CallModifierService::all();
        $destModules = \PBX\Destinations\DestinationRegistry::getModules();

        static::renderPage('call_modifiers/index', [
            'modifiers' => $modifiers,
            'dest_modules' => $destModules,
            'languages' => getAvailableLanguages(),
        ], ['title' => t('call_modifiers.title')] + $notices);
    }
}
