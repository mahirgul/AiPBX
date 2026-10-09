<?php
require_once __DIR__ . '/../services/LocalAiService.php';

/**
 * /ai-models — AI → Local models (admin only): the local AI runtime and the
 * models it runs on this server. Installing, downloading and measuring take
 * a while, so the page works through /api/ai_models.php and polls.
 */
class AiModelsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        static::renderPage('ai_models/index', [
            'status' => LocalAiService::status(),
        ], ['title' => t('ai_models.title')]);
    }
}
