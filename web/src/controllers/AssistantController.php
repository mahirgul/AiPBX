<?php
require_once __DIR__ . '/../services/ai/AssistantPlanContext.php';

/**
 * /assistant — AI → Dialplan editor (admin only).
 *
 * The page where a plain-language sentence becomes a PREVIEWED change, and
 * only an explicitly confirmed preview becomes a PBX change through the same
 * services the portal's own forms use. Everything else on the page is the
 * audit trail: what was applied, by whom, when, and what can still be
 * undone. There is also an Echo box: questions ABOUT the phone system
 * (read-only), answered from this server's own data.
 */
class AssistantController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        require_once __DIR__ . '/../services/ai/AssistantPlanService.php';
        require_once __DIR__ . '/../services/ai/EchoReporter.php';

        static::renderPage('assistant/index', [
            'changes'   => AssistantPlanService::recent(30),
            'echo_askable' => EchoReporter::askable(),
            'csrf'      => static::csrfToken(),
        ], ['title' => t('assistant.title')]);
    }
}
