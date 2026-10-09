<?php
require_once __DIR__ . '/../services/WebWidgetService.php';

/**
 * The website side of the call widget (anonymous: a visitor has no session).
 *
 *   GET  /widget-api/config?w=<id>   look and features for widget.js
 *   POST /widget-api/call            w, name → one call (SIP account + one-time token)
 *   POST /widget-api/callback        w, number, name → the PBX calls the visitor
 *
 * The website is checked by its Origin header; only an allowed website gets
 * the CORS header that lets its page read the answer. The limits and the
 * abuse log live in WebWidgetService.
 */
class WidgetApiController
{
    public static function serve(string $path): void
    {
        $action = trim(substr($path, strlen('/widget-api')), '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $publicId = (string) ($_GET['w'] ?? $_POST['w'] ?? '');

        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Vary: Origin');

        $widget = WebWidgetService::getByPublicId($publicId);
        if ($widget !== null && WebWidgetService::originAllowed($widget, $origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST');
            header('Access-Control-Allow-Headers: Content-Type');
        }
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($action === 'config' && $method === 'GET') {
            if ($widget === null || (int) $widget['is_active'] !== 1) {
                self::answer(404, ['success' => false, 'error' => 'not_found']);
            }
            if (!WebWidgetService::originAllowed($widget, $origin)) {
                self::answer(403, ['success' => false, 'error' => 'denied_origin']);
            }
            self::answer(200, ['success' => true] + WebWidgetService::publicConfig($widget));
        }
        if ($action === 'call' && $method === 'POST') {
            $res = WebWidgetService::requestCall($publicId, $origin, $ip, $ua, (string) ($_POST['name'] ?? ''));
            self::answer($res['status'], $res['body']);
        }
        if ($action === 'callback' && $method === 'POST') {
            $res = WebWidgetService::requestCallback(
                $publicId,
                $origin,
                $ip,
                $ua,
                (string) ($_POST['number'] ?? ''),
                (string) ($_POST['name'] ?? '')
            );
            self::answer($res['status'], $res['body']);
        }
        self::answer(404, ['success' => false, 'error' => 'not_found']);
    }

    private static function answer(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
