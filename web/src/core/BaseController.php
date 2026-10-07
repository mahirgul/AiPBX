<?php
/**
 * Shared controller helpers: access checks, CSRF verification, view
 * rendering, redirects and POST action dispatch. It WRAPS the existing
 * requireRole()/requireModulePermission()/verifyCSRFToken() functions in
 * auth.php instead of reimplementing them — the RBAC/CSRF logic stays there.
 *
 * Used through static methods, never instantiated, to match the rest of the
 * project (see BaseRepository).
 */
abstract class BaseController
{
    protected static function requireModule(string $moduleKey, string $action = 'view'): void
    {
        requireModulePermission($moduleKey, $action);
    }

    protected static function requireRole($allowedRoles): void
    {
        requireRole($allowedRoles);
    }

    protected static function requireLogin(): void
    {
        requireLogin();
    }

    protected static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    protected static function verifyCsrf(): bool
    {
        return verifyCSRFToken(static::csrfToken());
    }

    /** CSRF token submitted with the current form (empty when missing). */
    protected static function csrfToken(): string
    {
        return (string) ($_POST['csrf_token'] ?? '');
    }

    /**
     * Runs the action whose submit-button name is present in $_POST (checked
     * in array order) and turns its service result into layout notices.
     * Non-POST requests and unknown buttons produce no notice.
     *
     * @param array<string, callable(): array> $actions POST field => action returning a service result
     * @return array{message: string, error: string}
     */
    protected static function handlePost(array $actions): array
    {
        if (static::isPost()) {
            foreach ($actions as $field => $action) {
                if (isset($_POST[$field])) {
                    return static::notices($action());
                }
            }
        }
        return ['message' => '', 'error' => ''];
    }

    /**
     * Maps a service result (['success' => bool, 'message' | 'error' => string])
     * to the layout's message/error notices.
     *
     * @return array{message: string, error: string}
     */
    protected static function notices(array $res): array
    {
        return !empty($res['success'])
            ? ['message' => (string) ($res['message'] ?? ''), 'error' => '']
            : ['message' => '', 'error' => (string) ($res['error'] ?? '')];
    }

    /** Sends a JSON response and ends the request. */
    protected static function json(array $payload): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Guard for in-page AJAX actions: answers with a JSON error and ends the
     * request unless the user has $permission on $moduleKey and sent a valid
     * CSRF token (form field or X-CSRF-Token header).
     */
    protected static function requireAjaxAccess(string $moduleKey, string $permission, string $deniedMessage, string $csrfMessage): void
    {
        if (!hasModulePermission($moduleKey, $permission)) {
            static::json(['success' => false, 'message' => $deniedMessage]);
        }
        if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            static::json(['success' => false, 'message' => $csrfMessage]);
        }
    }

    protected static function render(string $view, array $data = []): void
    {
        View::render($view, $data);
    }

    /**
     * Renders a view inside the shared application layout (head, sidebar,
     * topbar, footer). Every page that needs a session uses this.
     *
     * The session and module permission checks live here, and layout
     * variables are passed explicitly through $page; message/error/warning/
     * info are shown by the footer as notifications, so views must not
     * print them again.
     *
     * @param array{title?: string, message?: string, error?: string, warning?: string, info?: string, extra_js?: string} $page
     */
    protected static function renderPage(string $view, array $data = [], array $page = []): void
    {
        require_once dirname(__DIR__, 2) . '/auth.php';
        // Second line of defence: on top of the controller's own
        // requireRole/requireModule check, the page's module permission
        // (RBAC matrix).
        requireLogin();
        requireModulePermission(getModuleKeyForPage(), 'view');

        // Names the layout templates expect.
        $page_title = $page['title'] ?? '';
        $message = $page['message'] ?? '';
        $error = $page['error'] ?? '';
        $warning = $page['warning'] ?? '';
        $info = $page['info'] ?? '';
        if (isset($page['extra_js'])) {
            $extra_js = $page['extra_js'];
        }

        $layouts = dirname(__DIR__, 2) . '/templates/layouts';
        require $layouts . '/app_header.php';
        View::render($view, $data);
        require $layouts . '/app_footer.php';
    }

    /**
     * Shared layout for pages without a session (login, 2FA, password reset,
     * mobile login).
     *
     * @param array{title?: string, head?: string, inline_lang_switch?: bool} $page head: extra raw HTML for <head>;
     *        inline_lang_switch: the page prints the EN/TR switch itself (templates/auth_lang_switch.php)
     */
    protected static function renderAuthPage(string $view, array $data = [], array $page = []): void
    {
        $auth_title = $page['title'] ?? '';
        $auth_head = $page['head'] ?? '';
        $auth_lang_switch_inline = !empty($page['inline_lang_switch']);
        $layouts = dirname(__DIR__, 2) . '/templates/layouts';
        require $layouts . '/auth_header.php';
        View::render($view, $data);
        require $layouts . '/auth_footer.php';
    }

    protected static function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    protected static function notifySuccess(string $message): void
    {
        notify($message, 'success');
    }

    protected static function notifyError(string $message): void
    {
        notify($message, 'danger');
    }
}
