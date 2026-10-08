<?php
require_once __DIR__ . '/../services/PhoneProvisionService.php';

/**
 * Configuration files for desk phones (anonymous: the phone has no session).
 *
 *   /provision/<token>/<file>  per-phone URL with its random token
 *   /provision/<file>          vendor MAC-based file names (allowed networks only)
 *
 * The rules (allowed networks, rate limit, unknown MACs, logging) live in
 * PhoneProvisionService::handleRequest().
 */
class ProvisionController
{
    public static function serve(string $path): void
    {
        $parts = explode('/', trim(substr($path, strlen('/provision')), '/'));
        if (count($parts) === 2) {
            [$token, $file] = $parts;
        } elseif (count($parts) === 1 && $parts[0] !== '') {
            [$token, $file] = [null, $parts[0]];
        } else {
            http_response_code(404);
            exit;
        }

        $res = PhoneProvisionService::handleRequest(
            $token,
            rawurldecode($file),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        http_response_code($res['status']);
        header('Content-Type: ' . $res['type']);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo $res['body'];
        exit;
    }
}
