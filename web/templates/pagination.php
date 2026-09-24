<?php

/**
 * Sunucu tarafı sayfalama — sistem genelindeki DataTable görünümüyle aynı dil.
 *
 * Neden ayrı: assets/js/datatable_enhancer.js tüm tabloları istemci tarafında
 * sayfalıyor, ama bunun için TÜM satırların tarayıcıya yüklenmiş olması
 * gerekiyor. Çağrı raporları ve faks kutusu binlerce satıra çıktığı için o yol
 * sayfayı yavaşlatıyordu; bu iki tablo `data-no-dt="true"` ile devre dışı ve
 * sayfalamayı sunucu yapıyor.
 *
 * Görsel olarak fark edilmemeli: aynı sınıflar (dt-pagination-bar, dt-info,
 * dt-pagination-nav, dt-page-btn) ve aynı görsel dil
 * ifadesi kullanılıyor.
 *
 * Beklenen değişkenler:
 *   $page        — bulunulan sayfa (1'den başlar)
 *   $total_pages — toplam sayfa sayısı
 * İsteğe bağlı:
 *   $total_rows  — toplam kayıt (bilgi satırı için)
 *   $page_size   — sayfa boyutu (bilgi satırındaki aralığı hesaplar)
 *
 * "Göster: N Kayıt" seçicisi tablonun ÜSTÜNDE ayrı durur:
 * templates/pagination_controls.php
 */

$page = max(1, intval($page ?? 1));
$total_pages = max(1, intval($total_pages ?? 1));
$page_size = intval($page_size ?? 0);
$total_rows = intval($total_rows ?? 0);

// Kayıt yoksa veya tek sayfa ve kayıt sayısı belirsizse gizle
if ($total_rows <= 0 && $total_pages <= 1) {
    return;
}

$sayfa_url = static function (int $n): string {
    $qs = $_GET;
    $qs['page'] = $n;
    return '?' . http_build_query($qs);
};


$ilk = max(1, $page - 2);
$son = min($total_pages, $ilk + 4);
$ilk = max(1, $son - 4);
?>
<div class="dt-pagination-bar">

        <div class="dt-info">
            <?php
            if (!empty($total_rows) && $page_size > 0) {
                $bas = (($page - 1) * $page_size) + 1;
                $bit = min($page * $page_size, (int) $total_rows);
                echo htmlspecialchars(sprintf(t('pagination.range'), $bas, $bit, $total_rows));
            } else {
                echo htmlspecialchars(sprintf(t('pagination.page_of'), $page, $total_pages));
            }
            ?>
        </div>

    <div class="dt-pagination-nav">
        <?php if ($total_pages > 1): ?>
            <a class="dt-page-btn" href="<?php echo htmlspecialchars($sayfa_url(max(1, $page - 1))); ?>"
               <?php echo $page <= 1 ? 'aria-disabled="true"' : ''; ?>>
                <i class="fas fa-chevron-left"></i>
            </a>

            <?php for ($n = $ilk; $n <= $son; $n++): ?>
                <a class="dt-page-btn<?php echo $n === $page ? ' active' : ''; ?>"
                   href="<?php echo htmlspecialchars($sayfa_url($n)); ?>"><?php echo $n; ?></a>
            <?php endfor; ?>

            <a class="dt-page-btn" href="<?php echo htmlspecialchars($sayfa_url(min($total_pages, $page + 1))); ?>"
               <?php echo $page >= $total_pages ? 'aria-disabled="true"' : ''; ?>>
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
