<?php
/**
 * Ortak Controller yardımcıları: yetki kontrolü, CSRF doğrulama, view render,
 * yönlendirme. Mevcut auth.php'deki requireRole()/requireModulePermission()/
 * verifyCSRFToken() fonksiyonlarını SARMALAR, yeniden yazmaz — o katmana
 * dokunulmuyor (bugün büyük emekle test edilip düzeltilen RBAC/CSRF mantığı
 * orada duruyor).
 *
 * Proje konvansiyonuyla tutarlı kalsın diye (bkz. BaseRepository) static
 * metotlarla kullanılır, örneklenmez.
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

    protected static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    protected static function verifyCsrf(): bool
    {
        return verifyCSRFToken($_POST['csrf_token'] ?? '');
    }

    protected static function render(string $view, array $data = []): void
    {
        View::render($view, $data);
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
