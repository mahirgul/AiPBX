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
        return verifyCSRFToken($_POST['csrf_token'] ?? '');
    }

    protected static function render(string $view, array $data = []): void
    {
        View::render($view, $data);
    }

    /**
     * Görünümü uygulamanın ortak düzeniyle (head, sidebar, topbar, footer)
     * birlikte basar. Tüm oturum gerektiren sayfalar bunu kullanır.
     *
     * Önceden her controller header.php/footer.php'yi kendisi require ediyordu
     * ve oturum/modül yetkisi kontrolü header.php'nin (şablonun) içindeydi;
     * düzen bildirimleri de controller'ın yerel $message/$error
     * değişkenlerinden "sızarak" okunuyordu. Artık kontrol burada, düzen
     * değişkenleri ise açıkça $page ile geçiyor.
     *
     * @param array{title?: string, message?: string, error?: string, warning?: string, info?: string, extra_js?: string} $page
     */
    protected static function renderPage(string $view, array $data = [], array $page = []): void
    {
        require_once dirname(__DIR__, 2) . '/auth.php';
        // İkinci savunma hattı: controller'ın kendi requireRole/requireModule
        // kontrolüne ek olarak, sayfanın modül izni (RBAC matrisi).
        requireLogin();
        requireModulePermission(getModuleKeyForPage(), 'view');

        // Düzen şablonlarının beklediği adlar.
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
     * Oturum gerektirmeyen sayfalar (giriş, 2FA, şifre sıfırlama, mobil giriş)
     * için ortak düzen. Bu sayfalar önceden <head>'i kendi görünümlerinde
     * baştan yazıyordu.
     *
     * @param array{title?: string, head?: string} $page head: ek <head> içeriği (ham HTML)
     */
    protected static function renderAuthPage(string $view, array $data = [], array $page = []): void
    {
        $auth_title = $page['title'] ?? '';
        $auth_head = $page['head'] ?? '';
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
