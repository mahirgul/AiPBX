<?php
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/LocalAiService.php';

/**
 * /ai-local — AI -> Local TTS (admin / ai_local RBAC): one table of the voices and detectors that run on the PBX
 * itself (EMA, Piper, Kokoro, Silero VAD, Smart Turn); downloaded here, chosen by the features that speak.
 */
class AiLocalController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('ai_local', 'view') && !userHasRole('admin')) {
            static::requireRole('admin');
        }
        static::renderPage('ai_local/index', [
            'plugin_enabled' => PluginManager::isEnabled('ailocal'),
            'local_models'   => LocalAiService::models(),
            'status'         => LocalAiService::status(),
            'dependencies'   => AiBindingService::dependenciesForScope('local'),
            'csrf_token'     => getCSRFToken(),
        ], ['title' => t('ai_local.title', 'Local TTS')]);
    }
}
