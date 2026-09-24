<?php

/**
 * Tablo ÜSTÜ sayfalama denetimleri — "Göster: N Kayıt" seçicisi.
 *
 * Yerleşim, istemci tarafı zenginleştiricinin (assets/js/datatable_enhancer.js)
 * ürettiğiyle aynı: `dt-controls-bar` tablonun üstünde, seçici SAĞDA.
 * Kullanıcı bunu diğer sayfalarda alışkanlık haline getirdiği için sunucu
 * tarafı sayfalamada da aynı yerde durmalı.
 *
 * Arama kutusu burada YOK: bu sayfaların kendi filtre formu var, ikinci bir
 * arama alanı iki farklı filtre gibi görünürdü.
 *
 * Beklenen değişken:
 *   $page_size — geçerli sayfa boyutu
 */

$page_size = intval($page_size ?? 0);
if ($page_size <= 0) {
    return;
}

// Boyut değişince 1. sayfaya dönülür: 5. sayfadayken 100'e çıkılırsa o sayfa
// var olmayabilir ve liste boş görünürdü.
$boyut_url = static function (int $b): string {
    $qs = $_GET;
    $qs['boyut'] = $b;
    $qs['page'] = 1;
    return '?' . http_build_query($qs);
};
?>
<div class="dt-controls-bar">
    <div></div>
    <div class="dt-length-box">
        <span><?php echo t('pagination.show'); ?></span>
        <select class="dt-length-select" onchange="if(window.loadSPAPage){window.loadSPAPage(this.value,true);}else{window.location.href=this.value;}">
            <?php foreach (View::SAYFA_BOYUTLARI as $b): ?>
                <option value="<?php echo htmlspecialchars($boyut_url($b)); ?>" <?php echo $b === $page_size ? 'selected' : ''; ?>>
                    <?php echo sprintf(t('pagination.records'), $b); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
