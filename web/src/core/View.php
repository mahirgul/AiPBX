<?php
/**
 * Simple view render helper. A separate template language like Twig is NOT
 * NEEDED — the project's plain PHP+HTML pattern continues, it just lives in a
 * file physically separate from the controller now (templates/views/).
 */
class View
{
    /** Page sizes the pagination component accepts. */
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * Reads the valid page size from the request.
     *
     * Free numbers are not accepted: this prevents fetching the whole table
     * with ?size=100000. A value not on the list falls back to the default.
     */
    public static function pageSize(int $default_value = 50): int
    {
        // ?size= (?boyut= is still read: links and bookmarks made before the rename).
        $b = intval($_GET['size'] ?? $_GET['boyut'] ?? $default_value);
        return in_array($b, self::PAGE_SIZES, true) ? $b : $default_value;
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
