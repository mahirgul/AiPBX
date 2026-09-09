<?php
/**
 * Basit View render yardımcısı. Twig gibi ayrı bir template dili GEREKMİYOR —
 * mevcut projenin düz PHP+HTML karışımı deseni devam ediyor, sadece artık
 * Controller'dan fiziksel olarak ayrı bir dosyada duruyor (templates/views/).
 */
class View
{
    /** Sayfalama bileseninin kabul ettigi boyutlar. */
    public const SAYFA_BOYUTLARI = [10, 25, 50, 100];

    /**
     * Istekten gecerli sayfa boyutunu okur.
     *
     * Serbest sayi kabul edilmez: ?boyut=100000 ile tum tablonun cekilmesi
     * engellenir. Listede olmayan bir deger gelirse varsayilana donulur.
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
            throw new \RuntimeException("View bulunamadı: {$view} ({$file})");
        }
        extract($data, EXTR_SKIP);
        require $file;
    }
}
