<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/CallQualityService.php';

/** /call-quality — Reports → Call quality: packet loss and jitter per trunk and extension (RTCP). */
class CallQualityController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_call_quality' => fn() => CallQualityService::save($_POST),
        ]);

        static::renderPage('call_quality/index', [
            'csrf' => getCSRFToken(),
            'settings' => CallQualityService::settings(),
            'status' => CallQualityService::status(),
            'summary' => CallQualityService::summary(24),
            'bad' => CallQualityService::badLegs(24, 50),
        ], ['title' => t('call_quality.title')] + $notices);
    }
}
