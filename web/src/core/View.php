<?php
/**
 * Simple view render helper. A separate template language like Twig is NOT
 * NEEDED — the project's plain PHP+HTML pattern continues, it just lives in a
 * file physically separate from the controller now (templates/views/).
 */
class View
{
    /** Sayfalama bileseninin kabul ettigi boyutlar. */
    public const SAYFA_BOYUTLARI = [10, 25, 50, 100];

    /**
     * Reads the valid page size from the request.
     *
     * Free numbers are not accepted: this prevents fetching the whole table
     * with ?boyut=100000. A value not on the list falls back to the default.
     */
    public static function sayfaBoyutu(int $varsayilan = 50): int
    {
        $b = intval($_GET['boyut'] ?? $varsayilan);
        return in_array($b, self::SAYFA_BOYUTLARI, true) ? $b : $varsayilan;
    }

    public static function render(string $view, array $data = []): void
    {
        $file = dirname(__DIR__, 2) . '/templates/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view} ({$file})");
        }
        extract($data, EXTR_SKIP);
        require $file;
    }
}
